<?php

namespace App\Actions\Fortify;

use App\Actions\Auth\PendingVerifiedRegistrationPhone;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final readonly class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private Request $request,
        private PendingVerifiedRegistrationPhone $pendingPhone,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $phone = $this->pendingPhone->current($this->request);
        $validator = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        $validator->after(function ($validator) use ($phone): void {
            if ($phone !== null && User::query()->where('phone', $phone->value())->exists()) {
                $validator->errors()->add('phone', trans('auth_ui.register.phone_unavailable'));
            }
        });

        $validator->validate();

        $user = DB::transaction(function () use ($input, $phone): User {
            $user = User::create([
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'email' => $input['email'],
                'phone' => $phone?->value(),
                'password' => $input['password'],
                'preferred_locale' => app()->getLocale(),
            ]);

            if ($phone !== null) {
                $user->forceFill(['phone_verified_at' => now()])->save();
            }

            return $user;
        });

        $this->pendingPhone->forget($this->request);

        return $user;
    }
}
