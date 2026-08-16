<?php

namespace App\Http\Requests\Store\Concerns;

use App\ValueObjects\Cart\ManualServiceCredentials;
use DomainException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

trait ValidatesManualServiceCart
{
    /** @return array<string, mixed> */
    private function fulfillmentRules(): array
    {
        return [
            'scheduleVersion' => ['required', 'integer:strict', 'min:1'],
            'platform' => ['required', 'string', 'in:playstation,pc'],
            'pcStore' => ['required_if:platform,pc', 'prohibited_unless:platform,pc', 'string', 'in:ea_app,steam'],
            'credentials' => ['required', 'array:ea_email,ea_password,ea_backup_codes,playstation_email,playstation_password,playstation_backup_codes,steam_username,steam_password'],
            'credentials.ea_email' => ['required_if:platform,pc', 'prohibited_if:platform,playstation', 'string', 'email:rfc', 'max:254'],
            'credentials.ea_password' => ['required_if:platform,pc', 'prohibited_if:platform,playstation', 'string', 'min:1', 'max:256'],
            'credentials.ea_backup_codes' => ['required', 'array', 'size:3'],
            'credentials.ea_backup_codes.*' => ['required', 'string', 'regex:/\A[0-9]{8}\z/D', 'distinct:strict'],
            'credentials.playstation_email' => ['required_if:platform,playstation', 'prohibited_if:platform,pc', 'string', 'email:rfc', 'max:254'],
            'credentials.playstation_password' => ['required_if:platform,playstation', 'prohibited_if:platform,pc', 'string', 'min:1', 'max:256'],
            'credentials.playstation_backup_codes' => ['required_if:platform,playstation', 'prohibited_if:platform,pc', 'array', 'size:3'],
            'credentials.playstation_backup_codes.*' => ['required', 'string', 'regex:/\A[A-Z0-9]{6}\z/D', 'distinct:strict'],
            'credentials.steam_username' => ['required_if:pcStore,steam', 'prohibited_unless:pcStore,steam', 'string', 'min:1', 'max:128'],
            'credentials.steam_password' => ['required_if:pcStore,steam', 'prohibited_unless:pcStore,steam', 'string', 'min:1', 'max:256'],
            'squadImage' => ['required', 'file', 'max:5120'],
        ];
    }

    /** @param list<string> $allowedTopLevel */
    private function validateManualServiceRequest(Validator $validator, array $allowedTopLevel): void
    {
        if (array_diff(array_keys($this->all()), $allowedTopLevel) !== []) {
            $validator->errors()->add('request', trans('store.cart.unknown_fields'));
        }

        $idempotencyKey = $this->header('Idempotency-Key');

        if (! is_string($idempotencyKey)
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $idempotencyKey) !== 1) {
            $validator->errors()->add('idempotency_key', trans('store.cart.idempotency_key'));
        }

        $credentials = $this->input('credentials');
        $platform = $this->input('platform');

        if (! is_array($credentials) || ! is_string($platform)) {
            return;
        }

        $combined = ['platform' => $platform, ...$credentials];

        if ($platform === 'pc') {
            $combined['pc_store'] = $this->input('pcStore');
        }

        try {
            ManualServiceCredentials::fromValidated($combined);
        } catch (DomainException) {
            $validator->errors()->add('credentials', trans('store.cart.validation_error'));
        }
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key');
    }

    protected function normalizeManualServiceInput(): void
    {
        $credentials = $this->input('credentials');

        if (! is_array($credentials)) {
            return;
        }

        foreach (['ea_email', 'playstation_email'] as $email) {
            if (is_string($credentials[$email] ?? null)) {
                $credentials[$email] = Str::lower(trim($credentials[$email]));
            }
        }

        if (is_string($credentials['steam_username'] ?? null)) {
            $credentials['steam_username'] = trim($credentials['steam_username']);
        }

        foreach (['ea_backup_codes', 'playstation_backup_codes'] as $key) {
            if (! is_array($credentials[$key] ?? null)) {
                continue;
            }

            $credentials[$key] = array_map(function (mixed $code) use ($key): mixed {
                if (! is_string($code)) {
                    return $code;
                }

                $normalized = trim($code);

                return $key === 'playstation_backup_codes' ? strtoupper($normalized) : $normalized;
            }, $credentials[$key]);
        }

        $this->merge(['credentials' => $credentials]);
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
}
