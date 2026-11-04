<?php

namespace App\Domain\Announcements;

use App\Domain\Notifications\PlatformNotification;
use App\Domain\Notifications\Support\NotificationRouting;

/**
 * « Nouvelle annonce » (PRD M13) : toujours dans la plateforme, par e-mail seulement si l'auteur l'a choisi
 * (F-COM-01 : plateforme toujours, e-mail en option), dans la limite des canaux du type.
 */
final class AnnouncementNotification extends PlatformNotification
{
    public function __construct(
        string $organizationId,
        ?string $membershipId,
        private string $title,
        private string $author,
        private bool $withEmail,
        private string $path,
    ) {
        parent::__construct($organizationId, $membershipId);
    }

    public function type(): string
    {
        return 'announcement_published';
    }

    public function replacements(): array
    {
        return ['title' => $this->title, 'author' => $this->author];
    }

    public function link(): string
    {
        return $this->path;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $wanted = $this->withEmail ? ['database', 'mail'] : ['database'];

        return array_values(array_intersect(app(NotificationRouting::class)->channels($this, $notifiable), $wanted));
    }
}
