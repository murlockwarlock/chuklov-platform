<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Security\Application\RevokePrivilegedSessions;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RevokePrivilegedSessionsController extends Controller
{
    public function __construct(private readonly RevokePrivilegedSessions $revokePrivilegedSessions) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $this->revokePrivilegedSessions->handle($actor);

        Filament::auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->guest(route('filament.admin.auth.login'));
    }
}
