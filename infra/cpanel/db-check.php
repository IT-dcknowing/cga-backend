<?php

/*
 * État de la base avant les migrations (infra/cpanel/install.sh). Ne modifie rien.
 *
 * Usage : php db-check.php <dossier backend de la version>
 * Codes de sortie :
 *   0  base vide, ou déjà celle de CGA Connect (toutes ses migrations sont connues du code) ;
 *   10 base occupée par autre chose : on n'y touche pas ;
 *   11 extensions PostgreSQL absentes du serveur, ou droit de les créer absent ;
 *   12 connexion impossible.
 */

$backend = $argv[1] ?? '';

require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = Illuminate\Support\Facades\DB::connection();

try {
    $version = (string) $db->selectOne('show server_version')->server_version;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Connexion à la base impossible : '.$exception->getMessage()."\n");
    exit(12);
}

echo "PostgreSQL {$version}\n";

$tables = array_map(
    static fn (object $row): string => (string) $row->tablename,
    $db->select("select tablename from pg_tables where schemaname = 'public' order by tablename"),
);

if ($tables === []) {
    echo "Base vide.\n";
} elseif (! in_array('migrations', $tables, true)) {
    echo 'Base occupée par une autre application (tables : '.implode(', ', array_slice($tables, 0, 15))."…).\n";
    exit(10);
} else {
    $known = array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob($backend.'/database/migrations/*.php') ?: [],
    );
    $ran = array_map(
        static fn (object $row): string => (string) $row->migration,
        $db->select('select migration from migrations'),
    );
    $foreign = array_values(array_diff($ran, $known));

    // Une table migrations seule et vide : une première installation interrompue.
    if ($foreign === [] && ($ran !== [] || $tables === ['migrations'])) {
        echo 'Base de CGA Connect : '.count($ran).' migration(s) déjà passée(s), '.count(array_diff($known, $ran))." à passer.\n";
    } else {
        echo 'Base occupée par une autre application Laravel (migrations : '.implode(', ', array_slice($foreign, 0, 5))."…).\n";
        exit(10);
    }
}

$installed = array_map(
    static fn (object $row): string => (string) $row->extname,
    $db->select('select extname from pg_extension'),
);
$missing = array_values(array_diff(['pg_trgm', 'unaccent', 'citext'], $installed));
$available = array_map(
    static fn (object $row): string => (string) $row->name,
    $db->select('select name from pg_available_extensions'),
);
$absent = array_values(array_diff($missing, $available));

if ($absent !== []) {
    echo 'Extensions absentes du serveur PostgreSQL ('.implode(', ', $absent).") : paquet « contrib » à faire installer par l'hébergeur.\n";
    exit(11);
}

$canCreate = (bool) $db->selectOne("select has_database_privilege(current_user, current_database(), 'CREATE') as allowed")->allowed;

if ($missing !== [] && ! $canCreate) {
    echo 'Extensions manquantes ('.implode(', ', $missing).") et droit de les créer absent : à demander à l'hébergeur.\n";
    exit(11);
}

echo $missing === [] ? "Extensions présentes.\n" : 'Extensions à créer par les migrations : '.implode(', ', $missing).".\n";
