<?php

namespace App\Http\Controllers\Account;

use App\Account\Actions\ConfirmPhoneChange;
use App\Account\Actions\RequestPhoneChange;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ProfilePhoneController extends Controller
{
    public function store(Request $request, RequestPhoneChange $action): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20', 'regex:/\A\+[1-9][0-9]{7,14}\z/D'],
            'current_password' => ['nullable', 'string'],
        ]);

        try {
            $phone = E164Phone::from($validated['phone']);
        } catch (DomainException) {
            throw ValidationException::withMessages([
                'phone' => trans('validation.regex', ['attribute' => 'phone']),
            ]);
        }

        try {
            $action->execute(
                $user,
                $request,
                $phone,
                $validated['current_password'] ?? null,
                app()->getLocale(),
            );
        } catch (DomainException) {
            if (! $request->expectsJson()) {
                throw ValidationException::withMessages([
                    'phone' => trans('account.errors.unexpected'),
                ]);
            }

            return response()->json(['error' => ['code' => 'phone_unavailable']], 503)
                ->header('Cache-Control', 'no-store, private');
        }

        return $request->expectsJson()
            ? response()->json(['data' => ['sent' => true]])
                ->header('Cache-Control', 'no-store, private')
            : redirect()->to($this->profileUrl());
    }

    public function confirm(Request $request, ConfirmPhoneChange $action): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/D'],
        ]);

        $action->execute($user, $validated['code']);

        return $request->expectsJson()
            ? response()->json(['data' => ['verified' => true]])
                ->header('Cache-Control', 'no-store, private')
            : redirect()->to($this->profileUrl());
    }

    private function profileUrl(): string
    {
        return route(
            app()->getLocale() === 'en' ? 'localized.account.profile.show' : 'account.profile.show',
            absolute: false,
        );
    }
}
