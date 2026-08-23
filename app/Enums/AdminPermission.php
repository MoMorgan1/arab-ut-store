<?php

namespace App\Enums;

enum AdminPermission: string
{
    case DashboardView = 'dashboard.view';
    case OrdersView = 'orders.view';
    case OrdersUpdate = 'orders.update';
    case OrdersCancel = 'orders.cancel';
    case OrdersRefund = 'orders.refund';
    case OrderCredentialsView = 'order_credentials.view';
    case CustomersView = 'customers.view';
    case CustomersUpdateStatus = 'customers.update_status';
    case CustomersUpdateContact = 'customers.update_contact';
    case PaymentsView = 'payments.view';
    case PaymentsRefund = 'payments.refund';
    case WalletView = 'wallet.view';
    case WalletAdjust = 'wallet.adjust';
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';
    case AuditView = 'audit.view';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';
}
