<?php

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Enums\Portfolio;
use App\Domain\Members\Models\Member;
use App\Domain\Organizations\Actions\ExportTerminatedCgaArchive;
use App\Domain\Organizations\Enums\OrganizationType;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\Storage;

test('RG-ORG-07 : export complet d un CGA résilié avec archive ZIP', function (): void {
    Storage::fake('private');

    $org = Organization::factory()->create([
        'name' => 'CGA Départ',
        'sigle' => 'CDP',
        'email' => 'cdp@example.ci',
        'phone_1' => '+22501020304',
        'address' => 'Abidjan',
        'city' => 'Abidjan',
    ]);

    $admin = User::factory()->create();

    Member::factory()->active()->create([
        'organization_id' => $org->id,
        'company_name' => 'Client CGA 1',
    ]);

    $action = app(ExportTerminatedCgaArchive::class);
    $archiveFile = $action->execute($org, $admin);

    expect($archiveFile)->not->toBeNull();
    expect($archiveFile->original_name)->toContain('archive-resiliation-CDP');
    expect($archiveFile->mime_type)->toBe('application/zip');

    // Vérification du contenu du ZIP
    $zipPath = tempnam(sys_get_temp_dir(), 'test_zip_');
    file_put_contents($zipPath, Storage::disk('private')->get($archiveFile->path));

    $zip = new \ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    expect($zip->locateName('adherents.csv'))->not->toBeFalse();
    expect($zip->locateName('paiements.csv'))->not->toBeFalse();
    expect($zip->locateName('obligations.csv'))->not->toBeFalse();

    $content = $zip->getFromName('adherents.csv');
    expect($content)->toContain('Client CGA 1');

    $zip->close();
    @unlink($zipPath);
});
