<?php

namespace App\Enums;

enum IngestionRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RateLimited = 'rate_limited';
}
