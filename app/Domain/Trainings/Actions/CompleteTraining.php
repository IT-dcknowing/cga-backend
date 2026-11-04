<?php

namespace App\Domain\Trainings\Actions;

use App\Domain\Documents\Actions\IssueDocument;
use App\Domain\Documents\Data\DocumentRequest;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Trainings\Enums\Attendance;
use App\Domain\Trainings\Enums\RegistrationStatus;
use App\Domain\Trainings\Enums\TrainingStatus;
use App\Domain\Trainings\Models\Training;
use App\Domain\Trainings\Models\TrainingRegistration;
use App\Domain\Trainings\Support\TrainingAccess;
use App\Domain\Trainings\Support\TrainingNotifier;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Clôturer la session » (F-FOR-04) : une fois chaque inscrit émargé, la formation est terminée et une
 * attestation de participation est produite pour chaque présent, qui en est notifié et peut évaluer la
 * session.
 */
class CompleteTraining
{
    public function __construct(
        private readonly IssueDocument $documents,
        private readonly TrainingNotifier $notifier,
    ) {}

    /**
     * @return int attestations produites
     *
     * @throws BusinessRuleException training_not_in_session, training_attendance_incomplete
     */
    public function execute(Training $training, Membership $actor): int
    {
        return TrainingAccess::acrossCentres(fn (): int => DB::transaction(function () use ($training, $actor): int {
            $training = Training::query()->lockForUpdate()->findOrFail($training->id);
            $now = CarbonImmutable::now();
            if ($training->status !== TrainingStatus::Published || $training->starts_at->greaterThan($now)) {
                throw new BusinessRuleException('training_not_in_session');
            }
            $registered = TrainingAccess::registrations($training)->where('status', RegistrationStatus::Registered)->get();
            $unmarked = $registered->whereNull('attendance')->count();
            if ($unmarked > 0) {
                throw new BusinessRuleException('training_attendance_incomplete', details: ['unmarked' => $unmarked]);
            }

            $training->status = TrainingStatus::Completed;
            $training->completed_at = $now;
            $training->save();

            $present = $registered->filter(static fn (TrainingRegistration $registration): bool => $registration->attendance === Attendance::Present);
            foreach ($present as $registration) {
                $certificate = $this->documents->execute(new DocumentRequest(
                    type: DocumentType::TrainingCertificate,
                    title: (string) __('trainings.certificate_title', ['title' => $training->title]),
                    fiscalYear: $training->starts_at->year,
                    visibleToMember: false,
                    sourceType: 'training_registration',
                    sourceId: $registration->id,
                ), $actor);
                $registration->certificate_document_id = $certificate->id;
                $registration->save();
                $this->notifier->registration($registration, 'certificate');
            }

            return $present->count();
        }));
    }
}
