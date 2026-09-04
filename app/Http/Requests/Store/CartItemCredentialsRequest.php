<?php

namespace App\Http\Requests\Store;

use App\Enums\ServiceType;
use App\Models\CartItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

final class CartItemCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $manual = $this->manualContext();

        if ($manual !== null) {
            return $this->manualRules($manual['platform'], $manual['store']);
        }

        return [
            'ea_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'ea_password' => ['present', 'string', 'min:1', 'max:128'],
            'backup_codes' => ['required', 'array', 'size:3'],
            'backup_codes.*' => ['required', 'string', 'regex:/\A[0-9]{8}\z/D', 'distinct:strict'],
            'current_balance' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'companion_market_open' => ['sometimes', 'boolean'],
            'policy_accepted' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $manual = $this->manualContext();

            $allowed = $manual !== null
                ? $this->manualAllowedFields($manual['platform'], $manual['store'])
                : [
                    'ea_email',
                    'ea_password',
                    'backup_codes',
                    'current_balance',
                    'companion_market_open',
                    'policy_accepted',
                ];

            if (array_diff(array_keys($this->all()), $allowed) !== []) {
                $validator->errors()->add('request', trans('store.cart.unknown_fields'));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $manual = $this->manualContext();

        if ($manual !== null) {
            $this->merge($this->normalizeManual($this->all(), $manual['platform']));

            return;
        }

        $email = $this->input('ea_email');
        $codes = $this->input('backup_codes');

        $this->merge([
            'ea_email' => is_string($email) ? Str::lower(trim($email)) : $email,
            'backup_codes' => is_array($codes)
                ? array_map(fn (mixed $code): mixed => is_string($code) ? trim($code) : $code, $codes)
                : $codes,
        ]);
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'message' => trans('store.cart.validation_error'),
                'errors' => $validator->errors(),
            ], 422)->header('Cache-Control', 'no-store'),
        );
    }

    /**
     * The stored platform/launcher when the named line is a manual service,
     * otherwise null. The platform and launcher live on the stored line and
     * are never editable here — they only select which fields are accepted.
     *
     * @return array{platform: string, store: string|null}|null
     */
    private function manualContext(): ?array
    {
        $publicId = $this->route('cartItem');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $item = CartItem::query()
            ->where('public_id', $publicId)
            ->with('productVariant')
            ->first();

        $serviceType = $item?->productVariant?->service_type;

        if (! in_array($serviceType, [ServiceType::Rivals, ServiceType::FutChampions], true)) {
            return null;
        }

        $configuration = $item->configuration ?? [];
        $platform = $configuration['platform'] ?? null;
        $store = $configuration['pc_store'] ?? null;

        if ($platform !== 'playstation' && $platform !== 'pc') {
            return null;
        }

        if ($platform === 'pc' && ! in_array($store, ['ea_app', 'steam'], true)) {
            return null;
        }

        return [
            'platform' => $platform,
            'store' => $platform === 'pc' ? $store : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manualRules(string $platform, ?string $store): array
    {
        if ($platform === 'playstation') {
            return [
                'playstation_email' => ['required', 'string', 'email:rfc', 'max:254'],
                'playstation_password' => ['present', 'string', 'min:1', 'max:256'],
                'ea_backup_codes' => ['required', 'array', 'size:3'],
                'ea_backup_codes.*' => ['required', 'string', 'regex:/\A[0-9]{8}\z/D', 'distinct:strict'],
                'playstation_backup_codes' => ['required', 'array', 'size:3'],
                'playstation_backup_codes.*' => ['required', 'string', 'regex:/\A[A-Z0-9]{6}\z/D', 'distinct:strict'],
            ];
        }

        $rules = [
            'ea_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'ea_password' => ['present', 'string', 'min:1', 'max:256'],
            'ea_backup_codes' => ['required', 'array', 'size:3'],
            'ea_backup_codes.*' => ['required', 'string', 'regex:/\A[0-9]{8}\z/D', 'distinct:strict'],
        ];

        if ($store === 'steam') {
            $rules['steam_username'] = ['required', 'string', 'min:1', 'max:128'];
            $rules['steam_password'] = ['present', 'string', 'min:1', 'max:256'];
        }

        return $rules;
    }

    /** @return list<string> */
    private function manualAllowedFields(string $platform, ?string $store): array
    {
        if ($platform === 'playstation') {
            return [
                'playstation_email',
                'playstation_password',
                'ea_backup_codes',
                'playstation_backup_codes',
            ];
        }

        return $store === 'steam'
            ? ['ea_email', 'ea_password', 'ea_backup_codes', 'steam_username', 'steam_password']
            : ['ea_email', 'ea_password', 'ea_backup_codes'];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeManual(array $input, string $platform): array
    {
        foreach (['ea_email', 'playstation_email'] as $email) {
            if (is_string($input[$email] ?? null)) {
                $input[$email] = Str::lower(trim($input[$email]));
            }
        }

        if (is_string($input['steam_username'] ?? null)) {
            $input['steam_username'] = trim($input['steam_username']);
        }

        foreach (['ea_backup_codes', 'playstation_backup_codes'] as $key) {
            if (! is_array($input[$key] ?? null)) {
                continue;
            }

            $input[$key] = array_map(function (mixed $code) use ($key): mixed {
                if (! is_string($code)) {
                    return $code;
                }

                $normalized = trim($code);

                return $key === 'playstation_backup_codes' ? strtoupper($normalized) : $normalized;
            }, $input[$key]);
        }

        return $input;
    }
}
