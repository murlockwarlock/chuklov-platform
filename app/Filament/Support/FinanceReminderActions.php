<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Application\RequestFinancialObligationReminder;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FinanceReminderActions
{
    public static function forObligation(): Action
    {
        return Action::make('sendReminder')
            ->label(__('Отправить напоминание'))
            ->color('warning')
            ->icon('heroicon-o-bell-alert')
            ->visible(function (FinancialObligation $record): bool {
                $actor = auth()->user();
                $reconciliation = app(FinancePresentation::class)->reconciliation($record);

                return $actor instanceof User
                    && app(FinanceAuthorization::class)->allowsManage($actor)
                    && $reconciliation?->displayOutstanding->isPositive() === true;
            })
            ->action(function (FinancialObligation $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    app(RequestFinancialObligationReminder::class)->handle($actor, $record, Str::uuid()->toString());
                    Notification::make()->success()->title(__('Напоминание поставлено в очередь.'))->send();
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first();
                    Notification::make()
                        ->danger()
                        ->title(is_string($message) && $message !== '' ? $message : __('Напоминание не отправлено.'))
                        ->send();
                }
            });
    }
}
