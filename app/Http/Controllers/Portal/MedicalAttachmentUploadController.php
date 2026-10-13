<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\UploadClientMedicalAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MedicalAttachmentUploadController extends Controller
{
    public function __invoke(Request $request, UploadClientMedicalAttachment $upload): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'file'], 'upload_key' => ['required', 'uuid']]);
        $upload->handle($data['file'], $data['upload_key']);

        return back();
    }
}
