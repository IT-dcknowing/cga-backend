<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Contracts\FinishesImport;
use App\Domain\Imports\Contracts\RowImporter;
use App\Domain\Imports\Data\MemberImportLine;
use App\Domain\Imports\Data\RowOutcome;
use App\Domain\Imports\Enums\ImportRowStatus;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\CellValue;
use App\Domain\Imports\Support\ImportWorkbook;
use App\Domain\Members\Actions\ImportMember;
use App\Domain\Members\Enums\EntityType;
use App\Domain\Members\Enums\FeesPeriodicity;
use App\Domain\Members\Enums\LegalForm;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Enums\TaxRegime;
use App\Domain\Members\Models\BusinessReferrer;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Rules\RegimeOfPortfolio;
use App\Domain\Members\Support\MemberNumbering;
use App\Domain\Members\Support\MembershipExclusivity;
use App\Domain\Members\Support\Ncc;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Referentials\Enums\SectorStatus;
use App\Domain\Referentials\Models\Commune;
use App\Domain\Referentials\Models\Sector;
use App\Domain\Referentials\Models\TaxOffice;
use App\Domain\Users\Models\User;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Numbering\SequenceService;
use App\Support\Phones\PhoneNormalizer;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Import d'un portefeuille existant (F-IMP-02) : une ligne par adhérent, champs du F-ADH-02 dont
 * portefeuille, régime, date de signature du contrat CGA et apporteur (lignes CGA seulement), ancien
 * n°, date d'adhésion, statut et honoraires. Une ligne invalide (champ obligatoire absent, NCC mal
 * formé, doublon, exclusivité, apporteur sur une ligne Cabinet) est rejetée avec ses motifs ; une
 * ligne incomplète, ou « brouillon » dans le fichier, devient un brouillon avec ce qui manque
 * (RG-IMP-02) ; une ligne complète et active devient un dossier actif (RG-IMP-03). À la fin du lot,
 * chaque dossier actif garde son ancien n° et reçoit son n° CGA Connect (ou son n° client au Cabinet)
 * dans l'ordre chronologique des adhésions, à partir du numéro de départ choisi (RG-IMP-01).
 */
class MemberRowImporter implements FinishesImport, RowImporter
{
    private const ACTIVE = ['', 'actif', 'active', 'valide'];

    private const DRAFT = ['brouillon', 'avalider', 'acompleter', 'encours'];

    /**
     * Champs exigés d'un dossier actif (RG-ADH-02), avec ceux du dirigeant d'une entreprise individuelle.
     */
    private const COMPLETE = ['adhesion_date', 'tax_office', 'sector', 'address', 'signatory_name', 'signatory_title'];

    private const OWNER = ['owner_last_name', 'owner_first_names', 'owner_nationality', 'owner_id_document_type', 'owner_id_document_number'];

    /**
     * Longueur maximale des champs texte de la fiche.
     */
    private const MAX = [
        'legacy_number' => 60, 'company_name' => 200, 'sigle' => 50, 'rccm' => 50, 'idu' => 50, 'main_activity' => 255,
        'address' => 2000, 'email' => 255, 'owner_last_name' => 150, 'owner_first_names' => 150, 'owner_nationality' => 80,
        'owner_id_document_type' => 30, 'owner_id_document_number' => 50, 'signatory_name' => 150, 'signatory_title' => 100,
    ];

    /**
     * Références désignées par leur code ou leur nom normalisés, chargées une fois par lot.
     *
     * @var array<string, array<string, string>>
     */
    private array $references = [];

    private ?User $actor = null;

    public function __construct(
        private readonly ImportMember $members,
        private readonly MembershipExclusivity $exclusivity,
        private readonly PhoneNormalizer $phones,
        private readonly TenantContext $context,
        private readonly SequenceService $sequences,
        private readonly MemberNumbering $numbering,
    ) {}

