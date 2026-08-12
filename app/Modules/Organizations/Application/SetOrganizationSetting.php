<?php

namespace App\Modules\Organizations\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Modules\Security\Application\RecordAuditEvent;
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
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $this->validate($key, $value);

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

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'organization.setting.updated',
            targetType: OrganizationSetting::class,
            targetId: (string) $setting->getKey(),
            metadata: ['setting_key' => $key->value, 'value_type' => $key->type()->value],
        );

        return $setting->refresh();
    }

    private function validate(OrganizationSettingKey $key, string|int|bool $value): void
    {
        if ($key->value === OrganizationSettingKey::DefaultLanguage->value) {
            if (! is_string($value) || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $value) !== 1) {
                throw new InvalidArgumentException('The default language must be a valid language tag.');
            }
        }

        if ($key->value === OrganizationSettingKey::DefaultTimezone->value) {
            if (! is_string($value) || ! in_array($value, timezone_identifiers_list(), true)) {
                throw new InvalidArgumentException('The default timezone must be an IANA timezone.');
            }
        }
    }
}
