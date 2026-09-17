<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Enums\FulfillmentResendAction;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ResendFulfillmentItem extends FormRequest
{
    /**
     * Why an operator is re-sending, as a code rather than prose.
     *
     * `forms.md` requires an allowlisted reason code and
     * `audit-logging.md` forbids copying a free-text reason into audit
     * metadata, so the dialog offers a select and this is the list behind it.
     * Four codes cover what actually happens to a stuck item.
     *
     * @var list<string>
     */
    public const REASON_CODES = [
        'never_placed',
        'callback_lost',
        'supplier_stalled',
        'customer_report',
    ];

    /**
     * Everything this request may carry.
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = ['action', 'reason_code', 'challenge_position'];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::FulfillmentAct->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::enum(FulfillmentResendAction::class)],
            'reason_code' => ['required', 'string', Rule::in(self::REASON_CODES)],
            // The challenge's position on the card, not a supplier id. The
            // position is what `RetryItemChallenge` resolves against the
            // placement's stored ids, and resolving it there is also the
            // ownership check.
            'challenge_position' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * A field nobody meant to send is a caller using the wrong shape.
     *
     * `forms.md` refuses the unknown key rather than ignoring it: a mutation
     * that silently drops half a payload is one that looks like it worked.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unknown = array_diff(array_keys($this->all()), self::ALLOWED_KEYS);

                if ($unknown !== []) {
                    $validator->errors()->add('unexpected_fields', 'Unknown fields are not allowed.');
                }
            },
        ];
    }

    public function action(): FulfillmentResendAction
    {
        return FulfillmentResendAction::from((string) $this->validated()['action']);
    }

    public function reasonCode(): string
    {
        return (string) $this->validated()['reason_code'];
    }

    public function challengePosition(): ?int
    {
        $position = $this->validated()['challenge_position'] ?? null;

        return $position === null ? null : (int) $position;
    }
}
