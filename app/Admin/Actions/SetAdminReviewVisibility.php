<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Exceptions\AdminReviewVisibilityConflict;
use App\Models\Review;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes a review off the storefront, or puts it back.
 *
 * The storefront reader also filters on `rating >= 4`, so a low rating is
 * already invisible; showing one here would write a state the store would
 * ignore and leave staff believing a one-star review is live. That is refused
 * rather than silently accepted.
 */
final class SetAdminReviewVisibility
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        string $reviewPublicId,
        bool $visible,
        bool $expectedVisible,
        ?string $ipAddress = null,
    ): Review {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        return DB::transaction(function () use ($actor, $reviewPublicId, $visible, $expectedVisible, $ipAddress): Review {
            /** @var Review $review */
            $review = Review::query()
                ->where('public_id', $reviewPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            $previouslyVisible = (bool) $review->is_visible;

            if ($previouslyVisible !== $expectedVisible) {
                throw new AdminReviewVisibilityConflict(
                    (string) $review->public_id,
                    $previouslyVisible,
                );
            }

            if ($visible && $review->rating < 4) {
                throw ValidationException::withMessages([
                    'visible' => ['A review below four stars cannot be shown in the store.'],
                ]);
            }

            if ($previouslyVisible === $visible) {
                return $review;
            }

            $review->is_visible = $visible;

            // A review that was never published has no date to sort by, and the
            // storefront reader skips rows with a null published_at entirely.
            if ($visible && $review->published_at === null) {
                $review->published_at = now();
            }

            $review->save();

            $this->recordStaffAudit->execute(
                $actor,
                $review,
                new StaffAuditEvent(
                    action: 'reviews.visibility_changed',
                    metadata: [
                        'previous_visible' => $previouslyVisible,
                        'new_visible' => $visible,
                        'rating' => (int) $review->rating,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $review;
        });
    }
}
