<?php

namespace App\Support\Health;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
}
