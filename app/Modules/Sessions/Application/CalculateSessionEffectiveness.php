<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\DTOs\MedicalSessionEffectivenessData;

final readonly class CalculateSessionEffectiveness
{
    public function handle(MedicalSessionData $session): MedicalSessionEffectivenessData
    {
        $painReductionValues = [];
        $fullyResolvedComplaints = 0;

        foreach ($session->pains as $pain) {
            if ($pain->beforeScore !== null && $pain->beforeScore > 0 && $pain->afterScore === 0) {
                $fullyResolvedComplaints++;
            }

            if ($pain->beforeScore === null || $pain->afterScore === null || $pain->beforeScore === 0) {
                continue;
            }

            $painReductionValues[] = (($pain->beforeScore - $pain->afterScore) / $pain->beforeScore) * 100;
        }

        $violationsBefore = 0;
        $violationsAfter = 0;
        $resolvedTests = 0;
        $newViolations = 0;
        $testsBecameZero = 0;
        $causeKeys = [];

        foreach ($session->testResults as $test) {
            $beforeCount = self::violationCount($test->beforeViolationCount, $test->beforeAbnormal, $test->beforeValueId);
            $afterCount = self::violationCount($test->afterViolationCount, $test->afterAbnormal, $test->afterValueId);
            $violationsBefore += $beforeCount;
            $violationsAfter += $afterCount;
            $beforeKeys = self::violationKeys($test, 'before', $beforeCount);
            $afterKeys = self::violationKeys($test, 'after', $afterCount);
            $resolvedTests += count(array_diff_key($beforeKeys, $afterKeys));
            $newViolations += count(array_diff_key($afterKeys, $beforeKeys));
            if ($beforeKeys !== [] && $afterKeys === []) {
                $testsBecameZero++;
            }

        }
        $causes = $session->causes !== []
            ? $session->causes
            : [];
        if ($causes === []) {
            foreach ($session->testResults as $test) {
                $causes = [...$causes, ...$test->causes];
            }
        }
        foreach ($causes as $cause) {
            $causeId = is_int($cause['id'] ?? null) || is_string($cause['id'] ?? null)
                ? (int) ($cause['id'] ?? 0)
                : 0;
            $causeName = is_string($cause['name'] ?? null) ? trim($cause['name']) : '';
            $causeKey = $causeId > 0 ? 'id:'.$causeId : 'name:'.$causeName;
            if ($causeKey !== 'name:') {
                $causeKeys[$causeKey] = true;
            }
        }

        return new MedicalSessionEffectivenessData(
            averagePainReductionPercentage: $painReductionValues === []
                ? null
                : round(array_sum($painReductionValues) / count($painReductionValues), 2),
            fullyResolvedComplaints: $fullyResolvedComplaints,
            violationsBefore: $violationsBefore,
            violationsAfter: $violationsAfter,
            resolvedTests: $resolvedTests,
            newViolations: $newViolations,
            testsBecameZero: $testsBecameZero,
            uniqueCauseCount: count($causeKeys),
        );
    }

    private static function violationCount(int $configuredCount, bool $abnormal, ?int $valueId): int
    {
        if ($configuredCount > 0) {
            return $configuredCount;
        }

        return $valueId !== null && $abnormal ? 1 : 0;
    }

    /** @return array<string, true> */
    private static function violationKeys(object $test, string $phase, int $count): array
    {
        $evaluation = $test->structuredPayload['evaluation'] ?? null;
        $keys = is_array($evaluation) && is_array($evaluation[$phase.'_keys'] ?? null)
            ? array_values(array_filter($evaluation[$phase.'_keys'], 'is_string'))
            : [];
        if ($keys !== []) {
            return array_fill_keys($keys, true);
        }

        return $count > 0 ? ['legacy' => true] : [];
    }
}
