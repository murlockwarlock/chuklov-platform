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

    private const MIN_GENERIC_TERM_LENGTH = 3;

    private const MAX_GENERIC_TERMS = 5;

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

        if (preg_match('/^(?:tg|telegram):(\d+)$/i', $input, $matches) === 1) {
            $telegramId = $matches[1];

            return $this->bounded($query->whereHas('channelIdentities', function (Builder $query) use ($telegramId): void {
                $query->where('channel', 'telegram')->where('external_id', $telegramId);
            }));
        }

        if (preg_match('/^\d+$/', $input) === 1) {
            $id = $this->exactId($input);
            $phoneKey = ClientPhoneSearchKey::from($input);
            $prefixCandidates = [];

            if (strlen($input) >= 4) {
                $prefixCandidates[] = $input;
                if ($input[0] === '8') {
                    $prefixCandidates[] = '7'.substr($input, 1);
                } elseif ($input[0] === '7') {
                    $prefixCandidates[] = '8'.substr($input, 1);
                }
                $prefixCandidates = array_values(array_unique($prefixCandidates));
            }

            if ($id !== null || $phoneKey !== null || $prefixCandidates !== []) {
                return $this->bounded($query->where(function (Builder $query) use ($id, $input, $phoneKey, $prefixCandidates): void {
                    $hasClause = false;

                    if ($id !== null) {
                        $query->whereKey($id);
                        $hasClause = true;
                    }

                    if ($phoneKey !== null) {
                        $method = $hasClause ? 'orWhere' : 'where';
                        $query->{$method}('phone_search_key', $phoneKey->value);
                        $hasClause = true;
                    }

                    if ($prefixCandidates !== []) {
                        foreach ($prefixCandidates as $prefix) {
                            $method = $hasClause ? 'orWhere' : 'where';
                            $query->{$method}('phone_search_key', 'LIKE', $prefix.'%');
                            $hasClause = true;
                        }

                        $method = $hasClause ? 'orWhereHas' : 'whereHas';
                        $query->{$method}('channelIdentities', function (Builder $query) use ($input): void {
                            $query->where('external_id', $input);
                        });
                        $hasClause = true;
                    }

                    if (! $hasClause) {
                        $query->whereKey(0);
                    }
                }));
            }

            return $this->bounded($query->whereKey(0));
        }

        if ($this->looksLikePhone($input)) {
            $digits = preg_replace('/\D+/u', '', $input);
            $phoneKey = ClientPhoneSearchKey::from($input);

            if ($phoneKey !== null) {
                return $this->bounded($query->where('phone_search_key', $phoneKey->value));
            }

            if (is_string($digits) && strlen($digits) >= 4) {
                $prefixCandidates = [$digits];
                if ($digits[0] === '8') {
                    $prefixCandidates[] = '7'.substr($digits, 1);
                } elseif ($digits[0] === '7') {
                    $prefixCandidates[] = '8'.substr($digits, 1);
                }
                $prefixCandidates = array_values(array_unique($prefixCandidates));

                return $this->bounded($query->where(function (Builder $query) use ($prefixCandidates): void {
                    $hasClause = false;
                    foreach ($prefixCandidates as $prefix) {
                        $method = $hasClause ? 'orWhere' : 'where';
                        $query->{$method}('phone_search_key', 'LIKE', $prefix.'%');
                        $hasClause = true;
                    }
                }));
            }

            return $this->bounded($query->whereKey(0));
        }

        $terms = $this->terms($input);

        if ($terms === []) {
            return $this->bounded($query->whereKey(0));
        }

        if (count($terms) > self::MAX_GENERIC_TERMS
            || count(array_filter(
                $terms,
                static fn (string $term): bool => mb_strlen($term) < self::MIN_GENERIC_TERM_LENGTH,
            )) > 0) {
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
