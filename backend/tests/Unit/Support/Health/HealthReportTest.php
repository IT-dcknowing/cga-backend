<?php

namespace Tests\Unit\Support\Health;

use App\Support\Health\HealthCheckError;
use App\Support\Health\HealthCheckResult;
use App\Support\Health\HealthReport;
use App\Support\Health\HealthStatus;
use PHPUnit\Framework\TestCase;

class HealthReportTest extends TestCase
{
    public function test_report_is_ok_when_every_check_is_ok(): void
    {
        $report = new HealthReport(['database' => HealthCheckResult::ok(), 'queue' => HealthCheckResult::ok()]);

        $this->assertSame(HealthStatus::Ok, $report->status());
    }

    public function test_report_fails_as_soon_as_one_check_fails(): void
    {
        $report = new HealthReport([
            'database' => HealthCheckResult::ok(),
            'storage' => HealthCheckResult::failed(HealthCheckError::LowSpace),
            'queue' => HealthCheckResult::ok(),
        ]);

        $this->assertSame(HealthStatus::Failed, $report->status());
    }

    public function test_failed_result_keeps_its_error_and_log_context(): void
    {
        $result = HealthCheckResult::failed(HealthCheckError::NotWritable, ['disk' => 'local']);

        $this->assertSame(HealthStatus::Failed, $result->status());
        $this->assertSame(HealthCheckError::NotWritable, $result->error);
        $this->assertSame(['disk' => 'local'], $result->context);
    }

    public function test_ok_result_has_neither_error_nor_context(): void
    {
        $result = HealthCheckResult::ok();

        $this->assertSame(HealthStatus::Ok, $result->status());
        $this->assertNull($result->error);
        $this->assertSame([], $result->context);
    }
}
