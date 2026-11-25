<?php

namespace App\Support\Health\Checks;

use App\Support\Health\HealthCheck;
use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthCheckResult;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * LibreOffice, qui convertit les documents en PDF (02-TRD §8.2), est installé et se lance.
 */
class LibreOfficeCheck implements HealthCheck
{
    public function name(): string
    {
        return 'libreoffice';
    }

    public function run(): HealthCheckResult
    {
        $binary = Config::string('pdf.libreoffice.binary');

        $result = Process::timeout(Config::integer('health.libreoffice_timeout_seconds'))
            ->run([$binary, '--version']);

        if ($result->failed() || ! str_contains($result->output(), 'LibreOffice')) {
            return HealthCheckResult::failed(HealthCheckError::Unavailable, [
                'binary' => $binary,
                'exit_code' => $result->exitCode(),
                'error_output' => Str::limit(trim($result->errorOutput()), 500),
            ]);
        }

        return HealthCheckResult::ok();
    }
}
