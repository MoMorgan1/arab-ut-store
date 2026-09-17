<?php

namespace App\Enums;

enum AdminPermission: string
{
    case DashboardView = 'dashboard.view';
    case OrdersView = 'orders.view';
    case OrdersCreate = 'orders.create';
    case OrdersUpdate = 'orders.update';
    case OrdersCancel = 'orders.cancel';
    case OrdersRefund = 'orders.refund';
    case OrderCredentialsView = 'order_credentials.view';

    /**
     * The fulfillment queue: what a supplier still owes, and how stale the
     * last reading of it is. Staff hold this one.
     */
    case FulfillmentView = 'fulfillment.view';

    /**
     * What a shipment actually cost us. Admin only, so the queue is one screen
     * with two views rather than two screens (owner decision, 2026-09-17).
     * Withheld by the query rather than hidden by the browser: see
     * `ListAdminFulfillment::paginate()`.
     */
    case FulfillmentViewCost = 'fulfillment.view_cost';

    /**
     * Re-sending an item to a supplier. Admin only, and deliberately narrower
     * than `FulfillmentView`: the action spends money at a supplier, the actor
     * would not be allowed to see the amount, and composing the request
     * decrypts the customer's EA account.
     */
    case FulfillmentAct = 'fulfillment.act';
    case CustomersView = 'customers.view';
    case CustomersUpdateStatus = 'customers.update_status';
    case CustomersUpdateContact = 'customers.update_contact';
    case PaymentsView = 'payments.view';
    case PaymentsRefund = 'payments.refund';
    case WalletView = 'wallet.view';
    case WalletAdjust = 'wallet.adjust';
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';
    case LoyaltyView = 'loyalty.view';
    case LoyaltyManage = 'loyalty.manage';
    case AuditView = 'audit.view';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';
    case ChatView = 'chat.view';
    case ChatReply = 'chat.reply';
    case MarketingView = 'marketing.view';
    case MarketingManage = 'marketing.manage';
}
