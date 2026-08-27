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

        <link rel="icon" href="/favicon-32x32.png?v=arab-ut-2026" sizes="32x32" type="image/png">
        <link rel="shortcut icon" href="/favicon-32x32.png?v=arab-ut-2026" type="image/png">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=arab-ut-2026">

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
