<?php

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Inscrit au journal des modifications chaque création, modification et suppression du modèle, avec
 * l'avant / après de chaque champ (02-TRD §6.5). À poser sur tous les modèles métier.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::registerModelEvent($event, static function (Model $model) use ($event): void {
                app(AuditLogger::class)->recordModelEvent($event, $model);
            });
        }
    }
}
