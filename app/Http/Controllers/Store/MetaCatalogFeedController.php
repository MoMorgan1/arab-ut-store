<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Support\Feeds\MetaCatalogFeed;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The catalogue feed Meta fetches on a schedule.
 *
 * It is cached for ten minutes: Meta pulls it a few times a day, but the URL
 * is public, and pricing a coin order per platform is not work to repeat for
 * whoever finds the link.
 */
class MetaCatalogFeedController extends Controller
{
    private const CACHE_KEY = 'feeds:meta-catalog:csv';

    private const CACHE_SECONDS = 600;

    public function __invoke(MetaCatalogFeed $feed): Response
    {
        $csv = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_SECONDS,
            static fn (): string => self::csv($feed->rows()),
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="meta-catalog.csv"',
            'Cache-Control' => 'public, max-age=600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, MetaCatalogFeed::COLUMNS);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn (string $column): string => $row[$column] ?? '',
                MetaCatalogFeed::COLUMNS,
            ));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
