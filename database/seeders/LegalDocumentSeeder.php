<?php

namespace Database\Seeders;

use App\Modules\Identity\Application\CreatePlatformLegalDocumentDraft;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Seeder;

final class LegalDocumentSeeder extends Seeder
{
    public function run(): void
    {
        Organization::query()
            ->orderBy('id')
            ->each(function (Organization $organization): void {
                foreach ($this->defaults() as $default) {
                    if (LegalDocument::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('document_type', $default['document_type'])
                        ->where('locale', $default['locale'])
                        ->exists()) {
                        continue;
                    }

                    app(CreatePlatformLegalDocumentDraft::class)->handle(
                        organization: $organization,
                        documentType: $default['document_type'],
                        purpose: $default['purpose'],
                        locale: $default['locale'],
                        version: $default['version'],
                        content: $default['content'],
                        isRequired: $default['is_required'],
                    );
                }
            });
    }

    /** @return list<array{document_type: string, purpose: string, locale: string, version: string, content: string, is_required: bool}> */
    private function defaults(): array
    {
        return [
            [
                'document_type' => 'offer',
                'purpose' => 'offer_consent',
                'locale' => 'ru',
                'version' => '2026-09-03-default-v1',
                'content' => 'Черновик публичной оферты. Перед публикацией замените этот текст на утверждённую оферту и укажите сведения об исполнителе, услугах, порядке оплаты, отмены и возврата, а также реквизиты и контакты.',
                'is_required' => true,
            ],
            [
                'document_type' => 'privacy',
                'purpose' => 'privacy_consent',
                'locale' => 'ru',
                'version' => '2026-09-03-default-v1',
                'content' => 'Черновик политики конфиденциальности. Перед публикацией укажите оператора, цели и основания обработки, категории данных, сроки хранения, права субъекта и контакты для обращений.',
                'is_required' => true,
            ],
            [
                'document_type' => 'medical_disclaimer',
                'purpose' => 'medical_consent',
                'locale' => 'ru',
                'version' => '2026-09-03-default-v1',
                'content' => 'Черновик медицинского дисклеймера. Перед публикацией замените этот текст на утверждённые условия о характере консультаций, ограничениях сервиса и необходимости обращения к врачу.',
                'is_required' => true,
            ],
            [
                'document_type' => 'marketing',
                'purpose' => 'marketing_consent',
                'locale' => 'ru',
                'version' => '2026-09-03-default-v1',
                'content' => 'Черновик согласия на рекламные и методические сообщения. Перед публикацией укажите цели, каналы, порядок отзыва и срок действия согласия.',
                'is_required' => false,
            ],
        ];
    }
}
