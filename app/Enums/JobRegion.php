<?php

namespace App\Enums;

enum JobRegion: string
{
    case Worldwide = 'worldwide';
    case Americas = 'americas';
    case NorthAmerica = 'north_america';
    case Latam = 'latam';
    case Europe = 'europe';
    case Emea = 'emea';
    case MiddleEast = 'middle_east';
    case Africa = 'africa';
    case Apac = 'apac';

    public function label(): string
    {
        return match ($this) {
            self::Worldwide => 'Worldwide',
            self::Americas => 'Americas',
            self::NorthAmerica => 'North America',
            self::Latam => 'Latin America',
            self::Europe => 'Europe',
            self::Emea => 'EMEA',
            self::MiddleEast => 'Middle East',
            self::Africa => 'Africa',
            self::Apac => 'Asia-Pacific',
        };
    }
}
