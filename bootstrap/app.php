<?php

use App\Http\Middleware\EnsureVerifiedPasswordRecoveryEmail;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireCatalogCartJson;
use App\Http\Middleware\RequireCoinsCartJson;
use App\Http\Middleware\SetDisplayCurrency;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyN8nSbcPricingReadSignature;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
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
                || $request->is('*/cart/items/coins')
                || $request->is('cart/items/sbc')
                || $request->is('*/cart/items/sbc')
                || $request->is('cart/items/*/credentials')
                || $request->is('*/cart/items/*/credentials'),
        ]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, RequireCoinsCartJson::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, RequireCatalogCartJson::class);
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            \App\Http\Middleware\EnsureChatEnabled::class,
        );
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            VerifyN8nSbcPricingReadSignature::class,
        );
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->route('locale') === 'en'
            ? route('localized.login', ['locale' => 'en'], absolute: false)
            : route('login', absolute: false));

        $middleware->web(append: [
            SetLocale::class,
            SetDisplayCurrency::class,
            HandleAppearance::class,
            EnsureVerifiedPasswordRecoveryEmail::class,
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
                    || $request->is('cart/items/sbc') || $request->is('*/cart/items/sbc')
                    || $request->is('checkout/paylink') || $request->is('*/checkout/paylink')
                    || $request->is('checkout/phone/*') || $request->is('*/checkout/phone/*')
                ))
                || ($request->isMethod('PATCH') && (
                    $request->is('cart/items/*/credentials')
                    || $request->is('*/cart/items/*/credentials')
                )),
        );
        $exceptions->respond(function (Response $exceptionResponse, Throwable $_exception, Request $request): Response {
            if ($request->is('cart/items/coins*') || $request->is('*/cart/items/coins*')
                || $request->is('cart/items/catalog*') || $request->is('*/cart/items/catalog*')
                || $request->is('cart/items/sbc*') || $request->is('*/cart/items/sbc*')
                || $request->is('cart/items/*/credentials') || $request->is('*/cart/items/*/credentials')
                || $request->is('checkout/paylink') || $request->is('*/checkout/paylink')
                || $request->is('checkout/phone/*') || $request->is('*/checkout/phone/*')) {
                if ($exceptionResponse->getStatusCode() >= 500) {
                    return response()->json([
                        'error' => [
                            'code' => 'internal_error',
                            'message' => trans($request->is('cart/items/catalog*') || $request->is('*/cart/items/catalog*')
                                || $request->is('cart/items/sbc*') || $request->is('*/cart/items/sbc*')
                                ? 'store.cart.catalog_internal_error'
                                : 'store.cart.internal_error'),
                        ],
                    ], 500)->header('Cache-Control', 'no-store');
                }

                $exceptionResponse->headers->set(
                    'Cache-Control',
                    $request->is('checkout/paylink') || $request->is('*/checkout/paylink')
                        || $request->is('checkout/phone/*') || $request->is('*/checkout/phone/*')
                        ? 'no-store, private'
                        : 'no-store',
                );
            }

            return $exceptionResponse;
        });
    })->create();
