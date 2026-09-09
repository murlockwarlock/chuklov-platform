<?php

namespace App\Filament\Pages;

use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Application\ScenarioNotificationCatalog;
use App\Modules\Scenarios\Application\UpdateScenarioRule;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnitEnum;

final class NotificationCatalog extends Page
{
    protected static ?string $title = 'Каталог уведомлений';

    protected static ?string $navigationLabel = 'Каталог уведомлений';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Коммуникации';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.notification-catalog';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(OrganizationAuthorizer::class)->allows(
            $actor,
            app(OrganizationContext::class)->organization(),
            OrganizationPermission::ViewScenarios,
        );
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $organizationId = app(OrganizationContext::class)->id();
        $rules = ScenarioRule::query()
            ->where('organization_id', $organizationId)
            ->where('system_managed', false)
            ->with(['templateVersion.template'])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ScenarioRule $rule): string => $rule->trigger_event->value);
        $deliveryStates = DB::table('scenario_deliveries as deliveries')
            ->join('scenario_actions as actions', function ($join): void {
                $join->on('actions.id', '=', 'deliveries.scenario_action_id')
                    ->on('actions.organization_id', '=', 'deliveries.organization_id');
            })
            ->where('deliveries.organization_id', $organizationId)
            ->select('actions.trigger_event', 'deliveries.channel', 'deliveries.status', DB::raw('count(*) as total'))
            ->groupBy('actions.trigger_event', 'deliveries.channel', 'deliveries.status')
            ->get()
            ->groupBy('trigger_event');

        return array_map(function (array $definition) use ($rules, $deliveryStates): array {
            $eventRules = $rules->get($definition['event'], collect());
            $channels = $eventRules
                ->flatMap(fn (ScenarioRule $rule): array => $rule->channel_priority)
                ->unique()
                ->values()
                ->all();
            $templates = $eventRules
                ->map(fn (ScenarioRule $rule): string => (string) ($rule->templateVersion->template->name ?? $definition['template']))
                ->unique()
                ->values()
                ->all();
            $state = $deliveryStates->get($definition['event'], collect())
                ->map(fn (object $item): string => $this->deliveryLabel((string) $item->status, (int) $item->total))
                ->values()
                ->all();
            $ruleItems = $eventRules
                ->flatMap(function (ScenarioRule $rule) use ($definition, $deliveryStates): array {
                    $channels = $rule->channel_priority !== [] ? $rule->channel_priority : ['—'];

                    return array_map(fn (string $channel): array => [
                        'id' => (int) $rule->getKey(),
                        'channel' => $this->channelLabel($channel),
                        'recipient' => $this->recipientLabel($rule, $definition['recipients']),
                        'template' => (string) ($rule->templateVersion->template->name ?? $definition['template']),
                        'enabled' => $rule->is_enabled,
                        'delivery' => $this->ruleDeliveryLabel($deliveryStates, $definition['event'], $channel),
                    ], $channels);
                })
                ->values()
                ->all();

            return [
                ...$definition,
                'configured' => $eventRules->isNotEmpty(),
                'enabled' => $eventRules->isNotEmpty()
                    ? $eventRules->contains(fn (ScenarioRule $rule): bool => $rule->is_enabled)
                    : $definition['enabled'],
                'channels' => $channels !== [] ? $this->channelLabels($channels) : $definition['channels'],
                'template' => $templates !== [] ? implode(', ', $templates) : $definition['template'],
                'delivery' => $state === [] ? 'Отправок пока нет' : implode(', ', $state),
                'rules' => $ruleItems,
                'rulesUrl' => ScenarioRuleResource::getUrl('index'),
                'templatesUrl' => NotificationTemplateResource::getUrl('index'),
            ];
        }, ScenarioNotificationCatalog::definitions());
    }

    public function toggleRule(int $ruleId): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $rule = ScenarioRule::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->where('system_managed', false)
            ->whereKey($ruleId)
            ->firstOrFail();

        $enabled = ! $rule->is_enabled;
        app(UpdateScenarioRule::class)->handle($actor, $rule, [
            'rule_key' => $rule->rule_key,
            'name' => $rule->name,
            'trigger_event' => $rule->trigger_event->value,
            'is_enabled' => $enabled,
            'delay_value' => $rule->delay_value,
            'delay_unit' => $rule->delay_unit->value,
            'purpose' => $rule->purpose->value,
            'conditions' => $rule->conditions,
            'recipient_strategy' => $rule->recipient_strategy,
            'channel_priority' => $rule->channel_priority,
            'template_version_id' => $rule->template_version_id,
            'max_occurrences' => $rule->max_occurrences,
            'repeat_interval_value' => $rule->repeat_interval_value,
            'repeat_interval_unit' => $rule->repeat_interval_unit?->value,
        ]);

        Notification::make()
            ->success()
            ->title($enabled ? 'Уведомление включено' : 'Уведомление выключено')
            ->send();
    }

    private function deliveryLabel(string $status, int $total): string
    {
        return match ($status) {
            'delivered' => 'доставлено '.$total,
            'pending', 'processing', 'retryable' => 'в работе '.$total,
            'unavailable' => 'нет канала '.$total,
            'permanent_failure' => 'ошибка '.$total,
            'suppressed' => 'подавлено '.$total,
            default => $status.' '.$total,
        };
    }

    /** @param array<int, string> $channels
     * @return list<string>
     */
    private function channelLabels(array $channels): array
    {
        return array_values(array_map(fn (string $channel): string => $this->channelLabel($channel), $channels));
    }

    private function channelLabel(string $channel): string
    {
        return $channel === 'database' ? 'CRM' : ($channel === 'telegram' ? 'Telegram' : $channel);
    }

    private function recipientLabel(ScenarioRule $rule, string $fallback): string
    {
        $strategy = $rule->recipient_strategy;

        return match ($strategy['type'] ?? null) {
            'client' => 'Клиент',
            'assigned_specialist' => 'Назначенный специалист',
            'members' => 'Выбранные сотрудники',
            'roles' => $this->rolesLabel($strategy['roles'] ?? [], $fallback),
            default => $fallback,
        };
    }

    private function rolesLabel(mixed $roles, string $fallback): string
    {
        if (! is_array($roles)) {
            return $fallback;
        }

        $labels = array_filter(array_map(
            fn (mixed $role): string => match ((string) $role) {
                'owner' => 'Владелец',
                'administrator' => 'Администратор',
                'staff' => 'Сотрудник',
                default => (string) $role,
            },
            $roles,
        ));

        return $labels === [] ? $fallback : implode(', ', $labels);
    }

    /** @param Collection<int|string, Collection<int, \stdClass>> $deliveryStates */
    private function ruleDeliveryLabel(Collection $deliveryStates, string $event, string $channel): string
    {
        $states = $deliveryStates->get($event, collect())
            ->filter(fn (object $item): bool => (string) $item->channel === $channel)
            ->map(fn (object $item): string => $this->deliveryLabel((string) $item->status, (int) $item->total))
            ->values()
            ->all();

        return $states === [] ? 'Отправок пока нет' : implode(', ', $states);
    }
}
