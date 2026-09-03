<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MilestoneTwoDateTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_default_timezone_is_an_iana_identifier(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        $setting = app(SetOrganizationSetting::class)->handle(
            actor: $admin,
            key: OrganizationSettingKey::DefaultTimezone,
            value: 'Europe/Moscow',
        );

        self::assertSame('Europe/Moscow', $setting->typedValue());
        self::assertSame('Europe/Moscow', $organization->refresh()->defaultTimezone());
    }

    public function test_fixed_offsets_are_not_accepted_as_timezone_context(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IanaTimezone::from('+05:00');
    }

    public function test_asia_almaty_wall_clock_value_is_stored_as_the_single_utc_instant(): void
    {
        $local = CarbonImmutable::createSafe(2026, 9, 3, 11, 0, 0, 'Asia/Almaty');

        self::assertSame('2026-09-03T06:00:00+00:00', $local->utc()->toIso8601String());
    }
}
