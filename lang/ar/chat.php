<?php

return [
    'conversation_closed' => 'المحادثة مقفلة. ابدأ محادثة جديدة للمتابعة.',
    'validation_error' => 'بيانات الشات المرسلة غير صالحة.',
    'rate_limited' => 'طلبات الشات كثيرة الآن. حاول مرة ثانية بعد قليل.',
    'unavailable' => 'الشات غير متاح مؤقتًا. حاول مرة ثانية.',
    'provider_connection_failed' => 'تعذر الاتصال بخدمة المساعد. يرجى المحاولة مرة أخرى.',
    'provider_timeout' => 'استغرق المساعد وقتًا أطول من المتوقع للرد. يرجى المحاولة مرة أخرى.',
    'provider_server_error' => 'حدث خطأ مؤقت في خدمة المساعد. يرجى المحاولة مرة أخرى.',
    'provider_incomplete' => 'انقطع الرد قبل اكتماله. يرجى المحاولة مرة أخرى.',
    'stream_terminated' => 'انقطع بث الرد. يرجى المحاولة مرة أخرى.',
    'stale_turn_recovered' => 'انتهت مهلة الطلب السابق وتم استعادته. يمكنك إعادة المحاولة الآن.',
    'sensitive_content_blocked' => 'تعذر معالجة رسالتك لاحتمال احتوائها على معلومات حساسة أو بيانات اعتماد. يرجى إعادة إرسال رسالتك بدون كلمات مرور أو رموز تحقق أو أرقام بطاقات أو بيانات سرية.',
    'configuration_invalid' => 'خدمة المساعد غير مهيأة بشكل صحيح حاليًا. يرجى التواصل مع الدعم.',
    'invalid_agent_request' => 'تعذر معالجة الطلب. يرجى المحاولة برسالة أخرى.',
    'provider_authentication_failed' => 'خدمة المساعد غير متوفرة حاليًا. يرجى المحاولة لاحقًا.',
    'provider_permission_denied' => 'خدمة المساعد غير متوفرة حاليًا. يرجى المحاولة لاحقًا.',
    'provider_request_rejected' => 'تم رفض الطلب بواسطة الخدمة. يرجى المحاولة برسالة أخرى.',
    'provider_malformed' => 'تلقى المساعد ردًا بصيغة غير متوقعة. يرجى المحاولة مرة أخرى.',
    'provider_terminal_failure' => 'تعذر على المساعد معالجة هذا الطلب. يرجى المحاولة لاحقًا.',
    'cancelled' => 'تم إلغاء الطلب.',

    'cards' => [
        'cta' => 'اطلب الآن',
        'coins' => [
            'title' => 'شحن كوينز FC',
            'subtitle' => 'اختر منصتك والكمية وشوف السعر',
            'options' => [
                'platform' => 'المنصة',
                'delivery' => 'التسليم',
                'quantity' => 'الكمية',
            ],
            'platforms' => [
                'playstation' => 'بلايستيشن',
                'xbox' => 'إكسبوكس',
                'pc' => 'بي سي',
            ],
            'deliveries' => [
                'normal' => 'عادي',
                'fast' => 'سريع',
            ],
            'quantity_value' => ':count كوينز',
        ],
        'sbc' => [
            'title' => 'تحديات SBC',
            'subtitle' => 'ننفذ التحدي ونشحن الكوينز اللازمة',
        ],
        'rivals' => [
            'title' => 'ديفيجن رايفلز',
            'subtitle' => 'نلعب لك ونصعدك للديفيجن اللي تبيه',
            'options' => [
                'current_division' => 'الديفجن الحالي',
                'target_division' => 'الديفجن المطلوب',
            ],
            'division_value' => 'ديفجن :division',
            'elite' => 'إيليت',
        ],
        'fut_champions' => [
            'title' => 'فوت شامبيونز',
            'subtitle' => 'من رانك 6 إلى رانك 1',
            'options' => [
                'rank' => 'الرانك',
                'urgent' => 'السرعة',
            ],
            'rank_value' => 'رانك :rank',
            'urgent_value' => 'مستعجل',
            'normal_value' => 'عادي',
        ],
    ],
    'choices' => [
        'service' => [
            'prompt' => 'وش الخدمة اللي تبيها؟',
            'coins' => [
                'label' => 'كوينز',
                'message' => 'ابي كوينز',
            ],
            'rivals' => [
                'label' => 'ديفيجن رايفلز',
                'message' => 'ابي رايفلز',
            ],
            'fut_champions' => [
                'label' => 'فوت شامبيونز',
                'message' => 'ابي فوت شامبيونز',
            ],
            'sbc' => [
                'label' => 'تحديات SBC',
                'message' => 'ابي تحديات SBC',
            ],
        ],
        'coins' => [
            'platform_prompt' => 'على أي منصة؟',
            'quantity_prompt' => 'كم كمية الكوينز؟',
            'delivery_prompt' => 'أي سرعة توصيل؟',
            'playstation' => [
                'label' => 'بلايستيشن',
                'message' => 'بلايستيشن',
            ],
            'pc' => [
                'label' => 'كمبيوتر PC',
                'message' => 'بي سي',
            ],
            'normal' => [
                'label' => 'عادي',
                'message' => 'توصيل عادي',
            ],
            'fast' => [
                'label' => 'سريع',
                'message' => 'توصيل سريع',
            ],
            'quantities' => [
                100000 => [
                    'label' => '100 ألف',
                    'message' => 'مية الف كوينز',
                ],
                500000 => [
                    'label' => '500 ألف',
                    'message' => 'نص مليون كوينز',
                ],
                1000000 => [
                    'label' => 'مليون',
                    'message' => 'مليون كوينز',
                ],
                2000000 => [
                    'label' => 'مليونين',
                    'message' => 'مليونين كوينز',
                ],
                5000000 => [
                    'label' => '5 مليون',
                    'message' => 'خمسة مليون كوينز',
                ],
            ],
        ],
        'fut_champions' => [
            'rank_prompt' => 'أي رانك تبي توصله؟',
            'urgency_prompt' => 'عادي ولا مستعجل؟',
            'normal' => [
                'label' => 'عادي',
                'message' => 'عادي',
            ],
            'urgent' => [
                'label' => 'مستعجل',
                'message' => 'مستعجل',
            ],
            'ranks' => [
                6 => [
                    'label' => 'رانك 6',
                    'message' => 'رانك 6',
                ],
                5 => [
                    'label' => 'رانك 5',
                    'message' => 'رانك 5',
                ],
                4 => [
                    'label' => 'رانك 4',
                    'message' => 'رانك 4',
                ],
                3 => [
                    'label' => 'رانك 3',
                    'message' => 'رانك 3',
                ],
                2 => [
                    'label' => 'رانك 2',
                    'message' => 'رانك 2',
                ],
                1 => [
                    'label' => 'رانك 1',
                    'message' => 'رانك 1',
                ],
            ],
        ],
    ],
];
