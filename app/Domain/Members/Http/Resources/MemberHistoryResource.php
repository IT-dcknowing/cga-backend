<?php

namespace App\Domain\Members\Http\Resources;

use App\Domain\Members\Data\MemberHistoryEntry;
use App\Domain\Members\Support\MemberHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;

/**
 * Ligne de l'onglet « Historique » d'un dossier (F-ADH-03) : date, auteur, élément et événement,
 * champs changés (ancienne → nouvelle valeur), justificatif, source.
 *
 * @property MemberHistoryEntry $resource
 */
class MemberHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'occurred_at' => $entry->occurredAt->toIso8601ZuluString(),
            'subject' => $entry->subject,
            'subject_label' => __("members.history.subjects.{$entry->subject}"),
            'event' => $entry->event,
            'event_label' => $this->label('events', $entry->event, __('members.history.events.other')),
            'actor' => $entry->actor,
            'changes' => array_map(fn (array $change): array => [
                'field' => $change['field'],
                'label' => $this->label('fields', $change['field'], $change['field']),
                'before' => $this->value($change['before']),
                'after' => $this->value($change['after']),
            ], $entry->changes),
            'justification' => $entry->justification,
            'source' => $entry->source,
            'source_label' => $entry->source === null ? null : $this->label('sources', $entry->source, $entry->source),
        ];
    }

    /**
     * Valeur d'un champ ; une donnée chiffrée est annoncée comme telle, sans son contenu.
     */
    private function value(mixed $value): mixed
    {
        return $value === MemberHistory::SEALED ? __('members.history.sealed') : $value;
    }

    private function label(string $group, string $key, string $fallback): string
    {
        $path = "members.history.{$group}.{$key}";

        return Lang::has($path) ? (string) __($path) : $fallback;
    }
}
