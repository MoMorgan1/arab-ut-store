<?php

namespace App\Http\Requests\Store;

use App\Http\Requests\Store\Concerns\ValidatesManualServiceCart;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class FutChampionsCartRequest extends FormRequest
{
    use ValidatesManualServiceCart;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->fulfillmentRules(),
            'replaceCartItemId' => ['nullable', 'string', 'ulid'],
            // Replacing keeps the old squad image unless a new one is sent.
            'squadImage' => ['required_without:replaceCartItemId', 'file', 'max:5120'],
            'rank' => ['required', 'integer:strict', 'between:1,6'],
            'urgent' => ['required', 'boolean'],
            'matchesPlayed' => ['required', 'integer:strict', 'between:0,100'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateManualServiceRequest($validator, [
            'scheduleVersion', 'platform', 'pcStore', 'rank', 'urgent', 'matchesPlayed', 'credentials', 'squadImage', 'replaceCartItemId',
        ])];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeManualServiceInput();

        $normalized = [];

        foreach (['scheduleVersion', 'rank', 'matchesPlayed'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && ctype_digit($value)) {
                $normalized[$field] = (int) $value;
            }
        }

        $urgent = $this->input('urgent');

        if (in_array($urgent, ['0', 'false'], true)) {
            $normalized['urgent'] = false;
        } elseif (in_array($urgent, ['1', 'true'], true)) {
            $normalized['urgent'] = true;
        }

        $this->merge($normalized);
    }
}
