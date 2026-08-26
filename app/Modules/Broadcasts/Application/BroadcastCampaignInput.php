<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use Carbon\CarbonImmutable;
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
        $allowed = ['name', 'send_mode', 'channel_priority', 'segment_definition', 'template_version_ru_id', 'template_version_en_id', 'scheduled_at'];
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

        $filters = $this->segments->validate($attributes['segment_definition'] ?? []);
        $ru = $this->template($organizationId, $attributes['template_version_ru_id'] ?? null, 'ru');
        $en = $this->template($organizationId, $attributes['template_version_en_id'] ?? null, 'en');
        if ($ru === null && $en === null) {
            throw ValidationException::withMessages(['template_version_ru_id' => 'Выберите хотя бы один опубликованный маркетинговый шаблон.']);
        }

        $scheduledAt = null;
        if ($mode === 'scheduled') {
            try {
                $scheduledAt = CarbonImmutable::parse((string) ($attributes['scheduled_at'] ?? ''))->utc();
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
            'channel_priority' => ['telegram'],
            'segment_definition' => $filters,
            'segment_summary' => $this->summaries->make($filters),
            'template_version_ru_id' => $ru?->getKey(),
            'template_version_en_id' => $en?->getKey(),
            'scheduled_at' => $scheduledAt,
        ];
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

        return $version;
    }
}
