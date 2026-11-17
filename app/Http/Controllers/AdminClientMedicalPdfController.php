<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\GenerateClientMedicalPdf;
use Illuminate\Http\Response;

final class AdminClientMedicalPdfController extends Controller
{
    public function __invoke(Client $client, GenerateClientMedicalPdf $generator): Response
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $pdf = $generator->handle($actor, $client);
        $filename = 'medical-questionnaire-'.$client->getKey().'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
