<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class OverviewController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('account/overview', [
            'accountUi' => trans('account'),
        ]);
    }
}
