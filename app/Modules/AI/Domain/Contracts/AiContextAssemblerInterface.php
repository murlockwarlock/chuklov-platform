<?php

namespace App\Modules\AI\Domain\Contracts;

use App\Models\User;
use App\Modules\AI\Application\Data\ContextAssemblyResult;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;

interface AiContextAssemblerInterface
{
    /**
     * @param  array<string, mixed>  $inputVariables
     * @param  list<AiInputReference>  $inputReferences
     */
    public function assemble(
        int $organizationId,
        AiContextPolicy $policy,
        array $inputVariables,
        array $inputReferences,
        ?User $actor = null,
    ): ContextAssemblyResult;
}
