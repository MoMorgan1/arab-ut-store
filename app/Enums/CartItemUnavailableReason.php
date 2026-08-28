<?php

namespace App\Enums;

/**
 * Why a cart item cannot be priced at all, as opposed to merely costing a
 * different amount than it did. The storefront renders these; keep the set
 * closed so a new reason is a deliberate decision rather than a stray string.
 */
enum CartItemUnavailableReason: string
{
    case VariantInactive = 'variant_inactive';

    case ProductHidden = 'product_hidden';

    case TierRemoved = 'tier_removed';

    case ScheduleRouteRemoved = 'schedule_route_removed';

    case ConfigurationInvalid = 'configuration_invalid';
}
