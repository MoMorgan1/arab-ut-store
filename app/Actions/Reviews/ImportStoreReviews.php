<?php

namespace App\Actions\Reviews;

use App\Models\OrderItem;
use App\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ImportStoreReviews
{
    private const SOURCE_KEY = 'n8n';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload): int
    {
        $root = Validator::make($payload, [
            'reviews' => ['present', 'array', 'max:500'],
        ])->validate();
        $projected = [];

        foreach ($root['reviews'] as $index => $review) {
            if (! is_array($review)) {
                throw ValidationException::withMessages(["reviews.{$index}" => 'Each review must be an object.']);
            }

            $projected[] = $this->project($review, $index);
        }

        return DB::transaction(function () use ($projected): int {
            $externalIds = [];

            foreach ($projected as $review) {
                $externalIds[] = $review['external_id'];
                Review::query()->updateOrCreate(
                    [
                        'source_key' => self::SOURCE_KEY,
                        'external_id' => $review['external_id'],
                    ],
                    $review,
                );
            }

            Review::query()
                ->where('source_key', self::SOURCE_KEY)
                ->when(
                    $externalIds !== [],
                    fn ($query) => $query->whereNotIn('external_id', $externalIds),
                )
                ->update(['is_visible' => false]);

            return count($projected);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function project(array $input, int $index): array
    {
        $allowed = [
            'id', 'rating', 'comment', 'locale', 'public_name',
            'order_item_public_id', 'published_at', 'is_visible',
            'phone', 'email', 'customer_name',
        ];
        $unknown = array_diff(array_keys($input), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                "reviews.{$index}" => 'The review contains unsupported fields.',
            ]);
        }

        $validated = Validator::make($input, [
            'id' => ['required', 'string', 'max:191'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'max:5000'],
            'locale' => ['sometimes', 'string', 'in:ar,en'],
            'public_name' => ['nullable', 'string', 'max:80'],
            'order_item_public_id' => ['nullable', 'string', 'max:26'],
            'published_at' => [
                'required',
                'string',
                'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})$/D',
            ],
            'is_visible' => ['required', 'boolean'],
            'phone' => ['sometimes', 'nullable'],
            'email' => ['sometimes', 'nullable'],
            'customer_name' => ['sometimes', 'nullable'],
        ])->validate();

        $body = trim(strip_tags($validated['comment']));

        if ($body === '' || mb_strlen($body) > 2000) {
            throw ValidationException::withMessages([
                "reviews.{$index}.comment" => 'The public review text is invalid.',
            ]);
        }

        $publicName = isset($validated['public_name'])
            ? trim(strip_tags((string) $validated['public_name']))
            : '';
        $locale = (string) ($validated['locale'] ?? 'ar');
        $orderItemId = isset($validated['order_item_public_id'])
            ? OrderItem::query()->where('public_id', $validated['order_item_public_id'])->value('id')
            : null;
        $hashInput = [
            'body' => $body,
            'locale' => $locale,
            'name' => $publicName,
            'orderItemId' => $orderItemId,
            'publishedAt' => $validated['published_at'],
            'rating' => $validated['rating'],
            'visible' => $validated['is_visible'],
        ];

        return [
            'reviewer_name' => $publicName !== ''
                ? $publicName
                : trans('store.reviews.anonymous_customer'),
            'rating' => (int) $validated['rating'],
            'body_ar' => $locale === 'ar' ? $body : null,
            'body_en' => $locale === 'en' ? $body : null,
            'source' => 'n8n',
            'source_key' => self::SOURCE_KEY,
            'external_id' => (string) $validated['id'],
            'content_hash' => hash('sha256', json_encode($hashInput, JSON_THROW_ON_ERROR)),
            'order_item_id' => is_int($orderItemId) ? $orderItemId : null,
            'is_visible' => (bool) $validated['is_visible'],
            'published_at' => CarbonImmutable::parse($validated['published_at'])->utc(),
        ];
    }
}
