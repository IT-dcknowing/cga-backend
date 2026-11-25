<?php

namespace App\Domain\Documents\Jobs;

use App\Domain\Audit\Enums\ActivityResult;
use App\Domain\Documents\Actions\ProduceCertificate;
use App\Domain\Documents\Events\CertificateCampaignFinished;
use App\Domain\Documents\Support\CertificateCampaign;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Logging\ActivityLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Campagne annuelle d'attestations d'un CGA (RG-DOC-06), en tâche de fond, dans l'espace de celui qui
 * l'a lancée : une attestation de l'année pour chaque dossier encore éligible au moment du passage ;
 * chaque attestation est ensuite produite par la chaîne documentaire (file « documents »). Un dossier
 * devenu inéligible entre-temps est passé et noté au journal. Une seule campagne à la fois par CGA et
 * par année ; un Job relancé ne double aucune attestation.
 */
class RunCertificateCampaign implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const CHUNK = 200;

    public int $tries = 1;

    public function __construct(
        public readonly string $membershipId,
        public readonly int $year,
    ) {}

    public function uniqueId(): string
    {
        return $this->membership()->organization_id.'|'.$this->year;
    }

    public function handle(TenantContext $context, CertificateCampaign $campaign, ProduceCertificate $produce, ActivityLogger $activity): void
    {
        $membership = $this->membership();

        $context->runAs($membership, function () use ($campaign, $produce, $activity, $membership): void {
            $produced = 0;
            $skipped = 0;
            $campaign->eligible($this->year)->chunkById(self::CHUNK, function (Collection $members) use ($produce, $activity, $membership, &$produced, &$skipped): void {
                foreach ($members as $member) {
                    /** @var Member $member */
                    try {
                        $produce->execute($member, $membership);
                        $produced++;
                    } catch (BusinessRuleException $exception) {
                        $skipped++;
                        $activity->record('documents.campaign_skipped', ActivityResult::BusinessError, [
                            'subject_type' => $member->getMorphClass(),
                            'subject_id' => $member->id,
                            'error_code' => $exception->errorCode,
                        ]);
                    }
                }
            });

            CertificateCampaignFinished::dispatch($membership, $this->year, $produced, $skipped);
        });
    }

    private function membership(): Membership
    {
        return Membership::query()->with('organization')->findOrFail($this->membershipId);
    }
}
