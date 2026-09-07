<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\AI\Application\Actions\ExportAiRun;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AiRunExportController extends Controller
{
    public function __invoke(Request $request, int $runId, ExportAiRun $export): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $format = strtolower((string) $request->query('format', 'json'));
        $identity = strtolower((string) $request->query('identity', 'identified'));
        $content = $export->handle($actor, $runId, $format, $identity);
        $filename = 'ai-run-'.$runId.'-'.($identity === 'anonymized' ? 'anonymized-' : '').$format;

        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, $filename, [
            'Content-Type' => $format === 'txt' ? 'text/plain; charset=UTF-8' : 'application/json; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
