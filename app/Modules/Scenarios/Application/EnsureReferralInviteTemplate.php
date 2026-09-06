<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use Illuminate\Support\Facades\DB;

final class EnsureReferralInviteTemplate
{
    public function handle(Organization $organization): void
    {
        DB::transaction(function () use ($organization): void {
            $organization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            foreach (['ru' => 'Приглашение друга', 'en' => 'Invite a friend'] as $locale => $name) {
                $template = NotificationTemplate::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('template_key', 'referral-invite')
                    ->where('locale', $locale)
                    ->first();

                if ($template === null) {
                    $template = new NotificationTemplate;
                    $template->forceFill([
                        'organization_id' => $organization->getKey(),
                        'template_key' => 'referral-invite',
                        'name' => $name,
                        'locale' => $locale,
                        'purpose' => ScenarioRulePurpose::Marketing->value,
                        'is_active' => true,
                    ])->save();
                }

                if (! $template->versions()->exists()) {
                    $version = new NotificationTemplateVersion;
                    $version->forceFill([
                        'organization_id' => $organization->getKey(),
                        'template_id' => $template->getKey(),
                        'version' => 1,
                        'status' => NotificationTemplateStatus::Published,
                        'body' => '{{ referral_link }}',
                        'variables' => ['referral_link'],
                        'published_at' => now(),
                    ])->save();
                }
            }
        });
    }
}
