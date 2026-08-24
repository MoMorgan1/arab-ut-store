<?php

namespace App\Console\Commands;

use App\Imports\Salla\ImportSallaOrders as ImportAction;
use Illuminate\Console\Command;
use Throwable;

final class ImportSallaOrders extends Command
{
    protected $signature = 'salla:import-orders
        {path : Path to the Salla orders CSV file}
        {--dry-run : Simulate the import without making any database changes}';

    protected $description = 'Import orders idempotently from a Salla orders CSV export';

    public function handle(ImportAction $action): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        if (! file_exists($path)) {
            $this->components->error("Orders file not found at [{$path}].");

            return self::FAILURE;
        }

        $this->components->info($dryRun ? 'Starting Salla orders dry run...' : 'Starting Salla orders import...');

        try {
            $report = $action->execute($path, $dryRun);
        } catch (Throwable $exception) {
            $this->components->error("Import failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->bulletList([
            'File: '.$report['filename'],
            'Checksum: '.$report['checksum'],
            'Mode: '.($report['dry_run'] ? 'DRY RUN (No changes written)' : 'APPLIED (Committed to DB)'),
            'Total CSV Rows: '.$report['total_rows'],
            'Distinct Orders: '.$report['total_orders'],
            'Created Orders: '.$report['created'],
            'Skipped: '.$report['skipped'],
            'Unmatched Customers: '.$report['unmatched_customer'],
            'Skipped - not completed: '.$report['skipped_not_completed'],
            'Skipped - zero value: '.$report['skipped_zero_total'],
            'Unrecognised Statuses: '.$report['unrecognised_statuses'],
        ]);

        if ($report['unconverted_currencies'] !== []) {
            // These orders keep their own currency, so they will not count
            // toward lifetime spend until a rate exists for them.
            $this->components->warn('No exchange rate for these currencies - those orders were left unconverted:');
            $lines = [];
            foreach ($report['unconverted_currencies'] as $code => $count) {
                $lines[] = $code.': '.$count.' order(s)';
            }
            $this->components->bulletList($lines);
        }

        if (! empty($report['unrecognised_status_list'])) {
            $this->components->warn('The following status values were unrecognised and mapped to Cancelled:');
            $this->components->bulletList($report['unrecognised_status_list']);
        }

        if ($report['batch_id'] !== null) {
            $this->components->info("Batch recorded with ID: {$report['batch_id']}");
        }

        return self::SUCCESS;
    }
}
