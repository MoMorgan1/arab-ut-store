<?php

use App\Http\Middleware\EnsureChatEnabled;
use App\Http\Middleware\EnsureVerifiedPasswordRecoveryEmail;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\OfferEmailLoginCode;
use App\Http\Middleware\RequireCatalogCartJson;
use App\Http\Middleware\RequireCoinsCartJson;
use App\Http\Middleware\SetDisplayCurrency;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyN8nFulfillmentSignature;
use App\Http\Middleware\VerifyN8nSbcPricingReadSignature;
use App\Http\Responses\ChatErrorResponse;
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
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'arabut_consent']);

        $middleware->trimStrings(except: [
            fn (Request $request): bool => $request->is('cart/items/coins')
                || $request->is('*/cart/items/coins')
                || $request->is('cart/items/sbc')
                || $request->is('*/cart/items/sbc')
                || $request->is('cart/items/*/credentials')
                || $request->is('*/cart/items/*/credentials')
                // A password is whatever the customer typed, spaces included.
                // Trimming it here would store and forward a different password
                // from the one that works, and the customer would have no way to
                // see why their correction did not help.
                || $request->is('orders/*/items/*/actions/edit-credentials')
                || $request->is('*/orders/*/items/*/actions/edit-credentials'),
        ]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, RequireCoinsCartJson::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, RequireCatalogCartJson::class);
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            EnsureChatEnabled::class,
        );
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            VerifyN8nSbcPricingReadSignature::class,
        );
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            VerifyN8nFulfillmentSignature::class,
        );
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->route('locale') === 'en'
            ? route('localized.login', ['locale' => 'en'], absolute: false)
            : route('login', absolute: false));

        $middleware->web(append: [
            SetLocale::class,
            SetDisplayCurrency::class,
            HandleAppearance::class,
            // Before the recovery guard, deliberately. That guard answers the
            // reset request with a "check your inbox" it never sends when the
            // address is unverified, and every imported account is unverified
            // - so it would swallow the request before this one could offer
            // the code that actually works.
            OfferEmailLoginCode::class,
            EnsureVerifiedPasswordRecoveryEmail::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*')
                || $request->is('chat')
                || $request->is('chat/*')
                || $request->is('*/chat')
                || $request->is('*/chat/*')
                || $request->expectsJson()
                || ($request->isMethod('POST') && (
                    // Without this a validation failure redirects instead of
                    // answering, and a redirect flashes what was submitted into
                    // the session - which for this route means the EA password
                    // and the backup codes, in a store outside the encrypted
                    // column and its access log. See dontFlash below.
                    $request->is('orders/*/items/*/actions/*') || $request->is('*/orders/*/items/*/actions/*')
                    || $request->is('cart/items/coins') || $request->is('*/cart/items/coins')
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
        // A redirect after a failed validation flashes what was submitted into the
        // session so the form can be refilled. Laravel's own list covers fields
        // named `password`; ours are not, and the session store is the database
        // with encryption off by default, so a mistyped correction would leave a
        // readable copy of an EA password outside the encrypted column that exists
        // to hold it. The routes above answer with JSON so this path should not be
        // reached at all - this is the belt behind that brace.
        $exceptions->dontFlash([
            'backup_codes',
            // A live login code, for the ten minutes it lasts. This route can
            // fail validation through a redirect, and the flashed old input
            // would put the code in the session store in plain text - beside
            // the hash that exists so it is never written down.
            'code',
            'current_password',
            'ea_password',
            'password',
            'password_confirmation',
        ]);

        $exceptions->respond(function (Response $exceptionResponse, Throwable $exception, Request $request): Response {
            if ($request->is('chat') || $request->is('chat/*') || $request->is('*/chat') || $request->is('*/chat/*')) {
                return app(ChatErrorResponse::class)->render($exceptionResponse, $exception, $request);
            }

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
