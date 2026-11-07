<?php

use App\Domain\Platform\Actions\RunBackup;
use App\Domain\Platform\Actions\VerifyBackupRestore;
use App\Domain\Platform\Backups\BackupConnections;
use App\Domain\Platform\Backups\BackupManifest;
use App\Domain\Platform\Backups\BackupTools;
use App\Domain\Platform\Backups\DatabaseSnapshot;
use App\Domain\Platform\Enums\BackupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/*
 * Étape 9.1 (02-TRD §12.3) : manifeste de chaque sauvegarde et test de restauration documenté (backup:restore-test).
 * Les outils système sont remplacés par des scripts PHP ; la restauration se fait dans une vraie base PostgreSQL
 * créée pour le test, puis supprimée.
 */

/**
 * Scripts PHP qui jouent gpg, pg_dump, pg_restore et tar.
 */
function restoreTestTools(string $directory): void
{
    File::ensureDirectoryExists("{$directory}/tools");
    File::put("{$directory}/tools/gpg.php", <<<'PHP'
        <?php
        if (in_array('--decrypt', $argv, true)) {
            $source = end($argv);
            if (! is_file($source)) { fwrite(STDERR, 'decrypt: no such file'); exit(2); }
            readfile($source);
            exit(0);
        }
        file_put_contents($argv[array_search('--output', $argv, true) + 1], stream_get_contents(STDIN));
        PHP);
    File::put("{$directory}/tools/pg_dump.php", '<?php fwrite(STDOUT, implode(" ", array_slice($argv, 1)));');
    File::put("{$directory}/tools/pg_restore.php", <<<'PHP'
        <?php
        $options = [];
        foreach ($argv as $argument) {
            if (preg_match('/^--(host|port|username|dbname)=(.*)$/', $argument, $match) === 1) { $options[$match[1]] = $match[2]; }
        }
        $sql = stream_get_contents(STDIN);
        if (str_contains($sql, 'RESTORE_FAILS')) { fwrite(STDERR, 'pg_restore: error: could not execute query'); exit(1); }
        $pdo = new PDO("pgsql:host={$options['host']};port={$options['port']};dbname={$options['dbname']}", $options['username'], (string) getenv('PGPASSWORD'));
        $pdo->exec($sql);
        PHP);
    File::put("{$directory}/tools/tar.php", <<<'PHP'
        <?php
        $target = null;
        foreach ($argv as $argument) {
            if (str_starts_with($argument, '--directory=')) { $target = substr($argument, 12); }
            if (str_starts_with($argument, '--listed-incremental=') && ! in_array('--extract', $argv, true)) { file_put_contents(substr($argument, 21), 'snapshot'); }
        }
        if (! in_array('--extract', $argv, true)) { fwrite(STDOUT, 'archive bytes'); exit(0); }
        $plan = json_decode(stream_get_contents(STDIN), true);
        foreach ($plan['write'] ?? [] as $path => $content) {
            if (! is_dir(dirname("{$target}/{$path}"))) { mkdir(dirname("{$target}/{$path}"), 0777, true); }
            file_put_contents("{$target}/{$path}", $content);
        }
        foreach ($plan['delete'] ?? [] as $path) { unlink("{$target}/{$path}"); }
        PHP);

    config(['backup.commands' => [
        'pg_dump' => [PHP_BINARY, "{$directory}/tools/pg_dump.php"],
        'pg_restore' => [PHP_BINARY, "{$directory}/tools/pg_restore.php"],
        'gpg' => [PHP_BINARY, "{$directory}/tools/gpg.php"],
        'tar' => [PHP_BINARY, "{$directory}/tools/tar.php"],
        'rsync' => [PHP_BINARY, "{$directory}/tools/pg_dump.php"],
    ]]);
}

/**
 * Sauvegarde factice : base (SQL lu par le faux pg_restore), jeu de fichiers (complète, incrémentale testée,
 * incrémentale postérieure qui ne doit pas être appliquée) et manifeste de la sauvegarde du 9 novembre.
 *
 * @param  array<string, int>  $tables
 * @param  array<string, int>  $files
 */
