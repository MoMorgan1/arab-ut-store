<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('every non-transactional storefront destination has the right bilingual page contract', function (
    string $path,
    string $locale,
    string $page,
    string $title,
    string $breadcrumb,
    string $firstSection,
) {
    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('store/simple-page')
            ->where('locale', $locale)
            ->where('direction', $locale === 'en' ? 'ltr' : 'rtl')
            ->where('page.key', $page)
            ->where('page.title', $title)
            ->where('page.breadcrumb.home', $locale === 'en' ? 'Home' : 'الرئيسية')
            ->where('page.breadcrumb.current', $breadcrumb)
            ->where('page.updated.label', $locale === 'en' ? 'Last updated' : 'آخر تحديث')
            ->where('page.blocks.1.type', 'heading')
            ->where('page.blocks.1.text', $firstSection)
            ->where('page.support.title', $locale === 'en' ? 'Have a question?' : 'هل لديك سؤال؟')
            ->where('page.support.url', 'https://wa.me/966537998099')
            ->missing('page.body')
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
    'privacy' => ['/privacy', 'ar', 'privacy', 'سياسة الخصوصية', 'سياسة الخصوصية', 'أولاً: المعلومات التي يحصل عليها المتجر ويحتفظ بها'],
    'English privacy' => ['/en/privacy', 'en', 'privacy', 'Privacy Policy', 'Privacy Policy', '1. Information We Collect and Retain'],
    'returns' => ['/returns', 'ar', 'returns', 'سياسة الاسترجاع', 'سياسة الاسترجاع', '١. طبيعة المنتج الرقمي'],
    'English returns' => ['/en/returns', 'en', 'returns', 'Returns Policy', 'Returns Policy', '1. Nature of the Digital Product'],
    'warranty' => ['/warranty', 'ar', 'warranty', 'سياسة الضمان والتعويض', 'سياسة الضمان والتعويض', '١. شروط سريان الضمان'],
    'English warranty' => ['/en/warranty', 'en', 'warranty', 'Warranty and Compensation', 'Warranty and Compensation', '1. Warranty Conditions'],
    'EA codes' => ['/ea-backup-codes', 'ar', 'ea_backup_codes', 'أكواد EA الاحتياطية', 'أكواد EA الاحتياطية', 'طريقة عرض الأكواد'],
    'English EA codes' => ['/en/ea-backup-codes', 'en', 'ea_backup_codes', 'EA Backup Codes', 'EA Backup Codes', 'How to view your codes'],
    'terms' => ['/terms', 'ar', 'terms', 'شروط الخدمة', 'شروط الخدمة', '١. قبول الشروط'],
    'English terms' => ['/en/terms', 'en', 'terms', 'Terms of Service', 'Terms of Service', '1. Acceptance of Terms'],
]);

test('the cart destinations render the real safe cart page', function (string $path, string $locale) {
    $this->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('store/cart')
            ->where('locale', $locale)
            ->where('cart.count', 0)
            ->where('cart.currency', 'SAR')
            ->where('cart.items', [])
            ->missing('cartPage.backUrl')
            ->has('cartPage.translations.title')
            ->missing('checkout')
            ->missing('payment'));
})->with([
    'cart' => ['/cart', 'ar'],
    'explicit Arabic cart' => ['/ar/cart', 'ar'],
    'English cart' => ['/en/cart', 'en'],
]);

test('the account destination changes from login to the locale-correct authenticated account', function (
    string $path,
    string $accountUrl,
) {
    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->where('storeShell.accountUrl', $accountUrl));
})->with([
    'Arabic shell' => ['/cart', '/my-account'],
    'English shell' => ['/en/cart', '/en/my-account'],
]);

test('structured information pages expose headings dividers and ordered lists through the route contract', function () {
    $this->get('/warranty')
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->where('page.blocks', fn ($blocks): bool => collect($blocks)
                ->contains(fn (array $block): bool => ($block['type'] ?? null) === 'heading' && ($block['level'] ?? null) === 3)
                && collect($blocks)->contains(fn (array $block): bool => ($block['type'] ?? null) === 'divider')));

    $this->get('/en/ea-backup-codes')
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->where('page.blocks', fn ($blocks): bool => collect($blocks)
                ->contains(fn (array $block): bool => ($block['type'] ?? null) === 'list' && ($block['ordered'] ?? null) === true)));
});
