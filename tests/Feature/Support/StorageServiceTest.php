<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Documents\Models\File;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Config\SettingsService;
use App\Support\Files\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/*
 * Fichiers sur le disque du serveur, par StorageService seulement (02-TRD §6.4) : nom régénéré, type
 * lu dans le contenu, empreinte, téléchargement par lien temporaire signé.
 */

const PDF_CONTENT = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

function pngContent(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
}

beforeEach(function () {
    Storage::fake();
});

it('range le fichier sous orgs/{organisation}/{type}/{année}/{ulid}.{ext} et l\'enregistre', function () {
    $cga = Organization::factory()->create();
    $uploader = User::factory()->create();

    $file = app(StorageService::class)->store(PDF_CONTENT, 'Contrat signé.pdf', 'documents', $cga->id, $uploader->id);

    expect($file->path)->toMatch('#^orgs/'.$cga->id.'/documents/'.now()->format('Y').'/[0-9a-z]{26}\.pdf$#')
        ->and($file->path)->toContain($file->id)
        ->and($file->disk)->toBe('private')
        ->and($file->mime_type)->toBe('application/pdf')
        ->and($file->size_bytes)->toBe(strlen(PDF_CONTENT))
        ->and($file->sha256)->toBe(hash('sha256', PDF_CONTENT))
        ->and($file->original_name)->toBe('Contrat signé.pdf')
        ->and($file->uploaded_by)->toBe($uploader->id);
    Storage::disk('private')->assertExists($file->path);
    expect(app(StorageService::class)->contents($file))->toBe(PDF_CONTENT);
});

it('lit le type dans le contenu, jamais dans le nom ni l\'extension annoncée', function () {
    $file = app(StorageService::class)->store(pngContent(), 'facture.pdf', 'pieces');

    expect($file->mime_type)->toBe('image/png')
        ->and($file->path)->toEndWith('.png');
});

it('range un fichier du réseau à part et ne garde que le nom du fichier envoyé', function () {
    $file = app(StorageService::class)->store(PDF_CONTENT, '..\\..\\etc/passwd', 'templates');

    expect($file->path)->toStartWith('orgs/network/templates/')
        ->and($file->original_name)->toBe('passwd');
});

it('enregistre un fichier téléversé', function () {
    $upload = UploadedFile::fake()->createWithContent('releve.pdf', PDF_CONTENT);

    $file = app(StorageService::class)->storeUpload($upload, 'pieces');

    expect($file->original_name)->toBe('releve.pdf')
        ->and($file->mime_type)->toBe('application/pdf');
});

it('refuse un type de dossier mal formé', function () {
    app(StorageService::class)->store(PDF_CONTENT, 'a.pdf', '../secrets');
})->throws(InvalidArgumentException::class);

it('délivre un lien signé qui télécharge le fichier', function () {
    $file = app(StorageService::class)->store(PDF_CONTENT, 'Contrat.pdf', 'documents');

    $response = $this->get(app(StorageService::class)->temporaryUrl($file));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('Contrat.pdf');
    expect($response->streamedContent())->toBe(PDF_CONTENT);
});

it('refuse un lien expiré ou modifié', function () {
    app(SettingsService::class)->set('files.download_link_minutes', 5);
    $file = app(StorageService::class)->store(PDF_CONTENT, 'Contrat.pdf', 'documents');
    $url = app(StorageService::class)->temporaryUrl($file);

    $this->get($url.'x')->assertForbidden();
    $this->get(route(StorageService::DOWNLOAD_ROUTE, ['file' => $file->id]))->assertForbidden();

    $this->travel(6)->minutes();
    $this->get($url)->assertForbidden();
});

it('vérifie la signature avant de chercher le fichier : un lien forgé ne dit pas s\'il existe', function () {
    $this->getJson(route(StorageService::DOWNLOAD_ROUTE, ['file' => strtolower((string) Str::ulid())]))
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

it('répond 404 pour un fichier inconnu, même avec une signature valide', function () {
    $url = URL::temporarySignedRoute(StorageService::DOWNLOAD_ROUTE, now()->addMinutes(5), ['file' => strtolower((string) Str::ulid())]);

    $this->getJson($url)->assertNotFound();
});

it('journalise l\'enregistrement d\'un fichier', function () {
    $file = app(StorageService::class)->store(PDF_CONTENT, 'Contrat.pdf', 'documents');

    expect(AuditLog::query()->where('subject_type', File::class)->where('subject_id', $file->id)->where('event', 'created')->exists())->toBeTrue();
});
