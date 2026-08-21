<?php

namespace App\Http\Controllers\Admin\Security;

use App\Admin\Presenters\AdminMfaState;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AdminMfaController extends Controller
{
    public function __construct(private readonly AdminMfaState $state) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $locale = $request->route('locale') === 'en' ? 'en' : 'ar';

        return Inertia::render('admin/security/mfa', [
            'auth' => null,
            'adminUi' => trans('admin', locale: $locale),
            'mfa' => $this->state->for($user, $locale),
        ]);
    }
}
