<?php

use App\Domain\Notifications\EventNotification;
use App\Domain\Platform\Actions\RunBackup;
use App\Domain\Platform\Backups\BackupTools;
use App\Domain\Platform\Enums\BackupStatus;
use App\Domain\Platform\Models\BackupRun;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

final class FakeBackupTools extends BackupTools
{
    public bool $fail = false;

    public function dumpDatabase(array $database, string $target): void
    {
        File::put($target, 'encrypted database');
    }

    public function archiveFiles(string $source, string $snapshot, string $target): void
    {
        File::put($snapshot, 'snapshot');
        File::put($target, 'encrypted files');
    }

    public function copyRemote(string $directory, string $remote): void
    {
        if ($this->fail) {
            throw new RuntimeException('Remote copy failed');
        }
    }
}

beforeEach(function () {
    $directory = storage_path('framework/testing/backups-'.uniqid('', true));
    config([
        'backup.directory' => $directory,
        'backup.gpg_recipient' => 'backup@example.invalid',
        'backup.remote' => 'backup@example.invalid:/srv/backups',
        'backup.retention_days' => 30,
        'backup.full_weekday' => 7,
        'database.default' => 'pgsql',
        'filesystems.disks.private.root' => storage_path('app/private'),
    ]);
    $this->backupDirectory = $directory;
    $this->backupTools = new FakeBackupTools;
    app()->instance(BackupTools::class, $this->backupTools);
});

afterEach(function () {
    File::deleteDirectory($this->backupDirectory);
});

it('RG-SEC-09 enregistre une sauvegarde chiffrée et complète la copie hors serveur', function () {
    $run = app(RunBackup::class)->execute(CarbonImmutable::parse('2026-11-08 04:00:00'));

    expect($run->status)->toBe(BackupStatus::Done)
        ->and($run->remote_copied)->toBeTrue()
        ->and($run->database_bytes)->toBe(strlen('encrypted database'))
        ->and($run->files_bytes)->toBe(strlen('encrypted files'))
        ->and($run->files_level)->toBe('full')
        ->and(File::exists($this->backupDirectory.'/db/'.$run->database_file))->toBeTrue()
        ->and(File::exists($this->backupDirectory.'/files/'.$run->files_file))->toBeTrue();
});

it('RG-SEC-09 crée une archive incrémentale hors du jour complet', function () {
    $action = app(RunBackup::class);
    $action->execute(CarbonImmutable::parse('2026-11-08 04:00:00'));
    $run = $action->execute(CarbonImmutable::parse('2026-11-09 04:00:00'));

    expect($run->files_level)->toBe('incremental');
});

it('RG-SEC-09 conserve le détail d’échec, supprime les partiels, alerte et relance', function () {
    $this->backupTools->fail = true;
    Organization::factory()->platform()->create();
    $platformAdmin = Membership::factory()->role(Role::PlatformAdmin)->create();
    Log::spy();
    Notification::fake();

    expect(fn () => app(RunBackup::class)->execute(CarbonImmutable::parse('2026-11-08 04:00:00')))
        ->toThrow(RuntimeException::class, 'Remote copy failed');

    $run = BackupRun::query()->latest('started_at')->firstOrFail();
    expect($run->status)->toBe(BackupStatus::Failed)
        ->and($run->error)->toBe('Remote copy failed')
        ->and(File::glob($this->backupDirectory.'/**/*.partial'))->toBe([]);
    Log::shouldHaveReceived('error')->once();
    Notification::assertSentTo($platformAdmin->user, EventNotification::class);
});

it('RG-SEC-09 signale une sauvegarde périmée au contrôle de santé', function () {
    config(['backup.enabled' => true, 'backup.max_age_hours' => 26]);
    $check = new App\Support\Health\Checks\BackupCheck;

    expect($check->run()->error?->value)->toBe('outdated');
});

