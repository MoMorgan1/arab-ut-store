<?php

namespace App\Actions\Reviews;

use App\Models\OrderItem;
use App\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ImportStoreReviews
{
    private const SOURCE_KEY = 'n8n';

    private const SALLA_ARCHIVE_SOURCE_KEY = 'salla-import';

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
     * @param  array<string, mixed>  $payload
     * @return array{count: int, ratings: array<int, int>}
     */
    public function executeArchive(array $payload, bool $apply): array
    {
        if (array_diff(array_keys($payload), ['schemaVersion', 'reviews']) !== []) {
            throw ValidationException::withMessages([
                'archive' => 'The archive contains unsupported fields.',
            ]);
        }

        $root = Validator::make($payload, [
            'schemaVersion' => ['required', 'integer', 'in:1'],
            'reviews' => ['required', 'array', 'min:1', 'max:5000'],
        ])->validate();
        $projected = [];
        $externalIds = [];
        $ratings = array_fill(1, 5, 0);

        foreach ($root['reviews'] as $index => $review) {
            if (! is_array($review)) {
                throw ValidationException::withMessages([
                    "reviews.{$index}" => 'Each review must be an object.',
                ]);
            }

            $safe = $this->projectArchiveReview($review, $index);

            if (isset($externalIds[$safe['external_id']])) {
                throw ValidationException::withMessages([
                    "reviews.{$index}.id" => 'The review identity is duplicated.',
                ]);
            }

            $externalIds[$safe['external_id']] = true;
            $ratings[$safe['rating']]++;
            $projected[] = $safe;
        }

        $summary = ['count' => count($projected), 'ratings' => $ratings];

        if (! $apply) {
            return $summary;
        }

        DB::transaction(function () use ($projected, $externalIds): void {
            foreach ($projected as $review) {
                Review::query()->updateOrCreate(
                    [
                        'source_key' => self::SALLA_ARCHIVE_SOURCE_KEY,
                        'external_id' => $review['external_id'],
                    ],
                    $review,
                );
            }

            Review::query()
                ->where('source_key', self::SALLA_ARCHIVE_SOURCE_KEY)
                ->whereNotIn('external_id', array_keys($externalIds))
                ->update(['is_visible' => false]);
        }, 3);

        return $summary;
    }

    /**
     * Project the existing n8n/Salla response without retaining its customer object.
     *
     * @param  array<string, mixed>  $payload
     * @return array{schemaVersion: 1, reviews: list<array<string, mixed>>}
     */
    public function projectSallaSource(array $payload): array
    {
        if (array_keys($payload) === ['reviews'] && is_array($payload['reviews'])) {
            return $this->projectNormalizedReviewSource($payload['reviews']);
        }

        if (array_keys($payload) !== ['data'] || ! is_array($payload['data'])) {
            throw ValidationException::withMessages([
                'source' => 'The Salla review source is malformed.',
            ]);
        }

        $reviews = [];

        foreach ($payload['data'] as $index => $input) {
            if (! is_array($input)) {
                throw ValidationException::withMessages([
                    "data.{$index}" => 'Each Salla review must be an object.',
                ]);
            }

            if (($input['is_published'] ?? null) !== true) {
                continue;
            }

            $rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT);

            if (! is_int($rating) || $rating < 1 || $rating > 5) {
                continue;
            }

            $id = $input['id'] ?? null;
            $content = $input['content'] ?? null;
            $publishedAt = $input['created_at'] ?? null;

            if ((! is_int($id) && ! is_string($id))
                || trim((string) $id) === ''
                || strlen((string) $id) > 180
                || ! is_string($content)
                || ! is_string($publishedAt)) {
                throw ValidationException::withMessages([
                    "data.{$index}" => 'The published Salla review is incomplete.',
                ]);
            }

            $body = trim(strip_tags($content));

            if ($body === '' || $this->containsPrivateContact($body)) {
                continue;
            }

            try {
                $date = CarbonImmutable::parse($publishedAt)->utc();
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    "data.{$index}.created_at" => 'The Salla review date is invalid.',
                ]);
            }

            $reviews[] = [
                'id' => 'salla:'.trim((string) $id),
                'rating' => $rating,
                'comment' => $body,
                'locale' => preg_match('/[\\x{0600}-\\x{06FF}]/u', $body) === 1 ? 'ar' : 'en',
                'public_name' => null,
                'published_at' => $date->format('Y-m-d\\TH:i:s\\Z'),
                'is_visible' => true,
            ];
        }

        if ($reviews === []) {
            throw ValidationException::withMessages([
                'source' => 'The Salla source contains no safe published ratings.',
            ]);
        }

        return ['schemaVersion' => 1, 'reviews' => $reviews];
    }

    /**
     * Project the storefront's existing safe review endpoint into the immutable archive shape.
     *
     * The endpoint may still contain display names or order-link metadata used by the old
     * refresh path. The historical archive intentionally discards those fields and retains
     * only anonymous public review content.
     *
     * @param  array<int, mixed>  $inputs
     * @return array{schemaVersion: 1, reviews: list<array<string, mixed>>}
     */
    private function projectNormalizedReviewSource(array $inputs): array
    {
        if ($inputs === [] || count($inputs) > 5000) {
            throw ValidationException::withMessages([
                'source' => 'The normalized review source size is invalid.',
            ]);
        }

        $reviews = [];

        foreach ($inputs as $index => $input) {
            if (! is_array($input)) {
                throw ValidationException::withMessages([
                    "reviews.{$index}" => 'Each normalized review must be an object.',
                ]);
            }

            if (($input['is_visible'] ?? null) !== true) {
                continue;
            }

            $rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT);
            $id = $input['id'] ?? null;
            $content = $input['comment'] ?? null;
            $publishedAt = $input['published_at'] ?? null;

            if (! is_int($rating)
                || $rating < 1
                || $rating > 5
                || (! is_int($id) && ! is_string($id))
                || trim((string) $id) === ''
                || strlen((string) $id) > 180
                || ! is_string($content)
                || ! is_string($publishedAt)) {
                throw ValidationException::withMessages([
                    "reviews.{$index}" => 'The normalized published review is incomplete.',
                ]);
            }

            $body = trim(strip_tags($content));

            if ($body === '' || $this->containsPrivateContact($body)) {
                continue;
            }

            try {
                $date = CarbonImmutable::parse($publishedAt)->utc();
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    "reviews.{$index}.published_at" => 'The normalized review date is invalid.',
                ]);
            }

            $locale = $input['locale'] ?? null;

            if (! is_string($locale) || ! in_array($locale, ['ar', 'en'], true)) {
                $locale = preg_match('/[\\x{0600}-\\x{06FF}]/u', $body) === 1 ? 'ar' : 'en';
            }

            $reviews[] = [
                'id' => 'salla:'.trim((string) $id),
                'rating' => $rating,
                'comment' => $body,
                'locale' => $locale,
                'public_name' => null,
                'published_at' => $date->format('Y-m-d\\TH:i:s\\Z'),
                'is_visible' => true,
            ];
        }

        if ($reviews === []) {
            throw ValidationException::withMessages([
                'source' => 'The normalized source contains no safe visible ratings.',
            ]);
        }

        return ['schemaVersion' => 1, 'reviews' => $reviews];
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

        if ($this->containsPrivateContact($body) || $this->containsPrivateContact($publicName)) {
            throw ValidationException::withMessages([
                "reviews.{$index}" => 'The public review fields contain private contact data.',
            ]);
        }

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

    private function containsPrivateContact(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            || preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value) === 1) {
            return true;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return is_string($digits) && strlen($digits) >= 9;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function projectArchiveReview(array $input, int $index): array
    {
        $allowed = [
            'id',
            'rating',
            'comment',
            'locale',
            'public_name',
            'published_at',
            'is_visible',
        ];

        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages([
                "reviews.{$index}" => 'The review contains unsupported fields.',
            ]);
        }

        $validated = Validator::make($input, [
            'id' => ['required', 'string', 'max:191'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'max:5000'],
            'locale' => ['required', 'string', 'in:ar,en'],
            'public_name' => ['present', 'nullable', 'string', 'max:80'],
            'published_at' => [
                'required',
                'string',
                'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})$/D',
            ],
            'is_visible' => ['required', 'accepted'],
        ])->validate();
        $body = trim(strip_tags($validated['comment']));
        $publicName = isset($validated['public_name'])
            ? trim(strip_tags((string) $validated['public_name']))
            : '';

        if ($body === ''
            || mb_strlen($body) > 2000
            || $this->containsPrivateContact($body)
            || $this->containsPrivateContact($publicName)) {
            throw ValidationException::withMessages([
                "reviews.{$index}" => 'The public review fields are invalid.',
            ]);
        }

        $locale = (string) $validated['locale'];
        $hashInput = [
            'body' => $body,
            'locale' => $locale,
            'name' => $publicName,
            'publishedAt' => $validated['published_at'],
            'rating' => $validated['rating'],
        ];

        return [
            'reviewer_name' => $publicName !== ''
                ? $publicName
                : trans('store.reviews.anonymous_customer'),
            'rating' => (int) $validated['rating'],
            'body_ar' => $locale === 'ar' ? $body : null,
            'body_en' => $locale === 'en' ? $body : null,
            'source' => self::SALLA_ARCHIVE_SOURCE_KEY,
            'source_key' => self::SALLA_ARCHIVE_SOURCE_KEY,
            'external_id' => (string) $validated['id'],
            'content_hash' => hash('sha256', json_encode($hashInput, JSON_THROW_ON_ERROR)),
            'order_item_id' => null,
            'is_visible' => true,
            'published_at' => CarbonImmutable::parse($validated['published_at'])->utc(),
        ];
    }
}
