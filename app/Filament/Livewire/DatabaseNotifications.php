<?php

namespace App\Filament\Livewire;

use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Livewire\DatabaseNotifications as FilamentDatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;

final class DatabaseNotifications extends FilamentDatabaseNotifications
{
    public function getNotificationsQuery(): Builder|Relation
    {
        return parent::getNotificationsQuery()
            ->where('data->organization_id', app(OrganizationContext::class)->id());
    }

    public function getNotification(DatabaseNotification $notification): Notification
    {
        $data = $notification->data;
        $title = (string) ($data['title'] ?? '');
        $body = (string) ($data['body'] ?? '');

        if ($title === 'Сбой AI-компаньона' && str_contains($body, 'AI-компаньон не смог подготовить ответ клиенту')) {
            $data['body'] = $this->humanizeCompanionFailure($body);
        } elseif ($title === 'Низкая оценка клиента') {
            $data['body'] = preg_replace('/Клиент #\d+/', 'Клиент', $body) ?: $body;
        }

        if ($data !== $notification->data) {
            $notification = clone $notification;
            $notification->setAttribute('data', $data);
        }

        return parent::getNotification($notification);
    }

    private function humanizeCompanionFailure(string $body): string
    {
        $reason = str_contains($body, 'провайдер временно недоступен')
            ? 'сервис AI временно недоступен'
            : (str_contains($body, 'неподдерживаемом формате')
                ? 'ответ AI не удалось обработать'
                : 'ответ AI не удалось обработать');

        return 'AI-компаньон не смог подготовить ответ. Причина: '.$reason.'. Откройте диалог и проверьте настройки AI.';
    }
}
