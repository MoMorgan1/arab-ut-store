<?php

namespace Tests\Unit\Salla;

use App\Enums\OrderStatus;
use App\Imports\Salla\SallaStatusMapper;
use PHPUnit\Framework\TestCase;

final class SallaStatusMapperTest extends TestCase
{
    public function test_maps_completed_statuses(): void
    {
        $result1 = SallaStatusMapper::map('تم التنفيذ');
        $this->assertSame(OrderStatus::Completed, $result1['status']);
        $this->assertFalse($result1['isUnrecognised']);

        $result2 = SallaStatusMapper::map('انتهت - تم شحن الكوينز');
        $this->assertSame(OrderStatus::Completed, $result2['status']);
        $this->assertFalse($result2['isUnrecognised']);

        $result3 = SallaStatusMapper::map('انتهت');
        $this->assertSame(OrderStatus::Completed, $result3['status']);
        $this->assertFalse($result3['isUnrecognised']);
    }

    public function test_maps_received_and_review_statuses(): void
    {
        $result = SallaStatusMapper::map('بإنتظار المراجعة');
        $this->assertSame(OrderStatus::Received, $result['status']);
        $this->assertFalse($result['isUnrecognised']);

        $result2 = SallaStatusMapper::map('بانتظار المراجعة');
        $this->assertSame(OrderStatus::Received, $result2['status']);
        $this->assertFalse($result2['isUnrecognised']);
    }

    public function test_maps_refunded_statuses(): void
    {
        $result = SallaStatusMapper::map('مسترجع');
        $this->assertSame(OrderStatus::Refunded, $result['status']);
        $this->assertFalse($result['isUnrecognised']);

        $result2 = SallaStatusMapper::map('تم الاسترجاع');
        $this->assertSame(OrderStatus::Refunded, $result2['status']);
        $this->assertFalse($result2['isUnrecognised']);
    }

    public function test_maps_cancelled_and_failed_statuses(): void
    {
        $result1 = SallaStatusMapper::map('ملغي');
        $this->assertSame(OrderStatus::Cancelled, $result1['status']);
        $this->assertFalse($result1['isUnrecognised']);

        $result2 = SallaStatusMapper::map('محذوف');
        $this->assertSame(OrderStatus::Cancelled, $result2['status']);
        $this->assertFalse($result2['isUnrecognised']);

        $result3 = SallaStatusMapper::map('فاشلة - رصيد غير كافي');
        $this->assertSame(OrderStatus::Cancelled, $result3['status']);
        $this->assertFalse($result3['isUnrecognised']);

        $result4 = SallaStatusMapper::map('فاشلة');
        $this->assertSame(OrderStatus::Cancelled, $result4['status']);
        $this->assertFalse($result4['isUnrecognised']);
    }

    public function test_unrecognised_status_falls_back_to_cancelled_and_is_flagged(): void
    {
        $result = SallaStatusMapper::map('حالة غير معروفة إطلاقاً');
        $this->assertSame(OrderStatus::Cancelled, $result['status']);
        $this->assertTrue($result['isUnrecognised']);
        $this->assertSame('حالة غير معروفة إطلاقاً', $result['originalStatus']);
    }
}