    public function columns(): array
    {
        $column = static fn (string $label, string $help, array $aliases = []): array => ['label' => $label, 'help' => __("imports.members.help.{$help}"), 'aliases' => $aliases];

        return [
            'portfolio' => $column('Portefeuille *', 'portfolio'),
            'legacy_number' => $column('Ancien n°', 'legacy_number', ['Ancien numéro', 'N° d\'adhésion', 'Numéro d\'adhésion']),
            'status' => $column('Statut', 'status'),
            'adhesion_date' => $column('Date d\'adhésion', 'adhesion_date', ['Date adhésion']),
            'cga_contract_signed_on' => $column('Date de signature du contrat CGA', 'cga_contract_signed_on', ['Date du contrat CGA']),
            'referrer' => $column('Apporteur', 'referrer', ['Accessoire']),
            'company_name' => $column('Raison sociale *', 'company_name', ['Raison sociale', 'Nom de l\'entreprise', 'Dénomination']),
            'sigle' => $column('Sigle', 'sigle'),
            'legal_form' => $column('Forme juridique *', 'legal_form', ['Forme juridique']),
            'ncc' => $column('NCC *', 'ncc', ['NCC', 'N° de compte contribuable']),
            'rccm' => $column('RCCM', 'rccm'),
            'idu' => $column('IDU', 'idu'),
            'regime' => $column('Régime *', 'regime', ['Régime', 'Régime d\'imposition']),
            'main_activity' => $column('Activité principale *', 'main_activity', ['Activité principale', 'Activité']),
            'sector' => $column('Secteur', 'sector', ['Secteur d\'activité']),
            'tax_office' => $column('CDI', 'tax_office', ['Centre des impôts', 'CDI de rattachement']),
            'commune' => $column('Commune', 'commune'),
            'address' => $column('Adresse', 'address', ['Localisation']),
            'phone' => $column('Téléphone *', 'phone', ['Téléphone', 'Tél', 'Contact']),
            'whatsapp' => $column('WhatsApp', 'whatsapp'),
            'email' => $column('E-mail', 'email', ['Email', 'Courriel']),
            'owner_last_name' => $column('Nom du dirigeant', 'owner_last_name'),
            'owner_first_names' => $column('Prénoms du dirigeant', 'owner_first_names'),
            'owner_nationality' => $column('Nationalité du dirigeant', 'owner_nationality'),
            'owner_id_document_type' => $column('Type de pièce d\'identité', 'owner_id_document_type'),
            'owner_id_document_number' => $column('N° de pièce d\'identité', 'owner_id_document_number'),
            'signatory_name' => $column('Signataire', 'signatory_name', ['Nom du signataire']),
            'signatory_title' => $column('Qualité du signataire', 'signatory_title'),
            'employees_count' => $column('Effectif', 'employees_count'),
            'fees_amount' => $column('Honoraires (FCFA)', 'fees_amount', ['Honoraires', 'Montant des honoraires']),
            'fees_periodicity' => $column('Périodicité des honoraires', 'fees_periodicity', ['Périodicité']),
        ];
    }

    public function requiredColumns(): array
    {
        return ['portfolio', 'company_name', 'legal_form', 'ncc', 'regime', 'main_activity', 'phone'];
    }

    public function labelColumn(): string
    {
        return 'company_name';
    }

    public function fileName(): string
    {
        return 'import-portefeuille';
    }

