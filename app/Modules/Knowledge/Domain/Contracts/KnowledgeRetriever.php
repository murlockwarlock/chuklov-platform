<?php

namespace App\Modules\Knowledge\Domain\Contracts;

use App\Models\User;
use App\Modules\Knowledge\Application\Data\RetrievalQuery;
use App\Modules\Knowledge\Application\Data\RetrievalResult;

interface KnowledgeRetriever
{
    /** @return list<RetrievalResult> */
    public function retrieve(User $actor, RetrievalQuery $query): array;

    /** @return list<RetrievalResult> */
    public function retrieveForOrganization(int|string $organizationId, RetrievalQuery $query): array;
}
