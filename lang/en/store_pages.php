<?php

return [
    'meta' => [
        'home' => 'Home',
        'breadcrumb_label' => 'Breadcrumb',
        'updated_label' => 'Last updated',
        'updated_value' => '12 August 2026',
        'support_title' => 'Have a question?',
        'support_subtitle' => 'Our team is ready to help around the clock',
        'support_action' => 'Contact us on WhatsApp',
    ],
    'pages' => [
        'privacy' => [
            'title' => 'Privacy Policy',
            'blocks' => [
                ['type' => 'paragraph', 'content' => [['text' => 'Arab UT respects your privacy. This policy explains how we collect and use information to protect your account and manually fulfill our digital services.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '1. Information We Collect'],
                ['type' => 'list', 'ordered' => false, 'items' => [
                    [['text' => 'Contact information such as name, email address, and phone number.']],
                    [['text' => 'Order details such as platform, service type, and fulfillment notes.']],
                    [['text' => 'Temporary access information when required for manual service fulfillment, used only for the order.']],
                    [['text' => 'Technical information such as cookies for site experience and security.']],
                ]],
                ['type' => 'heading', 'level' => 2, 'text' => '2. How We Use Information'],
                ['type' => 'paragraph', 'content' => [['text' => 'We use information to process orders, communicate with customers, support accounts, prevent abuse, improve the website experience, and meet payment and review requirements.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '3. Data Handling'],
                ['type' => 'paragraph', 'content' => [['text' => 'We do not share your information with unnecessary third parties. Sensitive information is handled confidentially and used only within the scope of the requested service.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '4. Your Rights'],
                ['type' => 'paragraph', 'content' => [['text' => 'You may request updates or deletion of your information when it is no longer required for legal, accounting, or security purposes.']]],
            ],
        ],
        'returns' => [
            'title' => 'Returns Policy',
            'blocks' => [
                ['type' => 'paragraph', 'content' => [['text' => 'Because our services are digital and manually fulfilled after order review, refund eligibility depends on the order stage and fulfillment status.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '1. Before Fulfillment Starts'],
                ['type' => 'paragraph', 'content' => [['text' => 'A customer may request cancellation and refund as long as our team has not started manual fulfillment. Non-refundable payment processor fees may be deducted where applicable.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '2. After Fulfillment Starts'],
                ['type' => 'paragraph', 'content' => [['text' => 'Once manual fulfillment has started, completed work cannot be cancelled. If we cannot complete the service due to an issue on our side, we will offer completion or refund the unfulfilled portion.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '3. Fulfillment Failure'],
                ['type' => 'paragraph', 'content' => [['text' => 'If we cannot provide the requested service under the agreed conditions, the customer may request an appropriate refund or alternative resolution based on the order status.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '4. Refund Processing Time'],
                ['type' => 'paragraph', 'content' => [['text' => 'Refund requests are reviewed within 48 hours. Funds may take 3 to 14 business days to reach the payment method depending on the bank or payment processor.']]],
            ],
        ],
        'warranty' => [
            'title' => 'Warranty and Compensation',
            'blocks' => [
                ['type' => 'paragraph', 'content' => [['text' => 'We aim to provide clear and careful manual digital services. This policy explains the warranty and compensation limits if an issue occurs during fulfillment.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '1. When the Warranty Applies'],
                ['type' => 'paragraph', 'content' => [['text' => 'The warranty applies when the order follows our instructions and the customer does not make external changes that affect fulfillment during processing.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '2. What We Guarantee'],
                ['type' => 'list', 'ordered' => false, 'items' => [
                    [['text' => 'Order review before fulfillment.']],
                    [['text' => 'Manual fulfillment with reasonable care.']],
                    [['text' => 'An appropriate resolution if we cannot complete the service due to an issue on our side.']],
                ]],
                ['type' => 'heading', 'level' => 2, 'text' => '3. Compensation Limits'],
                ['type' => 'paragraph', 'content' => [['text' => 'Each case is reviewed individually. Compensation may include service completion, an alternative service, or a partial or full refund depending on the situation.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '4. Exclusions'],
                ['type' => 'paragraph', 'content' => [['text' => 'The warranty does not cover issues caused by incorrect information, customer changes during fulfillment, platform restrictions, or external actions outside our control.']]],
            ],
        ],
        'terms' => [
            'title' => 'Terms of Service',
            'blocks' => [
                ['type' => 'paragraph', 'content' => [['text' => 'Welcome to Arab UT, operated by '], ['text' => 'Ultimate Digital Services FZE', 'strong' => true], ['text' => ', a licensed company in Sharjah, United Arab Emirates. By using this website or placing an order, you agree to these terms.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '1. Service Nature'],
                ['type' => 'paragraph', 'content' => [['text' => 'Ultimate Digital Services FZE, through Arab UT, provides digital EA FC/FC Ultimate Team services, including FC 27 coins, account services, and reviewed manual order fulfillment. Arab UT is independent and is not affiliated with EA Sports or Electronic Arts.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '2. Order Review and Fulfillment'],
                ['type' => 'paragraph', 'content' => [['text' => 'Each order is reviewed before fulfillment to confirm that the requested manual service can be performed safely and properly. We may request information required to complete the service, and it is used only for order fulfillment.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '3. Customer Responsibility'],
                ['type' => 'paragraph', 'content' => [['text' => 'Customers must provide accurate information, maintain access to the relevant account and platform, and avoid changes that may interrupt fulfillment while an order is being processed.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '4. Payment and Refunds'],
                ['type' => 'paragraph', 'content' => [['text' => 'Payments are processed through secure payment providers. If a service cannot be fulfilled, or fulfillment does not begin under the published conditions, the customer may request a refund according to our Refund Policy.']]],
                ['type' => 'heading', 'level' => 2, 'text' => '5. Intellectual Property'],
                ['type' => 'paragraph', 'content' => [['text' => 'All game names, logos, and trademarks belong to their respective owners. Arab UT is an independent service provider and does not claim official affiliation with EA Sports or Electronic Arts.']]],
            ],
        ],
        'ea_backup_codes' => [
            'title' => 'EA Backup Codes',
            'subtitle' => 'Direct steps from your EA Account settings to view your codes.',
            'blocks' => [
                ['type' => 'paragraph', 'content' => [['text' => 'Backup codes help you sign in from a new device if you cannot access your chosen verification method.']]],
                ['type' => 'heading', 'level' => 2, 'text' => 'How to view your codes'],
                ['type' => 'list', 'ordered' => true, 'items' => [
                    [['text' => 'Open your EA Account Security and Privacy page and sign in.']],
                    [['text' => 'Open the two-factor authentication (2FA) settings and finish enabling it if needed.']],
                    [['text' => 'After 2FA is enabled, select “View backup codes” and keep the codes in a safe place.']],
                ]],
                ['type' => 'notice', 'tone' => 'shield', 'content' => [['text' => 'Each backup code can be used once. To protect your account, only share codes through the store’s official channels when they are required to fulfill a service.']]],
                ['type' => 'paragraph', 'content' => [['text' => 'Read EA’s current official guide: '], ['text' => 'Enable two-factor authentication on your EA Account', 'url' => 'https://help.ea.com/en/articles/security-and-rules/two-factor-authentication/']]],
            ],
        ],
    ],
];
