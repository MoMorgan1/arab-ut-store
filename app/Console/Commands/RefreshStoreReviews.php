<?php

namespace App\Console\Commands;

use App\Actions\Reviews\ImportStoreReviews;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

final class RefreshStoreReviews extends Command
{
    protected $signature = 'reviews:refresh';

    protected $description = 'Refresh the safe public review snapshot from n8n';

    public function handle(ImportStoreReviews $import): int
    {
        $url = config('services.n8n.reviews_url');

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->error('The review source is not configured.');

            return self::FAILURE;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->retry([150, 350], throw: false)
                ->get($url);

            if (! $response->successful() || ! is_array($response->json())) {
                $this->error('The review source returned an invalid response.');

                return self::FAILURE;
            }

            $count = $import->execute($response->json());
        } catch (Throwable) {
            $this->error('The review snapshot could not be refreshed.');

            return self::FAILURE;
        }

        $this->info((string) $count);

        return self::SUCCESS;
    }
}