    /**
     * Sort attendu de la ligne, sans rien écrire : partagé par l'aperçu et l'import. $checkDatabase :
     * doublon dans le centre et exclusivité contrôlés ici (l'import les laisse à l'inscription).
     *
     * @param  array<string, mixed>  $values
     */
    public function analyze(array $values, bool $checkDatabase = true): MemberImportLine
    {
        $text = static function (string $key) use ($values): ?string {
            $value = CellValue::text($values[$key] ?? null);

            return $value === null || trim($value) === '' ? null : trim($value);
        };
        $label = $text('company_name');
        $legacy = $text('legacy_number');

        $portfolio = $this->option($text('portfolio'), Portfolio::cases());
        $legalForm = $this->option($text('legal_form'), LegalForm::cases());
        $ncc = $text('ncc') === null ? null : Ncc::normalize((string) $text('ncc'));
        $regime = $this->option($text('regime'), TaxRegime::cases());
        $periodicity = $this->option($text('fees_periodicity'), FeesPeriodicity::cases());
        $status = ImportWorkbook::normalize((string) $text('status'));
        $phone = $text('phone') === null ? null : $this->phones->e164((string) $text('phone'));
        $whatsapp = $text('whatsapp') === null ? null : $this->phones->e164((string) $text('whatsapp'));
        $adhesion = CellValue::date($values['adhesion_date'] ?? null);
        $contract = CellValue::date($values['cga_contract_signed_on'] ?? null);
        $fees = $this->amount($values['fees_amount'] ?? null);
        $employees = $this->amount($values['employees_count'] ?? null);

        $reasons = [];
        foreach ($this->requiredColumns() as $key) {
            if ($text($key) === null) {
                $reasons[] = __('imports.members.required', ['field' => $this->header($key)]);
            }
        }
        $invalid = [
            'portfolio' => $portfolio === false, 'legal_form' => $legalForm === false, 'regime' => $regime === false,
            'fees_periodicity' => $periodicity === false, 'phone' => $phone === null, 'whatsapp' => $whatsapp === null,
            'fees_amount' => $fees === false, 'employees_count' => $employees === false,
            'status' => ! in_array($status, [...self::ACTIVE, ...self::DRAFT], true),
            'email' => $text('email') !== null && filter_var($text('email'), FILTER_VALIDATE_EMAIL) === false,
        ];
        foreach ($invalid as $key => $wrong) {
            if ($wrong && $text($key) !== null) {
                $reasons[] = __('imports.members.invalid_value', ['field' => $this->header($key), 'value' => $text($key)]);
            }
        }
        if ($ncc !== null && ! Ncc::isValid($ncc)) {
            $reasons[] = __('imports.members.invalid_ncc', ['value' => $text('ncc')]);
        }
        if ($portfolio === Portfolio::Cga && $regime instanceof TaxRegime && ! in_array($regime, RegimeOfPortfolio::cga(), true)) {
            $reasons[] = __('imports.members.regime_not_cga', ['regime' => $regime->value]);
        }
        if ($portfolio === Portfolio::Cabinet && $text('referrer') !== null) {
            $reasons[] = __('imports.members.referrer_cabinet');
        }
        foreach (['adhesion_date' => $adhesion, 'cga_contract_signed_on' => $contract] as $key => $date) {
            if ($date === false) {
                $reasons[] = __('imports.members.invalid_date', ['field' => $this->header($key)]);
            } elseif ($date instanceof CarbonImmutable && $date->isFuture()) {
                $reasons[] = __('imports.members.future_date', ['field' => $this->header($key)]);
            }
        }
        foreach (self::MAX as $key => $max) {
            if ($text($key) !== null && mb_strlen((string) $text($key)) > $max) {
                $reasons[] = __('imports.members.too_long', ['field' => $this->header($key), 'max' => $max]);
            }
        }

        $adhesionDate = $adhesion instanceof CarbonImmutable ? $adhesion : null;
        if ($checkDatabase && $reasons === [] && $ncc !== null && Member::query()->open()->where('ncc', $ncc)->exists()) {
            $reasons[] = __('imports.members.duplicate', ['ncc' => $ncc]);
        } elseif ($checkDatabase && $reasons === [] && $ncc !== null && $portfolio === Portfolio::Cga && $this->exclusivity->enrolledElsewhere($ncc, $this->context->organizationId())) {
            $reasons[] = __('errors.membership_exclusive');
        }
        if ($reasons !== [] || ! $portfolio instanceof Portfolio || ! $legalForm instanceof LegalForm || ! $regime instanceof TaxRegime) {
            return new MemberImportLine(ImportRowStatus::Rejected, $reasons, $label, $portfolio instanceof Portfolio ? $portfolio : null, $ncc, $legacy, $adhesionDate, []);
        }

        $ids = [
            'sector' => $this->reference('sector', $text('sector')),
            'tax_office' => $this->reference('tax_office', $text('tax_office')),
            'commune' => $this->reference('commune', $text('commune')),
            'referrer' => $portfolio === Portfolio::Cga ? $this->reference('referrer', $text('referrer')) : null,
        ];
        $contractDate = $contract instanceof CarbonImmutable ? $contract : $adhesionDate;
        $attributes = [
            'entity_type' => ($legalForm === LegalForm::Ei ? EntityType::Individual : EntityType::Company)->value,
            'legal_form' => $legalForm->value,
            'ncc' => $ncc,
            'regime' => $regime->value,
            'regime_since' => $adhesionDate?->toDateString(),
            'referrer_id' => $ids['referrer'],
            'tax_office_id' => $ids['tax_office'],
            'sector_id' => $ids['sector'],
            'commune_id' => $ids['commune'],
            'phone' => $phone,
            'whatsapp' => $whatsapp,
            'employees_count' => is_int($employees) ? $employees : null,
            'fees_amount' => is_int($fees) ? $fees : null,
            'fees_periodicity' => $periodicity instanceof FeesPeriodicity ? $periodicity->value : null,
            'cga_contract_signed_on' => $portfolio === Portfolio::Cga ? $contractDate?->toDateString() : null,
        ];
        foreach (array_keys(self::MAX) as $key) {
            $attributes[$key] = $text($key);
        }

        $gaps = [];
        foreach ($ids as $key => $id) {
            if ($id === null && $text($key) !== null && ($key !== 'referrer' || $portfolio === Portfolio::Cga)) {
                $gaps[] = __('imports.members.unknown', ['field' => $this->header($key), 'value' => $text($key)]);
            }
        }
        $required = [...self::COMPLETE, ...($legalForm === LegalForm::Ei ? self::OWNER : [])];
        foreach ($required as $key) {
            if ($text($key) === null) {
                $gaps[] = __('imports.members.missing', ['field' => $this->header($key)]);
            }
        }
        if (in_array($status, self::DRAFT, true)) {
            array_unshift($gaps, __('imports.members.draft_status'));
        }

        return new MemberImportLine($gaps === [] ? ImportRowStatus::Imported : ImportRowStatus::Draft, $gaps, $label, $portfolio, $ncc, $legacy, $adhesionDate, $attributes);
    }

