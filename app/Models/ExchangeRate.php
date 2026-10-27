<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property CarbonImmutable $rate_date
 * @property string $currency
 * @property string $rate
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['rate_date', 'currency', 'rate'])]
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'decimal:8',
        ];
    }
}
