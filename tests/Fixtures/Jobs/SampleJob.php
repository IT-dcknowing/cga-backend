<?php

namespace Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Job de test : réussit, ou échoue si on le demande.
 */
class SampleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public bool $fails = false) {}

    public function handle(): void
    {
        if ($this->fails) {
            throw new RuntimeException('Job en échec');
        }
    }
}
