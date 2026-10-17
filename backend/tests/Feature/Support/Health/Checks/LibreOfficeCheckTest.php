<?php

namespace Tests\Feature\Support\Health\Checks;

use App\Support\Health\Checks\LibreOfficeCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthStatus;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * LibreOffice (conversion PDF, 02-TRD §8.2) : binaire LIBREOFFICE_BIN, délai du contrôle variabilisé.
 */
class LibreOfficeCheckTest extends TestCase
{
    public function test_libreoffice_answering_its_version_is_ok(): void
    {
        Process::fake(['*' => Process::result('LibreOffice 24.8.4.2 bb3cfa12c7b1bf994ecc5649a80400d06cd71002')]);

        $this->assertSame(HealthStatus::Ok, (new LibreOfficeCheck)->run()->status());
    }

    public function test_runs_the_configured_binary_with_the_configured_timeout(): void
    {
        config([
            'pdf.libreoffice.binary' => '/opt/libreoffice/program/soffice',
            'health.libreoffice_timeout_seconds' => 7,
        ]);
        Process::fake(['*' => Process::result('LibreOffice 24.8.4.2')]);

        (new LibreOfficeCheck)->run();

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['/opt/libreoffice/program/soffice', '--version']
            && $process->timeout === 7);
    }

    public function test_a_missing_binary_is_unavailable_with_its_exit_code_for_the_log(): void
    {
        config(['pdf.libreoffice.binary' => 'soffice-absent']);
        Process::fake(['*' => Process::result(output: '', errorOutput: 'soffice-absent: command not found', exitCode: 127)]);

        $result = (new LibreOfficeCheck)->run();

        $this->assertSame(HealthCheckError::Unavailable, $result->error);
        $this->assertSame(
            ['binary' => 'soffice-absent', 'exit_code' => 127, 'error_output' => 'soffice-absent: command not found'],
            $result->context,
        );
    }

    public function test_another_program_than_libreoffice_is_unavailable(): void
    {
        Process::fake(['*' => Process::result('Python 3.12.4')]);

        $this->assertSame(HealthCheckError::Unavailable, (new LibreOfficeCheck)->run()->error);
    }
}
