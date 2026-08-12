<?php

namespace App\Http\Requests\Automation;

use App\Enums\ServiceType;
use Illuminate\Validation\Rule;

final class SbcCatalogSnapshotRequest extends CatalogSnapshotRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'products.*.serviceType' => [
                'required',
                Rule::in([ServiceType::Sbc->value]),
            ],
        ]);
    }
}
