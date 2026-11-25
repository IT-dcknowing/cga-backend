<?php

namespace Tests\Fixtures;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Donnée cloisonnée de test (table tenant_notes, créée par le test lui-même).
 *
 * @property int $id
 * @property string $organization_id
 * @property string $body
 */
class TenantNote extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['body', 'organization_id'];

    public static function createTable(): void
    {
        Schema::create('tenant_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('body');
        });
    }
}
