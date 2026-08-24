<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\User;
use App\Modules\ClientCompanion\Application\Services\ReadCompanionConversation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

final class ClientCompanionHistory extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected string $view = 'filament.pages.client-companion-history';

    public function getTitle(): string
    {
        return 'AI-компаньон / История общения';
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $actor = Auth::user();
        $client = $this->getRecord();
        abort_unless($actor instanceof User && $client instanceof Client, 404);

        return [
            'client' => $client,
            'companion' => app(ReadCompanionConversation::class)->forStaff($actor, $client, beforeMessageId: request()->integer('before') ?: null),
            'canManage' => app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ManageCompanionHandoff,
            ),
            'canExport' => app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ExportCompanionHistory,
            ),
            'canExportMetadata' => app(OrganizationAuthorizer::class)->allows(
                $actor,
                app(OrganizationContext::class)->organization(),
                OrganizationPermission::ExportCompanionMetadata,
            ),
            'urls' => [
                'reply' => route('admin.clients.companion.reply', ['client' => $client]),
                'resolve' => route('admin.clients.companion.resolve', ['client' => $client]),
                'resume' => route('admin.clients.companion.resume', ['client' => $client]),
                'reset' => route('admin.clients.companion.reset', ['client' => $client]),
                'history' => ClientResource::getUrl('companion', ['record' => $client]),
                'export' => route('admin.clients.companion.export', ['client' => $client]),
                'metadataExport' => route('admin.clients.companion.metadata-export', ['client' => $client]),
            ],
        ];
    }
}
