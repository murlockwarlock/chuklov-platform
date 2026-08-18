<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\ValueObjects\ClientPhoneSearchKey;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Database\Eloquent\Builder;

final readonly class ClientSearch
{
    private const MAX_INPUT_LENGTH = 160;

    public const MAX_RESULTS = 50;

    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private OrganizationFeatureGate $features,
    ) {}

    /**
     * @return Builder<Client>
     */
    public function query(User $actor, string $search): Builder
    {
        $organization = $this->context->organization();
        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);

        return $this->apply(
            Client::query()->where('organization_id', $organization->getKey()),
            $search,
        );
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function apply(Builder $query, string $search): Builder
    {
        $input = trim($search);

        if ($input === '' || mb_strlen($input) > self::MAX_INPUT_LENGTH) {
            return $this->bounded($query->whereKey(0));
        }

        if (str_starts_with($input, '#')) {
            $id = $this->exactId($input);

            return $id === null
                ? $this->bounded($query->whereKey(0))
                : $this->bounded($query->whereKey($id));
        }

        if (preg_match('/^\d+$/', $input) === 1) {
            $id = $this->exactId($input);
            $phoneKey = ClientPhoneSearchKey::from($input);

            if ($phoneKey !== null) {
                return $this->bounded($query->where(function (Builder $query) use ($id, $phoneKey): void {
                    if ($id !== null) {
                        $query->whereKey($id)->orWhere('phone_search_key', $phoneKey->value);

                        return;
                    }

                    $query->where('phone_search_key', $phoneKey->value);
                }));
            }

            return $id === null
                ? $this->bounded($query->whereKey(0))
                : $this->bounded($query->whereKey($id));
        }

        if ($this->looksLikePhone($input)) {
            $phoneKey = ClientPhoneSearchKey::from($input);

            if ($phoneKey === null) {
                return $this->bounded($query->whereKey(0));
            }

            return $this->bounded($query->where('phone_search_key', $phoneKey->value));
        }

        $terms = $this->terms($input);

        if ($terms === []) {
            return $this->bounded($query->whereKey(0));
        }

        return $this->bounded($query->where(function (Builder $query) use ($terms): void {
            $operator = config('database.default') === 'pgsql' ? 'ILIKE' : 'LIKE';

            foreach ($terms as $term) {
                $pattern = '%'.addcslashes($term, '\\%_').'%';

                $query->where(function (Builder $query) use ($pattern, $operator): void {
                    $query
                        ->where('full_name', $operator, $pattern)
                        ->orWhere('email', $operator, $pattern);
                });
            }
        }));
    }

    private function exactId(string $input): ?string
    {
        $isExplicit = str_starts_with($input, '#');
        $candidate = $isExplicit ? substr($input, 1) : $input;

        if ($candidate === '' || preg_match('/^\d+$/', $candidate) !== 1 || trim($candidate, '0') === '') {
            return null;
        }

        return $candidate;
    }

    private function looksLikePhone(string $input): bool
    {
        if (str_contains($input, '@') || preg_match('/\p{L}/u', $input) === 1) {
            return false;
        }

        $digits = preg_replace('/\D+/u', '', $input);

        return preg_match('/[+()\-]/', $input) === 1
            || (is_string($digits) && strlen($digits) >= 7);
    }

    /** @return list<string> */
    private function terms(string $input): array
    {
        $terms = preg_split('/\s+/u', $input, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($terms)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $term): string => trim($term), $terms),
            static fn (string $term): bool => $term !== '',
        ));
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private function bounded(Builder $query): Builder
    {
        return $query->limit(self::MAX_RESULTS);
    }
}
