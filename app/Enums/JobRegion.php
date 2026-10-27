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

    private const NORTH_AMERICA = ['US', 'CA', 'MX'];

    private const CENTRAL_AMERICA = ['BZ', 'CR', 'SV', 'GT', 'HN', 'MX', 'NI', 'PA'];

    private const CARIBBEAN = [
        'AI', 'AG', 'AW', 'BS', 'BB', 'BQ', 'VG', 'KY', 'CU', 'CW', 'DM', 'DO', 'GD', 'GP',
        'HT', 'JM', 'MQ', 'MS', 'PR', 'BL', 'KN', 'LC', 'MF', 'VC', 'SX', 'TT', 'TC', 'VI',
    ];

    private const SOUTH_AMERICA = ['AR', 'BO', 'BV', 'BR', 'CL', 'CO', 'EC', 'FK', 'GF', 'GY', 'PY', 'PE', 'GS', 'SR', 'UY', 'VE'];

    private const AMERICAS = [...self::NORTH_AMERICA, ...self::CENTRAL_AMERICA, ...self::CARIBBEAN, ...self::SOUTH_AMERICA, 'BM', 'GL', 'PM'];

    private const LATAM = [...self::CENTRAL_AMERICA, ...self::SOUTH_AMERICA, 'CU', 'DO', 'PR'];

    private const EUROPE = [
        'AX', 'AL', 'AD', 'AT', 'BY', 'BE', 'BA', 'BG', 'HR', 'CZ', 'DK', 'EE', 'FO', 'FI', 'FR',
        'DE', 'GI', 'GR', 'GG', 'VA', 'HU', 'IS', 'IE', 'IM', 'IT', 'JE', 'LV', 'LI', 'LT', 'LU',
        'MT', 'MD', 'MC', 'ME', 'NL', 'MK', 'NO', 'PL', 'PT', 'RO', 'RU', 'SM', 'RS', 'SK', 'SI',
        'ES', 'SJ', 'SE', 'CH', 'UA', 'GB',
    ];

    private const MIDDLE_EAST = ['AE', 'BH', 'CY', 'EG', 'IL', 'IQ', 'IR', 'JO', 'KW', 'LB', 'OM', 'PS', 'QA', 'SA', 'SY', 'TR', 'YE'];

    private const AFRICA = [
        'DZ', 'AO', 'BJ', 'BW', 'IO', 'BF', 'BI', 'CV', 'CM', 'CF', 'TD', 'KM', 'CG', 'CD', 'CI',
        'DJ', 'EG', 'GQ', 'ER', 'SZ', 'ET', 'TF', 'GA', 'GM', 'GH', 'GN', 'GW', 'KE', 'LS', 'LR',
        'LY', 'MG', 'MW', 'ML', 'MR', 'MU', 'YT', 'MA', 'MZ', 'NA', 'NE', 'NG', 'RE', 'RW', 'SH',
        'ST', 'SN', 'SC', 'SL', 'SO', 'ZA', 'SS', 'SD', 'TZ', 'TG', 'TN', 'UG', 'EH', 'ZM', 'ZW',
    ];

    private const EMEA = [...self::EUROPE, ...self::MIDDLE_EAST, ...self::AFRICA];

    private const APAC = [
        'CN', 'HK', 'MO', 'KP', 'JP', 'MN', 'KR', 'TW',
        'BN', 'KH', 'ID', 'LA', 'MY', 'MM', 'PH', 'SG', 'TH', 'TL', 'VN',
        'AF', 'BD', 'BT', 'IN', 'IR', 'MV', 'NP', 'PK', 'LK',
        'AS', 'AU', 'CX', 'CC', 'CK', 'FJ', 'PF', 'GU', 'HM', 'KI', 'MH', 'FM', 'NR', 'NC', 'NZ',
        'NU', 'NF', 'MP', 'PW', 'PG', 'PN', 'WS', 'SB', 'TK', 'TO', 'TV', 'UM', 'VU', 'WF',
    ];

    /**
     * Determine whether a person who lives in the country can take a remote job open to this region.
     *
     * The lists follow the UN M49 regions. EMEA and the Americas reuse the smaller lists.
     */
    public function includes(string $countryCode): bool
    {
        if ($this === self::Worldwide) {
            return true;
        }

        $codes = match ($this) {
            self::Americas => self::AMERICAS,
            self::NorthAmerica => self::NORTH_AMERICA,
            self::Latam => self::LATAM,
            self::Europe => self::EUROPE,
            self::MiddleEast => self::MIDDLE_EAST,
            self::Africa => self::AFRICA,
            self::Emea => self::EMEA,
            self::Apac => self::APAC,
        };

        return in_array(strtoupper($countryCode), $codes, true);
    }

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
