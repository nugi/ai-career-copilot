<?php

namespace App\Enums;

enum WorkArrangement: string
{
    case Remote = 'remote';
    case Hybrid = 'hybrid';
    case Onsite = 'onsite';
}