    public function import(array $values): RowOutcome
    {
        $line = $this->analyze($values, checkDatabase: false);
        if ($line->status === ImportRowStatus::Rejected || $line->portfolio === null) {
            return RowOutcome::rejected($line->label, $line->messages === [] ? [__('imports.members.invalid_row')] : $line->messages);
        }

        try {
            $member = $this->members->execute($this->context->organizationId(), $line->portfolio, $line->attributes, $line->active() ? $line->adhesionDate : null, $this->actor());
        } catch (BusinessRuleException $exception) {
            return RowOutcome::rejected($line->label, [$exception->errorCode === 'ncc_already_registered' ? __('imports.members.duplicate', ['ncc' => $line->ncc]) : $exception->getMessage()]);
        }

        return $line->messages === [] ? RowOutcome::imported($member->id, $line->label) : RowOutcome::draft($member->id, $line->label, $line->messages);
    }

    /**
     * Double numérotation (RG-IMP-01) : n° CGA Connect des dossiers actifs du portefeuille CGA dans
     * l'ordre chronologique des adhésions (puis des lignes), à partir de start_number ; n° client au
     * Cabinet. L'abréviation du centre est figée.
     */
    public function finish(ImportBatch $batch, array $options): array
    {
        $rows = $batch->rows()->where('status', ImportRowStatus::Imported)->whereNotNull('created_entity_id')->pluck('row_number', 'created_entity_id');
        if ($rows->isEmpty()) {
            return [];
        }

        $organization = Organization::query()->lockForUpdate()->findOrFail($batch->organization_id);
        $start = $options['start_number'] ?? null;
        if (is_int($start)) {
            $this->sequences->advanceTo($organization->id, MemberNumbering::MEMBERSHIP, $start - 1);
        }

        $members = $this->chronological(Member::query()->whereIn('id', $rows->keys())->lockForUpdate()->get(), $rows);
        $cga = $members->filter(static fn (Member $member): bool => $member->portfolio === Portfolio::Cga)->count();
        $membership = $this->sequences->reserve($organization->id, MemberNumbering::MEMBERSHIP, $cga);
        $client = $this->sequences->reserve($organization->id, MemberNumbering::CLIENT, $members->count() - $cga);

        $notes = [];
        foreach ($members as $member) {
            if ($member->portfolio === Portfolio::Cga) {
                $member->membership_seq = $membership++;
                $member->membership_number = $this->numbering->membershipNumber($member->membership_seq, $member->adhesion_date?->year, $organization->numbering_abbreviation);
            } else {
                $member->client_number = $this->numbering->clientNumber($client++, $organization->numbering_abbreviation);
            }
            $member->save();
            $notes[$member->id] = __('imports.members.numbered', ['number' => $member->membership_number ?? $member->client_number, 'legacy' => $member->legacy_number ?? '—']);
        }

        if ($organization->abbreviation_locked_at === null) {
            $organization->abbreviation_locked_at = now();
            $organization->save();
        }

        return $notes;
    }

