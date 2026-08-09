<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasPublicUlid
{
    protected static function bootHasPublicUlid(): void
    {
        static::creating(function ($model): void {
            if (! $model->getAttribute('public_id')) {
                $model->setAttribute('public_id', (string) Str::ulid());
            }
        });
    }
}
