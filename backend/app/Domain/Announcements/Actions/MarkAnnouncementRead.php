<?php

namespace App\Domain\Announcements\Actions;

use App\Domain\Announcements\Models\Announcement;
use App\Domain\Announcements\Models\AnnouncementRead;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lecture d'une annonce reçue (05-BACKEND-SCHEMA §14) : la première lecture de l'utilisateur est retenue, les
 * suivantes ne changent rien.
 */
class MarkAnnouncementRead
{
    public function execute(Announcement $announcement, User $reader): AnnouncementRead
    {
        return DB::transaction(static function () use ($announcement, $reader): AnnouncementRead {
            $existing = AnnouncementRead::query()->where('announcement_id', $announcement->id)->where('user_id', $reader->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $read = new AnnouncementRead;
            $read->announcement_id = $announcement->id;
            $read->user_id = $reader->id;
            $read->read_at = CarbonImmutable::now();
            $read->save();

            return $read;
        });
    }
}