function restoreTestBackup(string $directory, string $sql, array $tables, array $files = []): void
{
    $backups = "{$directory}/backups";
    $set = '20261108-040000';
    File::ensureDirectoryExists("{$backups}/db");
    File::ensureDirectoryExists("{$backups}/files/{$set}");
    File::put("{$backups}/db/cga-20261109-040000.dump.gpg", $sql);
    File::put("{$backups}/files/{$set}/20261108-040000-full.tar.gz.gpg", json_encode(['write' => ['orgs/a/x.pdf' => '12345', 'orgs/a/y.pdf' => '123']]));
    File::put("{$backups}/files/{$set}/20261109-040000-incremental.tar.gz.gpg", json_encode(['write' => ['orgs/a/z.pdf' => '1'], 'delete' => ['orgs/a/y.pdf']]));
    File::put("{$backups}/files/{$set}/20261110-040000-incremental.tar.gz.gpg", json_encode(['write' => ['orgs/a/later.pdf' => 'later']]));
    File::put("{$backups}/db/cga-20261109-040000.manifest.json", json_encode([
        'version' => BackupManifest::VERSION,
        'created_at' => '2026-11-09T04:00:00+00:00',
        'database_file' => 'cga-20261109-040000.dump.gpg',
        'tables' => $tables,
        'files' => [
            'set' => $set,
            'archive' => '20261109-040000-incremental.tar.gz.gpg',
            'count_min' => 2, 'count_max' => 2, 'bytes_min' => 6, 'bytes_max' => 6,
            ...$files,
        ],
    ]));
}

/**
 * Base PostgreSQL vide de restauration, créée hors de la transaction du test.
 */
function restoreTestCreateDatabase(): string
{
    $name = 'cga_restore_'.bin2hex(random_bytes(5));
    $connections = app(BackupConnections::class);
    // @phpstan-ignore cga.rawSqlWithVariable (nom de base généré par le test)
    $connections->open('restore_admin')->unprepared("CREATE DATABASE \"{$name}\"");
    $connections->close('restore_admin');

    return $name;
}

function restoreTestDropDatabase(string $name): void
{
    $connections = app(BackupConnections::class);
    $connections->close(BackupConnections::RESTORE);
    // @phpstan-ignore cga.rawSqlWithVariable (nom de base généré par le test)
    $connections->open('restore_admin')->unprepared("DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)");
    $connections->close('restore_admin');
}

beforeEach(function () {
    $this->restoreDirectory = storage_path('framework/testing/restore-'.uniqid('', true));
    restoreTestTools($this->restoreDirectory);
    config([
        'backup.directory' => "{$this->restoreDirectory}/backups",
        'backup.gpg_recipient' => 'backup@example.invalid',
        'backup.remote' => null,
        'backup.restore.environments' => ['testing'],
        'backup.restore.work_directory' => "{$this->restoreDirectory}/work",
        'backup.restore.report_directory' => "{$this->restoreDirectory}/reports",
    ]);
    $this->restoreDatabases = [];
});

afterEach(function () {
    foreach ($this->restoreDatabases as $database) {
        restoreTestDropDatabase($database);
    }
    File::deleteDirectory($this->restoreDirectory);
});

describe('manifeste de la sauvegarde', function () {
    it('RG-SEC-09 lit la base dans un instantané dont les comptes de lignes vont au manifeste', function () {
        $storage = "{$this->restoreDirectory}/private";
        File::ensureDirectoryExists("{$storage}/orgs/a");
        File::put("{$storage}/orgs/a/x.pdf", '12345');
        File::put("{$storage}/orgs/a/y.pdf", '123');
        config(['filesystems.disks.private.root' => $storage]);
        DB::table('migrations')->count(); // migre la base de test avant l'ouverture de l'instantané

        $run = app(RunBackup::class)->execute(CarbonImmutable::parse('2026-11-08 04:00:00'));

        $dump = "{$this->restoreDirectory}/backups/db/{$run->database_file}";
        $manifest = BackupManifest::read(BackupManifest::pathFor($dump));
        expect($run->status)->toBe(BackupStatus::Done)
            ->and(File::get($dump))->toMatch('/--snapshot=[0-9A-F]+-[0-9A-F]+-\d+/')
            ->and($manifest['database_file'])->toBe($run->database_file)
            ->and($manifest['tables'])->toHaveKeys(['members', 'organizations', 'migrations'])
            ->and($manifest['tables']['migrations'])->toBe(DB::table('migrations')->count())
            ->and($manifest['files'])->toMatchArray([
                'set' => '20261108-040000',
                'archive' => '20261108-040000-full.tar.gz.gpg',
                'count_min' => 2, 'count_max' => 2, 'bytes_min' => 8, 'bytes_max' => 8,
            ]);
    });

    it('RG-SEC-09 referme l’instantané même quand pg_dump échoue', function () {
        File::put("{$this->restoreDirectory}/tools/pg_dump.php", '<?php fwrite(STDERR, "dump failed"); exit(1);');
        DB::table('migrations')->count();

        expect(fn () => app(RunBackup::class)->execute(CarbonImmutable::parse('2026-11-08 04:00:00')))
            ->toThrow(RuntimeException::class, 'dump failed')
            ->and(array_keys(DB::getConnections()))->not->toContain(BackupConnections::SNAPSHOT);

        $snapshot = app(DatabaseSnapshot::class);
        expect($snapshot->open()['tables'])->toHaveKey('migrations');
        $snapshot->release();
        $snapshot->release(); // sans instantané ouvert : sans effet
        expect(array_keys(DB::getConnections()))->not->toContain(BackupConnections::SNAPSHOT);
    });

    it('rejette un manifeste absent, illisible ou d’une autre version', function () {
        $path = "{$this->restoreDirectory}/m.manifest.json";
        expect(fn () => BackupManifest::read($path))->toThrow(RuntimeException::class, 'Manifeste introuvable');

        File::ensureDirectoryExists($this->restoreDirectory);
        File::put($path, json_encode(['version' => 99]));
        expect(fn () => BackupManifest::read($path))->toThrow(RuntimeException::class, 'Manifeste invalide')
            ->and(BackupManifest::pathFor('/b/base.sql'))->toBe('/b/base.sql.manifest.json')
            ->and(fn () => BackupManifest::latest("{$this->restoreDirectory}/vide"))->toThrow(RuntimeException::class, 'Aucune sauvegarde avec manifeste');
    });
});

