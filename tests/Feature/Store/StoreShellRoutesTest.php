<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('every non-transactional storefront destination has the right bilingual page contract', function (
    string $path,
    string $locale,
    string $page,
    string $title,
    string $body,
) {
    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('store/simple-page')
            ->where('locale', $locale)
            ->where('page.key', $page)
            ->where('page.title', $title)
            ->where('page.body', $body)
            ->where('storeShell.homeUrl', $locale === 'en' ? '/en' : '/')
            ->where('storeShell.coinsUrl', $locale === 'en' ? '/en#coins' : '/#coins')
            ->where('storeShell.cartUrl', $locale === 'en' ? '/en/cart' : '/cart')
            ->where('storeShell.sbcUrl', $locale === 'en' ? '/en/sbc' : '/sbc')
            ->where('storeShell.futChampionsUrl', $locale === 'en' ? '/en/fut-champions' : '/fut-champions')
            ->where('storeShell.privacyUrl', $locale === 'en' ? '/en/privacy' : '/privacy')
            ->where('storeShell.returnsUrl', $locale === 'en' ? '/en/returns' : '/returns')
            ->where('storeShell.warrantyUrl', $locale === 'en' ? '/en/warranty' : '/warranty')
            ->where('storeShell.eaBackupCodesUrl', $locale === 'en' ? '/en/ea-backup-codes' : '/ea-backup-codes')
            ->where('storeShell.termsUrl', $locale === 'en' ? '/en/terms' : '/terms')
            ->where('storeShell.accountUrl', $locale === 'en' ? '/en/login' : '/login')
            ->where('storeShell.whatsappUrl', 'https://wa.me/966537998099')
            ->where('storeShell.email', 'info@arab-ut.com')
            ->where('storeShell.payments.0', [
                'name' => 'Mada',
                'imageUrl' => '/images/store/payments/mada.png',
                'width' => 120,
                'height' => 41,
            ])
            ->where('storeShell.socials.x', 'https://x.com/fut_fi')
            ->where('storeShell.socials.instagram', 'https://www.instagram.com/arabutcoins/')
            ->missing('storeShell.socials.tiktok')
            ->missing('storeShell.socials.snapchat'));
})->with([
    'privacy' => ['/privacy', 'ar', 'privacy', 'سياسة الخصوصية', 'ننقل ونراجع سياسة الخصوصية للمتجر الجديد. ما نجمع أي بيانات من هذه الصفحة.'],
    'English privacy' => ['/en/privacy', 'en', 'privacy', 'Privacy Policy', 'We are migrating and reviewing the policy for the new store. This page does not collect any data.'],
    'returns' => ['/returns', 'ar', 'returns', 'سياسة الاسترجاع', 'ننقل شروط الاسترجاع بصياغة واضحة قبل تفعيل الدفع.'],
    'English returns' => ['/en/returns', 'en', 'returns', 'Returns Policy', 'We are migrating the return terms in clear language before payments are enabled.'],
    'warranty' => ['/warranty', 'ar', 'warranty', 'سياسة الضمان والتعويض', 'ننقل تفاصيل الضمان والتعويض قبل تفعيل الطلبات.'],
    'English warranty' => ['/en/warranty', 'en', 'warranty', 'Warranty and Compensation', 'We are migrating the warranty and compensation details before orders are enabled.'],
    'EA codes' => ['/ea-backup-codes', 'ar', 'ea_backup_codes', 'أكواد EA الاحتياطية', 'نجهز شرح بسيط وآمن لطريقة استخراج الأكواد.'],
    'English EA codes' => ['/en/ea-backup-codes', 'en', 'ea_backup_codes', 'EA Backup Codes', 'We are preparing a simple, secure guide for obtaining backup codes.'],
    'terms' => ['/terms', 'ar', 'terms', 'شروط الخدمة', 'ننقل ونراجع شروط الخدمة قبل إطلاق الطلب والدفع.'],
    'English terms' => ['/en/terms', 'en', 'terms', 'Terms of Service', 'We are migrating and reviewing the terms before ordering and payments launch.'],
]);

test('the cart destinations render the real safe cart page', function (string $path, string $locale) {
    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('store/cart')
            ->where('locale', $locale)
            ->where('cart.count', 0)
            ->where('cart.currency', 'SAR')
            ->where('cart.items', [])
            ->where('cartPage.backUrl', $locale === 'en' ? '/en#coins' : '/#coins')
            ->has('cartPage.translations.title')
            ->missing('checkout')
            ->missing('payment'));
})->with([
    'cart' => ['/cart', 'ar'],
    'explicit Arabic cart' => ['/ar/cart', 'ar'],
    'English cart' => ['/en/cart', 'en'],
]);

test('the account destination changes from login to the authenticated dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get('/cart')
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->where('storeShell.accountUrl', '/dashboard'));
});
