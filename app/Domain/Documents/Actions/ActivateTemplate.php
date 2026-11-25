<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Support\TemplateInspector;
use App\Support\Http\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Activation d'une version de modèle (F-ADM-02, RG-DATA-03), après sa validation par le CNCGA, dont la
 * date est enregistrée : elle remplace la version active du même type pour les productions suivantes ;
 * chaque document déjà émis garde la version qui l'a produit (generated_documents.template_id). Une
 * version dont les variables s'écartent de celles que la plateforme remplit n'est jamais activée.
 */
class ActivateTemplate
{
    public function __construct(private readonly TemplateInspector $inspector) {}

    /**
     * @throws BusinessRuleException template_already_active, template_variables_mismatch
     */
    public function execute(DocumentTemplate $template, CarbonImmutable $approvedOn): DocumentTemplate
    {
        return DB::transaction(function () use ($template, $approvedOn): DocumentTemplate {
            $template = DocumentTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($template->is_active) {
                throw new BusinessRuleException('template_already_active');
            }
            $check = $this->inspector->check($template->type, $template->variables);
            if (! $check['valid']) {
                throw new BusinessRuleException('template_variables_mismatch', details: ['missing' => $check['missing'], 'unknown' => $check['unknown']]);
            }

            DocumentTemplate::query()->where('type', $template->type)->where('is_active', true)->lockForUpdate()->get()
                ->each(static function (DocumentTemplate $active): void {
                    $active->is_active = false;
                    $active->save();
                });

            $template->is_active = true;
            $template->approved_by_cncga_at = $approvedOn;
            $template->save();

            return $template;
        });
    }
}
