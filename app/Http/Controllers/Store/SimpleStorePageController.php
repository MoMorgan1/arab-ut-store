<?php

namespace App\Http\Controllers\Store;

use App\Actions\Store\ValidateStoreInformationPage;
use App\Http\Controllers\Controller;
use App\Support\Seo\StorePageSeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class SimpleStorePageController extends Controller
{
    public function __invoke(Request $request, ValidateStoreInformationPage $validator): Response
    {
        $page = $request->route('storePage');
        $allowedPages = Config::array('store.simple_pages');

        if (! is_string($page) || ! in_array($page, $allowedPages, true)) {
            throw new LogicException('A simple storefront page must be an allowlisted route default.');
        }

        $translations = trans("store_pages.pages.{$page}");
        $meta = trans('store_pages.meta');

        return Inertia::render('store/simple-page', [
            'page' => $validator->validate(
                $page,
                $translations,
                $meta,
                config('store.support.whatsapp_url'),
            ),
            'seo' => StorePageSeo::default(
                is_array($translations) && isset($translations['title'])
                    ? (string) $translations['title']
                    : null,
            )->toArray(),
        ]);
    }
}
