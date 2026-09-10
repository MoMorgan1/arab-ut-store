<?php

namespace App\Http\Controllers\Account;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\FulfillmentAttachment;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\PublicHandle\OrderHandle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderItemSquadImageController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 404);

        $order = OrderHandle::resolveForCustomer($user, (string) $request->route('order'));
        $item = OrderItem::query()
            ->where('public_id', (string) $request->route('orderItem'))
            ->where('order_id', $order->id)
            ->whereIn('service_type', ServiceType::manual())
            ->firstOrFail();
        $attachment = FulfillmentAttachment::query()
            ->where('order_item_id', $item->id)
            ->whereNull('cart_item_id')
            ->where('kind', 'squad_image')
            ->firstOrFail();
        $disk = Storage::disk($attachment->disk);

        if ($attachment->disk !== 'local' || ! $disk->exists($attachment->path)) {
            abort(404);
        }

        $extension = match ($attachment->mime_type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => abort(404),
        };

        return response()->stream(function () use ($disk, $attachment): void {
            $stream = $disk->readStream($attachment->path);

            if (! is_resource($stream)) {
                return;
            }

            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'inline; filename="squad-image.'.$extension.'"',
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