it('RG-SEC-09 considère le contrôle de santé bon quand la sauvegarde est désactivée ou récente', function () {
    $check = new App\Support\Health\Checks\BackupCheck;
    config(['backup.enabled' => false]);
    expect($check->run()->error)->toBeNull();

    config(['backup.enabled' => true, 'backup.max_age_hours' => 26]);
    $run = new BackupRun;
    $run->status = BackupStatus::Done;
    $run->started_at = CarbonImmutable::now()->subHour();
    $run->finished_at = CarbonImmutable::now()->subHour();
    $run->database_file = 'backup.dump.gpg';
    $run->files_file = 'files.tar.gz.gpg';
    $run->save();

    expect($check->run()->error)->toBeNull();
});

it('RG-SEC-09 garde backup:run sans effet lorsque les sauvegardes sont désactivées', function () {
    config(['backup.enabled' => false]);

    $this->artisan('backup:run')->assertSuccessful();

    expect(BackupRun::query()->count())->toBe(0);
});

it('RG-SEC-09 affiche le résultat d’une sauvegarde réussie dans backup:run', function () {
    config(['backup.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 04:00:00'));
    $databaseFile = config('database.connections.pgsql.database').'-20261108-040000.dump.gpg';
    $filesFile = '20261108-040000/20261108-040000-full.tar.gz.gpg';

    $this->artisan('backup:run')
        ->expectsOutput("Sauvegarde faite : {$databaseFile}, {$filesFile}, copiée hors du serveur.")
        ->assertSuccessful();
});

it('RG-SEC-09 retourne une erreur et laisse l’échec remonter depuis backup:run', function () {
    config(['backup.enabled' => true, 'backup.gpg_recipient' => null]);

    expect(fn () => $this->artisan('backup:run'))
        ->toThrow(RuntimeException::class, 'Aucune clé de chiffrement');
});

it('RG-SEC-09 chiffre le flux pg_dump et tar sans fichier temporaire en clair', function () {
    File::ensureDirectoryExists($this->backupDirectory);
    $producer = $this->backupDirectory.'/producer.php';
    $encryptor = $this->backupDirectory.'/encryptor.php';
    $archiver = $this->backupDirectory.'/archiver.php';
    File::put($producer, '<?php fwrite(STDOUT, "database bytes");');
    File::put($archiver, '<?php foreach ($argv as $arg) { if (str_starts_with($arg, "--listed-incremental=")) { file_put_contents(substr($arg, 21), "snapshot"); } } fwrite(STDOUT, "archive bytes");');
    File::put($encryptor, '<?php $target = $argv[array_search("--output", $argv, true) + 1]; file_put_contents($target, stream_get_contents(STDIN));');
    config(['backup.commands' => [
        'pg_dump' => [PHP_BINARY, $producer],
        'tar' => [PHP_BINARY, $archiver],
        'gpg' => [PHP_BINARY, $encryptor],
        'rsync' => [PHP_BINARY, $producer],
    ]]);
    $tools = new BackupTools;

    $tools->dumpDatabase(['host' => 'localhost', 'port' => '5432', 'username' => 'test', 'password' => 'secret', 'database' => 'cga_test'], $this->backupDirectory.'/database.gpg');
    $tools->archiveFiles(storage_path('app/private'), $this->backupDirectory.'/snapshot.snar', $this->backupDirectory.'/files.gpg');

    expect(File::get($this->backupDirectory.'/database.gpg'))->toBe('database bytes')
        ->and(File::get($this->backupDirectory.'/files.gpg'))->toBe('archive bytes')
        ->and(File::get($this->backupDirectory.'/snapshot.snar'))->toBe('snapshot');
});

it('RG-SEC-09 copie les sauvegardes hors serveur et remonte les erreurs de rsync', function () {
    File::ensureDirectoryExists($this->backupDirectory);
    $success = $this->backupDirectory.'/success.php';
    $failure = $this->backupDirectory.'/failure.php';
    File::put($success, '<?php exit(0);');
    File::put($failure, '<?php fwrite(STDERR, "copy failed"); exit(2);');
    config(['backup.commands.rsync' => [PHP_BINARY, $success]]);
    $tools = new BackupTools;
    $tools->copyRemote($this->backupDirectory, 'backup@example.invalid:/srv/backups');

    config(['backup.commands.rsync' => [PHP_BINARY, $failure]]);
    expect(fn () => $tools->copyRemote($this->backupDirectory, 'backup@example.invalid:/srv/backups'))
        ->toThrow(RuntimeException::class, 'copy failed');
});
