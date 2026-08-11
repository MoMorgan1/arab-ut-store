<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class RefreshDisplayExchangeRates extends Command
{
    protected $signature = 'currency:refresh-display-rates';

    protected $description = 'Refresh cached display-only exchange rates from the configured provider';

    public function handle(): int
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(6)
                ->retry([200, 400], throw: false)
                ->get((string) config('store.display_exchange_rates.provider_url'));
        } catch (ConnectionException) {
            $this->error('The display exchange-rate provider could not be reached.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('The display exchange-rate provider returned an unsuccessful response.');

            return self::FAILURE;
        }

        $rates = $this->validatedRates($response->body());

        if ($rates === null) {
            $this->error('The display exchange-rate provider response was invalid.');

            return self::FAILURE;
        }

        $fetchedAt = now();

        DB::transaction(function () use ($rates, $fetchedAt): void {
            foreach ($rates as $currency => $rate) {
                ExchangeRate::query()->updateOrCreate(
                    ['base_currency' => 'SAR', 'quote_currency' => $currency],
                    [
                        'rate' => $rate,
                        'source' => config('store.display_exchange_rates.source'),
                        'fetched_at' => $fetchedAt,
                    ],
                );
            }
        });

        $this->info('Display exchange rates refreshed.');

        return self::SUCCESS;
    }

    /** @return array<string, string>|null */
    private function validatedRates(string $body): ?array
    {
        $payload = json_decode($body, true);

        if (! is_array($payload)
            || ($payload['result'] ?? null) !== 'success'
            || ($payload['base_code'] ?? null) !== 'SAR'
            || ! is_array($payload['rates'] ?? null)
            || preg_match('/"rates"\s*:\s*\{(?<rates>[^{}]*)\}/s', $body, $ratesMatch) !== 1) {
            return null;
        }

        $configured = array_values(array_diff(config('store.display_currencies'), ['SAR']));
        $rates = [];

        foreach ($configured as $currency) {
            $quotedCurrency = preg_quote($currency, '/');
            $matchCount = preg_match_all(
                '/"'.$quotedCurrency.'"\s*:\s*(?<rate>[^,}\s]+)/',
                $ratesMatch['rates'],
                $rateMatches,
                PREG_SET_ORDER,
            );

            if ($matchCount !== 1) {
                return null;
            }

            $canonicalRate = $this->quantizeRate($rateMatches[0]['rate']);

            if ($canonicalRate === null) {
                return null;
            }

            $rates[$currency] = $canonicalRate;
        }

        return $rates;
    }

    private function quantizeRate(string $rate): ?string
    {
        if (preg_match('/^(?<whole>0|[1-9]\d{0,9})(?:\.(?<fraction>\d+))?$/D', $rate, $matches) !== 1) {
            return null;
        }

        $fraction = $matches['fraction'] ?? '';
        $eightDigits = str_pad(substr($fraction, 0, 8), 8, '0');
        $digits = ltrim($matches['whole'].$eightDigits, '0');

        if ($digits === '') {
            return null;
        }

        $scaledRate = 0;

        foreach (str_split($digits) as $digit) {
            $value = ord($digit) - ord('0');

            if ($scaledRate > intdiv(PHP_INT_MAX - $value, 10)) {
                return null;
            }

            $scaledRate = ($scaledRate * 10) + $value;
        }

        if (strlen($fraction) > 8 && $fraction[8] >= '5') {
            $scaledRate++;
        }

        return sprintf('%d.%08d', intdiv($scaledRate, 100_000_000), $scaledRate % 100_000_000);
    }
}
