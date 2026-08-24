<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Exceptions\AdminCategoryVisibilityConflict;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Takes a whole category off the storefront, or puts it back.
 *
 * This writes `admin_hidden_at`, never `is_visible`. `is_visible` belongs to
 * the catalog snapshot, which rewrites it on every run - so an admin edit to
 * it would look like it worked and then silently revert on the next sync.
 * Hiding a category hides every product under it because storefront visibility
 * cascades through category membership.
 */
final class SetAdminCategoryStorefrontVisibility
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        string $categoryPublicId,
        bool $hidden,
        bool $expectedHidden,
        ?string $ipAddress = null,
    ): Category {
        if (! $actor->is_active || ! $actor->can(AdminPermission::CatalogManage->value)) {
            throw new AuthorizationException('This action requires catalog.manage permission.');
        }

        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may change storefront visibility.');
        }

        return DB::transaction(function () use ($actor, $categoryPublicId, $hidden, $expectedHidden, $ipAddress): Category {
            /** @var Category $category */
            $category = Category::query()
                ->where('public_id', $categoryPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            $previouslyHidden = $category->admin_hidden_at !== null;

            if ($previouslyHidden !== $expectedHidden) {
                throw new AdminCategoryVisibilityConflict(
                    (string) $category->public_id,
                    $previouslyHidden,
                );
            }

            if ($previouslyHidden === $hidden) {
                return $category;
            }

            // Deliberately product-level only: applyStorefrontVisible() also checks the
            // category, which would report 0 when restoring a category that is still
            // hidden. This number answers "how many products does this change move".
            $visibleProductCount = Product::query()
                ->where('category_id', $category->id)
                ->where('is_visible', true)
                ->whereNull('archived_at')
                ->whereNull('admin_hidden_at')
                ->count();

            $category->admin_hidden_at = $hidden ? now() : null;
            $category->save();

            $this->recordStaffAudit->execute(
                $actor,
                $category,
                new StaffAuditEvent(
                    action: $hidden ? 'categories.hidden_from_store' : 'categories.restored_to_store',
                    metadata: [
                        'previous_hidden' => $previouslyHidden,
                        'new_hidden' => $hidden,
                        'visible_product_count' => $visibleProductCount,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $category;
        });
    }
}
