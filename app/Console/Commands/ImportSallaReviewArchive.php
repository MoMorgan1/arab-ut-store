<?php

namespace App\Console\Commands;

use App\Actions\Reviews\ImportStoreReviews;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

final class ImportSallaReviewArchive extends Command
{
    protected $signature = 'reviews:import-salla-archive
        {path? : Absolute path to the PII-free archive JSON}
        {--from-config : Fetch and safely project the configured n8n/Salla source}
        {--apply : Persist the validated archive}';

    protected $description = 'Preview or import the one-time PII-free Salla review archive';

    public function handle(ImportStoreReviews $import): int
    {
        try {
            $payload = $this->payload($import);

            $summary = $import->executeArchive($payload, $this->option('apply'));
        } catch (Throwable) {
            $this->error('The review archive failed validation. No changes were made.');

            return self::FAILURE;
        }

        $distribution = collect($summary['ratings'])
            ->map(fn (int $count, int $rating): string => "{$rating}:{$count}")
            ->implode(',');
        $mode = $this->option('apply') ? 'apply' : 'dry-run';

        $this->info("mode={$mode} count={$summary['count']} ratings={$distribution}");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function payload(ImportStoreReviews $import): array
    {
        $path = $this->argument('path');
        $fromConfig = (bool) $this->option('from-config');

        if ($fromConfig) {
            if (is_string($path) && $path !== '') {
                throw new JsonException('Choose one archive source.');
            }

            $url = config('services.n8n.reviews_url');

            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new JsonException('The configured source is unavailable.');
            }

            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(60)
                ->retry([250, 750], throw: false)
                ->get($url);
            $source = $response->json();

            if (! $response->successful() || ! is_array($source)) {
                throw new JsonException('The configured source failed.');
            }

            return $import->projectSallaSource($source);
        }

        if (! is_string($path) || ! is_file($path) || filesize($path) > 10_000_000) {
            throw new JsonException('The archive file is unavailable.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new JsonException('Unreadable archive.');
        }

        $payload = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new JsonException('Invalid archive root.');
        }

        return $payload;
    }
}
