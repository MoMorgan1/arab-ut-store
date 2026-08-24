<?php

namespace App\Models;

/**
 * @property int $id
 * @property string $public_id
 * @property string $source
 * @property string $entity
 * @property string $external_id
 * @property int $internal_id
 */
class ExternalRef extends DomainModel
{
    /** @var list<string> */
    protected $fillable = [
        'source',
        'entity',
        'external_id',
        'internal_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'internal_id' => 'integer',
        ];
    }
}
