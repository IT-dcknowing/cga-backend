<?php

namespace App\Domain\Payments\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Payments\Enums\CertificationMode;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Mode de certification FNE d'une organisation (RG-FNE-03, F-ADM-07, écran « Facturation FNE ») :
 * manuel par défaut, API FNE de la DGI (adresse en liste blanche, identifiants chiffrés jamais
 * renvoyés), expression de contrôle du n° FNE saisi en mode manuel, résultat du dernier test.
 *
 * @property string $id
 * @property string $organization_id
 * @property CertificationMode $mode
 * @property string|null $base_url
 * @property string|null $credentials
 * @property string|null $fne_number_pattern
 * @property bool $active
 * @property CarbonInterface|null $last_test_at
 * @property bool|null $last_test_ok
 * @property string|null $updated_by
 */
class InvoicingProvider extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    /**
     * Écrit par SaveInvoicingProvider seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Les identifiants ne sortent jamais, pas même dans le journal des modifications.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => CertificationMode::class,
            'credentials' => 'encrypted',
            'active' => 'boolean',
            'last_test_at' => 'immutable_datetime',
            'last_test_ok' => 'boolean',
        ];
    }
}
