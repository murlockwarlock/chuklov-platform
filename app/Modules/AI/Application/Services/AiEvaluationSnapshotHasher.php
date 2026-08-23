<?php

namespace App\Modules\AI\Application\Services;

use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use InvalidArgumentException;

final class AiEvaluationSnapshotHasher
{
    /** @param array<string, mixed> $testInputs */
    public function testInputsDigest(array $testInputs): string
    {
        return $this->digest($this->canonicalize($testInputs));
    }

    /** @param list<array<string, mixed>> $cases */
    public function casesDigest(array $cases): string
    {
        if (count($cases) > AiRuntimeLimits::PLATFORM_MAX_EVALUATION_CASES) {
            throw new InvalidArgumentException('Evaluation provenance exceeds the case limit.');
        }

        $normalizedCases = [];
        $caseIds = [];

        foreach ($cases as $case) {
            $caseId = $this->positiveId($case['id'] ?? null);
            if ($caseId === null
                || ! array_key_exists('assertions', $case)
                || ! is_array($case['assertions'])
                || ! array_key_exists('expected_output_schema', $case)
                || ($case['expected_output_schema'] !== null && ! is_array($case['expected_output_schema']))
                || ! is_string($case['test_inputs_digest'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $case['test_inputs_digest']) !== 1) {
                throw new InvalidArgumentException('Evaluation provenance does not contain a complete case snapshot.');
            }

            if (isset($caseIds[$caseId])) {
                throw new InvalidArgumentException('Evaluation provenance contains an invalid case identity.');
            }
            $caseIds[$caseId] = true;

            $normalizedCases[] = [
                'id' => $caseId,
                'assertions' => $case['assertions'],
                'expected_output_schema' => $case['expected_output_schema'],
                'test_inputs_digest' => $case['test_inputs_digest'],
            ];
        }

        usort($normalizedCases, function (array $left, array $right): int {
            $idComparison = $left['id'] <=> $right['id'];
            if ($idComparison !== 0) {
                return $idComparison;
            }

            return strcmp($this->encode($this->canonicalize($left)), $this->encode($this->canonicalize($right)));
        });

        return $this->digest($this->canonicalize($normalizedCases));
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalize(mixed $value, ?string $key = null): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $itemKey => $item) {
            $value[$itemKey] = $this->canonicalize($item, is_string($itemKey) ? $itemKey : $key);
        }

        if (array_is_list($value)) {
            if (in_array($key, ['assertions', 'required', 'enum', 'values'], true)) {
                usort($value, fn (mixed $left, mixed $right): int => strcmp($this->encode($left), $this->encode($right)));
            }

            return $value;
        }

        ksort($value, SORT_STRING);

        return $value;
    }
}
