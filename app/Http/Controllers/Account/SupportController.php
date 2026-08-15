<?php

namespace App\Http\Controllers\Account;

use App\Account\Presenters\AccountShell;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SupportController extends Controller
{
    public function __construct(private readonly AccountShell $shell) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $whatsAppUrl = $this->secureHttpsUrl(config('store.support.whatsapp_url'));
        $emailUrl = $this->emailUrl(config('store.support.email'));

        return Inertia::render('account/support', [
            ...$this->shell->for($user, app()->getLocale()),
            'support' => [
                'whatsappUrl' => $whatsAppUrl,
                'emailUrl' => $emailUrl,
                'orderNumber' => $this->ownedOrderNumber($request, $user),
                'available' => $whatsAppUrl !== null || $emailUrl !== null,
            ],
        ]);
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
