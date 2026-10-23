<?php

namespace App\Domain\Documents\Http\Resources;

use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Support\TemplateInspector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Version d'un modèle de document (F-ADM-02) : type, numéro de version, active ou non, date de
 * validation par le CNCGA, note, variables et contrôle (manquantes, inconnues), fichier (lien signé),
 * nombre de documents émis avec elle (RG-DATA-03 : chacun la garde), actions permises.
 *
 * @property DocumentTemplate $resource
 */
class DocumentTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $template = $this->resource;
        $check = app(TemplateInspector::class)->check($template->type, $template->variables);

        return [
            'id' => $template->id,
            'type' => $template->type->value,
            'type_label' => $template->type->label(),
            'version' => $template->version,
            'is_active' => $template->is_active,
            'approved_by_cncga_at' => $template->approved_by_cncga_at?->toDateString(),
            'notes' => $template->notes,
            'variables' => $template->variables,
            'check' => $check,
            'file' => $this->whenLoaded('file', fn (): ?array => DocumentResource::file($template->file)),
            'documents_count' => $this->whenCounted('documents'),
            'created_at' => $template->created_at?->toIso8601ZuluString(),
            'can' => [
                'preview' => $check['valid'] && Gate::allows('preview', $template),
                'activate' => ! $template->is_active && $check['valid'] && Gate::allows('activate', $template),
            ],
        ];
    }
}
