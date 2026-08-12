<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class SimpleStorePageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $page = $request->route('storePage');
        $allowedPages = Config::array('store.simple_pages');

        if (! is_string($page) || ! in_array($page, $allowedPages, true)) {
            throw new LogicException('A simple storefront page must be an allowlisted route default.');
        }

        $translations = trans("store_pages.pages.{$page}");
        $meta = trans('store_pages.meta');

        if (! is_array($translations)
            || ! isset($translations['title'], $translations['blocks'])
            || ! is_string($translations['title'])
            || ! is_array($translations['blocks'])
            || $translations['blocks'] === []) {
            throw new LogicException("The simple storefront page translation [{$page}] is invalid.");
        }

        if (! is_array($meta) || ! $this->hasStringMeta($meta)) {
            throw new LogicException('The simple storefront page metadata translation is invalid.');
        }

        return Inertia::render('store/simple-page', [
            'page' => [
                'key' => $page,
                'title' => $translations['title'],
                'subtitle' => $translations['subtitle'] ?? null,
                'breadcrumb' => [
                    'label' => $meta['breadcrumb_label'],
                    'home' => $meta['home'],
                    'current' => $translations['title'],
                ],
                'updated' => [
                    'label' => $meta['updated_label'],
                    'value' => $meta['updated_value'],
                ],
                'blocks' => $translations['blocks'],
                'support' => [
                    'title' => $meta['support_title'],
                    'subtitle' => $meta['support_subtitle'],
                    'action' => $meta['support_action'],
                    'url' => config('store.support.whatsapp_url'),
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function hasStringMeta(array $meta): bool
    {
        foreach (['home', 'breadcrumb_label', 'updated_label', 'updated_value', 'support_title', 'support_subtitle', 'support_action'] as $key) {
            if (! isset($meta[$key]) || ! is_string($meta[$key])) {
                return false;
            }
        }

        return true;
    }
}
