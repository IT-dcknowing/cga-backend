<?php

namespace App\Domain\Announcements\Models;

use App\Domain\Audit\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Lecture d'une annonce par un utilisateur (05-BACKEND-SCHEMA §14) : une seule, la première.
 *
 * @property string $announcement_id
 * @property string $user_id
 * @property CarbonInterface $read_at
 */
class AnnouncementRead extends Model
{
    use Auditable;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    /**
     * Écrit par MarkAnnouncementRead seulement.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }
}
