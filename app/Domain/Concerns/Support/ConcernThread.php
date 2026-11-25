<?php

namespace App\Domain\Concerns\Support;

use App\Domain\Concerns\Enums\ConcernLevel;
use App\Domain\Concerns\Enums\ConcernSide;
use App\Domain\Concerns\Models\Concern;
use App\Domain\Concerns\Models\ConcernMessage;
use App\Domain\Concerns\Models\ConcernMessageFile;
use App\Support\Files\StorageService;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Fil d'une préoccupation (F-PRE-02) : chaque message et ses pièces jointes appartiennent au CGA de la
 * demande, fichiers rangés par StorageService (type déterminé par le contenu). Le CNCGA lit et écrit dans
 * les demandes des CGA par un contournement nommé (05-BACKEND-SCHEMA §12 : visibles du CGA émetteur et du
 * CNCGA) ; jamais dans celles des adhérents.
 */
final class ConcernThread
{
    private const FILE_TYPE = 'concerns';

    public function __construct(private readonly StorageService $storage) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function post(
        Concern $concern,
        string $authorUserId,
        ConcernSide $side,
        string $body,
        array $files = [],
        bool $internal = false,
        bool $system = false,
        ?ConcernMessage $copiedFrom = null,
        ?CarbonImmutable $at = null,
    ): ConcernMessage {
        $message = new ConcernMessage;
        $message->organization_id = $concern->organization_id;
        $message->concern_id = $concern->id;
        $message->author_user_id = $authorUserId;
        $message->author_side = $side;
        $message->body = $body;
        $message->is_internal = $internal;
        $message->is_system = $system;
        $message->copied_from_message_id = $copiedFrom?->id;
        $message->copied_side = $copiedFrom?->author_side;
        $message->created_at = $at ?? CarbonImmutable::now();
        $message->save();

        foreach ($files as $upload) {
            $link = new ConcernMessageFile;
            $link->organization_id = $concern->organization_id;
            $link->message_id = $message->id;
            $link->file_id = $this->storage->storeUpload($upload, self::FILE_TYPE, $concern->organization_id, $authorUserId)->id;
            $link->save();
        }

        return $message;
    }

    /**
     * Traitement dans le CGA de la demande : direct depuis son espace, par un contournement nommé depuis
     * l'espace du CNCGA (demande d'un CGA) ou hors espace (tâche planifiée).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function within(Concern $concern, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        if ($context->has() && $context->organizationId() === $concern->organization_id) {
            return $callback();
        }

        return Tenancy::bypass($callback, 'Préoccupations : demande d\'un CGA traitée par le CNCGA.');
    }

    /**
     * Demande d'un CGA au CNCGA, lue depuis l'espace du CNCGA ; tout autre identifiant répond 404.
     *
     * @throws NotFoundHttpException
     */
    public static function networkConcern(string $id): Concern
    {
        $concern = Str::isUlid($id)
            ? Tenancy::bypass(static fn (): ?Concern => Concern::query()->with(['organization', 'author'])->where('level', ConcernLevel::CgaToCncga)->find($id), 'Préoccupations : demande d\'un CGA lue par le CNCGA.')
            : null;

        return $concern ?? throw new NotFoundHttpException;
    }
}
