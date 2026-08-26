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
        $isWeeklyMatches = $this->input('mode') === 'weekly_matches';

        return [
            ...$this->fulfillmentRules(),
            'mode' => ['required', 'string', 'in:promotion,weekly_matches'],
            // A week of matches promotes nothing, so a division on it would be
            // a claim the service does not make.
            'currentDivision' => [$isWeeklyMatches ? 'missing' : 'required', 'string', 'in:7,6,5,4,3,2,1,elite'],
            'targetDivision' => [$isWeeklyMatches ? 'missing' : 'required', 'string', 'in:7,6,5,4,3,2,1,elite'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateManualServiceRequest($validator, [
            'scheduleVersion', 'platform', 'pcStore', 'mode', 'currentDivision', 'targetDivision', 'credentials', 'squadImage',
        ])];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeManualServiceInput();

        // A page opened before weekly matches existed sends no mode, and so does
        // a bookmarked deep link. Both mean the promotion this endpoint always
        // sold, so default rather than reject.
        if (! $this->has('mode')) {
            $this->merge(['mode' => 'promotion']);
        }

        $version = $this->input('scheduleVersion');

        if (is_string($version) && ctype_digit($version)) {
            $this->merge(['scheduleVersion' => (int) $version]);
        }
    }
}
