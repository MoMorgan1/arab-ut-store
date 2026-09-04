<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Serves robots.txt from the application so the sitemap line always matches
 * the deployed host. A static file in `public/` would shadow this route
 * (Laravel serves `public/` first), so none may exist.
 */
final class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        $sitemap = rtrim((string) config('app.url'), '/').'/sitemap.xml';

        $lines = [
            'User-agent: *',
            'Allow: /',
            '',
            '# Private, transactional, or duplicate paths. These are already auth-gated;',
            '# excluding them keeps crawl budget on the pages that can actually rank.',
            'Disallow: /admin',
            'Disallow: /account',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /orders',
            'Disallow: /payments',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Disallow: /two-factor-challenge',
            'Disallow: /confirm-password',
            'Disallow: /dashboard',
            'Disallow: /settings',
            'Disallow: /chat',
            'Disallow: /en/admin',
            'Disallow: /en/account',
            'Disallow: /en/cart',
            'Disallow: /en/checkout',
            'Disallow: /en/orders',
            'Disallow: /en/payments',
            'Disallow: /en/login',
            'Disallow: /en/register',
            'Disallow: /en/forgot-password',
            'Disallow: /en/reset-password',
            '',
            '# The explicit /ar prefix duplicates the unprefixed Arabic pages, which are',
            '# canonical. Crawling it would only spend budget re-reading the same content.',
            'Disallow: /ar/',
            '',
            "Sitemap: {$sitemap}",
            '',
        ];

        return response(implode("\n", $lines))->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
