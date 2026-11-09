<?php

use App\Domain\Documents\Enums\DocumentType;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Reçu d'un paiement de formation (RG-FOR-06, étape 7.2, décision 0013) : nouveau type de document et de
 * modèle. Les contraintes sur le type sont reposées avec la liste à jour (bases déjà installées).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replace(DocumentType::cases());
    }

    public function down(): void
    {
        $this->replace(array_values(array_filter(DocumentType::cases(), static fn (DocumentType $type): bool => $type !== DocumentType::TrainingReceipt)));
    }

    /**
     * @param  list<DocumentType>  $types
     */
    private function replace(array $types): void
    {
        DB::statement('ALTER TABLE generated_documents DROP CONSTRAINT IF EXISTS generated_documents_type_check');
        DB::statement('ALTER TABLE document_templates DROP CONSTRAINT IF EXISTS document_templates_type_check');

        $values = array_map(static fn (DocumentType $type): string => $type->value, $types);
        Constraints::in('generated_documents', 'type', $values);
        // Un document déposé (F-DOC-05) n'a pas de modèle.
        Constraints::in('document_templates', 'type', array_values(array_diff($values, [DocumentType::Uploaded->value])));
    }
};
