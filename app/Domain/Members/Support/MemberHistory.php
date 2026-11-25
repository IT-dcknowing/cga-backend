<?php

namespace App\Domain\Members\Support;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Documents\Models\AttestationBlock;
use App\Domain\Documents\Models\File;
use App\Domain\Members\Data\MemberHistoryEntry;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberPiece;
use App\Domain\Members\Models\MemberRegimeChange;
use App\Domain\Members\Models\MemberRevenue;
use App\Domain\Members\Models\PortfolioMovement;
use App\Domain\Members\Models\Radiation;
use App\Domain\Referentials\Models\ActivityCode;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Models\User;
use App\Support\Files\StorageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Onglet « Historique » d'un dossier (F-ADH-03, 03-APPFLOW §4.3) : le journal des modifications du
 * dossier et de ses éléments (chiffre d'affaires, régime, radiation, bascule de portefeuille, blocage
 * d'attestation, pièces), du plus récent au plus ancien — date, auteur, champ, ancienne et nouvelle
 * valeur, justificatif (RG-ADH-06b), source (saisie, import, Compta Flow).
 * Jamais montrés : les champs techniques, l'apporteur sans le droit de le voir (RG-PTF-06),
 * l'observation d'une radiation à qui ne demande ni ne valide les radiations.
 */
class MemberHistory
{
    /**
     * Éléments du dossier dont le journal compose l'historique, et leur nom dans la réponse.
     *
     * @var array<class-string<Model>, string>
     */
    private const SUBJECTS = [
        Member::class => 'member',
        MemberRevenue::class => 'revenue',
        MemberRegimeChange::class => 'regime_change',
        Radiation::class => 'radiation',
        PortfolioMovement::class => 'portfolio_movement',
        AttestationBlock::class => 'attestation_block',
        MemberPiece::class => 'piece',
    ];

    /**
     * Champs techniques : identifiants, auteurs (déjà montrés par l'entrée), fichiers, index.
     */
    private const TECHNICAL = [
        'id', 'organization_id', 'member_id', 'search_text', 'membership_seq', 'deleted_at',
        'file_id', 'cga_contract_file_id', 'proof_file_id', 'justification_file_id',
        'created_by', 'updated_by', 'validated_by', 'requested_by', 'cancelled_by', 'blocked_by',
        'unblocked_by', 'released_by', 'cga_contract_confirmed_by', 'moved_by', 'radiation_id',
    ];

    /**
     * Champs qui désignent une valeur d'un référentiel : leur nom est montré à la place de l'identifiant.
     *
     * @var array<string, class-string<Model>>
     */
    private const REFERENCES = [
        'tax_office_id' => TaxOffice::class,
        'sector_id' => Sector::class,
        'commune_id' => Commune::class,
        'activity_code_id' => ActivityCode::class,
        'referrer_id' => BusinessReferrer::class,
    ];

    /**
     * Valeur montrée à la place d'une donnée chiffrée en base (cast « encrypted »).
     */
    public const SEALED = '[chiffré]';

    public function __construct(
        private readonly TenantContext $context,
        private readonly StorageService $storage,
    ) {}

    /**
     * @return LengthAwarePaginator<int, MemberHistoryEntry>
     */
    public function page(Member $member, int $perPage, int $page): LengthAwarePaginator
    {
        $subjects = $this->subjects($member);
        $paginator = AuditLog::query()
            ->where(static function (Builder $query) use ($subjects): void {
                foreach ($subjects as $type => $ids) {
                    $query->orWhere(static fn (Builder $subject) => $subject->where('subject_type', $type)->whereIn('subject_id', $ids));
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        /** @var Collection<int, AuditLog> $logs */
        $logs = collect($paginator->items());
        $actors = User::query()->whereIn('id', $logs->pluck('user_id')->filter()->unique()->values())->get()->keyBy('id');
        $justifications = $this->justifications($logs);
        $names = $this->referenceNames($logs);
        $hidden = $this->hiddenFields($member);
        $types = [];
        $encrypted = [];
        foreach (self::SUBJECTS as $model => $subject) {
            $instance = new $model;
            $types[$instance->getMorphClass()] = $subject;
            $encrypted[$subject] = array_keys(array_filter($instance->getCasts(), static fn (string $cast): bool => str_starts_with($cast, 'encrypted')));
        }

        return $paginator->through(function (AuditLog $log) use ($actors, $justifications, $names, $hidden, $types, $encrypted): MemberHistoryEntry {
            $subject = $types[(string) $log->subject_type] ?? 'member';
            $changes = [];
            foreach ($log->changes ?? [] as $field => [$before, $after]) {
                if (in_array($field, $hidden, true)) {
                    continue;
                }
                // Une valeur chiffrée en base n'est lisible que sur la fiche, jamais dans le journal.
                $sealed = in_array($field, $encrypted[$subject], true);
                $changes[] = [
                    'field' => $field,
                    'before' => $sealed && $before !== null ? self::SEALED : $this->shown($field, $before, $names),
                    'after' => $sealed && $after !== null ? self::SEALED : $this->shown($field, $after, $names),
                ];
            }
            $source = $log->changes['source'][1] ?? $log->context['source'] ?? null;
            $actor = $log->user_id === null ? null : $actors->get($log->user_id);

            return new MemberHistoryEntry(
                id: $log->id,
                occurredAt: $log->created_at,
                event: $log->event,
                subject: $subject,
                actor: $actor instanceof User ? $actor->fullName() : null,
                changes: $changes,
                justification: $justifications[$log->id] ?? null,
                source: is_string($source) ? $source : null,
            );
        });
    }

    /**
     * Identifiants des éléments du dossier, par type enregistré au journal.
     *
     * @return array<string, list<string>>
     */
    private function subjects(Member $member): array
    {
        $subjects = [];
        foreach (array_keys(self::SUBJECTS) as $model) {
            $instance = new $model;
            $ids = $model === Member::class
                ? [$member->id]
                : $model::query()->where('member_id', $member->id)->pluck($instance->getKeyName())->map(static fn (mixed $id): string => (string) $id)->all();
            if ($ids !== []) {
                $subjects[$instance->getMorphClass()] = $ids;
            }
        }

        return $subjects;
    }

    /**
     * @return list<string>
     */
    private function hiddenFields(Member $member): array
    {
        $hidden = self::TECHNICAL;
        if (! $this->context->allows('members.referrers.view')) {
            $hidden[] = 'referrer_id';
        }
        if (! Gate::allows('requestRadiation', $member) && ! Gate::allows('validateRadiation', $member)) {
            $hidden[] = 'observation';
        }

        return $hidden;
    }

    /**
     * Justificatif joint à une modification (audit_attachments, RG-ADH-06b), par entrée du journal.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<int, array{name: string, url: string}>
     */
    private function justifications(Collection $logs): array
    {
        $links = DB::table('audit_attachments')->whereIn('audit_log_id', $logs->pluck('id'))->get(['audit_log_id', 'file_id']);
        $files = File::query()->whereIn('id', $links->pluck('file_id'))->get()->keyBy('id');
        $justifications = [];
        foreach ($links as $link) {
            $file = $files->get($link->file_id);
            if ($file instanceof File) {
                $justifications[(int) $link->audit_log_id] = ['name' => $file->original_name, 'url' => $this->storage->temporaryUrl($file)];
            }
        }

        return $justifications;
    }

    /**
     * Noms des valeurs de référentiel citées par la page, par champ puis identifiant.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<string, array<string, string>>
     */
    private function referenceNames(Collection $logs): array
    {
        $names = [];
        foreach (self::REFERENCES as $field => $model) {
            $ids = $logs->flatMap(static fn (AuditLog $log): array => $log->changes[$field] ?? [])->filter(static fn (mixed $id): bool => is_string($id))->unique()->values();
            if ($ids->isNotEmpty()) {
                $column = $model === BusinessReferrer::class ? 'label' : ($model === ActivityCode::class ? 'code' : 'name');
                $names[$field] = $model::query()->whereIn('id', $ids)->pluck($column, 'id')->map(static fn (mixed $name): string => (string) $name)->all();
            }
        }

        return $names;
    }

    /**
     * Valeur montrée : le nom d'une valeur de référentiel, sinon la valeur enregistrée.
     *
     * @param  array<string, array<string, string>>  $names
     */
    private function shown(string $field, mixed $value, array $names): mixed
    {
        if (is_string($value) && isset($names[$field][$value])) {
            return $names[$field][$value];
        }

        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    }
}
