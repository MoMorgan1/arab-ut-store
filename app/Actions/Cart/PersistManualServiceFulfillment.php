<?php

namespace App\Actions\Cart;

use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\FulfillmentAttachment;
use App\ValueObjects\Cart\ManualServiceCredentials;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class PersistManualServiceFulfillment
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function execute(
        CartItem $cartItem,
        ManualServiceCredentials $credentials,
        UploadedFile $squadImage,
    ): void {
        if (! $cartItem->exists) {
            throw new DomainException('The cart item must exist before fulfillment data can be stored.');
        }

        $image = $this->inspectImage($squadImage);
        $filename = (string) Str::ulid().'.'.$image['extension'];
        $path = Storage::disk('local')->putFileAs('fulfillment/squad-images', $squadImage, $filename);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The squad image could not be stored.');
        }

        try {
            DB::transaction(function () use ($cartItem, $credentials, $image, $path): void {
                $secret = new CartItemSecret(['cart_item_id' => $cartItem->id]);
                $secret->forceFill([
                    'masked_summary' => $credentials->maskedSummary(),
                    'retained_until' => null,
                    'deleted_at' => null,
                ]);
                $secret->encrypted_payload = $credentials->payload();
                $secret->save();

                FulfillmentAttachment::create([
                    'cart_item_id' => $cartItem->id,
                    'order_item_id' => null,
                    'kind' => 'squad_image',
                    'disk' => 'local',
                    'path' => $path,
                    'mime_type' => $image['mime'],
                    'bytes' => $image['bytes'],
                    'sha256' => $image['sha256'],
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    /** @return array{extension: string, mime: string, bytes: int, sha256: string} */
    private function inspectImage(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $bytes = $file->getSize();

        if (! $file->isValid()
            || ! is_string($path)
            || $path === ''
            || ! is_int($bytes)
            || $bytes <= 0
            || $bytes > self::MAX_BYTES) {
            throw new DomainException('The squad image must be a valid file up to 5MB.');
        }

        $details = @getimagesize($path);
        $type = is_array($details) ? $details[2] : null;
        $format = match ($type) {
            IMAGETYPE_JPEG => ['extension' => 'jpg', 'mime' => 'image/jpeg'],
            IMAGETYPE_PNG => ['extension' => 'png', 'mime' => 'image/png'],
            IMAGETYPE_WEBP => ['extension' => 'webp', 'mime' => 'image/webp'],
            default => throw new DomainException('The squad image must be JPG, PNG, or WebP.'),
        };
        $sha256 = hash_file('sha256', $path);

        if (! is_string($sha256)) {
            throw new DomainException('The squad image could not be verified.');
        }

        return [
            'extension' => $format['extension'],
            'mime' => $format['mime'],
            'bytes' => $bytes,
            'sha256' => $sha256,
        ];
    }
}
