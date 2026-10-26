<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\DocumentVerification;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Documents\Support\IpPrefix;
use App\Support\Http\Exceptions\TooManyAttemptsException;
use App\Support\RateLimiting\AttemptLimiter;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Vérification publique d'un document par le code de son QR (F-DOC-04, RG-DOC-09), sans compte : la
 * limite de consultations par minute et par adresse (rate_limit.verification) est comptée avant toute
 * recherche ; seul un document émis (valide ou annulé) répond, un code inconnu ou un document jamais
 * émis répond 404 sans distinction. Chaque consultation est enregistrée avec l'adresse IP tronquée.
 * Le document est cherché hors de tout espace : contournement du cloisonnement journalisé.
 */
class VerifyDocument
{
    public function __construct(
        private readonly AttemptLimiter $limiter,
        private readonly IpPrefix $ipPrefix,
    ) {}

    /**
     * @throws TooManyAttemptsException limite de consultations atteinte
     * @throws ModelNotFoundException code inconnu, document en production ou en échec
     */
    public function execute(string $code, ?string $ip, ?string $userAgent): GeneratedDocument
    {
        $this->limiter->consume('rate_limit.verification', $ip ?? '');

        return Tenancy::bypass(fn (): GeneratedDocument => DB::transaction(function () use ($code, $ip, $userAgent): GeneratedDocument {
            $document = GeneratedDocument::query()
                ->with(['member', 'organization'])
                ->where('verification_token', $code)
                ->whereIn('status', [DocumentStatus::Valid, DocumentStatus::Cancelled])
                ->firstOrFail();

            $verification = new DocumentVerification;
            $verification->document_id = $document->id;
            $verification->ip_prefix = $this->ipPrefix->of($ip);
            $verification->user_agent = $userAgent === null ? null : mb_substr($userAgent, 0, 255);
            $verification->verified_at = now()->toImmutable();
            $verification->save();

            return $document;
        }), 'Vérification publique d\'un document par son QR code (F-DOC-04).');
    }
}
