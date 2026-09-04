<?php

declare(strict_types=1);

namespace App\View\Components;

use Inertia\Ssr\SsrState;
use Inertia\View\Components\App;

/**
 * Inertia's own component encodes the page with the default json_encode flags,
 * which escape every non-ASCII character. The storefront is Arabic, so each
 * letter left the server as six bytes (ع) instead of two, and the browser
 * had to parse ~98 KB of JSON before React could paint a single pixel. Emitting
 * the same payload as UTF-8 halves that to ~51 KB.
 *
 * Slashes stay escaped, exactly as the parent leaves them: that is what keeps a
 * "</script>" inside the payload from closing the tag it lives in.
 */
final class InertiaApp extends App
{
    public function __construct(string $id = 'app')
    {
        parent::__construct($id);

        $encoded = json_encode(app(SsrState::class)->page, JSON_UNESCAPED_UNICODE);

        if ($encoded !== false) {
            $this->pageJson = $encoded;
        }
    }
}
