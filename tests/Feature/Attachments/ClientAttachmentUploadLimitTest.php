<?php

namespace Tests\Feature\Attachments;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ClientAttachmentsRelationManager;
use App\Models\User;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class ClientAttachmentUploadLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_successive_crm_uploads_refresh_the_attachment_table(): void
    {
        Storage::fake('private');
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)->test(ClientAttachmentsRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => ViewClient::class,
        ]);
        $component->assertCanSeeTableRecords([]);

        foreach (['first-report.pdf', 'second-report.pdf'] as $filename) {
            $component
                ->mountTableAction('upload')
                ->fillForm([
                    'attachment_type' => AttachmentType::MedicalReport->value,
                    'file' => UploadedFile::fake()->createWithContent($filename, "%PDF-1.4\n({$filename})\n%%EOF\n"),
                ])
                ->callMountedAction()
                ->assertNotified(Notification::make()->title('Файл загружен')->success());

            $attachment = MedicalAttachment::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('original_filename', $filename)
                ->firstOrFail();

            $component->assertCanSeeTableRecords([$attachment]);
        }

        $component->assertCanSeeTableRecords(MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->get());
    }

    public function test_crm_explains_the_medical_attachment_limit_and_logs_the_rejection(): void
    {
        Storage::fake('private');
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            app(UploadMedicalAttachment::class)->handle($admin, new AttachmentUploadCommand(
                file: UploadedFile::fake()->createWithContent('synthetic-report.pdf', "%PDF-1.4\n({$attempt})\n%%EOF\n"),
                clientId: (int) $client->getKey(),
            ));
        }

        Log::spy();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ClientAttachmentsRelationManager::class, [
                'ownerRecord' => $client,
                'pageClass' => ViewClient::class,
            ])
            ->mountTableAction('upload')
            ->fillForm([
                'attachment_type' => AttachmentType::MedicalReport->value,
                'file' => UploadedFile::fake()->createWithContent('sixth-report.pdf', "%PDF-1.4\n(sixth)\n%%EOF\n"),
            ])
            ->callMountedAction()
            ->assertNotified(Notification::make()
                ->title('Файл не загружен')
                ->body('В медицинском профиле уже пять файлов. Откройте список «Файлы и МРТ», чтобы проверить загруженные документы.')
                ->warning());

        self::assertSame(5, MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('attachment_type', AttachmentType::MedicalReport)
            ->count());

        Log::shouldHaveReceived('log')
            ->once()
            ->with('warning', 'crm.client_attachment.upload_rejected', Mockery::on(
                static fn (array $context): bool => $context['organization_id'] === $organization->getKey()
                    && $context['actor_user_id'] === $admin->getKey()
                    && $context['entity_id'] === $client->getKey()
                    && $context['safe_error_code'] === 'medical_attachment_limit_reached'
                    && $context['outcome'] === 'rejected'
                    && ! array_key_exists('filename', $context)
                    && ! array_key_exists('file_contents', $context),
            ));
    }

    public function test_crm_storage_failure_shows_a_searchable_reference_without_logging_exception_text(): void
    {
        Storage::fake('private');
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        $sensitiveFailureText = 'Synthetic private storage failure at medical/secret-path.pdf';
        $storage = Mockery::mock(AttachmentStorageInterface::class);
        $storage->shouldReceive('store')->once()->andThrow(new RuntimeException($sensitiveFailureText));
        $this->app->instance(AttachmentStorageInterface::class, $storage);
        Log::spy();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(ClientAttachmentsRelationManager::class, [
                'ownerRecord' => $client,
                'pageClass' => ViewClient::class,
            ])
            ->mountTableAction('upload')
            ->fillForm([
                'attachment_type' => AttachmentType::MedicalReport->value,
                'file' => UploadedFile::fake()->createWithContent('synthetic-report.pdf', "%PDF-1.4\n(synthetic)\n%%EOF\n"),
            ])
            ->callMountedAction();

        $loggedContext = null;
        Log::shouldHaveReceived('log')
            ->once()
            ->withArgs(static function (string $level, string $event, array $context) use (&$loggedContext, $organization, $admin, $client): bool {
                $loggedContext = $context;

                return $level === 'error'
                    && $event === 'crm.client_attachment.upload_failed'
                    && $context['organization_id'] === $organization->getKey()
                    && $context['actor_user_id'] === $admin->getKey()
                    && $context['entity_id'] === $client->getKey()
                    && $context['safe_error_code'] === 'private_storage_or_persistence_failed'
                    && $context['outcome'] === 'failed';
            });

        self::assertIsArray($loggedContext);
        self::assertNotEmpty($loggedContext['reference_id']);
        self::assertSame(RuntimeException::class, $loggedContext['exception_class']);
        self::assertStringNotContainsString($sensitiveFailureText, json_encode($loggedContext, JSON_THROW_ON_ERROR));
        $component->assertNotified(Notification::make()
            ->title('Файл не загружен')
            ->body("Не удалось сохранить файл. Повторите загрузку. Если ошибка повторится, сообщите администратору код {$loggedContext['reference_id']}.")
            ->danger());
        self::assertSame(0, MedicalAttachment::query()->where('client_id', $client->getKey())->count());
    }
}
