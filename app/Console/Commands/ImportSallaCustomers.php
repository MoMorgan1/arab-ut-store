<?php

namespace App\Console\Commands;

use App\Imports\Salla\ImportSallaCustomers as ImportAction;
use Illuminate\Console\Command;
use Throwable;

final class ImportSallaCustomers extends Command
{
    protected $signature = 'salla:import-customers
        {path : Path to the Salla customer export file}
        {--dry-run : Simulate the import without making any database changes}';

    protected $description = 'Import customers idempotently from a Salla customer export file';

    public function handle(ImportAction $action): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        if (! file_exists($path)) {
            $this->components->error("Customer file not found at [{$path}].");

            return self::FAILURE;
        }

        $this->components->info($dryRun ? 'Starting Salla customer dry run...' : 'Starting Salla customer import...');

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
            'Total Processed: '.$report['total_processed'],
            'Created: '.$report['created'],
            'Updated / Matched: '.$report['updated'],
            'Skipped (Already Imported): '.$report['skipped'],
            'Conflicts: '.$report['conflicts'],
        ]);

        if (! empty($report['conflict_details'])) {
            $this->components->warn('The following conflicts were detected and skipped:');
            $rows = array_map(fn (array $c): array => [
                $c['salla_id'],
                $c['name'],
                $c['email'] ?? 'N/A',
                $c['phone'] ?? 'N/A',
                // Three kinds of conflict reach here: an email and a mobile
                // pointing at two different existing accounts, two rows of this
                // same file claiming one identity, and a row whose identity
                // belongs to a staff or owner account.
                match (true) {
                    isset($c['staff_user_id']) => 'Belongs to non-customer account #'.$c['staff_user_id'].', not imported',
                    isset($c['email_user_id'], $c['phone_user_id']) => "Email User #{$c['email_user_id']} vs Phone User #{$c['phone_user_id']}",
                    default => 'Duplicate identity in file, already claimed by Salla #'.($c['claimed_by'] ?? '?'),
                },
            ], $report['conflict_details']);

            $this->table(['Salla ID', 'Name', 'Email', 'Phone', 'Conflict Reason'], $rows);
        }

        if ($report['batch_id'] !== null) {
            $this->components->info("Batch recorded with ID: {$report['batch_id']}");
        }

        return self::SUCCESS;
    }
}
