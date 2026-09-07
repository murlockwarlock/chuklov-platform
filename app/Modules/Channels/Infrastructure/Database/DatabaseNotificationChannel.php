<?php

namespace App\Modules\Channels\Infrastructure\Database;

use App\Models\User;
use App\Modules\Channels\Domain\Contracts\NotificationChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\NotificationActionButton;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Support\RichText\RichTextDocument;
use Filament\Actions\Action;
use Filament\Notifications\DatabaseNotification;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

final class DatabaseNotificationChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'database';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            supportsInlineButtons: true,
            supportsProactiveDelivery: true,
        );
    }

    public function send(NotificationMessage $message): NotificationDeliveryResult
    {
        if (! ctype_digit($message->recipientExternalId) || (int) $message->recipientExternalId < 1) {
            return NotificationDeliveryResult::unavailable('database_recipient_unavailable');
        }

        $userId = (int) $message->recipientExternalId;

        if (! User::query()->whereKey($userId)->exists()) {
            return NotificationDeliveryResult::unavailable('database_recipient_unavailable');
        }

        try {
            $notification = Notification::make()
                ->title(trim((string) ($message->subject ?: 'Оперативное уведомление')))
                ->body($this->body($message));
            $actions = $this->actions($message);

            if ($actions !== []) {
                $notification->actions($actions);
            }

            DB::table('notifications')->insertOrIgnore([
                'id' => $this->notificationId($message->idempotencyKey),
                'type' => DatabaseNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $userId,
                'data' => json_encode($notification->getDatabaseMessage(), JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return NotificationDeliveryResult::delivered($this->notificationId($message->idempotencyKey));
        } catch (\Throwable) {
            return NotificationDeliveryResult::retryable('database_notification_error');
        }
    }

    private function body(NotificationMessage $message): string
    {
        try {
            return RichTextDocument::plainText($message->body);
        } catch (\InvalidArgumentException) {
            return trim(strip_tags($message->body));
        }
    }

    /** @return list<Action> */
    private function actions(NotificationMessage $message): array
    {
        $buttons = [];
        $candidateButtons = array_merge(
            $message->actionButton === null ? [] : [$message->actionButton],
            $message->actionButtons,
        );

        foreach ($candidateButtons as $index => $button) {
            if (! $button instanceof NotificationActionButton || $button->url === null) {
                continue;
            }

            $buttons[] = Action::make('openNotificationTarget'.$index)
                ->label($button->text)
                ->url($button->url)
                ->button()
                ->markAsRead();
        }

        return $buttons;
    }

    private function notificationId(string $idempotencyKey): string
    {
        $bytes = md5('database|'.$idempotencyKey, true);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x30);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
