<?php

namespace App\Enums;

enum AiUsageStatus: string
{
    case Succeeded = 'succeeded';
    case FailedOver = 'failed_over';
    case Failed = 'failed';
}
