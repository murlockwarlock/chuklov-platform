<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Support\SupportedLocale;

final class CompanionClientMessage
{
    public function __construct(
        public readonly string $locale,
        public readonly string $failure,
        public readonly string $unavailable,
        public readonly string $specialistNotified,
        public readonly string $humanTakeover,
        public readonly string $urgentSafety,
        public readonly string $outOfScope,
    ) {}

    public static function from(?string $locale): self
    {
        $isRussian = SupportedLocale::normalize($locale) === 'ru';

        return $isRussian
            ? new self(
                locale: 'ru',
                failure: 'Не получилось подготовить ответ.',
                unavailable: 'Помощник сейчас недоступен. Попробуйте позже.',
                specialistNotified: 'Специалист уведомлён. Пока он не подключился, помощник продолжит отвечать.',
                humanTakeover: 'К диалогу подключился специалист. AI-помощник временно не отвечает.',
                urgentSafety: 'Это сообщение требует внимания специалиста. Если нужна срочная медицинская помощь, обратитесь в экстренную службу.',
                outOfScope: 'Я не могу ответить на этот вопрос. Могу позвать специалиста.',
            )
            : new self(
                locale: 'en',
                failure: 'I could not prepare a response.',
                unavailable: 'The assistant is temporarily unavailable. Please try again later.',
                specialistNotified: 'A specialist has been notified. The assistant will keep responding until they join.',
                humanTakeover: 'A specialist has joined the conversation. The AI assistant is temporarily paused.',
                urgentSafety: 'This message needs a specialist’s attention. If you need urgent medical help, contact emergency services.',
                outOfScope: 'I can’t answer this question. I can ask a specialist to help.',
            );
    }

    public function imageFailure(): string
    {
        return $this->locale === 'ru'
            ? 'Не удалось безопасно обработать одно или несколько изображений. Отправьте их ещё раз или выберите меньше изображений.'
            : 'One or more images could not be processed safely. Please send them again or choose fewer images.';
    }

    public function imageLimitFailure(): string
    {
        return $this->locale === 'ru'
            ? 'В одном сообщении можно обработать меньше изображений. Отправьте их несколькими сообщениями.'
            : 'This message contains too many images to process. Please send fewer images at a time.';
    }

    public function documentFailure(): string
    {
        return $this->locale === 'ru'
            ? 'Не удалось безопасно обработать PDF-документ. Проверьте файл и отправьте его ещё раз.'
            : 'The PDF document could not be processed safely. Check the file and send it again.';
    }

    public function albumIncomplete(): string
    {
        return $this->locale === 'ru'
            ? 'Фотоальбом получен не полностью. Чтобы учесть все изображения вместе, отправьте, пожалуйста, весь альбом ещё раз.'
            : 'The photo album was not received completely. Please send the whole album again so all images can be considered together.';
    }
}
