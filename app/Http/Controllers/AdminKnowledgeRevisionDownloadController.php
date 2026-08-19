<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Knowledge\Application\DownloadKnowledgeRevision;
use App\Modules\Knowledge\Domain\Exceptions\KnowledgeRevisionFileUnavailable;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminKnowledgeRevisionDownloadController extends Controller
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function __invoke(
        Request $request,
        int $knowledgeSourceId,
        int $knowledgeRevisionId,
        DownloadKnowledgeRevision $downloadAction,
    ): StreamedResponse {
        abort_unless($request->hasValidSignature(), 403, 'Ссылка для скачивания недействительна или срок её действия истёк.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $organization = $this->context->organization();
        $source = KnowledgeSource::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($knowledgeSourceId)
            ->firstOrFail();
        $revision = KnowledgeRevision::query()
            ->where('organization_id', $organization->getKey())
            ->where('knowledge_source_id', $source->getKey())
            ->whereKey($knowledgeRevisionId)
            ->firstOrFail();

        try {
            $result = $downloadAction->handle($actor, $source, $revision);
        } catch (KnowledgeRevisionFileUnavailable) {
            abort(404, 'Файл недоступен.');
        }

        return response()->streamDownload(
            function () use ($result): void {
                fpassthru($result->stream);
                if (is_resource($result->stream)) {
                    fclose($result->stream);
                }
            },
            $result->filename,
            [
                'Content-Type' => $result->mimeType,
                'Content-Length' => (string) $result->sizeBytes,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
