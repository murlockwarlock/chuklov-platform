<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\ClientCompanion\Application\Services\CompanionExportService;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Http\Request;

final class CompanionExportController extends Controller
{
    public function history(Request $request, int $client, CompanionExportService $export): mixed
    {
        $clientModel = $this->client($client);
        $format = strtolower((string) $request->query('format', 'json'));
        $identity = strtolower((string) $request->query('identity', 'identified'));
        $content = $export->history($this->actor($request), $clientModel, $format, $identity);
        $extension = $format === 'txt' ? 'txt' : 'json';
        $contentType = $format === 'txt' ? 'text/plain; charset=UTF-8' : 'application/json; charset=UTF-8';

        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, 'client-companion.'.$extension, ['Content-Type' => $contentType, 'Cache-Control' => 'private, no-store']);
    }

    public function metadata(Request $request, int $client, CompanionExportService $export): mixed
    {
        $content = $export->metadata($this->actor($request), $this->client($client));

        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, 'client-companion-metadata.json', ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
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
