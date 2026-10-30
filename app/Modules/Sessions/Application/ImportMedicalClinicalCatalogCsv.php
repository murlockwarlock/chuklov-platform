<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

final readonly class ImportMedicalClinicalCatalogCsv
{
    private const int MaximumBytes = 2_097_152;

    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private SaveMedicalClinicalCatalog $catalog,
    ) {}

    public function handle(User $actor, UploadedFile $file, string $section): int
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);

        if (! in_array($section, ['tests', 'causes', 'problem_tags'], true)) {
            throw ValidationException::withMessages(['section' => 'Выберите раздел каталога.']);
        }

        $size = $file->getSize();
        if (is_int($size) && $size > self::MaximumBytes) {
            throw ValidationException::withMessages(['file' => 'CSV-файл не должен превышать 2 МБ.']);
        }

        $rows = $this->readRows($file);
        $organizationId = (int) $organization->getKey();
        $tests = $this->existingTests($organizationId);
        $causes = $this->existingCauses($organizationId);
        $problemTags = $this->existingProblemTags($organizationId);

        if ($section === 'tests') {
            $tests = $this->parseTests($rows, $tests);
        } elseif ($section === 'causes') {
            $causes = $this->parseDictionary($rows, $causes, 'causes');
        } else {
            $problemTags = $this->parseDictionary($rows, $problemTags, 'problem_tags');
        }

        $this->catalog->handle($actor, $tests, $causes, $problemTags);

        return count($rows);
    }

    /** @return list<array<string, string>> */
    private function readRows(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            throw ValidationException::withMessages(['file' => 'Не удалось прочитать файл каталога.']);
        }

        $extension = mb_strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            return $this->readSpreadsheetRows($path);
        }

        return $this->readCsvRows($path);
    }

    /** @return list<array<string, string>> */
    private function readCsvRows(string $path): array
    {

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Не удалось открыть CSV-файл.']);
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw ValidationException::withMessages(['file' => 'CSV-файл пуст.']);
            }

            $delimiter = $this->delimiter($firstLine);
            rewind($handle);
            $header = fgetcsv($handle, 0, $delimiter);
            if (! is_array($header)) {
                throw ValidationException::withMessages(['file' => 'В CSV-файле отсутствует строка заголовков.']);
            }

            $headers = array_map(fn (mixed $value): string => $this->normalizeHeader($value), $header);
            if (count(array_filter($headers, static fn (string $value): bool => $value !== '')) !== count(array_unique(array_filter($headers)))) {
                throw ValidationException::withMessages(['file' => 'Заголовки CSV-файла должны быть уникальными.']);
            }

            $rows = [];
            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($values === [null] || count(array_filter($values, static fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                    continue;
                }

                $row = [];
                foreach ($headers as $index => $name) {
                    if ($name !== '') {
                        $row[$name] = trim((string) ($values[$index] ?? ''));
                    }
                }
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'CSV-файл не содержит строк для импорта.']);
        }

        return $rows;
    }

    /** @return list<array<string, string>> */
    private function readSpreadsheetRows(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getActiveSheet();
            if ($sheet->getHighestDataRow() > 5001 || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > 20) {
                throw ValidationException::withMessages(['file' => 'XLSX-файл превышает допустимый размер.']);
            }
            $matrix = $sheet->toArray(null, true, true, false);
            $header = array_shift($matrix);
            if (! is_array($header)) {
                throw ValidationException::withMessages(['file' => 'В XLSX-файле отсутствует строка заголовков.']);
            }

            $headers = array_values(array_map(fn (mixed $value): string => $this->normalizeHeader($value), $header));
            $matrix = array_values(array_map(
                static fn (array $row): array => array_values($row),
                $matrix,
            ));

            return $this->rowsFromMatrix($headers, $matrix, 'XLSX-файл');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'Не удалось прочитать XLSX-файл.']);
        }
    }

    /** @param list<string> $headers
     * @param  list<array<int, mixed>>  $matrix
     * @return list<array<string, string>>
     */
    private function rowsFromMatrix(array $headers, array $matrix, string $format): array
    {
        if (count(array_filter($headers, static fn (string $value): bool => $value !== '')) !== count(array_unique(array_filter($headers)))) {
            throw ValidationException::withMessages(['file' => 'Заголовки '.$format.' должны быть уникальными.']);
        }

        $rows = [];
        foreach ($matrix as $values) {
            if (count(array_filter($values, static fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $name) {
                if ($name !== '') {
                    $row[$name] = trim((string) ($values[$index] ?? ''));
                }
            }
            $rows[] = $row;
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => $format.' не содержит строк для импорта.']);
        }

        return $rows;
    }

    /** @param list<array<string, string>> $rows
     * @param  array<int, array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    private function parseTests(array $rows, array $existing): array
    {
        $testNameHeader = $this->requiredHeader($rows, ['название теста', 'test name', 'test']);
        $valueHeader = $this->requiredHeader($rows, ['значение', 'value', 'value label']);
        $sectionHeader = $this->optionalHeader($rows, ['раздел', 'section']);
        $activeHeader = $this->optionalHeader($rows, ['активен', 'active']);
        $testActiveHeader = $this->optionalHeader($rows, ['активен тест', 'test active']);
        $valueActiveHeader = $this->optionalHeader($rows, ['активно значение', 'value active']);
        $abnormalHeader = $this->optionalHeader($rows, ['нарушение', 'abnormal']);
        $testOrderHeader = $this->optionalHeader($rows, ['порядок теста', 'test order']);
        $valueOrderHeader = $this->optionalHeader($rows, ['порядок значения', 'value order']);
        $existingByName = [];
        $usedTestKeys = [];
        foreach ($existing as $test) {
            $key = (string) ($test['key'] ?? '');
            $name = $this->nameKey((string) ($test['name'] ?? ''));
            if ($name !== '') {
                $existingByName[$name] = $test;
            }
            if ($key !== '') {
                $usedTestKeys[$key] = true;
            }
        }

        $parsed = [];
        $seenValues = [];
        foreach ($rows as $index => $row) {
            $testName = trim($row[$testNameHeader] ?? '');
            $valueLabel = trim($row[$valueHeader] ?? '');
            if ($testName === '' || $valueLabel === '') {
                throw ValidationException::withMessages(['file' => 'В каждой строке тестов укажите название теста и значение.']);
            }

            $testNameKey = $this->nameKey($testName);
            if (! isset($parsed[$testNameKey])) {
                $current = $existingByName[$testNameKey] ?? null;
                $testKey = is_array($current) && is_string($current['key'] ?? null)
                    ? $current['key']
                    : $this->stableKey('test', $testName, $usedTestKeys);
                $usedTestKeys[$testKey] = true;
                $parsed[$testNameKey] = [
                    'key' => $testKey,
                    'name' => $testName,
                    'section' => $sectionHeader !== null ? $this->nullable($row[$sectionHeader] ?? null) : (is_array($current) ? ($current['section'] ?? null) : null),
                    'is_active' => $this->boolean($row[$testActiveHeader ?? $activeHeader ?? ''] ?? null, is_array($current) ? (bool) ($current['is_active'] ?? true) : true),
                    'sort_order' => $this->integer($row[$testOrderHeader ?? ''] ?? null, $index),
                    'values' => [],
                ];
                $seenValues[$testNameKey] = [];
            }

            $currentValues = is_array($existingByName[$testNameKey] ?? null) ? ($existingByName[$testNameKey]['values'] ?? []) : [];
            $valueKey = $this->nameKey($valueLabel);
            if (isset($seenValues[$testNameKey][$valueKey])) {
                throw ValidationException::withMessages(['file' => 'Одинаковое значение нельзя повторять для одного теста.']);
            }
            $seenValues[$testNameKey][$valueKey] = true;
            $existingValue = collect(is_array($currentValues) ? $currentValues : [])->first(fn (mixed $value): bool => is_array($value) && $this->nameKey((string) ($value['label'] ?? '')) === $valueKey);
            $usedValueKeys = collect($parsed[$testNameKey]['values'])->mapWithKeys(static fn (array $value): array => [(string) $value['key'] => true])->all();
            $valueKeyValue = is_array($existingValue) && is_string($existingValue['key'] ?? null)
                ? $existingValue['key']
                : $this->stableKey('value', $testName.'_'.$valueLabel, $usedValueKeys);
            $parsed[$testNameKey]['values'][] = [
                'key' => $valueKeyValue,
                'label' => $valueLabel,
                'is_abnormal' => $this->boolean($row[$abnormalHeader ?? ''] ?? null, is_array($existingValue) ? (bool) ($existingValue['is_abnormal'] ?? false) : false),
                'is_active' => $this->boolean($row[$valueActiveHeader ?? $activeHeader ?? ''] ?? null, is_array($existingValue) ? (bool) ($existingValue['is_active'] ?? true) : true),
                'sort_order' => $this->integer($row[$valueOrderHeader ?? ''] ?? null, count($parsed[$testNameKey]['values'])),
            ];
        }

        return array_values($parsed);
    }

    /** @param list<array<string, string>> $rows
     * @param  array<int, array<string, mixed>>  $existing
     * @return list<array{name:string,group_name:?string,is_active:bool,sort_order:int}>
     */
    private function parseDictionary(array $rows, array $existing, string $section): array
    {
        $nameHeader = $this->requiredHeader($rows, $section === 'causes' ? ['название причины', 'название', 'name'] : ['название категории', 'название', 'name']);
        $groupHeader = $this->optionalHeader($rows, ['группа', 'group', 'group name']);
        $activeHeader = $this->optionalHeader($rows, ['активна', 'активен', 'active']);
        $orderHeader = $this->optionalHeader($rows, ['порядок', 'order', 'sort order']);
        $existingByName = collect($existing)->filter('is_array')->keyBy(fn (array $row): string => $this->nameKey((string) ($row['name'] ?? '')));
        $seen = [];
        $parsed = [];
        foreach ($rows as $index => $row) {
            $name = trim($row[$nameHeader] ?? '');
            $nameKey = $this->nameKey($name);
            if ($name === '' || isset($seen[$nameKey])) {
                throw ValidationException::withMessages(['file' => 'Названия в CSV должны быть заполнены и уникальны.']);
            }
            $seen[$nameKey] = true;
            $current = $existingByName->get($nameKey);
            $parsed[] = [
                'name' => $name,
                'group_name' => $groupHeader === null ? (is_array($current) ? ($current['group_name'] ?? null) : null) : $this->nullable($row[$groupHeader] ?? null),
                'is_active' => $this->boolean($row[$activeHeader ?? ''] ?? null, is_array($current) ? (bool) ($current['is_active'] ?? true) : true),
                'sort_order' => $this->integer($row[$orderHeader ?? ''] ?? null, $index),
            ];
        }

        return $parsed;
    }

    /** @return array<int, array<string, mixed>> */
    private function existingTests(int $organizationId): array
    {
        return MedicalTestDefinition::query()
            ->where('organization_id', $organizationId)
            ->with('values')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalTestDefinition $test): array => [
                'key' => (string) $test->key,
                'name' => (string) $test->name,
                'section' => $test->section,
                'is_active' => (bool) $test->is_active,
                'sort_order' => (int) $test->sort_order,
                'values' => $test->values->map(static fn ($value): array => [
                    'key' => (string) $value->key,
                    'label' => (string) $value->label,
                    'is_abnormal' => (bool) $value->is_abnormal,
                    'is_active' => (bool) $value->is_active,
                    'sort_order' => (int) $value->sort_order,
                ])->all(),
            ])->all();
    }

    /** @return array<int, array{name:string,group_name:?string,is_active:bool,sort_order:int}> */
    private function existingCauses(int $organizationId): array
    {
        return MedicalCauseDefinition::query()
            ->where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalCauseDefinition $cause): array => [
                'name' => (string) $cause->name,
                'group_name' => $cause->group_name,
                'is_active' => (bool) $cause->is_active,
                'sort_order' => (int) $cause->sort_order,
            ])->all();
    }

    /** @return array<int, array{name:string,group_name:?string,is_active:bool,sort_order:int}> */
    private function existingProblemTags(int $organizationId): array
    {
        return MedicalProblemTag::query()
            ->where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MedicalProblemTag $tag): array => [
                'name' => (string) $tag->name,
                'group_name' => $tag->group_name,
                'is_active' => (bool) $tag->is_active,
                'sort_order' => (int) $tag->sort_order,
            ])->all();
    }

    /** @param list<array<string, string>> $rows
     * @param  list<string>  $aliases
     */
    private function requiredHeader(array $rows, array $aliases): string
    {
        $header = $this->optionalHeader($rows, $aliases);
        if ($header === null) {
            throw ValidationException::withMessages(['file' => 'В CSV-файле отсутствует обязательный столбец.']);
        }

        return $header;
    }

    /** @param list<array<string, string>> $rows
     * @param  list<string>  $aliases
     */
    private function optionalHeader(array $rows, array $aliases): ?string
    {
        $headers = array_keys($rows[0] ?? []);
        $normalized = array_map(fn (string $header): string => $this->normalizeHeader($header), $headers);
        foreach ($aliases as $alias) {
            $index = array_search($this->normalizeHeader($alias), $normalized, true);
            if ($index !== false) {
                return $headers[$index];
            }
        }

        return null;
    }

    private function delimiter(string $line): string
    {
        $counts = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function normalizeHeader(mixed $value): string
    {
        $header = trim((string) $value);
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        return mb_strtolower((string) preg_replace('/\s+/', ' ', trim($header)));
    }

    private function nameKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function boolean(?string $value, bool $default): bool
    {
        $value = mb_strtolower(trim((string) $value));
        if ($value === '') {
            return $default;
        }
        if (in_array($value, ['1', 'true', 'yes', 'да', 'активен', 'активна'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'нет', 'неактивен', 'неактивна'], true)) {
            return false;
        }

        throw ValidationException::withMessages(['file' => 'В CSV-файле найдена неизвестная отметка активности или нарушения.']);
    }

    private function integer(?string $value, int $default): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw ValidationException::withMessages(['file' => 'Порядок строк CSV должен быть целым неотрицательным числом.']);
        }

        return (int) $value;
    }

    /** @param array<string, bool> $used */
    private function stableKey(string $prefix, string $value, array $used): string
    {
        $slug = Str::slug($value, '_');
        $slug = $slug !== '' ? $slug : substr(hash('sha256', $value), 0, 12);
        $base = $prefix.'_'.mb_substr($slug, 0, 100);
        if (! isset($used[$base])) {
            return $base;
        }

        return mb_substr($base, 0, 88).'_'.substr(hash('sha256', $value), 0, 12);
    }
}
