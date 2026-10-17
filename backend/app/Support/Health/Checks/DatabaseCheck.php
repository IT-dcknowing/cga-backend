<?php

namespace App\Support\Health\Checks;

use App\Support\Health\HealthCheck;
use App\Support\Health\HealthCheckResult;
use Illuminate\Support\Facades\DB;

/**
 * La base de données répond à une requête.
 */
class DatabaseCheck implements HealthCheck
{
    public function name(): string
    {
        return 'database';
    }

    public function run(): HealthCheckResult
    {
        DB::connection()->select('select 1');

        return HealthCheckResult::ok();
    }
}
