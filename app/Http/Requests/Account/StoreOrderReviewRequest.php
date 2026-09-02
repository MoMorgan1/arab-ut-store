<?php

namespace App\Http\Requests\Account;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class StoreOrderReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:600'],
        ];
    }

    public function rating(): int
    {
        return (int) $this->validated('rating');
    }

    /** The comment as it will be stored: plain text, or nothing at all. */
    public function body(): ?string
    {
        $body = $this->validated('body');
        $body = is_string($body) ? trim($body) : '';

        return $body === '' ? null : $body;
    }

    /**
     * Strip control characters before validation so the length limit counts
     * what a reader will actually see, and a bidi override can never be stored.
     * Line breaks survive: a textarea is allowed to have paragraphs.
     */
    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (! is_string($body)) {
            return;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $stripped = preg_replace('/[^\P{C}\n]/u', '', $normalized);

        $this->merge(['body' => is_string($stripped) ? $stripped : '']);
    }
}
