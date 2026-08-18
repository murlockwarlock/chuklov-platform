<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\ReplaceOrganizationCredential;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CredentialRevisionBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_processes_only_the_requested_batch_and_is_resumable_and_idempotent(): void
    {
        $organization = Organization::create(['name' => 'Credential Clinic', 'slug' => 'credential-clinic']);
        $legacy = [];
        foreach (range(1, 3) as $index) {
            $credential = $this->credential($organization, "Legacy {$index}");
            $legacy[] = $credential;
        }
        $populated = $this->credential($organization, 'Already populated', (string) Str::uuid());

        $this->assertSame(0, Artisan::call('security:backfill-credential-revisions', ['--limit' => 2]));
        $legacy[0]->refresh();
        $legacy[1]->refresh();
        $legacy[2]->refresh();
        $populated->refresh();
        $this->assertNotNull($legacy[0]->revision_id);
        $this->assertNotNull($legacy[1]->revision_id);
        $this->assertNull($legacy[2]->revision_id);
        $this->assertSame($populated->revision_id, $populated->getRawOriginal('revision_id'));

        $firstRevision = $legacy[0]->revision_id;
        $this->assertSame(0, Artisan::call('security:backfill-credential-revisions', ['--limit' => 2]));
        $legacy[0]->refresh();
        $legacy[2]->refresh();
        $this->assertSame($firstRevision, $legacy[0]->revision_id);
        $this->assertNotNull($legacy[2]->revision_id);

        $this->assertSame(0, Artisan::call('security:backfill-credential-revisions', ['--limit' => 2]));
        $this->assertSame(0, OrganizationCredential::query()->whereNull('revision_id')->count());
    }

    public function test_new_credentials_always_receive_a_revision_from_the_application_action(): void
    {
        $organization = Organization::create(['name' => 'New Credential Clinic', 'slug' => 'new-credential-clinic']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        $credential = app(ReplaceOrganizationCredential::class)->handle(
            actor: $admin,
            provider: 'openai',
            credentialName: 'New credential',
            credentials: ['api_key' => 'new-key'],
        );

        $this->assertNotNull($credential->revision_id);
        $this->assertSame(0, OrganizationCredential::query()->whereNull('revision_id')->count());
    }

    private function credential(Organization $organization, string $name, ?string $revision = null): OrganizationCredential
    {
        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => $name,
            'revision_id' => $revision,
        ]);
        $credential->organization_id = $organization->id;
        $credential->credentials = ['api_key' => 'test-key'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        return $credential;
    }
}
