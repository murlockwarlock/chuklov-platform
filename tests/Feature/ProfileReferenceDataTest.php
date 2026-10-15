<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\ProfileReferenceData;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ProfileReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_normalization_uses_country_metadata_and_supports_foreign_international_numbers(): void
    {
        $reference = app(ProfileReferenceData::class);
        foreach ([
            ['+7 (701) 234-56-78', 'Казахстан', '+77012345678'],
            ['8 (701) 234-56-78', 'Казахстан', '+77012345678'],
            ['+7 (912) 345-67-89', 'Россия', '+79123456789'],
            ['+66 81 234 5678', 'Таиланд', '+66812345678'],
            ['+1 202 555 0123', 'США', '+12025550123'],
            ['+44 20 7946 0018', 'Thailand', '+442079460018'],
        ] as [$phone, $country, $expected]) {
            self::assertSame($expected, $reference->phone($phone, $country));
        }
        foreach (['+7abc9123456789', '+1999', '+999123456789', '+120255501234567890', 'not a number'] as $invalid) {
            try {
                $reference->phone($invalid, 'Казахстан');
                self::fail('Invalid phone must be rejected.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('phone', $exception->errors());
            }
        }
    }

    public function test_city_lookup_is_authenticated_bounded_and_matches_transliterated_aliases(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['tenancy.default_organization_id' => $organization->getKey()]);
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'ru']);
        $url = route('portal.profile.cities');
        $query = ['country' => 'Казахстан', 'query' => 'Almaty'];
        $this->postJson($url, $query)->assertUnauthorized();
        $response = $this->withSession(['client_portal.client_id' => $client->getKey()])->postJson($url, $query)
            ->assertOk()->assertJsonStructure(['cities']);
        self::assertNotEmpty($response->json('cities'));
        self::assertLessThanOrEqual(25, count($response->json('cities')));
        $this->postJson($url, ['country' => '../../private', 'query' => 'secret'])
            ->assertOk()->assertJsonPath('cities', [])->assertJsonPath('attribution.source', 'GeoNames');
        $this->postJson($url, ['country' => 'RU', 'query' => str_repeat('a', 101)])->assertUnprocessable();
    }
}
