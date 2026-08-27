<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Support\Seo\StoreSitemap;
use Illuminate\Http\Response;

final class SitemapController extends Controller
{
    public function __invoke(StoreSitemap $sitemap): Response
    {
        return response()
            ->view('sitemap', ['entries' => $sitemap->entries()])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
