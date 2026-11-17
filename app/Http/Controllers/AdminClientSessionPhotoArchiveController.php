<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\BuildClientSessionPhotoArchive;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminClientSessionPhotoArchiveController extends Controller
{
    public function __invoke(
        Request $request,
        Client $client,
        BuildClientSessionPhotoArchive $archive,
    ): StreamedResponse {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $path = $archive->handle($actor, $client);

        return response()->streamDownload(static function () use ($path): void {
            readfile($path);
            if (is_file($path)) {
                unlink($path);
            }
        }, 'client-'.$client->getKey().'-session-photos.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
