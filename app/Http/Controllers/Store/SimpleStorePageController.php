<?php

namespace App\Http\Controllers\Store;

use App\Actions\Store\ValidateStoreInformationPage;
use App\Http\Controllers\Controller;
use App\Services\Content\StorePageReader;
use App\Support\Seo\StorePageSeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class SimpleStorePageController extends Controller
{
    public function __invoke(
        Request $request,
        ValidateStoreInformationPage $validator,
        StorePageReader $reader,
    ): Response {
        $page = $request->route('storePage');
        $allowedPages = Config::array('store.simple_pages');

        if (! is_string($page) || ! in_array($page, $allowedPages, true)) {
            throw new LogicException('A simple storefront page must be an allowlisted route default.');
        }

        $pageData = $reader->page($page, app()->getLocale());
        $meta = trans('store_pages.meta');
        if (is_array($meta)) {
            $meta['updated_value'] = $pageData['updated_label'];
        }

        return Inertia::render('store/simple-page', [
            'page' => $validator->validate(
                $page,
                $pageData,
                $meta,
                config('store.support.whatsapp_url'),
            ),
            'seo' => StorePageSeo::default($pageData['title'])->toArray(),
        ]);
    }
}