describe('test de restauration', function () {
    it('RG-SEC-09 restaure la base et le jeu de fichiers puis les déclare conformes au manifeste', function () {
        $target = $this->restoreDatabases[] = restoreTestCreateDatabase();
        restoreTestBackup($this->restoreDirectory, 'CREATE TABLE organizations (id int); INSERT INTO organizations VALUES (1), (2); CREATE TABLE members (id int);', ['organizations' => 2, 'members' => 0]);

        $report = app(VerifyBackupRestore::class)->execute($target, null, CarbonImmutable::parse('2026-11-12 10:00:00'));

        expect($report['status'])->toBe('passed')
            ->and($report['database_file'])->toBe('cga-20261109-040000.dump.gpg')
            ->and($report['archives'])->toBe(['20261108-040000-full.tar.gz.gpg', '20261109-040000-incremental.tar.gz.gpg'])
            ->and($report['tables'])->toBe(['checked' => 2, 'mismatches' => []])
            ->and($report['files'])->toMatchArray(['restored_count' => 2, 'restored_bytes' => 6, 'ok' => true])
            ->and(json_decode(File::get($report['report_file']), true)['status'])->toBe('passed')
            ->and(File::isDirectory("{$this->restoreDirectory}/work/20261112-100000"))->toBeFalse();
    });

    it('RG-SEC-09 signale chaque table dont le nombre de lignes restaurées diffère, ou absente', function () {
        $target = $this->restoreDatabases[] = restoreTestCreateDatabase();
        restoreTestBackup($this->restoreDirectory, 'CREATE TABLE organizations (id int); INSERT INTO organizations VALUES (1), (2);', ['organizations' => 3, 'payments' => 4]);

        $report = app(VerifyBackupRestore::class)->execute($target, 'cga-20261109-040000.dump.gpg', CarbonImmutable::parse('2026-11-12 10:00:00'));

        expect($report['status'])->toBe('failed')
            ->and($report['tables']['mismatches'])->toBe([
                'organizations' => ['expected' => 3, 'restored' => 2],
                'payments' => ['expected' => 4, 'restored' => null],
            ]);
    });

    it('RG-SEC-09 signale des fichiers restaurés hors de la plage du manifeste', function () {
        $target = $this->restoreDatabases[] = restoreTestCreateDatabase();
        restoreTestBackup($this->restoreDirectory, 'CREATE TABLE organizations (id int);', ['organizations' => 0], ['count_min' => 3, 'count_max' => 3]);

        $report = app(VerifyBackupRestore::class)->execute($target, null, CarbonImmutable::parse('2026-11-12 10:00:00'));

        expect($report['status'])->toBe('failed')
            ->and($report['files']['ok'])->toBeFalse();
    });

    it('RG-SEC-09 refuse la production, un nom invalide, la base de l’application ou une base non vide', function () {
        restoreTestBackup($this->restoreDirectory, 'SELECT 1;', []);
        Log::spy();
        $verify = app(VerifyBackupRestore::class);
        $now = CarbonImmutable::parse('2026-11-12 10:00:00');

        config(['backup.restore.environments' => ['restore']]);
        expect(fn () => $verify->execute('cga_restore', null, $now))->toThrow(RuntimeException::class, 'interdit dans l’environnement « testing »');

        config(['backup.restore.environments' => ['testing']]);
        expect(fn () => $verify->execute('base; DROP', null, $now))->toThrow(RuntimeException::class, 'Nom de base de restauration invalide')
            ->and(fn () => $verify->execute((string) config('database.connections.pgsql.database'), null, $now))->toThrow(RuntimeException::class, 'celle de l’application');

        $target = $this->restoreDatabases[] = restoreTestCreateDatabase();
        app(BackupConnections::class)->open(BackupConnections::RESTORE, $target)->unprepared('CREATE TABLE existing (id int)');
        expect(fn () => $verify->execute($target, null, $now))->toThrow(RuntimeException::class, 'doit être vide');

        Log::shouldHaveReceived('error')->times(4);
    });

    it('RG-SEC-09 refuse une sauvegarde introuvable ou un jeu de fichiers incomplet', function () {
        $target = $this->restoreDatabases[] = restoreTestCreateDatabase();
        restoreTestBackup($this->restoreDirectory, 'SELECT 1;', []);
        $verify = app(VerifyBackupRestore::class);
        $now = CarbonImmutable::parse('2026-11-12 10:00:00');

        expect(fn () => $verify->execute($target, 'cga-20250101-040000.dump.gpg', $now))->toThrow(RuntimeException::class, 'Manifeste introuvable');

        File::delete("{$this->restoreDirectory}/backups/files/20261108-040000/20261108-040000-full.tar.gz.gpg");
        expect(fn () => $verify->execute($target, null, $now))->toThrow(RuntimeException::class, 'Jeu de fichiers incomplet');

        File::delete("{$this->restoreDirectory}/backups/db/cga-20261109-040000.dump.gpg");
        expect(fn () => $verify->execute($target, null, $now))->toThrow(RuntimeException::class, 'Sauvegarde de la base introuvable');
    });

    it('RG-SEC-09 remonte l’échec de pg_restore ou du déchiffrement', function () {
        $target = $this->restoreDatabases[] = restoreTestCreateDatabase();
        restoreTestBackup($this->restoreDirectory, 'RESTORE_FAILS', []);
        $tools = app(BackupTools::class);
        $database = app(BackupConnections::class)->parameters($target);

        expect(fn () => $tools->restoreDatabase($database, "{$this->restoreDirectory}/backups/db/cga-20261109-040000.dump.gpg"))
            ->toThrow(RuntimeException::class, 'en échec (1) : pg_restore: error')
            ->and(fn () => $tools->extractFiles("{$this->restoreDirectory}/absent.tar.gz.gpg", $this->restoreDirectory))
            ->toThrow(RuntimeException::class, 'Déchiffrement en échec : decrypt: no such file');
    });
});

