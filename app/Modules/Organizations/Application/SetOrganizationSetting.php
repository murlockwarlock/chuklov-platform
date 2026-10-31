<?php

namespace App\Modules\Organizations\Application;

use App\Models\User;
use App\Modules\Analytics\Application\ClientSessionAxisCatalog;
use App\Modules\Attachments\Application\MedicalAudioPolicy;
use App\Modules\ClientPortal\Domain\Enums\RoadMapAvailabilityMode;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingType;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SetOrganizationSetting
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, OrganizationSettingKey $key, string|int|bool $value): OrganizationSetting
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize(
            $actor,
            $organization,
            in_array($key, [
                OrganizationSettingKey::B2bSalesCallDurationMinutes,
                OrganizationSettingKey::B2bZoomHostLicensed,
                OrganizationSettingKey::DefaultTimezone,
                OrganizationSettingKey::HomeVisitOccupiedBufferMinutes,
                OrganizationSettingKey::OnlineConsultationServiceId,
                OrganizationSettingKey::PatientNotificationRecipients,
                OrganizationSettingKey::RoadMapAvailabilityMode,
            ], true)
                ? OrganizationPermission::ManageScheduling
                : OrganizationPermission::ManageSettings,
        );
        $this->validate($key, $value);

        return DB::transaction(function () use ($organization, $actor, $key, $value): OrganizationSetting {
            $setting = $organization->settings()->where('setting_key', $key->value)->first()
                ?? new OrganizationSetting;

            $setting->forceFill([
                'organization_id' => $organization->getKey(),
                'setting_key' => $key->value,
                'value_type' => $key->type(),
                'string_value' => $key->type()->value === 'string' ? (string) $value : null,
                'integer_value' => $key->type()->value === 'integer' ? (int) $value : null,
                'boolean_value' => $key->type()->value === 'boolean' ? (bool) $value : null,
            ]);
            $setting->save();

            if ($key === OrganizationSettingKey::DefaultTimezone) {
                $this->context->invalidateDefaultTimezone();
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'organization.setting.updated',
                targetType: OrganizationSetting::class,
                targetId: (string) $setting->getKey(),
                metadata: ['setting_key' => $key->value, 'value_type' => $key->type()->value],
            );

            return $setting->refresh();
        });
    }

    private function validate(OrganizationSettingKey $key, string|int|bool $value): void
    {
        if ($key->type() === OrganizationSettingType::Integer
            && (! is_int($value) || $value < 0)) {
            throw new InvalidArgumentException('The integer setting value is invalid.');
        }

        if (in_array($key, [
            OrganizationSettingKey::ClientSegmentNewDays,
            OrganizationSettingKey::ClientSegmentRegularCompletedVisits,
            OrganizationSettingKey::ClientSegmentDormantDays,
            OrganizationSettingKey::ClientSegmentSleepingDays,
        ], true) && (! is_int($value) || $value < 1 || $value > 3650)) {
            throw new InvalidArgumentException('The client segment threshold is outside the supported range.');
        }

        if ($key === OrganizationSettingKey::OnlineConsultationServiceId
            && (! is_int($value) || $value < 1)) {
            throw new InvalidArgumentException('The consultation service identifier is invalid.');
        }

        if ($key === OrganizationSettingKey::B2bSalesCallDurationMinutes
            && (! is_int($value) || $value < 1 || $value > 1440)) {
            throw new InvalidArgumentException('The B2B sales-call duration is outside the supported range.');
        }

        if ($key === OrganizationSettingKey::HomeVisitOccupiedBufferMinutes
            && (! is_int($value) || $value > 1440)) {
            throw new InvalidArgumentException('The HomeVisit occupied buffer is outside the supported range.');
        }

        if (in_array($key, [
            OrganizationSettingKey::CompanionContextFirstExchanges,
            OrganizationSettingKey::CompanionContextRecentExchanges,
        ], true) && (! is_int($value) || $value > 20)) {
            throw new InvalidArgumentException('The Companion context setting is outside the platform safety bounds.');
        }

        if ($key->value === OrganizationSettingKey::DefaultLanguage->value) {
            if (! is_string($value) || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $value) !== 1) {
                throw new InvalidArgumentException('The default language must be a valid language tag.');
            }
        }

        if ($key->value === OrganizationSettingKey::DefaultTimezone->value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('The default timezone must be an IANA timezone.');
            }

            try {
                IanaTimezone::from($value);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('The default timezone must be an IANA timezone.');
            }
        }

        if ($key->value === OrganizationSettingKey::HomeVisitTransportDepositCurrency->value) {
            if (! is_string($value) || preg_match('/^[A-Z]{3}$/', $value) !== 1) {
                throw new InvalidArgumentException('The transport deposit currency must be a three-letter uppercase code.');
            }
        }

        if ($key->value === OrganizationSettingKey::OfficeLocation->value
            && (! is_string($value) || trim($value) === '' || mb_strlen($value) > 500)) {
            throw new InvalidArgumentException('The office location is invalid.');
        }

        if (in_array($key, [
            OrganizationSettingKey::AnalyticsTherapyEffectivenessFormulaReference,
            OrganizationSettingKey::AnalyticsLtvPercentFormulaReference,
        ], true) && (! is_string($value) || mb_strlen($value) > 2000)) {
            throw new InvalidArgumentException('The analytics formula reference is invalid.');
        }

        if (in_array($key, [
            OrganizationSettingKey::ClientSegmentWarmLeadServiceIds,
            OrganizationSettingKey::ClientSegmentWarmLeadTargetServiceIds,
        ], true)) {
            if (! is_string($value) || mb_strlen($value) > 4096) {
                throw new InvalidArgumentException('The client segment service list is invalid.');
            }
            $decoded = json_decode($value, true);
            if (! is_array($decoded) || count($decoded) > 100 || array_filter($decoded, static fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) !== []) {
                throw new InvalidArgumentException('The client segment service list is invalid.');
            }
        }

        if ($key === OrganizationSettingKey::ClientSegmentPenaltyTag
            && (! is_string($value) || mb_strlen($value) > 80)) {
            throw new InvalidArgumentException('The client penalty tag is invalid.');
        }

        if ($key === OrganizationSettingKey::PatientNotificationRecipients
            && (! is_string($value) || ! in_array($value, ['patient_only', 'booker_only', 'both'], true))) {
            throw new InvalidArgumentException('The patient notification recipient policy is invalid.');
        }

        if ($key === OrganizationSettingKey::RoadMapAvailabilityMode
            && (! is_string($value) || RoadMapAvailabilityMode::tryFrom($value) === null)) {
            throw new InvalidArgumentException('The Road Map availability mode is invalid.');
        }

        if ($key === OrganizationSettingKey::MedicalAudioAllowedMimeTypes) {
            $decoded = is_string($value) ? json_decode($value, true) : null;
            if (! is_array($decoded) || $decoded === [] || count($decoded) > count(MedicalAudioPolicy::allowedMimeTypes())
                || array_diff($decoded, MedicalAudioPolicy::allowedMimeTypes()) !== []) {
                throw new InvalidArgumentException('The medical audio format policy is invalid.');
            }
        }

        if ($key === OrganizationSettingKey::MedicalAudioBusinessMaxBytes) {
            $hardCap = (int) config('medical.audio_attachment_max_bytes', 67_108_864);
            if (! is_int($value) || $value < 1 || $value > $hardCap) {
                throw new InvalidArgumentException('The medical audio size policy exceeds the server safety limit.');
            }
        }

        if ($key === OrganizationSettingKey::AnalyticsAgeRanges) {
            $decoded = is_string($value) ? json_decode($value, true) : null;
            if (! is_array($decoded) || $decoded === [] || count($decoded) > 20 || mb_strlen((string) $value) > 4096) {
                throw new InvalidArgumentException('The analytics age ranges are invalid.');
            }
            $keys = [];
            foreach ($decoded as $range) {
                if (! is_array($range)
                    || ! is_string($range['key'] ?? null)
                    || preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $range['key']) !== 1
                    || ! is_string($range['label'] ?? null)
                    || trim($range['label']) === ''
                    || mb_strlen($range['label']) > 80
                    || ! is_int($range['min'] ?? null)
                    || $range['min'] < 0
                    || $range['min'] > 150
                    || (($range['max'] ?? null) !== null && (! is_int($range['max']) || $range['max'] < $range['min'] || $range['max'] > 150))) {
                    throw new InvalidArgumentException('The analytics age ranges are invalid.');
                }
                if (array_key_exists('active', $range) && ! is_bool($range['active'])) {
                    throw new InvalidArgumentException('The analytics age ranges are invalid.');
                }
                if (array_key_exists('sort_order', $range) && (! is_int($range['sort_order']) || $range['sort_order'] < 0)) {
                    throw new InvalidArgumentException('The analytics age ranges are invalid.');
                }
                if (in_array($range['key'], $keys, true)) {
                    throw new InvalidArgumentException('The analytics age ranges are invalid.');
                }
                $keys[] = $range['key'];
            }
        }

        if ($key === OrganizationSettingKey::AnalyticsPurchaseGroups) {
            $decoded = is_string($value) ? json_decode($value, true) : null;
            if (! is_array($decoded) || $decoded === [] || count($decoded) > 50 || mb_strlen((string) $value) > 8192) {
                throw new InvalidArgumentException('The analytics purchase groups are invalid.');
            }
            $keys = [];
            $types = [];
            foreach ($decoded as $group) {
                if (! is_array($group)
                    || ! is_string($group['key'] ?? null)
                    || preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $group['key']) !== 1
                    || ! is_string($group['label'] ?? null)
                    || trim($group['label']) === ''
                    || mb_strlen($group['label']) > 120
                    || ! is_array($group['catalog_types'] ?? null)
                    || $group['catalog_types'] === []) {
                    throw new InvalidArgumentException('The analytics purchase groups are invalid.');
                }
                if (array_key_exists('active', $group) && ! is_bool($group['active'])) {
                    throw new InvalidArgumentException('The analytics purchase groups are invalid.');
                }
                if (array_key_exists('sort_order', $group) && (! is_int($group['sort_order']) || $group['sort_order'] < 0)) {
                    throw new InvalidArgumentException('The analytics purchase groups are invalid.');
                }
                if (in_array($group['key'], $keys, true)) {
                    throw new InvalidArgumentException('The analytics purchase groups are invalid.');
                }
                $keys[] = $group['key'];
                foreach ($group['catalog_types'] as $type) {
                    if (! is_string($type) || trim($type) === '' || mb_strlen($type) > 80 || in_array($type, $types, true)) {
                        throw new InvalidArgumentException('The analytics purchase groups are invalid.');
                    }
                    $types[] = $type;
                }
            }
        }

        if ($key === OrganizationSettingKey::AnalyticsSessionAxisSemantics
            && (! is_string($value) || ! array_key_exists($value, ClientSessionAxisCatalog::definitions()))) {
            throw new InvalidArgumentException('The analytics session axis semantics are invalid.');
        }
    }
}
