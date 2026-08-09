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

        $translations = trans("ui.simple_pages.{$page}");

        if (! is_array($translations)
            || ! isset($translations['title'], $translations['body'])
            || ! is_string($translations['title'])
            || ! is_string($translations['body'])) {
            throw new LogicException("The simple storefront page translation [{$page}] is invalid.");
        }

        return Inertia::render('store/simple-page', [
            'page' => [
                'key' => $page,
                'title' => $translations['title'],
                'body' => $translations['body'],
            ],
        ]);
    }
}
