<?php

namespace App\Http\Requests\Store;

use App\Http\Requests\Store\Concerns\ValidatesManualServiceCart;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class RivalsCartRequest extends FormRequest
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
            'currentDivision' => ['required', 'string', 'in:7,6,5,4,3,2,1,elite'],
            'targetDivision' => ['required', 'string', 'in:7,6,5,4,3,2,1,elite'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateManualServiceRequest($validator, [
            'scheduleVersion', 'platform', 'pcStore', 'currentDivision', 'targetDivision', 'credentials', 'squadImage',
        ])];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeManualServiceInput();

        $version = $this->input('scheduleVersion');

        if (is_string($version) && ctype_digit($version)) {
            $this->merge(['scheduleVersion' => (int) $version]);
        }
    }
}
