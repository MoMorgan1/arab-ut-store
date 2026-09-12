<?php

return [
    // Written into order_status_history when an order ends, so a customer who
    // sees "cancelled" is also told where their money went. The status alone
    // cannot carry this: a cancelled order and a refunded one read the same.
    'closed' => [
        'refund_to_card' => 'The order was cancelled and :amount SAR was returned to your payment method. Banks can take up to 14 business days to show it.',
        'refund_to_wallet' => 'The order was cancelled and :amount SAR went back to your wallet, ready to use straight away.',
        'refund_split' => 'The order was cancelled: :card SAR was returned to your payment method and :wallet SAR went back to your wallet. Banks can take up to 14 business days to show the card amount.',
        'checkout_expired' => 'The payment window closed, so the order was cancelled automatically. You were not charged, and any balance you used went back to your wallet.',
        'payment_cancelled' => 'The payment was cancelled, so the order was too. You were not charged, and any balance you used went back to your wallet.',
        'customer_cancelled' => 'You cancelled the order before paying. You were not charged, and any balance you used went back to your wallet.',
    ],
    // Shown to the customer on the order page when an order stops.
    // The admin picks one of these when moving an order to "waiting for customer";
    // the text is copied into the order history so later wording changes do not
    // rewrite what a customer was already told.
    'hold_reasons' => [
        'backup_codes' => 'The backup codes on the account are wrong or already used. Create new codes from the security settings of your EA account, then update the order details.',
        'credentials' => 'We could not sign in with the email or password you sent. Check them, then update the order details form.',
        'platform' => 'Your account platform is not the platform selected on the order. Message us so we can correct it before we start.',
        'market_locked' => 'EA has locked the transfer market on your account. Play at least 3 matches a day until it opens, or give us another account. Your money is held safely and is not lost.',
        'insufficient_coins' => 'Your coin balance is under 1,500 coins, the minimum we need to deliver by buying players. Top it up, then let us know.',
        'active_session' => 'The account is still signed in to the game or the Companion app. Sign out everywhere and close the game fully, then let us know.',
        'ea_servers' => 'EA servers refused the sign-in for now. We are retrying and will update you as soon as it works.',
        'no_club' => 'There is no Ultimate Team club on the account. Open the game, create your club, then let us know.',
        'transfer_list_full' => 'Your transfer list is full and has no room for the delivery. Clear it, then let us know.',
        'captcha' => 'EA is asking for a captcha on the account. Open the Web App, solve it, then let us know.',
        'unassigned' => 'There are unassigned items on the account blocking the delivery. Open them or move them to your club, then let us know.',
        'account_banned' => 'We could not sign in to the account, and EA may have blocked it. Press resume to try again, or check your details and update them.',
        'store_stock' => 'The requested amount is not in stock with us right now. We are restocking and will resume your order shortly.',
        'connection' => 'The connection to the game servers dropped during delivery. We are retrying and will update you.',
        'no_player' => 'We could not find a suitable player on the market to complete the delivery right now. We are watching the market and will resume shortly.',
        'maintenance' => 'The game servers are under EA maintenance. We resume automatically once it ends.',
        'paused' => 'Your order is paused for now and we will resume shortly. Message us any time for details.',
        'below_minimum' => 'The remaining amount is below the minimum transfer. We are finishing the order.',
    ],
    'tracking_states' => [
        'processing' => [
            'headline' => 'Processing',
            'subline' => 'Your order is being processed',
        ],
        'cooldown_tempban' => [
            'headline' => 'Processing',
            'subline' => 'We are currently working on your order. You can log in and play normally; once you exit the game, delivery will resume automatically.',
        ],
        'cooldown_listing' => [
            'headline' => 'Processing',
            'subline' => 'The transfer market is in a temporary cooldown from EA. We are waiting for it to lift and delivery will resume automatically — you can play normally.',
        ],
        'cooldown_daily_limit' => [
            'headline' => 'Processing',
            'subline' => 'You have reached the daily limit. For account safety, please wait 36 hours then refresh the order. You can play normally.',
        ],
        'logging_in' => [
            'headline' => 'Signing In',
            'subline' => 'Signing into your account, no action needed right now...',
        ],
        'preparing' => [
            'headline' => 'Preparing Transfer',
            'subline' => 'Preparing the transfer on the market...',
        ],
        'transferring' => [
            'headline' => 'Transferring Coins',
            'subline' => 'Transferring coins to your account',
        ],
        'transferring_part_done' => [
            'headline' => 'Transferring Coins',
            'subline' => 'Part of the coins has been transferred, transferring the rest...',
        ],
        'finishing' => [
            'headline' => 'Finishing Order',
            'subline' => 'Delivery complete, finishing up the order. Please wait for player listings to clear before logging in.',
        ],
        'completed' => [
            'headline' => 'Completed',
            'subline' => 'Order completed successfully.',
        ],
        'stopped' => [
            'headline' => 'Temporarily Paused',
            'subline' => 'The order has been paused',
        ],
        'needs_review' => [
            'headline' => 'Action Required',
            'subline' => 'Please check the details below',
        ],
        'cancelled' => [
            'headline' => 'Order cancelled',
            'subline' => 'This order was cancelled',
        ],
        'refunded' => [
            'headline' => 'Refunded',
            'subline' => 'The amount was returned to your wallet',
        ],
        'not_reported' => [
            'headline' => 'Queued',
            'subline' => 'Waiting to start',
        ],
    ],
    'challenge_states' => [
        'queued' => 'Queued',
        'waiting_previous_solve' => 'Waiting for previous challenge',
        'started' => 'Started',
        'fetching_challenge' => 'Fetching challenge info',
        'fetching_squads' => 'Fetching squads',
        'solving' => 'Solving squad',
        'done' => 'Completed',
        'sign_in_failed' => 'Sign-in failed',
        'session_expired' => 'Session expired',
        'failed' => 'Failed',
        'unknown' => 'Unknown',
    ],
];
