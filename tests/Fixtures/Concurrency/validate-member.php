<?php

/*
 * Processus enfant des tests de concurrence (étapes 2.3 et 2.13) : démarre l'application sur la base
 * de test du worker, écrit « ready », attend le fichier de départ commun, puis valide l'adhésion
 * donnée dans l'espace du rattachement donné. Écrit « OK <numéro> » ou « ERR <code> ».
 *
 * Usage : php validate-member.php <rattachement> <dossier> <fichier de départ>
 */

use App\Domain\Members\Actions\ValidateMembership;
use App\Domain\Members\Models\Member;
use App\Domain\Users\Models\Membership;
use App\Support\Http\Exceptions\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $membershipId, $memberId, $start] = $argv;
$membership = Membership::query()->with(['organization', 'user'])->findOrFail($membershipId);

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
$deadline = microtime(true) + 60;
while (! is_file($start) && microtime(true) < $deadline) {
    usleep(5_000);
}

try {
    $number = app(TenantContext::class)->runAs($membership, static function () use ($memberId, $membership): string {
        $member = app(ValidateMembership::class)->execute(Member::query()->findOrFail($memberId), true, $membership->user);

        return (string) ($member->membership_number ?? $member->client_number);
    });
    fwrite(STDOUT, "OK {$number}\n");
} catch (BusinessRuleException $exception) {
    fwrite(STDOUT, "ERR {$exception->errorCode}\n");
}
