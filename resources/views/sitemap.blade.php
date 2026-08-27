<?php /** @var list<array{loc: string, alternates: array{ar: string, en: string}, lastmod: string|null}> $entries */ ?>
<?= '<?xml version="1.0" encoding="UTF-8"?>'."\n" ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($entries as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
@foreach ($entry['alternates'] as $hreflang => $href)
        <xhtml:link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}"/>
@endforeach
        <xhtml:link rel="alternate" hreflang="x-default" href="{{ $entry['alternates']['ar'] }}"/>
@if ($entry['lastmod'] !== null)
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
@endif
    </url>
@endforeach
</urlset>
