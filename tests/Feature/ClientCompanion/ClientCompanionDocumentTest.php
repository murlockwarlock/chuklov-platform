<?php

namespace Tests\Feature\ClientCompanion;

use App\Modules\AI\Application\Attachments\AiAttachmentResolver;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\ClientCompanion\Application\Actions\HandleTelegramCompanionDocument;
use App\Modules\ClientCompanion\Application\Services\AssembleCompanionContext;
use App\Modules\ClientCompanion\Domain\Models\CompanionMessageAttachment;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\StoredDocument;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

final class ClientCompanionDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->organization = Organization::factory()->create();
        $this->client = Client::factory()->forOrganization($this->organization)->create();
        app(OrganizationContext::class)->set($this->organization);
        config()->set('tenancy.default_organization_id', $this->organization->getKey());
        Queue::fake();
    }

    public function test_verified_private_pdf_uses_the_companion_document_path_and_document_model_modality(): void
    {
        $this->verifyTelegram('810101');
        $pdf = $this->pdf('telegram-report.pdf');
        $contents = (string) file_get_contents($pdf->getRealPath());
        $bot = FakeNutgram::instance(null, [
            new Response(200, [], json_encode([
                'ok' => true,
                'result' => [
                    'file_id' => 'document-file-id',
                    'file_unique_id' => 'document-file-unique-id',
                    'file_path' => 'documents/telegram-report.pdf',
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], $contents),
        ]);
        $bot->setCommonUser(User::make(id: 810101, is_bot: false, first_name: 'Client', language_code: 'ru'));
        $bot->setCommonChat(Chat::fromArray(['id' => 910101, 'type' => ChatType::PRIVATE->value]));
        $handler = app(HandleTelegramCompanionDocument::class);
        $bot->onDocument(function (Nutgram $bot) use ($handler): void {
            $handler->handle($bot);
        });

        $bot->hearMessage([
            'message_id' => 801,
            'document' => [
                'file_id' => 'document-file-id',
                'file_unique_id' => 'document-file-unique-id',
                'file_name' => 'telegram-report.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => strlen($contents),
            ],
            'caption' => 'Посмотри заключение',
        ]);
        $bot->reply();

        $turn = CompanionTurn::query()->sole();
        self::assertSame('document', $turn->input_modality);
        self::assertSame(1, CompanionMessageAttachment::query()->count());
        $attachment = CompanionMessageAttachment::query()->sole()->medicalAttachment;
        self::assertSame(AttachmentType::CompanionDocument, $attachment?->attachment_type);
        self::assertNotNull($attachment);
        Storage::disk('private')->assertExists($attachment->storage_path);

        $context = app(AssembleCompanionContext::class)->handle(
            $this->organization->getKey(),
            $turn->conversation()->firstOrFail(),
            $turn->fresh(),
        );
        self::assertSame([AiModelModality::DocumentInput], $context['required_modalities']);
        self::assertStringContainsString('[Документ: 1]', $context['current_message']);

        $resolved = app(AiAttachmentResolver::class)->resolve(
            organizationId: $this->organization->getKey(),
            capability: AiCapability::ClientCompanion,
            references: [new AiInputReference('companion_attachment', $attachment->getKey())],
            actor: null,
            clientId: $this->client->getKey(),
        );
        self::assertInstanceOf(StoredDocument::class, $resolved['files'][0]);
    }

    public function test_unsupported_telegram_document_is_recorded_for_a_user_facing_failure_instead_of_being_dropped(): void
    {
        $this->verifyTelegram('810102');
        $contents = 'not a pdf';
        $bot = FakeNutgram::instance(null, [
            new Response(200, [], json_encode([
                'ok' => true,
                'result' => [
                    'file_id' => 'text-file-id',
                    'file_unique_id' => 'text-file-unique-id',
                    'file_path' => 'documents/not-a-pdf.txt',
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], $contents),
        ]);
        $bot->setCommonUser(User::make(id: 810102, is_bot: false, first_name: 'Client', language_code: 'ru'));
        $bot->setCommonChat(Chat::fromArray(['id' => 910102, 'type' => ChatType::PRIVATE->value]));
        $handler = app(HandleTelegramCompanionDocument::class);
        $bot->onDocument(function (Nutgram $bot) use ($handler): void {
            $handler->handle($bot);
        });

        $bot->hearMessage([
            'message_id' => 802,
            'document' => [
                'file_id' => 'text-file-id',
                'file_unique_id' => 'text-file-unique-id',
                'file_name' => 'not-a-pdf.txt',
                'mime_type' => 'text/plain',
                'file_size' => strlen($contents),
            ],
        ]);
        $bot->reply();

        self::assertSame('document_unavailable', CompanionTurn::query()->sole()->input_failure_code);
        self::assertSame(0, CompanionMessageAttachment::query()->count());
    }

    private function verifyTelegram(string $externalId): void
    {
        ClientChannelIdentity::factory()->forClient($this->client)->create([
            'channel' => 'telegram',
            'external_id' => $externalId,
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verified_at' => now(),
        ]);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    }
}
