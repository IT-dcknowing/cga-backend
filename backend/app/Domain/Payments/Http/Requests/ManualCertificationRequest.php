<?php

namespace App\Domain\Payments\Http\Requests;

use App\Domain\Payments\Models\Invoice;
use App\Support\Config\SettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/cga/invoices/{invoice}/manual-certification : « Saisir la certification » en mode
 * manuel (RG-FNE-03, multipart) — n° FNE et facture certifiée en PDF (type réel contrôlé, taille
 * files.max_upload_mb), tous deux obligatoires.
 */
class ManualCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', $this->invoice());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(SettingsService $settings): array
    {
        return [
            'fne_number' => ['required', 'string', 'max:80'],
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.((int) $settings->get('files.max_upload_mb') * 1024)],
        ];
    }

    public function fneNumber(): string
    {
        return trim((string) $this->validated('fne_number'));
    }

    public function pdf(): UploadedFile
    {
        /** @var UploadedFile $file fichier exigé par les règles */
        $file = $this->validated('file');

        return $file;
    }

    /**
     * @throws AuthorizationException route sans facture
     */
    public function invoice(): Invoice
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice ? $invoice : throw new AuthorizationException;
    }
}
