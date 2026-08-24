<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\RecordPortalCompanionMessageRequest;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Actions\RecordCompanionFeedback;
use App\Modules\ClientCompanion\Application\Actions\ResetCompanionContext;
use App\Modules\ClientCompanion\Application\Actions\UploadCompanionImages;
use App\Modules\ClientCompanion\Application\Services\ReadCompanionConversation;
use App\Modules\ClientCompanion\Domain\Enums\CompanionFeedbackValue;
use App\Modules\ClientCompanion\Domain\Enums\CompanionImageReferenceMode;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class CompanionController extends Controller
{
    public function index(Request $request, ClientPortalContext $context, ReadCompanionConversation $reader): Response
    {
        $before = $request->integer('before') ?: null;

        return Inertia::render('Portal/Companion', [
            'companion' => $reader->forClient($context->client(), beforeMessageId: $before),
            'urls' => [
                'send' => route('portal.companion.send'),
                'feedback' => route('portal.companion.feedback', ['messageId' => '__id__']),
                'reset' => route('portal.companion.reset'),
                'history' => route('portal.companion'),
            ],
        ]);
    }

    public function send(
        RecordPortalCompanionMessageRequest $request,
        ClientPortalContext $context,
        AcceptCompanionMessage $accept,
        UploadCompanionImages $upload,
    ): RedirectResponse {
        $body = trim((string) $request->input('body', ''));
        $files = array_values(array_filter((array) $request->file('images', [])));
        if ($body === '' && $files === []) {
            throw ValidationException::withMessages(['body' => 'Добавьте сообщение или изображение.']);
        }
        $checksums = array_map(static function (mixed $file): string {
            $path = $file instanceof UploadedFile ? $file->getRealPath() : false;
            $checksum = $path === false ? false : hash_file('sha256', $path);

            return is_string($checksum) ? $checksum : 'invalid';
        }, $files);
        $reinspectRecentImages = $request->boolean('reinspect_recent_images');
        $payloadHash = hash('sha256', json_encode([
            'body' => $body,
            'checksums' => $checksums,
            'reinspect_recent_images' => $reinspectRecentImages,
        ], JSON_THROW_ON_ERROR));
        $organizationId = app(OrganizationContext::class)->id();
        $existing = CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->where('idempotency_key', (string) $request->string('idempotency_key'))
            ->first();
        if ($existing !== null) {
            abort_unless($existing->request_hash === $payloadHash, 409, 'Запрос уже принят с другими данными.');

            return back()->with('companion_message_accepted', true);
        }
        $attachments = $files === [] ? [] : app(UploadCompanionImages::class)->handle($context->client(), $files);
        $accept->handle(
            client: $context->client(),
            channel: 'portal',
            body: $body,
            idempotencyKey: (string) $request->string('idempotency_key'),
            originExternalId: 'portal:'.(string) $request->string('idempotency_key'),
            transportChatId: null,
            locale: app()->getLocale(),
            attachmentIds: array_map(static fn ($attachment): int => (int) $attachment->getKey(), $attachments),
            payloadHash: $payloadHash,
            imageReferenceMode: $reinspectRecentImages ? CompanionImageReferenceMode::RecentTurn : CompanionImageReferenceMode::None,
        );

        return back()->with('companion_message_accepted', true);
    }

    public function feedback(Request $request, int $messageId, ClientPortalContext $context, RecordCompanionFeedback $feedback): RedirectResponse
    {
        $value = CompanionFeedbackValue::tryFrom((string) $request->input('value'));
        abort_unless($value instanceof CompanionFeedbackValue, 422);
        $feedback->handle($context->client(), $messageId, $value, $request->input('reason'));

        return back();
    }

    public function reset(ClientPortalContext $context, ResetCompanionContext $reset): RedirectResponse
    {
        $reset->handleForClient($context->client());

        return redirect()->route('portal.companion')->with('companion_context_reset', true);
    }
}
