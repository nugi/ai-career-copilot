<?php

use App\Enums\JobRegion;
use App\JobSources\Normalization\LocationParser;

/**
 * @param  list<string>  $countryCodes
 */
test('locations resolve to countries, a region and an unambiguous city', function (
    ?string $location,
    array $countryCodes,
    ?JobRegion $region = null,
    ?string $city = null,
) {
    $parsed = (new LocationParser)->parse($location);

    expect($parsed->countryCodes)->toBe($countryCodes)
        ->and($parsed->region)->toBe($region)
        ->and($parsed->city)->toBe($city);
})->with([
    'country name' => ['United States', ['US']],
    'country alias' => ['USA', ['US']],
    'two countries sorted' => ['Ireland,  UK', ['GB', 'IE']],
    'two North American countries' => ['Canada, USA', ['CA', 'US']],
    'anywhere' => ['Anywhere', [], JobRegion::Worldwide],
    'no restriction' => [null, [], JobRegion::Worldwide],
    'regional restriction' => ['APAC', [], JobRegion::Apac],
    'city and country' => ['Berlin, Germany', ['DE'], null, 'Berlin'],
    'Turkish country name' => ['Türkiye', ['TR']],
    'Indonesian country name' => ['Jerman', ['DE']],
    'unknown city' => ['Hamburg', []],
]);
