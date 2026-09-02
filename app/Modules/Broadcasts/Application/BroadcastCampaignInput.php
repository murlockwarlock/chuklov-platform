<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final readonly class BroadcastCampaignInput
{
    public function __construct(private SegmentDefinition $segments, private BroadcastSegmentSummary $summaries) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function normalize(int $organizationId, array $attributes): array
    {
        $allowed = ['name', 'send_mode', 'audience_type', 'selected_client_ids', 'channel_priority', 'segment_definition', 'message_mode', 'message_body', 'template_version_ru_id', 'template_version_en_id', 'scheduled_at'];
        if (array_diff(array_keys($attributes), $allowed) !== []) {
            throw ValidationException::withMessages(['name' => 'Форма содержит неподдерживаемые поля.']);
        }

        $name = is_string($attributes['name'] ?? null) ? trim($attributes['name']) : '';
        if ($name === '' || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['name' => 'Укажите название длиной до 160 символов.']);
        }

        $mode = $attributes['send_mode'] ?? 'immediate';
        if (! in_array($mode, ['immediate', 'scheduled'], true)) {
            throw ValidationException::withMessages(['send_mode' => 'Выберите способ запуска рассылки.']);
        }

        $channels = $attributes['channel_priority'] ?? [];
        if (! is_array($channels) || array_values(array_unique($channels)) !== ['telegram']) {
            throw ValidationException::withMessages(['channel_priority' => 'Выберите доступный способ связи.']);
        }

        $rawFilters = $attributes['segment_definition'] ?? [];
        if (! is_array($rawFilters) || ! array_is_list($rawFilters)) {
            throw ValidationException::withMessages(['segment_definition' => 'Расширенный выбор имеет неверный формат.']);
        }
        $audienceType = $attributes['audience_type'] ?? ($rawFilters === [] ? 'all' : 'segment');
        if (! in_array($audienceType, ['selected', 'all', 'segment'], true)) {
            throw ValidationException::withMessages(['audience_type' => 'Выберите способ выбора клиентов.']);
        }
        $selectedClientIds = $this->selectedClientIds($organizationId, $audienceType, $attributes['selected_client_ids'] ?? []);
        $filters = $audienceType === 'segment' ? $this->segments->validate($rawFilters) : [];

        $messageMode = $this->messageMode($attributes);
        $ru = $messageMode === 'saved_template'
            ? $this->template($organizationId, $attributes['template_version_ru_id'] ?? null, 'ru')
            : null;
        $en = $messageMode === 'saved_template'
            ? $this->template($organizationId, $attributes['template_version_en_id'] ?? null, 'en')
            : null;
        if ($messageMode === 'saved_template' && $ru === null && $en === null) {
            throw ValidationException::withMessages(['template_version_ru_id' => 'Нет готовых шаблонов для этого типа сообщения. Создайте сообщение или выберите шаблон.']);
        }
        $messageBody = $messageMode === 'compose' ? $this->messageBody($attributes['message_body'] ?? null) : null;

        $scheduledAt = null;
        if ($mode === 'scheduled') {
            try {
                $timezone = Organization::query()->findOrFail($organizationId)->defaultTimezone();
                $scheduledAt = $this->scheduledInstant($attributes['scheduled_at'] ?? null, $timezone);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['scheduled_at' => 'Укажите корректные дату и время отправки.']);
            }
            if ($scheduledAt->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['scheduled_at' => 'Время запланированной отправки должно быть в будущем.']);
            }
        }

        return [
            'name' => $name,
            'send_mode' => $mode,
            'audience_type' => $audienceType,
            'channel_priority' => ['telegram'],
            'segment_definition' => $filters,
            'selected_client_ids' => $selectedClientIds,
            'message_mode' => $messageMode,
            'message_body' => $messageBody,
            'segment_summary' => $this->summary($organizationId, $audienceType, $selectedClientIds, $filters),
            'template_version_ru_id' => $ru?->getKey(),
            'template_version_en_id' => $en?->getKey(),
            'scheduled_at' => $scheduledAt,
        ];
    }

    private function scheduledInstant(mixed $value, string $timezone): CarbonImmutable
    {
        $wallClock = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i:s')
            : trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/D', $wallClock, $matches) !== 1) {
            throw new \InvalidArgumentException('The scheduled wall-clock value is invalid.');
        }

        $seconds = isset($matches[6]) ? (int) $matches[6] : 0;
        $local = CarbonImmutable::createSafe(
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
            (int) $matches[4],
            (int) $matches[5],
            $seconds,
            new DateTimeZone($timezone),
        );
        if (! $local instanceof CarbonImmutable) {
            throw new \InvalidArgumentException('The scheduled wall-clock value is invalid.');
        }
        $expectedFormat = isset($matches[6]) ? 'Y-m-d H:i:s' : 'Y-m-d H:i';
        if ($local->format($expectedFormat) !== $wallClock) {
            throw new \InvalidArgumentException('The scheduled wall-clock value is invalid.');
        }

        return $local->utc();
    }

    private function template(int $organizationId, mixed $id, string $locale): ?NotificationTemplateVersion
    {
        if ($id === null || $id === '') {
            return null;
        }
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw ValidationException::withMessages(["template_version_{$locale}_id" => 'Выбран неверный шаблон.']);
        }

        $version = NotificationTemplateVersion::query()->where('organization_id', $organizationId)->whereKey((int) $id)->where('status', NotificationTemplateStatus::Published->value)->whereHas('template', fn ($query) => $query->where('organization_id', $organizationId)->where('locale', $locale)->where('purpose', ScenarioRulePurpose::Marketing->value)->where('is_active', true))->first();
        if ($version === null) {
            throw ValidationException::withMessages(["template_version_{$locale}_id" => 'Шаблон недоступен для маркетинговой рассылки.']);
        }

        $variables = $version->variables;
        if (array_diff($variables, ScenarioTemplateVariableCatalog::allowedForPurpose(ScenarioRulePurpose::Marketing)) !== []) {
            throw ValidationException::withMessages(["template_version_{$locale}_id" => 'Шаблон содержит данные, недоступные для рассылки.']);
        }
        try {
            $used = ScenarioTemplateVariableCatalog::used($version->body, (string) $version->subject);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(["template_version_{$locale}_id" => 'Шаблон содержит неподдерживаемые данные.']);
        }
        if (array_diff($used, $variables) !== []) {
            throw ValidationException::withMessages(["template_version_{$locale}_id" => 'Шаблон содержит незаявленные данные.']);
        }

        return $version;
    }

    /** @return list<int> */
    private function selectedClientIds(int $organizationId, string $audienceType, mixed $values): array
    {
        if ($audienceType !== 'selected') {
            return [];
        }
        if (! is_array($values) || ! array_is_list($values) || $values === [] || count($values) > 10000) {
            throw ValidationException::withMessages(['selected_client_ids' => 'Выберите хотя бы одного клиента.']);
        }

        $ids = [];
        foreach ($values as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                throw ValidationException::withMessages(['selected_client_ids' => 'Выбран неверный клиент.']);
            }
            $id = (int) $value;
            if ($id < 1 || in_array($id, $ids, true)) {
                throw ValidationException::withMessages(['selected_client_ids' => 'Список клиентов содержит повтор или неверный ID.']);
            }
            $ids[] = $id;
        }

        $found = Client::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->count();
        if ($found !== count($ids)) {
            throw ValidationException::withMessages(['selected_client_ids' => 'Выберите клиентов только из текущей организации.']);
        }

        return $ids;
    }

    /** @param array<string, mixed> $attributes */
    private function messageMode(array $attributes): string
    {
        $mode = $attributes['message_mode'] ?? null;
        if ($mode === null) {
            $mode = filled($attributes['message_body'] ?? null) ? 'compose' : 'saved_template';
        }
        if (! in_array($mode, ['compose', 'saved_template'], true)) {
            throw ValidationException::withMessages(['message_mode' => 'Выберите способ подготовки сообщения.']);
        }

        return $mode;
    }

    private function messageBody(mixed $value): string
    {
        $body = is_string($value) ? trim($value) : '';
        if ($body === '' || mb_strlen($body) > 100000) {
            throw ValidationException::withMessages(['message_body' => 'Напишите текст сообщения.']);
        }

        try {
            $used = ScenarioTemplateVariableCatalog::used($body);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['message_body' => 'В тексте есть неподдерживаемые данные.']);
        }
        if (array_diff($used, ScenarioTemplateVariableCatalog::allowedForPurpose(ScenarioRulePurpose::Marketing)) !== []) {
            throw ValidationException::withMessages(['message_body' => 'В рассылке можно использовать только имя и язык клиента.']);
        }

        return $body;
    }

    /** @param list<int> $selectedClientIds
     * @param  list<array{key: string, operator: string, value: mixed}>  $filters
     */
    private function summary(int $organizationId, string $audienceType, array $selectedClientIds, array $filters): string
    {
        if ($audienceType === 'all') {
            return 'Всем клиентам с согласием';
        }
        if ($audienceType === 'segment') {
            return $this->summaries->make($filters);
        }

        $names = Client::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $selectedClientIds)
            ->orderBy('full_name')
            ->pluck('full_name')
            ->map(fn (?string $name): string => trim((string) $name))
            ->filter()
            ->values()
            ->all();

        return 'Выбранные клиенты: '.implode(', ', $names);
    }
}
