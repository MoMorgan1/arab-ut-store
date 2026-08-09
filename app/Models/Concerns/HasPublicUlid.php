<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

trait HasPublicUlid
{
    protected static function bootHasPublicUlid(): void
    {
        static::creating(function ($model): void {
            if (! $model->getAttribute('public_id')) {
                $model->setAttribute('public_id', (string) Str::ulid());
            }

            if (! Str::isUlid($model->getAttribute('public_id'))) {
                throw new InvalidArgumentException('A public ID must be a valid ULID.');
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('public_id')) {
                throw new LogicException('A public ID cannot be changed after creation.');
            }
        });
    }

    public function usePublicIdForImport(string $publicId): static
    {
        if ($this->exists) {
            throw new LogicException('An imported public ID can only be assigned before creation.');
        }

        if (! Str::isUlid($publicId)) {
            throw new InvalidArgumentException('An imported public ID must be a valid ULID.');
        }

        $this->setAttribute('public_id', $publicId);

        return $this;
    }
}
