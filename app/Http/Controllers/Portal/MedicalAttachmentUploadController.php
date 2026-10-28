<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\UploadClientMedicalAttachment;
use App\Modules\ClientPortal\Domain\Exceptions\MedicalAttachmentUploadFailed;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MedicalAttachmentUploadController extends Controller
{
    public function __invoke(Request $request, UploadClientMedicalAttachment $upload): Response
    {
        $data = $request->validate(['file' => ['required', 'file'], 'upload_key' => ['required', 'uuid'], 'patient_profile_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        try {
            $upload->handle($data['file'], $data['upload_key'], isset($data['patient_profile_id']) ? (int) $data['patient_profile_id'] : null);
        } catch (MedicalAttachmentUploadFailed) {
            $message = __('Не удалось загрузить файл. Повторите загрузку. Если ошибка повторится, снова откройте медицинские данные.');

            return $request->expectsJson()
                ? response()->json(['message' => $message], Response::HTTP_SERVICE_UNAVAILABLE)
                : response($message, Response::HTTP_SERVICE_UNAVAILABLE)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return back();
    }
}
