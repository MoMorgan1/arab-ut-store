@php($isStoreRoute = str_starts_with($page['component'] ?? '', 'store/'))
@php($isAdminRoute = str_starts_with($page['component'] ?? '', 'admin/'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" @class(['dark' => $isAdminRoute || ($appearance ?? 'system') == 'dark', 'store-document' => $isStoreRoute, 'admin-document' => $isAdminRoute])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @if ($isStoreRoute)
            <meta name="theme-color" content="#0d0b08">
        @elseif ($isAdminRoute)
            <meta name="theme-color" content="#080705">
        @endif

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Match each shell before the stylesheet loads without leaking the store theme. --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }

            html.store-document {
                background-color: #0d0b08;
            }

            html.admin-document {
                background-color: #080705;
            }
        </style>

        {{-- Social and canonical metadata is rendered here, not in React.
             WhatsApp, X, Facebook, and many crawlers never execute JavaScript,
             so anything injected client-side arrives after they have already
             read the response and moved on. --}}
        @php($storeSeo = \App\Support\Seo\StoreCanonicalUrls::forRequest(request()))
        @if ($storeSeo !== null)
            <link rel="canonical" href="{{ $storeSeo['canonical'] }}">
            @foreach ($storeSeo['alternates'] as $hreflang => $href)
                <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
            @endforeach
        @endif

        @if ($isStoreRoute && isset($page['props']['seo']))
            @php($seo = $page['props']['seo'])
            <meta name="description" content="{{ $seo['description'] }}">
            <meta property="og:title" content="{{ $seo['title'] }}">
            <meta property="og:description" content="{{ $seo['description'] }}">
            <meta property="og:type" content="{{ $seo['type'] }}">
            <meta property="og:image" content="{{ $seo['image'] }}">
            <meta property="og:url" content="{{ $storeSeo['canonical'] ?? url()->current() }}">
            <meta property="og:site_name" content="{{ __('store.seo_brand') }}">
            <meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_SA' : 'en_US' }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $seo['title'] }}">
            <meta name="twitter:description" content="{{ $seo['description'] }}">
            <meta name="twitter:image" content="{{ $seo['image'] }}">
            <script type="application/ld+json">{!! json_encode($seo['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        @endif

        @php($analyticsVendors = array_filter([
            'ga4' => (string) config('services.analytics.ga4_measurement_id'),
            'meta' => (string) config('services.analytics.meta_pixel_id'),
            'tiktok' => (string) config('services.analytics.tiktok_pixel_id'),
        ]))
        @if ($analyticsVendors !== [] && ($isStoreRoute || ($page['component'] ?? '') === 'account/live-order'))
            {{-- Consent bootstrap only. No vendor script lives in the head:
                 resources/js/lib/analytics.ts loads each vendor after the
                 visitor accepts, and Google's consent default has to exist
                 before its tag can ever run, so it is declared here first. --}}
            <script>
                window.dataLayer = window.dataLayer || [];
                function gtag() { window.dataLayer.push(arguments); }
                gtag('consent', 'default', {
                    ad_storage: 'denied',
                    ad_user_data: 'denied',
                    ad_personalization: 'denied',
                    analytics_storage: 'denied',
                });
                window.__arabutAnalytics = @json($analyticsVendors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            </script>
        @endif

        @if ($isStoreRoute)
            {{-- Installable storefront: the manifest gives phones an "add to
                 home screen" entry with the brand icon and the warm-black shell. --}}
            <link rel="manifest" href="/site.webmanifest?v=arab-ut-2026-2">
            <meta name="apple-mobile-web-app-title" content="Arab UT">

            {{-- The four faces every store screen paints above the fold.
                 Without the hints the browser only discovers them after the
                 stylesheet has downloaded and parsed. --}}
            <link rel="preload" href="/fonts/thmanyah/thmanyahsans-Regular.woff2" as="font" type="font/woff2" crossorigin>
            <link rel="preload" href="/fonts/thmanyah/thmanyahsans-Bold.woff2" as="font" type="font/woff2" crossorigin>
            <link rel="preload" href="/fonts/thmanyah/thmanyahsans-Black.woff2" as="font" type="font/woff2" crossorigin>
            <link rel="preload" href="/fonts/thmanyah/thmanyahserifdisplay-Bold.woff2" as="font" type="font/woff2" crossorigin>
        @endif

        @if (($page['component'] ?? '') === 'store/home')
            {{-- The hero backdrop is the home page's largest paint. The media
                 queries mirror the stylesheet so each device fetches one file,
                 and browsers without AVIF skip the hint and load WebP from CSS. --}}
            <link rel="preload" href="/images/store/hero/background.avif" as="image" type="image/avif" media="(hover: hover) and (pointer: fine)">
            <link rel="preload" href="/images/store/hero/background-mobile.avif" as="image" type="image/avif" media="(max-width: 40rem)">
        @endif

        <link rel="icon" href="/favicon-32x32.png?v=arab-ut-2026-2" sizes="32x32" type="image/png">
        <link rel="shortcut icon" href="/favicon-32x32.png?v=arab-ut-2026-2" type="image/png">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=arab-ut-2026-2">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ $isStoreRoute ? __('ui.brand') : config('app.name', 'Arab UT') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
