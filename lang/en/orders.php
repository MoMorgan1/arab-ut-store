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
        'backup_codes' => 'The backup codes on the account are wrong or already used. Create new codes from the security settings of your EA account, then update them with the edit details button.',
        'credentials' => 'We could not sign in with the email or password you sent. Check them, then update them with the edit details button.',
        'platform' => 'The account platform is not the one selected on the order. Update the email to an account on the same platform with the edit details button, or message us if the platform on the order is the wrong one - each platform is priced differently.',
        'market_locked' => 'EA has locked the transfer market on your account. Play at least 3 matches a day until it opens, then press resume. Your money is held safely and is not lost.',
        'insufficient_coins' => 'Your coin balance is under 1,500 coins, the minimum we need to deliver by buying players. Top it up, then press resume.',
        'active_session' => 'The account is still signed in to the game or the Companion app. Sign out everywhere, close the game fully, then press resume.',
        'ea_servers' => 'EA servers refused the sign-in for now. We retry automatically; press resume to try it now.',
        'no_club' => 'There is no Ultimate Team club on the account. Open the game, create your club, then press resume.',
        'transfer_list_full' => 'Your transfer list is full and has no room for the delivery. Clear it, then press resume.',
        'captcha' => 'EA is asking for a captcha on the account. Open the Web App, solve it, then press resume.',
        'unassigned' => 'There are unassigned items on the account blocking the delivery. Open them or move them to your club, then press resume.',
        'account_banned' => 'We could not sign in to the account, and EA may have blocked it. Press resume to try again, or check your details and update them.',
        'store_stock' => 'The requested amount is not in stock with us right now. We are restocking and resume automatically; press resume to try it now.',
        'connection' => 'The connection to the game servers dropped during delivery. We retry automatically; press resume to try it now.',
        'no_player' => 'We could not find a suitable player on the market to complete the delivery right now. We are watching the market and resume automatically; press resume to try it now.',
        'maintenance' => 'The game servers are under EA maintenance. We resume automatically once it ends; press resume to try it now.',
        'paused' => 'Your order is paused for now and we resume automatically. Press resume to try it now, or message us for details.',
        'two_factor_off' => 'Two-factor is off on the account. Turn it on in your EA security settings, then press resume.',
        'email_confirm' => 'EA wants the email address confirmed. Confirm it from EA\'s message and we carry on automatically.',
        'web_app_locked' => 'The Web App has never been opened on this account. Open it once in a browser and we carry on automatically.',
        'below_minimum' => 'The remaining amount is below the minimum transfer. We are finishing the order; press resume to try it now.',
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
            'subline' => 'Your coins have all been delivered

1. You can open the game on :console right away and enjoy
2. If you want to use the app, wait 30 minutes.

