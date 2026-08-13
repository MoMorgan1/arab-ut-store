<?php

namespace App\Console\Commands;

use App\Actions\Reviews\ImportStoreReviews;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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
        } catch (Throwable $exception) {
            $reason = $this->safeFailureReason($exception);

            $this->error("The review archive failed validation. No changes were made. reason={$reason}");

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

    private function safeFailureReason(Throwable $exception): string
    {
        if ($exception instanceof JsonException) {
            return 'source_unavailable';
        }

        if (! $exception instanceof ValidationException) {
            return 'unexpected';
        }

        $messages = collect($exception->errors())->flatten()->all();
        $known = [
            'The Salla review source is malformed.' => 'source_shape',
            'The normalized review source size is invalid.' => 'normalized_size',
            'Each normalized review must be an object.' => 'normalized_object',
            'The normalized published review is incomplete.' => 'normalized_incomplete',
            'The normalized review date is invalid.' => 'normalized_date',
            'The normalized source contains no safe visible ratings.' => 'normalized_empty',
            'Each Salla review must be an object.' => 'raw_object',
            'The published Salla review is incomplete.' => 'raw_incomplete',
            'The Salla review date is invalid.' => 'raw_date',
            'The Salla source contains no safe published ratings.' => 'raw_empty',
            'The archive contains unsupported fields.' => 'archive_shape',
            'The review identity is duplicated.' => 'archive_duplicate',
            'The review contains unsupported fields.' => 'archive_record_shape',
            'The public review fields are invalid.' => 'archive_public_fields',
        ];

        foreach ($messages as $message) {
            if (is_string($message) && isset($known[$message])) {
                return $known[$message];
            }
        }

        return 'validation';
    }
}
