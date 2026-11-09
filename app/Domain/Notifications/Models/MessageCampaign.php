<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Notifications\Enums\CampaignStatus;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Envoi groupé d'un centre à ses adhérents (F-COM-03) : objet, message, canaux (plateforme, e-mail,
 * SMS, WhatsApp), filtre et nombre des destinataires, validation par le gérant au-delà du seuil
 * (RG-COM-01), envoi. Les SMS sont décomptés du quota du centre (RG-COM-02).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $title
 * @property string $body
 * @property string|null $template_key
 * @property list<string> $channels
 * @property array<string, mixed> $recipient_filter
 * @property int $recipients_count
 * @property CampaignStatus $status
 * @property string $requested_by
 * @property string|null $approved_by
 * @property CarbonInterface|null $approved_at
 * @property string|null $rejection_reason
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface $created_at
 * @property-read User $requester
 * @property-read User|null $approver
 */
class MessageCampaign extends Model
{
    use Auditable, BelongsToOrganization, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'recipient_filter' => 'array',
            'recipients_count' => 'integer',
            'status' => CampaignStatus::class,
            'approved_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
