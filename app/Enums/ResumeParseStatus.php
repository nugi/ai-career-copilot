<?php

namespace App\Enums;

enum ResumeParseStatus: string
{
    case Pending = 'pending';
    case Parsed = 'parsed';
    case Failed = 'failed';
}
