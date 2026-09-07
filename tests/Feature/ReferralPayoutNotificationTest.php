<?php

namespace Tests\Feature;

use App\Filament\Resources\ReferralPayoutRequests\ReferralPayoutRequestResource;
use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\NotifyReferralPayoutRequest;
use App\Modules\Referrals\Application\SendReferralPayoutStatusNotification;
use App\Modules\Referrals\Application\TransitionReferralPayoutRequest;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use App\Modules\Referrals\Jobs\SendReferralPayoutStatusNotification as SendReferralPayoutStatusNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class ReferralPayoutNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_payout_creates_a_tenant_scoped_finance_notification_with_an_exact_cta(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        $financeUser = User::factory()->forOrganization($organization)->create();
        $inactiveUser = User::factory()->forOrganization($organization)->create();
        $inactiveUser->memberships()->update(['is_active' => false]);
        $otherFinanceUser = User::factory()->forOrganization($otherOrganization)->create();
        $partner = Client::factory()->forOrganization($organization)->create(['full_name' => 'Алина Партнёр']);
        $request = $this->payout($organization, $partner, $financeUser, ReferralPayoutRequestStatus::Requested);

        app(NotifyReferralPayoutRequest::class)->handle($request);

        $notification = $financeUser->notifications()->sole();
        self::assertNull($notification->read_at);
        self::assertSame('Новая заявка на выплату', $notification->data['title']);
        self::assertSame('Алина Партнёр · 50.00 USD', $notification->data['body']);
        self::assertSame(
            ReferralPayoutRequestResource::getUrl('view', ['record' => $request]),
            $notification->data['actions'][0]['url'],
        );
        self::assertSame('Открыть заявку', $notification->data['actions'][0]['label']);
        self::assertDatabaseMissing('notifications', [
            'notifiable_id' => $inactiveUser->getKey(),
        ]);
        self::assertDatabaseMissing('notifications', [
            'notifiable_id' => $otherFinanceUser->getKey(),
        ]);
    }

    public function test_approved_rejected_and_paid_statuses_use_the_telegram_delivery_abstraction(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        $admin = User::factory()->forOrganization($organization)->create();
        $partner = Client::factory()->forOrganization($organization)->create(['language' => 'ru']);
        ClientChannelIdentity::factory()
            ->forClient($partner)
            ->state([
                'verification_status' => ChannelIdentityStatus::Verified->value,
                'external_id' => 'partner-chat',
            ])
            ->create();
        $channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));

        $approved = $this->payout($organization, $partner, $admin, ReferralPayoutRequestStatus::Approved);
        $rejected = $this->payout($organization, $partner, $admin, ReferralPayoutRequestStatus::Rejected);
        $paid = $this->payout($organization, $partner, $admin, ReferralPayoutRequestStatus::Paid);

        app(SendReferralPayoutStatusNotification::class)->handle($approved);
        app(SendReferralPayoutStatusNotification::class)->handle($rejected);
        app(SendReferralPayoutStatusNotification::class)->handle($paid);

        self::assertCount(3, $channel->messages);
        self::assertSame('Заявка на выплату 50.00 USD одобрена.', $channel->messages[0]->body);
        self::assertStringContainsString('Заявка на выплату отклонена', $channel->messages[1]->body);
        self::assertSame('Выплата 50.00 USD отмечена как выполненная.', $channel->messages[2]->body);
        self::assertSame('partner-chat', $channel->messages[0]->recipientExternalId);
    }

    public function test_status_transition_queues_only_real_terminal_status_changes(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        $admin = User::factory()->forOrganization($organization)->create();
        $partner = Client::factory()->forOrganization($organization)->create();
        $request = $this->payout($organization, $partner, $admin, ReferralPayoutRequestStatus::Requested);
        Queue::fake();

        app(TransitionReferralPayoutRequest::class)->handle(
            request: $request,
            target: ReferralPayoutRequestStatus::Approved,
            actor: $admin,
            idempotencyKey: 'approve-notification-test',
        );
        app(TransitionReferralPayoutRequest::class)->handle(
            request: $request,
            target: ReferralPayoutRequestStatus::Approved,
            actor: $admin,
            idempotencyKey: 'same-status-notification-test',
        );

        Queue::assertPushed(SendReferralPayoutStatusNotificationJob::class, 1);
        Queue::assertPushed(SendReferralPayoutStatusNotificationJob::class, function (SendReferralPayoutStatusNotificationJob $job) use ($organization, $request): bool {
            return $job->organizationId === $organization->getKey()
                && $job->payoutRequestId === $request->getKey()
                && $job->status === ReferralPayoutRequestStatus::Approved->value;
        });
    }

    private function payout(
        Organization $organization,
        Client $partner,
        User $admin,
        ReferralPayoutRequestStatus $status,
    ): ReferralPayoutRequest {
        $attributes = [
            'organization_id' => $organization->getKey(),
            'beneficiary_client_id' => $partner->getKey(),
            'amount_minor' => 5000,
            'currency' => 'USD',
            'status' => $status->value,
            'idempotency_key' => 'notification-'.$status->value.'-'.uniqid('', true),
            'request_hash' => str_repeat('a', 64),
            'requested_at' => now(),
        ];

        if ($status === ReferralPayoutRequestStatus::Approved) {
            $attributes['approved_by_user_id'] = $admin->getKey();
            $attributes['approved_at'] = now();
        }

        if ($status === ReferralPayoutRequestStatus::Rejected) {
            $attributes['rejected_by_user_id'] = $admin->getKey();
            $attributes['rejected_at'] = now();
            $attributes['rejection_reason'] = 'Проверьте реквизиты';
        }

        if ($status === ReferralPayoutRequestStatus::Paid) {
            $attributes['approved_by_user_id'] = $admin->getKey();
            $attributes['approved_at'] = now();
            $attributes['paid_by_user_id'] = $admin->getKey();
            $attributes['paid_at'] = now();
        }

        $request = new ReferralPayoutRequest;
        $request->forceFill($attributes)->save();

        return $request->refresh();
    }
}
