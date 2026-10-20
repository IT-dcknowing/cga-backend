<?php

namespace App\Domain\Referentials\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Referentials\Contracts\Referential;
use App\Support\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Jour férié du calendrier géré par le CNCGA (RG-OBL-06).
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property string $label
 */
class Holiday extends Model implements Referential
{
    /** @use HasFactory<HolidayFactory> */
    use Auditable, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['date', 'label'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'immutable_date'];
    }

    public static function referencedBy(): array
    {
        return [];
    }

    protected static function newFactory(): HolidayFactory
    {
        return HolidayFactory::new();
    }

    protected static function booted(): void
    {
        static::saved(static fn () => app(BusinessCalendar::class)->forget());
        static::deleted(static fn () => app(BusinessCalendar::class)->forget());
    }
}
