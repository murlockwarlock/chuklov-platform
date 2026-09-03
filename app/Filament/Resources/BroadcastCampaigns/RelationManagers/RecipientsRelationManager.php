<?php

namespace App\Filament\Resources\BroadcastCampaigns\RelationManagers;

use App\Filament\Support\BroadcastFailurePresentation;
use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Domain\Models\BroadcastRecipient;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Получатели рассылки';

    public function table(Table $table): Table
    {
        $actor = auth()->user();
        $campaign = $this->getOwnerRecord();
        abort_unless($actor instanceof User, 403);
        abort_unless($campaign instanceof BroadcastCampaign, 404);
        abort_unless((int) $campaign->organization_id === app(OrganizationContext::class)->id(), 404);

        return $table
            ->poll(fn (): ?string => $this->shouldPoll() ? '5s' : null)
            ->columns([
                TextColumn::make('client.full_name')->label('Клиент')->placeholder('Имя не указано')->limit(80),
                TextColumn::make('state')->label('Состояние')->badge()->formatStateUsing(fn (BroadcastRecipientState|string $state): string => self::stateLabel($state)),
                TextColumn::make('channel')->label('Канал')->formatStateUsing(fn (?string $state): string => $state === 'telegram' ? 'Telegram' : '—'),
                TextColumn::make('reason')->label('Причина')->state(function (BroadcastRecipient $record): string {
                    $code = $record->last_error_code ?: $record->exclusion_code;

                    return $code === null ? '—' : BroadcastFailurePresentation::label($code);
                })->placeholder('—')->wrap(),
                TextColumn::make('delivered_at')->label('Доставлено')->dateTime('d.m.Y H:i')->placeholder('—'),
                TextColumn::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->modifyQueryUsing(function (Builder $query): Builder {
                $recipientTable = (new BroadcastRecipient)->getTable();

                return $query
                    ->where("{$recipientTable}.organization_id", app(OrganizationContext::class)->id())
                    ->where("{$recipientTable}.kind", 'production')
                    ->select([
                        "{$recipientTable}.id",
                        "{$recipientTable}.organization_id",
                        "{$recipientTable}.campaign_id",
                        "{$recipientTable}.client_id",
                        "{$recipientTable}.state",
                        "{$recipientTable}.channel",
                        "{$recipientTable}.last_error_code",
                        "{$recipientTable}.exclusion_code",
                        "{$recipientTable}.delivered_at",
                        "{$recipientTable}.updated_at",
                    ])
                    ->with(['client:id,organization_id,full_name']);
            })
            ->defaultSort('updated_at', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Получателей пока нет')
            ->emptyStateDescription('После фиксации списка здесь появятся результаты отправки.');
    }

    private function shouldPoll(): bool
    {
        $campaign = $this->getOwnerRecord();
        if (! $campaign instanceof BroadcastCampaign) {
            return false;
        }

        return BroadcastRecipient::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('campaign_id', $campaign->getKey())
            ->where('kind', 'production')
            ->whereIn('state', [BroadcastRecipientState::Pending->value, BroadcastRecipientState::Claimed->value])
            ->exists();
    }

    private static function stateLabel(BroadcastRecipientState|string $state): string
    {
        $state = $state instanceof BroadcastRecipientState ? $state : BroadcastRecipientState::tryFrom($state);

        return match ($state) {
            BroadcastRecipientState::Pending => 'Ожидает отправки',
            BroadcastRecipientState::Suppressed => 'Исключён',
            BroadcastRecipientState::Claimed => 'Отправляется',
            BroadcastRecipientState::Delivered => 'Доставлено',
            BroadcastRecipientState::Failed => 'Не отправлено',
            default => 'Неизвестно',
        };
    }
}
