<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireCatalogCartJson;
use App\Http\Middleware\RequireCoinsCartJson;
use App\Http\Middleware\SetDisplayCurrency;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->trimStrings(except: [
            fn (Request $request): bool => $request->is('cart/items/coins')
                || $request->is('*/cart/items/coins'),
        ]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, RequireCoinsCartJson::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, RequireCatalogCartJson::class);
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->route('locale') === 'en'
            ? route('localized.login', ['locale' => 'en'], absolute: false)
            : route('login', absolute: false));

        $middleware->web(append: [
            SetLocale::class,
            SetDisplayCurrency::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*')
                || $request->expectsJson()
                || ($request->isMethod('POST') && (
                    $request->is('cart/items/coins') || $request->is('*/cart/items/coins')
                    || $request->is('cart/items/catalog') || $request->is('*/cart/items/catalog')
                )),
        );
        $exceptions->respond(function (Response $exceptionResponse, Throwable $_exception, Request $request): Response {
            if ($request->is('cart/items/coins*') || $request->is('*/cart/items/coins*')
                || $request->is('cart/items/catalog*') || $request->is('*/cart/items/catalog*')) {
                if ($exceptionResponse->getStatusCode() >= 500) {
                    return response()->json([
                        'error' => [
                            'code' => 'internal_error',
                            'message' => trans($request->is('cart/items/catalog*') || $request->is('*/cart/items/catalog*')
                                ? 'store.cart.catalog_internal_error'
                                : 'store.cart.internal_error'),
                        ],
                    ], 500)->header('Cache-Control', 'no-store');
                }

                $exceptionResponse->headers->set('Cache-Control', 'no-store');
            }

            return $exceptionResponse;
        });
    })->create();
