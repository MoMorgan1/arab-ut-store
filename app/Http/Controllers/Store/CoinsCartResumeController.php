<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CoinsCartResumeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->route('locale');
        $homeRoute = $locale === 'en' ? 'localized.home' : 'home';
        $routeParameters = $locale === 'en' ? ['locale' => 'en'] : [];
        $safeSelection = array_filter([
            'platform' => $request->query('platform'),
            'delivery' => $request->query('delivery'),
            'quantity' => $request->query('quantity'),
            'step' => 'credentials',
        ], fn (mixed $selectionValue): bool => $selectionValue !== null);

        return redirect()->to(
            route($homeRoute, $routeParameters, absolute: false).'?'.http_build_query($safeSelection),
        );
    }
}
