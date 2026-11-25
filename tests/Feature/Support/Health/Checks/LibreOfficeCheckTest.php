<?php

use App\Support\Health\Checks\LibreOfficeCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthStatus;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/*
 * LibreOffice (conversion PDF, 02-TRD §8.2) : binaire LIBREOFFICE_BIN, délai du contrôle variabilisé.
 */

it('est bon quand LibreOffice donne sa version', function () {
    Process::fake(['*' => Process::result('LibreOffice 26.2.6.3 8221e31b3ac356a1623c672912a3d2b492f7e3d1')]);

    expect((new LibreOfficeCheck)->run()->status())->toBe(HealthStatus::Ok);
});

it('lance le binaire configuré avec le délai configuré', function () {
    config([
        'pdf.libreoffice.binary' => '/opt/libreoffice/program/soffice',
        'health.libreoffice_timeout_seconds' => 7,
    ]);
    Process::fake(['*' => Process::result('LibreOffice 26.2.6.3')]);

    (new LibreOfficeCheck)->run();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['/opt/libreoffice/program/soffice', '--version']
        && $process->timeout === 7);
});

it('déclare indisponible un binaire absent, avec son code de sortie pour le journal', function () {
    config(['pdf.libreoffice.binary' => 'soffice-absent']);
    Process::fake(['*' => Process::result(output: '', errorOutput: 'soffice-absent: command not found', exitCode: 127)]);

    $result = (new LibreOfficeCheck)->run();

    expect($result->error)->toBe(HealthCheckError::Unavailable)
        ->and($result->context)->toBe([
            'binary' => 'soffice-absent',
            'exit_code' => 127,
            'error_output' => 'soffice-absent: command not found',
        ]);
});

it('déclare indisponible un autre programme que LibreOffice', function () {
    Process::fake(['*' => Process::result('Python 3.12.4')]);

    expect((new LibreOfficeCheck)->run()->error)->toBe(HealthCheckError::Unavailable);
});
