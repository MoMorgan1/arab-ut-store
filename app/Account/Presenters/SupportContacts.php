<?php

namespace App\Account\Presenters;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

final class SupportContacts
{
    /**
     * @return array{
     *     whatsappUrl: string|null,
     *     emailUrl: string|null,
     *     orderNumber: string|null,
     *     available: bool
     * }
     */
    public function for(User $user, Request $request): array
    {
        $whatsAppUrl = $this->secureHttpsUrl(config('store.support.whatsapp_url'));
        $emailUrl = $this->emailUrl(config('store.support.email'));

        return [
            'whatsappUrl' => $whatsAppUrl,
            'emailUrl' => $emailUrl,
            'orderNumber' => $this->ownedOrderNumber($request, $user),
            'available' => $whatsAppUrl !== null || $emailUrl !== null,
        ];
    }

    private function ownedOrderNumber(Request $request, User $user): ?string
    {
        $publicId = $request->query('order');

        if (! is_string($publicId) || $publicId === '' || mb_strlen($publicId) > 64) {
            return null;
        }

        $number = Order::query()
            ->where('user_id', $user->getKey())
            ->where('public_id', $publicId)
            ->value('order_number');

        return is_string($number) ? $number : null;
    }

    private function secureHttpsUrl(mixed $configured): ?string
    {
        if (! is_string($configured)) {
            return null;
        }

        $url = trim($configured);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
                ? $url
                : null;
    }

    private function emailUrl(mixed $configured): ?string
    {
        if (! is_string($configured)) {
            return null;
        }

        $email = trim($configured);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? 'mailto:'.$email
            : null;
    }
}
