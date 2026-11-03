<?php

/*
 * Écrit ou remplace une variable du .env partagé (infra/cpanel/install.sh), sans passer par sed.
 * Une valeur hors du jeu de caractères sûr est mise entre apostrophes : Laravel la lit alors telle
 * quelle, sans interpréter « $ » ni « \ ». Toutes les lignes de la clé prennent la nouvelle valeur.
 *
 * Usage : php set-env.php <fichier .env> <CLÉ> <valeur>
 */

[, $file, $key, $value] = $argv + [null, null, null, null];

if (! is_string($file) || ! is_string($key) || ! is_string($value) || preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
    fwrite(STDERR, "Usage : php set-env.php <fichier .env> <CLÉ> <valeur>\n");
    exit(2);
}

if (str_contains($value, "'") || str_contains($value, "\n") || str_contains($value, "\r")) {
    fwrite(STDERR, "La valeur de {$key} ne peut contenir ni apostrophe ni retour à la ligne.\n");
    exit(3);
}

$line = $key.'='.(preg_match('/^[A-Za-z0-9_.\/:@,+-]*$/', $value) === 1 ? $value : "'".$value."'");
$content = is_file($file) ? (string) file_get_contents($file) : '';
$pattern = '/^'.preg_quote($key, '/').'=.*$/m';

$content = preg_match($pattern, $content) === 1
    ? (string) preg_replace_callback($pattern, static fn (): string => $line, $content)
    : rtrim($content, "\n")."\n".$line."\n";

if (file_put_contents($file, $content) === false) {
    fwrite(STDERR, "Impossible d'écrire {$file}.\n");
    exit(4);
}
