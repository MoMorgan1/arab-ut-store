<?php

namespace App\Models;

/**
 * @property int $id
 * @property string $public_id
 * @property string $source
 * @property string $filename
 * @property string $checksum
 * @property string $status
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property int $conflict_count
 * @property array<string, mixed>|null $report
 * @property bool $dry_run
 */
class ImportBatch extends DomainModel
{
    /** @var list<string> */
    protected $fillable = [
        'source',
        'filename',
        'checksum',
        'status',
        'created_count',
        'updated_count',
        'skipped_count',
        'conflict_count',
        'report',
        'dry_run',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'conflict_count' => 'integer',
            'report' => 'array',
            'dry_run' => 'boolean',
        ];
    }
}
