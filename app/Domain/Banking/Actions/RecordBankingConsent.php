<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Models\BankingConsentEvent;
use App\Domain\Banking\Models\BankingProfile;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Enregistre le consentement explicite du propriétaire de l'espace adhérent.
 * Le texte exact reste fourni/versionné par la configuration métier approuvée.
 */
class RecordBankingConsent
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function grant(Member $member, string $version, string $textSha256, User $actor): BankingProfile
    {
        $this->authorizeOwner($member, $actor);

        if (blank($version) || mb_strlen($version) > 40 || ! preg_match('/^[0-9a-f]{64}$/', $textSha256)) {
            throw ValidationException::withMessages([
                'consent' => 'La version et l’empreinte SHA-256 du texte de consentement sont obligatoires.',
            ]);
        }

        return DB::transaction(function () use ($member, $version, $textSha256, $actor): BankingProfile {
            $profile = BankingProfile::query()->firstOrNew(['member_id' => $member->id]);
            $profile->organization_id = $member->organization_id;
            $profile->consent_granted = true;
            $profile->consent_version = $version;
            $profile->consent_text_sha256 = $textSha256;
            $profile->consented_at = now();
            $profile->consented_by = $actor->id;
            $profile->withdrawn_at = null;
            $profile->save();

            $event = new BankingConsentEvent;
            $event->organization_id = $member->organization_id;
            $event->member_id = $member->id;
            $event->event = 'granted';
            $event->consent_version = $version;
            $event->consent_text_sha256 = $textSha256;
            $event->actor_user_id = $actor->id;
            $event->membership_id = $this->tenant->membership()->id;
            $event->occurred_at = now();
            $event->save();

            return $profile->refresh();
        });
    }

    public function withdraw(Member $member, User $actor): BankingProfile
    {
        $this->authorizeOwner($member, $actor);

        return DB::transaction(function () use ($member, $actor): BankingProfile {
            $profile = BankingProfile::query()->where('member_id', $member->id)->lockForUpdate()->first();

            if (! $profile || ! $profile->consent_granted) {
                throw ValidationException::withMessages([
                    'consent' => 'Aucun consentement bancaire actif à retirer.',
                ]);
            }

            $profile->consent_granted = false;
            $profile->withdrawn_at = now();
            $profile->save();

            $event = new BankingConsentEvent;
            $event->organization_id = $member->organization_id;
            $event->member_id = $member->id;
            $event->event = 'withdrawn';
            $event->consent_version = $profile->consent_version;
            $event->consent_text_sha256 = $profile->consent_text_sha256;
            $event->actor_user_id = $actor->id;
            $event->membership_id = $this->tenant->membership()->id;
            $event->occurred_at = now();
            $event->save();

            return $profile->refresh();
        });
    }

    private function authorizeOwner(Member $member, User $actor): void
    {
        if (! $this->tenant->has()
            || $this->tenant->memberId() !== $member->id
            || $this->tenant->organizationId() !== $member->organization_id
            || $this->tenant->role() !== Role::MemberOwner
            || $this->tenant->userId() !== $actor->id) {
            throw new AuthorizationException('Seul le propriétaire authentifié de cet espace adhérent peut gérer le consentement bancaire.');
        }
    }
}
