<?php

namespace App\Http\Controllers\Auth;

use App\Account\AccountOverviewUrl;
use App\Actions\Auth\PendingVerifiedRegistrationPhone;
use App\Actions\Auth\SendWhatsAppLoginCode;
use App\Actions\Auth\VerifyWhatsAppLoginCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendWhatsAppCodeRequest;
use App\Http\Requests\Auth\VerifyWhatsAppCodeRequest;
use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class WhatsAppLoginController extends Controller
{
    public function send(SendWhatsAppCodeRequest $request, SendWhatsAppLoginCode $send): JsonResponse
    {
        $phone = $this->phone((string) $request->validated('phone'));

        try {
            $send->execute($phone, $request->route('locale') === 'en' ? 'en' : 'ar');
        } catch (DomainException) {
            return response()->json([
                'error' => ['code' => 'whatsapp_unavailable'],
            ], 503)->header('Cache-Control', 'no-store, private');
        }

        return response()->json(['data' => ['sent' => true]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function verify(
        VerifyWhatsAppCodeRequest $request,
        VerifyWhatsAppLoginCode $verify,
        PendingVerifiedRegistrationPhone $pendingPhone,
        AccountOverviewUrl $accountOverviewUrl,
    ): JsonResponse {
        $phone = $this->phone((string) $request->validated('phone'));

        try {
            $result = $verify->execute($phone, (string) $request->validated('code'));
        } catch (DomainException) {
            throw ValidationException::withMessages([
                'code' => trans('auth_ui.login.phone_code_invalid'),
            ]);
        }

        $request->session()->regenerate();

        if ($result->needsRegistration()) {
            $pendingPhone->remember($request, $result->phone);
            $registerRoute = $request->route('locale') === 'en'
                ? route('localized.register', ['locale' => 'en'], absolute: false)
                : route('register', absolute: false);

            return response()->json(['data' => ['redirectUrl' => $registerRoute]])
                ->header('Cache-Control', 'no-store, private');
        }

        $pendingPhone->forget($request);
        Auth::login($result->user, remember: true);
        $request->session()->put('auth.identity_confirmed_at', now()->timestamp);
        $accountUrl = $accountOverviewUrl->for($result->user);
        $targetUrl = redirect()->intended($accountUrl)->getTargetUrl();
        $parts = parse_url($targetUrl);
        $redirectUrl = is_array($parts) && (! isset($parts['host']) || $parts['host'] === $request->getHost())
            ? ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '')
            : $accountUrl;

        return response()->json(['data' => ['redirectUrl' => $redirectUrl]])
            ->header('Cache-Control', 'no-store, private');
    }

    private function phone(string $candidate): E164Phone
    {
        try {
            return E164Phone::from($candidate);
        } catch (DomainException) {
            throw ValidationException::withMessages([
                'phone' => trans('auth_ui.login.phone_invalid'),
            ]);
        }
    }
}
