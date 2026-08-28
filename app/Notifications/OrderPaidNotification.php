<?php

namespace App\Notifications;

use App\Enums\ServiceType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Payments\PaymentMethodLabel;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * The receipt a customer gets once an order is actually paid for.
 *
 * Queued and dispatched after commit: a slow or refusing mail server must
 * never be able to fail a checkout that has already taken the money.
 *
 * The view is handed finished strings rather than models. Formatting money and
 * resolving image paths are decisions with rules attached, and a Blade template
 * is the wrong place to keep them.
 */
final class OrderPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * What a mail client will actually draw. Outlook, Yahoo and older Apple
     * Mail render no WebP at all, and image proxies that do re-encode it
     * flatten its transparency onto black - which is how the coin reached a
     * customer as a black tile. MirrorCatalogMedia accepts image/webp, so a
     * catalogue sync can put one of these in front of a receipt at any time;
     * a neutral placeholder beats a broken image or a black square.
     *
     * @var list<string>
     */
    private const MAIL_SAFE_FORMATS = ['png', 'jpg', 'jpeg', 'gif'];

    public function __construct(private readonly Order $order)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing(['items.productVariant.product.media', 'payments']);
        $locale = $order->locale === 'en' ? 'en' : 'ar';
        $orderUrl = rtrim((string) config('app.url'), '/')
            .($locale === 'en' ? '/en' : '')
            .'/my-account/orders/'.$order->public_id;

        $this->locale($locale);

        return (new MailMessage)
            ->subject(trans('mail.order_paid_subject', ['number' => $order->order_number], $locale))
            ->markdown('mail.order-paid', [
                'discount' => (int) $order->discount_halalah > 0
                    ? $this->money((int) $order->discount_halalah, $locale)
                    : null,
                'items' => $order->items->map(fn (OrderItem $item): array => [
                    'imageUrl' => $this->itemImageUrl($item),
                    'name' => (string) $item->getAttribute($locale === 'en' ? 'name_en' : 'name_ar'),
                    'quantity' => (int) $item->quantity,
                    'total' => $this->money((int) $item->total_halalah, $locale),
                ])->all(),
                'locale' => $locale,
                'number' => (string) $order->order_number,
                'orderUrl' => $orderUrl,
                'paymentMethod' => $this->paymentMethod($order, $locale),
                'placedAt' => $this->placedAt($order),
                'subtotal' => $this->money((int) $order->subtotal_halalah, $locale),
                'total' => $this->money((int) $order->total_halalah, $locale),
                'wallet' => (int) $order->wallet_halalah > 0
                    ? $this->money((int) $order->wallet_halalah, $locale)
                    : null,
            ]);
    }

    /** Halalah are integers everywhere; they must not become floats on the way to a customer. */
    private function money(int $halalah, string $locale): string
    {
        $amount = intdiv($halalah, 100).'.'.str_pad((string) ($halalah % 100), 2, '0', STR_PAD_LEFT);

        return $locale === 'en' ? "SAR {$amount}" : "{$amount} ر.س.";
    }

    private function paymentMethod(Order $order, string $locale): ?string
    {
        $method = PaymentMethodLabel::for($order->payments->sortByDesc('id')->first());

        return $method === null ? null : (string) trans("payments.method_{$method}", [], $locale);
    }

    private function placedAt(Order $order): string
    {
        $placed = $order->placed_at ?? $order->created_at;

        return $placed instanceof CarbonInterface ? $placed->format('d/m/Y') : '';
    }

    /**
     * Resolved exactly as the storefront does, including the path checks that
     * keep a malformed media row from becoming a URL.
     */
    private function itemImageUrl(OrderItem $item): ?string
    {
        if ($item->service_type === ServiceType::Coins) {
            // PNG rather than the storefront's WebP: image proxies flatten
            // WebP alpha onto black, which put a black tile behind the coin.
            return rtrim((string) config('app.url'), '/').'/images/mail/ut-coin-mail.png';
        }

        $variant = $item->productVariant;

        if (! $variant instanceof ProductVariant || ! $variant->product instanceof Product) {
            return null;
        }

        $media = $variant->product->media->first();

        if (! $media instanceof ProductMedia || $media->disk !== 'public') {
            return null;
        }

        $path = (string) $media->path;

        if ($path === '' || str_contains($path, '..')
            || preg_match('/\A[A-Za-z0-9_\/.\-]+\z/D', $path) !== 1) {
            return null;
        }

        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::MAIL_SAFE_FORMATS, true)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
