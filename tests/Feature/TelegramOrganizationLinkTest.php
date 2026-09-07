<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Application\ConnectTelegramOrganizationIdentity;
use App\Modules\Identity\Application\InitiateTelegramOrganizationLink;
use App\Modules\Identity\Application\InvalidTelegramLinkToken;
use App\Modules\Identity\Application\VerifiedChannelIdentity;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Identity\Domain\Models\OrganizationChannelLinkToken;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Specialists\Application\CreateSpecialist;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistNotificationSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TelegramOrganizationLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_connection_requires_one_time_verified_link_and_rejects_reuse(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        app(OrganizationContext::class)->set($organization);

        $url = app(InitiateTelegramOrganizationLink::class)->handle($admin, $staff->getKey());
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $start = (string) ($query['start'] ?? '');
        self::assertStringStartsWith('staff_', $start);
        $token = substr($start, strlen('staff_'));
        $link = OrganizationChannelLinkToken::query()->sole();
        self::assertNotSame($token, $link->token_hash);
        self::assertSame(hash('sha256', $token), $link->token_hash);

        app(ConnectTelegramOrganizationIdentity::class)->handle(
            $token,
            new VerifiedChannelIdentity('telegram', '100200300', 'Иван Сотрудник', 'ru'),
        );

        $identity = OrganizationChannelIdentity::query()->sole();
        self::assertSame($staff->getKey(), $identity->user_id);
        self::assertSame(ChannelIdentityStatus::Verified, $identity->verification_status);
        self::assertSame('telegram_crm_link', $identity->verification_method);
        self::assertNotNull($link->fresh()->consumed_at);
        self::assertSame(1, DB::table('audit_events')
            ->where('action', 'organization.channel_identity.verified')
            ->where('organization_id', $organization->getKey())
            ->count());

        $this->expectException(InvalidTelegramLinkToken::class);
        app(ConnectTelegramOrganizationIdentity::class)->handle(
            $token,
            new VerifiedChannelIdentity('telegram', '100200300', 'Иван Сотрудник', 'ru'),
        );
    }

    public function test_same_telegram_identity_cannot_be_linked_to_another_staff_member(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $firstStaff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $secondStaff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');
        app(OrganizationContext::class)->set($organization);

        $firstUrl = app(InitiateTelegramOrganizationLink::class)->handle($admin, $firstStaff->getKey());
        $firstToken = $this->tokenFromUrl($firstUrl);
        app(ConnectTelegramOrganizationIdentity::class)->handle(
            $firstToken,
            new VerifiedChannelIdentity('telegram', '400500600', 'Первый сотрудник', 'ru'),
        );

        $secondUrl = app(InitiateTelegramOrganizationLink::class)->handle($admin, $secondStaff->getKey());
        $this->expectException(AuthorizationException::class);
        app(ConnectTelegramOrganizationIdentity::class)->handle(
            $this->tokenFromUrl($secondUrl),
            new VerifiedChannelIdentity('telegram', '400500600', 'Первый сотрудник', 'ru'),
        );

        self::assertSame($firstStaff->getKey(), OrganizationChannelIdentity::query()->sole()->user_id);
    }

    public function test_specialist_configuration_cannot_accept_an_arbitrary_telegram_id(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        app(OrganizationContext::class)->set($organization);

        $this->expectException(ValidationException::class);
        app(CreateSpecialist::class)->handle(
            actor: $admin,
            displayName: 'Неподтверждённый специалист',
            staffUserId: $staff->getKey(),
            notificationSettings: SpecialistNotificationSettings::from('123456789', true),
        );
    }

    private function tokenFromUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $start = (string) ($query['start'] ?? '');

        return substr($start, strlen('staff_'));
    }
}
