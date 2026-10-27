<?php

namespace Database\Factories;

use App\Models\ExchangeRate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    /**
     * @return array{rate_date: CarbonInterface, currency: string, rate: float}
     */
    public function definition(): array
    {
        return [
            'rate_date' => today('UTC'),
            'currency' => fake()->randomElement(['USD', 'GBP', 'IDR', 'CAD']),
            'rate' => fake()->randomFloat(8, 0.01, 25000),
        ];
    }
}
