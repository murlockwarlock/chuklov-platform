<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\ClientCompanion\Application\Actions\ReplyToCompanion;
use App\Modules\ClientCompanion\Application\Actions\ResetCompanionContext;
use App\Modules\ClientCompanion\Application\Actions\ResolveCompanionHandoff;
use App\Modules\ClientCompanion\Application\Actions\ResumeCompanionAi;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AdminCompanionController extends Controller
{
    public function reply(Request $request, int $client, ReplyToCompanion $reply): RedirectResponse
    {
        $clientModel = $this->client($client);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $reply->handle($this->actor($request), $clientModel, (string) $data['body']);

        return back();
    }

    public function resolve(Request $request, int $client, ResolveCompanionHandoff $resolve): RedirectResponse
    {
        $resolve->handle($this->actor($request), $this->client($client));

        return back();
    }

    public function resume(Request $request, int $client, ResumeCompanionAi $resume): RedirectResponse
    {
        $resume->handle($this->actor($request), $this->client($client));

        return back();
    }

    public function reset(Request $request, int $client, ResetCompanionContext $reset): RedirectResponse
    {
        $reset->handleForStaff($this->actor($request), $this->client($client));

        return back();
    }

    private function client(int $client): Client
    {
        return Client::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->findOrFail($client);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