describe('commande backup:restore-test', function () {
    it('RG-SEC-09 affiche le résultat d’une restauration conforme', function () {
        $target = $this->restoreDatabases[] = restoreTestCreateDatabase();
        restoreTestBackup($this->restoreDirectory, 'CREATE TABLE organizations (id int); INSERT INTO organizations VALUES (1), (2);', ['organizations' => 2]);

        $this->artisan('backup:restore-test', ['target' => $target])
            ->expectsOutputToContain('Restauration réussie. Rapport :')
            ->assertSuccessful();
    });

    it('RG-SEC-09 échoue et détaille les écarts d’une restauration non conforme', function () {
        $target = $this->restoreDatabases[] = restoreTestCreateDatabase();
        restoreTestBackup($this->restoreDirectory, 'CREATE TABLE organizations (id int);', ['organizations' => 2]);

        $this->artisan('backup:restore-test', ['target' => $target, '--dump' => 'cga-20261109-040000.dump.gpg'])
            ->expectsOutput('Écart sur organizations : 2 attendu(s), 0 restauré(s).')
            ->expectsOutputToContain('Restauration NON conforme.')
            ->assertFailed();
    });

    it('RG-SEC-09 laisse remonter une restauration impossible', function () {
        config(['backup.restore.environments' => ['restore']]);

        expect(fn () => $this->artisan('backup:restore-test', ['target' => 'cga_restore']))
            ->toThrow(RuntimeException::class, 'interdit');
    });
});