Congratulations on the squad!',
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
            'subline' => 'This order was refunded',
        ],
        'not_reported' => [
            // The tracker has no 'nothing reported yet' screen: an order it knows
            // nothing about still reads as being worked on, which is true.
            'headline' => 'Processing',
            'subline' => 'Your order is being processed',
        ],
    ],
    'challenge_help' => [
        'queued' => [
            'title' => 'Queued',
            'desc' => 'Your order is in the queue and starts shortly.',
            'action' => 'Nothing is needed from you.',
        ],
        'waiting_previous_solve' => [
            'title' => 'Waiting',
            'desc' => 'Another challenge on your account is being solved right now.',
            'action' => 'This one starts as soon as the current challenge finishes.',
        ],
        'started' => [
            'title' => 'Solving',
            'desc' => 'Reading the challenge and preparing the right squads.',
            'action' => 'Please wait, and do not open the game right now.',
        ],
        'fetching_challenge' => [
            'title' => 'Reading the challenge',
            'desc' => 'Reading what the challenge needs before building a squad.',
            'action' => 'Please wait, and do not open the game right now.',
        ],
        'fetching_squads' => [
            'title' => 'Fetching squads',
            'desc' => 'Preparing the squads that fit the challenge.',
            'action' => 'Please wait, and do not open the game right now.',
        ],
        'solving' => [
            'title' => 'Submitting the squad',
            'desc' => 'Buying the players and submitting the squad in the game.',
            'action' => 'Please wait; this can take a few minutes.',
        ],
        'done' => [
            'title' => 'Complete',
            'desc' => 'The challenge was solved successfully.',
            'action' => 'Nothing is needed from you.',
        ],
        'cooldown' => [
            'title' => 'Safety cooldown',
            'desc' => 'Paused as a safety measure to avoid an EA transfer market ban.',
            'action' => 'It resumes on its own once the cooldown ends. You can play normally.',
        ],
        'reconnecting' => [
            'title' => 'Reconnecting',
            'desc' => 'A brief connection problem; we are retrying.',
            'action' => 'Nothing is needed from you.',
        ],
        'sign_in_failed' => [
            'title' => 'Wrong details',
            'desc' => 'The email, the password or the backup code is not right.',
            'action' => 'Update them from "Update order details", then try again.',
        ],
        'session_expired' => [
            'title' => 'Session expired',
            'desc' => 'The session with the EA account has expired.',
            'action' => 'Press "Try again" to renew the connection.',
        ],
        'failed' => [
            'title' => 'Could not finish the challenge',
            'desc' => 'We could not complete this challenge on the last attempt.',
            'action' => 'Press "Try again", and message us if it keeps happening.',
        ],
        'unknown' => [
            'title' => 'No details available',
            'desc' => 'There is no detailed description for this state yet.',
            'action' => 'If it needs you, a button or a message appears underneath.',
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
        'cooldown' => 'Safety cooldown',
        'reconnecting' => 'Reconnecting',
        'sign_in_failed' => 'Sign-in failed',
        'session_expired' => 'Session expired',
        'failed' => 'Failed',
        'unknown' => 'Unknown',
    ],
    // One label per raw supplier status, keyed identically in ar and en. Wording
    // follows the tracker's SBC_STATUS_MAP except where it names plumbing
    // (proxy, HTTP codes, our own click) — those are rewritten for the customer.
    'challenge_statuses' => [
        // Progress
        'entered' => 'Waiting',
        'waitingForOtherSolve' => 'Waiting for previous challenge',
        'started' => 'Started',
        'fetchSBCInfo' => 'Fetching challenge info',
        'fetchChallengeInfo' => 'Fetching squads',
        'solvingChallenge' => 'Solving squad',
        'finished' => 'Completed',
        // Auth / session errors
        'sessionExpired' => 'Session expired',
        'needEmailConfirm' => 'Email confirmation required',
        'LoginFailed495' => 'Could not sign in',
        'LoginFailed401' => 'Could not sign in',
        'LoginFailedDeviceBan' => 'Device banned',
        'LoginError' => 'Sign-in error',
        'LoginFailed' => 'Sign-in failed',
        'WrongUserPass' => 'Wrong email or password',
        '2FADisabled' => 'Two-factor verification disabled',
        'No2FA' => 'Two-factor verification disabled',
        'WrongBA' => 'Wrong backup code',
        'loginLoop' => 'Sign-in problem',
        'loginFailed' => 'Sign-in failed',
        // Proxy / connection errors
        'FailProxyConn' => 'Temporary connection problem',
        'FailedProxyConnectionError' => 'Temporary connection problem',
        'FailProxy' => 'Temporary connection problem',
        // Account / setup errors
        'failedNoClub' => 'No club',
        'consoleLoggedIn' => 'Account signed in to the game',
        'FailedPersonaSwitch' => 'Wrong persona details',
        'TMLocked' => 'Transfer market locked',
        // SBC-specific errors
        'setNotFound' => 'SBC not found',
        'foundationNotSolved' => 'Base SBC not solved',
        'alreadyCompleted' => 'Already completed',
        'challengeDataMissing' => 'Squad data missing',
        'noSolutionFound' => 'No solution',
        'tooExpensive' => 'Solution too expensive',
        'clickFailed' => 'Could not complete a step',
        'submitFailed' => 'Submit failed',
        'squadCreateFailed' => 'Failed to build squad',
        // Player / market errors
        'playerBuyFailed' => 'Failed to buy player',
        'playerNotFound' => 'Player not found',
        'playerNotMoved' => 'Player not moved',
        'clubQueryFailed' => 'Could not read the club',
        'tooManyExchanges' => 'Too many exchanges',
        // Financial errors
        'noFunds' => 'Insufficient balance',
        'OutOfCoins' => 'Out of coins',
        'tempban' => 'Safety cooldown',
        'TempbanCooldown' => 'Waiting (cooldown)',
        'dailyReceiverLimit' => 'Daily limit (safety)',
        // System errors
        'aborted' => 'Cancelled',
        'failed' => 'Failed',
        'FailUnassignedFound' => 'Unassigned items found',
    ],
    // Per-status help, layered above challenge_help. Written only where the coarse
    // state help is wrong for this status; everything else falls back unchanged.
    'challenge_status_help' => [
        'sign_in_refused' => [
            'title' => 'Could not sign in',
            'desc' => 'EA refused the sign-in for now, usually because their servers are under pressure.',
            'action' => 'Press "Try again" to retry. If you are sure the email is wrong, update it in the order details.',
        ],
        'two_factor_off' => [
            'title' => 'Two-factor verification not enabled',
            'desc' => 'Two-factor verification (2FA) is not enabled on the account, and we need it to continue.',
            'action' => 'Enable two-factor verification from your EA account security settings, then update the order.',
        ],
        'WrongUserPass' => [
            'title' => 'Wrong sign-in details',
            'desc' => 'The email or password for your EA account is not correct.',
            'action' => 'Update your sign-in details in the order settings, then try again.',
        ],
        'WrongBA' => [
            'title' => 'Wrong backup codes',
            'desc' => 'The backup codes you entered are wrong or already used.',
            'action' => 'Create new codes from your EA account security settings and update the order details.',
        ],
        'TMLocked' => [
            'title' => 'Transfer market locked',
            'desc' => 'The market on this account is currently locked by EA.',
            'action' => 'Give us another account with an open market, or wait until the market opens on this account.',
        ],
        'failedNoClub' => [
            'title' => 'No club',
            'desc' => 'The account has no Ultimate Team club in the game.',
            'action' => 'Open the game, create your club, then let us know.',
        ],
        'consoleLoggedIn' => [
            'title' => 'Account signed in to the game',
            'desc' => 'The account is currently signed in to the game or the Companion app, so we cannot start.',
            'action' => 'Sign out of the game and the Companion app completely, then try again.',
        ],
        'FailUnassignedFound' => [
            'title' => 'Unassigned items found',
            'desc' => 'There are unassigned items on the account blocking the solve.',
            'action' => 'Remove the unassigned items from your account (below 50), then try again.',
        ],
        'LoginFailedDeviceBan' => [
            'title' => 'Device banned',
            'desc' => 'The account is banned on this device by EA.',
            'action' => 'Message us to resolve it.',
        ],
        'tempban' => [
            'title' => 'Safety cooldown',
            'desc' => 'Paused as a safety measure to avoid an EA transfer market ban.',
            'action' => 'It resumes on its own once the cooldown ends. You can play normally.',
        ],
        'TempbanCooldown' => [
            'title' => 'Cooldown',
            'desc' => 'Your account is in a mandatory cooldown because of too many exchanges, to avoid a market ban.',
            'action' => 'Work resumes automatically soon.',
        ],
        'dailyReceiverLimit' => [
            'title' => 'Daily receive limit',
            'desc' => 'You have reached the daily limit, and we are waiting to protect your account from a ban.',
            'action' => 'Please wait up to 36 hours. You can play normally in the meantime.',
        ],
        'FailedPersonaSwitch' => [
            'title' => 'Wrong persona details',
            'desc' => 'The persona details on the account are wrong or do not match.',
            'action' => 'Message us to correct the persona details.',
        ],
        'needEmailConfirm' => [
            'title' => 'Email confirmation required',
            'desc' => 'EA is asking for your email to be confirmed before signing in.',
            'action' => 'Confirm your email from EA, then try again.',
        ],
    ],
];
