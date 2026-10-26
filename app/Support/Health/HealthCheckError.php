<?php

namespace App\Support\Health;

/**
 * Code d'anomalie renvoyé au client. Le détail technique ne va que dans le journal.
 */
enum HealthCheckError: string
{
    case Unavailable = 'unavailable';
    case NotWritable = 'not_writable';
    case LowSpace = 'low_space';
}
