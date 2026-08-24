<?php

namespace App\Imports\Salla;

use App\Enums\OrderStatus;

final class SallaStatusMapper
{
    /**
     * Explicit exact mapping table for Salla order and payment status strings.
     *
     * @var array<string, OrderStatus>
     */
    private const EXACT_MAP = [
        // Completed variants
        'تم التنفيذ' => OrderStatus::Completed,
        'انتهت' => OrderStatus::Completed,
        'انتهت - تم شحن الكوينز' => OrderStatus::Completed,
        'مكتمل' => OrderStatus::Completed,
        'completed' => OrderStatus::Completed,
        'fulfilled' => OrderStatus::Completed,

        // Received / Pending review variants
        'بإنتظار المراجعة' => OrderStatus::Received,
        'بانتظار المراجعة' => OrderStatus::Received,
        'انتظار المراجعة' => OrderStatus::Received,
        'قيد المراجعة' => OrderStatus::Received,
        'قيد التنفيذ' => OrderStatus::InProgress,
        'قيد الانتظار' => OrderStatus::Received,
        'جاري التجهيز' => OrderStatus::Received,
        'received' => OrderStatus::Received,

        // Refunded variants
        'مسترجع' => OrderStatus::Refunded,
        'مسترجعة' => OrderStatus::Refunded,
        'استرجاع' => OrderStatus::Refunded,
        'تم الاسترجاع' => OrderStatus::Refunded,
        'refunded' => OrderStatus::Refunded,
        'partial_refunded' => OrderStatus::Refunded,

        // Cancelled / Failed variants
        'ملغي' => OrderStatus::Cancelled,
        'ملغى' => OrderStatus::Cancelled,
        'تم الإلغاء' => OrderStatus::Cancelled,
        'تم الالغاء' => OrderStatus::Cancelled,
        'محذوف' => OrderStatus::Cancelled,
        'محذوفة' => OrderStatus::Cancelled,
        'فاشل' => OrderStatus::Cancelled,
        'فاشلة' => OrderStatus::Cancelled,
        'cancelled' => OrderStatus::Cancelled,
        'canceled' => OrderStatus::Cancelled,
        'failed' => OrderStatus::Cancelled,
    ];

    /**
     * Map Salla order and payment status to OrderStatus.
     *
     * @return array{status: OrderStatus, isUnrecognised: bool, originalStatus: string}
     */
    public static function map(?string $orderStatusRaw, ?string $paymentStatusRaw = null): array
    {
        $orderStatus = trim((string) $orderStatusRaw);
        $paymentStatus = trim((string) $paymentStatusRaw);
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $orderStatus) ?? $orderStatus);

        // 1. Check exact map on order status
        if ($normalized !== '' && isset(self::EXACT_MAP[$normalized])) {
            return [
                'status' => self::EXACT_MAP[$normalized],
                'isUnrecognised' => false,
                'originalStatus' => $orderStatus,
            ];
        }

        // 2. Pattern matching based on explicit owner rules
        if ($normalized !== '') {
            // Rule: مسترجع → Refunded
            if (str_contains($normalized, 'مسترجع') || str_contains($normalized, 'استرجاع') || str_contains($normalized, 'refund')) {
                return [
                    'status' => OrderStatus::Refunded,
                    'isUnrecognised' => false,
                    'originalStatus' => $orderStatus,
                ];
            }

            // Rule: ملغي, محذوف and every فاشلة… → Cancelled
            if (str_contains($normalized, 'ملغي')
                || str_contains($normalized, 'ملغى')
                || str_contains($normalized, 'محذوف')
                || str_contains($normalized, 'فاشل')
                || str_contains($normalized, 'cancel')
                || str_contains($normalized, 'fail')) {
                return [
                    'status' => OrderStatus::Cancelled,
                    'isUnrecognised' => false,
                    'originalStatus' => $orderStatus,
                ];
            }

            // Rule: بإنتظار المراجعة → Received
            if (str_contains($normalized, 'مراجعة') || str_contains($normalized, 'انتظار') || str_contains($normalized, 'pending')) {
                return [
                    'status' => OrderStatus::Received,
                    'isUnrecognised' => false,
                    'originalStatus' => $orderStatus,
                ];
            }

            // Rule: قيد التنفيذ… → InProgress. The export suffixes this with a
            // step ("جاري سحب الكوينز", "تم ارسال بيانات الحساب للمحترف"), so it
            // needs a contains-match, and it must run before the Completed rule
            // below or the suffix wins. These are live orders, not cancelled ones.
            if (str_contains($normalized, 'قيد التنفيذ')) {
                return [
                    'status' => OrderStatus::InProgress,
                    'isUnrecognised' => false,
                    'originalStatus' => $orderStatus,
                ];
            }

            // Rule: تم التنفيذ and every انتهت… → Completed
            if (str_contains($normalized, 'تم التنفيذ') || str_starts_with($normalized, 'انتهت') || str_contains($normalized, 'مكتمل') || str_contains($normalized, 'complete')) {
                return [
                    'status' => OrderStatus::Completed,
                    'isUnrecognised' => false,
                    'originalStatus' => $orderStatus,
                ];
            }
        }

        // Check payment status fallback if order status is empty
        $normPayment = mb_strtolower($paymentStatus);
        if ($normPayment === 'refunded' || $normPayment === 'partial_refunded') {
            return [
                'status' => OrderStatus::Refunded,
                'isUnrecognised' => false,
                'originalStatus' => $orderStatus ?: $paymentStatus,
            ];
        }

        // Unrecognised status → Cancelled and reported
        return [
            'status' => OrderStatus::Cancelled,
            'isUnrecognised' => true,
            'originalStatus' => $orderStatus,
        ];
    }
}
