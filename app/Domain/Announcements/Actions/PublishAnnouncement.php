<?php

namespace App\Domain\Announcements\Actions;

use App\Domain\Announcements\AnnouncementNotification;
use App\Domain\Announcements\Enums\AnnouncementAudience;
use App\Domain\Announcements\Models\Announcement;
use App\Domain\Announcements\Models\AnnouncementTarget;
use App\Domain\Announcements\Support\AnnouncementFeed;
use App\Domain\Users\Models\Membership;
use App\Support\Files\StorageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Publier une annonce (F-COM-01, 02) : titre, texte, pièce jointe, cible, e-mail en option. Elle est publiée
 * aussitôt, ses destinataires sont comptés et notifiés (plateforme, e-mail si choisi). La cible et son
 * périmètre viennent de l'espace de l'auteur : le CNCGA vise les CGA ou les adhérents du réseau, un CGA ses
 * seuls adhérents.
 */
class PublishAnnouncement
{
    private const FILE_TYPE = 'announcements';

    public function __construct(
        private readonly AnnouncementFeed $feed,
        private readonly StorageService $storage,
    ) {}

    /**
     * @param  list<string>  $targets  CGA choisis (« à certains CGA »)
     * @param  array{portfolio: string, regimes: list<string>, sector_ids: list<string>}|null  $filter  adhérents d'un CGA
     */
    public function execute(string $title, string $body, ?UploadedFile $attachment, AnnouncementAudience $audience, array $targets, ?array $filter, bool $sendEmail, Membership $actor): Announcement
    {
        return DB::transaction(function () use ($title, $body, $attachment, $audience, $targets, $filter, $sendEmail, $actor): Announcement {
            $announcement = new Announcement;
            $announcement->organization_id = $actor->organization_id;
            $announcement->title = $title;
            $announcement->body = $body;
            $announcement->attachment_file_id = $attachment === null ? null : $this->storage->storeUpload($attachment, self::FILE_TYPE, $actor->organization_id, $actor->user_id)->id;
            $announcement->audience = $audience;
            $announcement->member_filter = $audience === AnnouncementAudience::OwnMembers ? $filter : null;
            $announcement->send_email = $sendEmail;
            $announcement->published_at = CarbonImmutable::now();
            $announcement->created_by = $actor->user_id;
            $announcement->save();

            if ($audience === AnnouncementAudience::SelectedCgas) {
                foreach (array_values(array_unique($targets)) as $organizationId) {
                    $target = new AnnouncementTarget;
                    $target->announcement_id = $announcement->id;
                    $target->target_organization_id = $organizationId;
                    $target->save();
                }
            }

            $recipients = $this->feed->recipients($announcement);
            $author = (string) $actor->organization()->value('name');
            foreach ($recipients->unique('id') as $membership) {
                $membership->user->notify(new AnnouncementNotification(
                    $membership->organization_id,
                    $membership->id,
                    $title,
                    $author,
                    $sendEmail,
                    $membership->member_id !== null ? '/adherent' : '/cga/communications',
                ));
            }
            $announcement->recipients_count = $recipients->unique('id')->count();
            $announcement->save();

            return $announcement;
        });
    }
}
