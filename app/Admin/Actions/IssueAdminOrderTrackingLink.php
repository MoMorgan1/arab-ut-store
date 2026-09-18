<?php

namespace App\Admin\Actions;

use App\Actions\Orders\IssueOrderTrackingLink;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\Order;
use App\Models\User;
use App\Support\PublicHandle\OrderHandle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Hands a member of staff the customer's own tracking link.
 *
 * The store has been able to mint this link since the tracking work landed and
 * has had no screen that shows it, so `admin-links.php` on the retired tracker
 * stayed the only way to give a customer a link they could open without
 * signing in - and that is what kept the tracker alive. This is the screen.
 *
 * The link is a capability: whoever holds it reads that order's status without
 * a session. So issuing one is audited like a reveal rather than treated as a
 * read, and it is the same link every time - `IssueOrderTrackingLink` returns
 * the live token and mints one only when there is none, so pressing the button
 * twice never invalidates a link already sent to a customer.
 *
 * It carries no credentials and no payment detail: the tracking page shows the
 * order's state and nothing a thief would want, which is why `orders.view` is
 * the permission and not `order_credentials.view`.
 */
final readonly class IssueAdminOrderTrackingLink
{
    public function __construct(
        private IssueOrderTrackingLink $links,
        private RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @return array{url: string, order_number: string}
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException<Order>
     */
    public function execute(User $actor, string $orderHandle, ?string $ipAddress): array
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::OrdersView->value)) {
            throw new AuthorizationException('Actor is not authorized to issue a tracking link.');
        }

        /** @var Order $order */
        $order = Order::query()
            ->where(OrderHandle::column($orderHandle), OrderHandle::value($orderHandle))
            ->firstOrFail();

        // One write: the link and the record of who asked for it. An audit row
        // without a link would accuse somebody of nothing, and a link without
        // one would be a capability nobody can account for.
        return DB::transaction(function () use ($actor, $order, $ipAddress): array {
            $url = $this->links->execute($order);

            $this->recordStaffAudit->execute(
                actor: $actor,
                subject: $order,
                event: new StaffAuditEvent(
                    action: 'orders.tracking_link_issued',
                    metadata: ['order_number' => (string) $order->order_number],
                    ipAddress: $ipAddress,
                ),
            );

            return ['url' => $url, 'order_number' => (string) $order->order_number];
        });
    }
}
