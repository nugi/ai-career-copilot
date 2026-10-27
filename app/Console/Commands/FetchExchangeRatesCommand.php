<?php

namespace App\Console\Commands;

use App\Actions\Money\FetchExchangeRates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

#[Signature('exchange-rates:fetch')]
#[Description('Store the latest ECB daily reference rates.')]
class FetchExchangeRatesCommand extends Command
{
    /**
     * Fetch and store the rates. A failure logs the exception class only, because the message can contain the response.
     */
    public function handle(FetchExchangeRates $fetchExchangeRates): int
    {
        try {
            $count = $fetchExchangeRates->handle();
        } catch (RuntimeException|ConnectionException $exception) {
            Log::error('exchange_rates.fetch_failed', ['exception' => $exception::class]);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Stored {$count} rates.");

        return self::SUCCESS;
    }
}