    /**
     * Dossiers dans l'ordre chronologique des adhésions, puis des lignes du fichier.
     *
     * @param  Collection<int, Member>  $members
     * @param  Collection<string, int>  $rows  ligne du fichier par dossier créé
     * @return Collection<int, Member>
     */
    private function chronological(Collection $members, Collection $rows): Collection
    {
        return $members->sortBy(static fn (Member $member): string => sprintf('%s-%08d', $member->adhesion_date?->toDateString() ?? '9999-12-31', $rows->get($member->id)))->values();
    }

    /**
     * Valeur d'une liste fermée, par sa valeur ou son libellé (casse, accents et ponctuation ignorés).
     *
     * @param  list<BackedEnum>  $cases
     * @return BackedEnum|false|null false si la valeur n'est pas reconnue
     */
    private function option(?string $text, array $cases): BackedEnum|false|null
    {
        if ($text === null) {
            return null;
        }

        $wanted = ImportWorkbook::normalize($text);
        foreach ($cases as $case) {
            $label = method_exists($case, 'label') ? (string) $case->label() : '';
            if (in_array($wanted, [ImportWorkbook::normalize((string) $case->value), ImportWorkbook::normalize($label)], true)) {
                return $case;
            }
        }

        return $wanted === 'autre' && in_array(LegalForm::Other, $cases, true) ? LegalForm::Other : false;
    }

    /**
     * Montant ou effectif entier positif ; false si illisible.
     */
    private function amount(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            return $value >= 0 ? (int) $value : false;
        }
        $digits = is_string($value) ? preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value) : null;

        return is_string($digits) && preg_match('/^\d+$/', $digits) === 1 ? (int) $digits : false;
    }

    /**
     * Identifiant d'une référence désignée par son code ou son nom : secteur validé (ou proposé par le
     * centre), CDI actif, commune, apporteur actif du centre (son libellé « Accessoire n » ou son nom).
     */
    private function reference(string $type, ?string $designation): ?string
    {
        if ($designation === null) {
            return null;
        }

        $this->references[$type] ??= $this->load($type);

        return $this->references[$type][ImportWorkbook::normalize($designation)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function load(string $type): array
    {
        $organizationId = $this->context->organizationId();
        $rows = match ($type) {
            'sector' => Sector::query()->where(static fn (Builder $sector) => $sector
                ->where('status', SectorStatus::Validated)
                ->orWhere(static fn (Builder $proposed) => $proposed->where('status', SectorStatus::Proposed)->where('proposed_by_organization_id', $organizationId)))
                ->get(['id', 'code', 'name'])->map(static fn (Sector $sector): array => [$sector->id, $sector->code, $sector->name]),
            'tax_office' => TaxOffice::query()->where('active', true)->get(['id', 'code', 'name'])->map(static fn (TaxOffice $office): array => [$office->id, $office->code, $office->name]),
            'commune' => Commune::query()->get(['id', 'name'])->map(static fn (Commune $commune): array => [$commune->id, $commune->name, $commune->name]),
            default => BusinessReferrer::query()->where('active', true)->get(['id', 'label', 'name'])->map(static fn (BusinessReferrer $referrer): array => [$referrer->id, $referrer->label, $referrer->name]),
        };

        $index = [];
        foreach ($rows as [$id, $code, $name]) {
            $index[ImportWorkbook::normalize((string) $code)] = (string) $id;
            $index[ImportWorkbook::normalize((string) $name)] ??= (string) $id;
        }

        return $index;
    }

    private function header(string $key): string
    {
        return rtrim($this->columns()[$key]['label'], ' *');
    }

    private function actor(): User
    {
        return $this->actor ??= User::query()->findOrFail($this->context->userId());
    }
}
