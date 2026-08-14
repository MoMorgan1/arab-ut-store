<?php

namespace App\Http\Controllers\Store;

use App\Actions\Checkout\SendCheckoutPhoneCode;
use App\Actions\Checkout\VerifyCheckoutPhoneCode;
use App\Exceptions\Checkout\CheckoutPhoneUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendWhatsAppCodeRequest;
use App\Http\Requests\Auth\VerifyWhatsAppCodeRequest;
use App\Models\User;
use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class CheckoutPhoneVerificationController extends Controller
{
    public function send(SendWhatsAppCodeRequest $request, SendCheckoutPhoneCode $send): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        try {
            $send->execute($user, $this->phone((string) $request->validated('phone')), $this->locale($request));
        } catch (CheckoutPhoneUnavailable) {
            return $this->error('phone_unavailable', 422);
        } catch (DomainException) {
            return $this->error('whatsapp_unavailable', 503);
        }

        return response()->json(['data' => ['sent' => true]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function verify(VerifyWhatsAppCodeRequest $request, VerifyCheckoutPhoneCode $verify): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        try {
            $verify->execute(
                $user,
                $this->phone((string) $request->validated('phone')),
                (string) $request->validated('code'),
            );
        } catch (CheckoutPhoneUnavailable) {
            return $this->error('phone_unavailable', 422);
        } catch (DomainException) {
            return $this->error('phone_code_invalid', 422);
        }

        return response()->json(['data' => ['verified' => true]])
            ->header('Cache-Control', 'no-store, private');
    }

    private function phone(string $candidate): E164Phone
    {
        try {
            return E164Phone::from($candidate);
        } catch (DomainException) {
            throw ValidationException::withMessages(['phone' => trans('auth_ui.login.phone_invalid')]);
        }
    }

    private function locale(SendWhatsAppCodeRequest $request): string
    {
        return $request->route('locale') === 'en' ? 'en' : 'ar';
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code]], $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
