<?php

namespace App\Modules\ClientCompanion\Application\Services;

final class CompanionClientMessage
{
    public function __construct(
        public readonly string $locale,
        public readonly string $failure,
        public readonly string $handoff,
    ) {}

    public static function from(?string $locale): self
    {
        $isRussian = str_starts_with(strtolower((string) $locale), 'ru');

        return $isRussian
            ? new self(
                locale: 'ru',
                failure: 'Не удалось подготовить ответ. Попробуйте ещё раз или попросите специалиста подключиться.',
                handoff: 'Я передал обращение специалисту. Новые сообщения сохранены, а AI-помощник временно приостановлен.',
            )
            : new self(
                locale: 'en',
                failure: 'I could not prepare a response. Please try again or ask a specialist to join the conversation.',
                handoff: 'I have passed this conversation to a specialist. New messages are saved while the AI Companion is paused.',
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

    public function albumIncomplete(): string
    {
        return $this->locale === 'ru'
            ? 'Фотоальбом получен не полностью. Чтобы учесть все изображения вместе, отправьте, пожалуйста, весь альбом ещё раз.'
            : 'The photo album was not received completely. Please send the whole album again so all images can be considered together.';
    }
}
