<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\UploadedDocumentCategory;
use App\Domain\Documents\Events\DocumentReady;
use App\Domain\Documents\Models\GeneratedDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\Membership;
use App\Support\Files\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Dépôt d'un document par le CGA dans l'espace de l'adhérent (F-DOC-05) : titre, catégorie, fichier
 * PDF rangé par StorageService avec son empreinte SHA-256 ; le document est
 * valide d'emblée, visible de l'adhérent, qui en est prévenu (DocumentReady).
 */
class UploadDocument
{
    public function __construct(private readonly StorageService $storage) {}

    /**
     * @param  UploadedFile  $pdf  fichier dont le type réel a été contrôlé par la validation (PDF)
     */
    public function execute(Member $member, string $title, UploadedDocumentCategory $category, UploadedFile $pdf, Membership $requester): GeneratedDocument
    {
        return DB::transaction(function () use ($member, $title, $category, $pdf, $requester): GeneratedDocument {
            $file = $this->storage->storeUpload($pdf, 'documents', $member->organization_id, $requester->user_id);

            $document = new GeneratedDocument;
            $document->organization_id = $member->organization_id;
            $document->member_id = $member->id;
            $document->type = DocumentType::Uploaded;
            $document->title = $title;
            $document->category = $category;
            $document->version = 1;
            $document->pdf_file_id = $file->id;
            $document->sha256 = $file->sha256;
            $document->status = DocumentStatus::Valid;
            $document->issued_at = now()->toImmutable();
            $document->visible_to_member = true;
            $document->generated_by = $requester->user_id;
            $document->save();

            DocumentReady::dispatch($document);

            return $document;
        });
    }
}
