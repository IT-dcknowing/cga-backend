<?php

namespace App\Domain\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Travail en échec d'une file d'attente (table queue.failed.table, pilote « database-uuids ») : lu par l'écran
 * « Technique » de /plateforme (02-TRD §12.3) et relancé par RetryFailedJob. Jamais modifié directement ; la
 * relance passe par queue:retry, qui remet le travail dans sa file et efface la ligne. Le contenu des travaux
 * (payload) n'est jamais renvoyé au navigateur.
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property string $payload
 * @property string $exception
 * @property CarbonImmutable $failed_at
 */
class FailedJob extends Model
{
    public $timestamps = false;

    /**
     * Lecture seule : aucune affectation de masse.
     *
     * @var list<string>
     */
    protected $fillable = [];

    public function getTable(): string
    {
        return (string) config('queue.failed.table');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['failed_at' => 'immutable_datetime'];
    }

    /**
     * Nom du travail (displayName de la charge), sans ses données.
     */
    public function jobName(): string
    {
        $payload = json_decode($this->payload, true);

        return is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : '';
    }

    /**
     * Première ligne du message d'erreur, tronquée (queue-jobs.failed_error_length).
     */
    public function errorSummary(): string
    {
        $firstLine = strtok($this->exception, "\n");

        return mb_substr(trim($firstLine === false ? '' : $firstLine), 0, (int) config('queue-jobs.failed_error_length'));
    }
}
