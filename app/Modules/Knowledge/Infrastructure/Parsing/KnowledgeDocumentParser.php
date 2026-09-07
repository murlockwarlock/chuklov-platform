<?php

namespace App\Modules\Knowledge\Infrastructure\Parsing;

use App\Modules\Knowledge\Domain\Enums\KnowledgeExtractionStatus;
use DateInterval;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xls as LegacyXlsReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PrinsFrank\PdfParser\PdfParser;
use Throwable;
use ZipArchive;

final class KnowledgeDocumentParser
{
    /** @var array<string, list<string>> */
    private const MIME_TYPES_BY_EXTENSION = [
        'txt' => ['text/plain', 'text/markdown'],
        'md' => ['text/plain', 'text/markdown'],
        'markdown' => ['text/plain', 'text/markdown'],
        'pdf' => ['application/pdf'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/msexcel', 'application/xls', 'application/x-excel', 'application/x-msexcel', 'application/octet-stream'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip', 'application/octet-stream'],
    ];

    public function parse(string $path, string $originalFilename): ParsedKnowledgeDocument
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (! is_file($path) || ! is_readable($path)) {
            return $this->failed('application/octet-stream', str_repeat('0', 64), $extension, 'file_unavailable');
        }
        $originalChecksum = $this->checksum($path);
        $mimeType = $this->sniffMimeType($path);

        if (! in_array($extension, config('rag.uploads.allowed_extensions', []), true)
            || ! isset(self::MIME_TYPES_BY_EXTENSION[$extension])
            || ! in_array($mimeType, self::MIME_TYPES_BY_EXTENSION[$extension], true)) {
            return $this->suspicious($mimeType, $originalChecksum, $extension, 'mime_or_extension_not_allowed');
        }

        $size = filesize($path);
        if ($size === false || $size > ((int) config('rag.uploads.maximum_kilobytes') * 1024)) {
            return $this->suspicious($mimeType, $originalChecksum, $extension, 'file_too_large');
        }

        try {
            return match ($extension) {
                'txt', 'md', 'markdown' => $this->parsePlainText($path, $mimeType, $originalChecksum),
                'pdf' => $this->parsePdf($path, $mimeType, $originalChecksum),
                'csv' => $this->parseOpenSpout($path, $originalFilename, $mimeType, $originalChecksum, 'csv'),
                'xlsx' => $this->parseOpenSpout($path, $originalFilename, $mimeType, $originalChecksum, 'xlsx'),
                'ods' => $this->parseOpenSpout($path, $originalFilename, $mimeType, $originalChecksum, 'ods'),
                'xls' => $this->parseLegacyXls($path, $originalFilename, $mimeType, $originalChecksum),
            };
        } catch (Throwable $exception) {
            $reason = $this->safeParserReason($exception->getMessage());
            if (in_array($reason, [
                'cell_count_limit_exceeded',
                'cell_character_limit_exceeded',
                'sheet_row_limit_exceeded',
                'sheet_column_limit_exceeded',
                'parser_time_limit_exceeded',
            ], true)) {
                return $this->suspicious($mimeType, $originalChecksum, $extension, $reason);
            }

            return $this->failed(
                $mimeType,
                $originalChecksum,
                $extension,
                'parser_failed',
                ['exception' => $exception::class, 'reason' => $reason],
            );
        }
    }

    private function parsePlainText(string $path, string $mimeType, string $originalChecksum): ParsedKnowledgeDocument
    {
        $content = file_get_contents($path);
        if (! is_string($content)) {
            return $this->failed($mimeType, $originalChecksum, 'txt', 'text_read_failed');
        }
        if (mb_strlen($content) > $this->maximumExtractedCharacters()) {
            return $this->suspicious($mimeType, $originalChecksum, 'txt', 'extracted_text_too_large');
        }
        if (trim($content) === '') {
            return $this->textNotFound($mimeType, $originalChecksum, 'plain_text', 'empty_text');
        }

        return new ParsedKnowledgeDocument(
            KnowledgeExtractionStatus::Ready,
            $content,
            $mimeType,
            $originalChecksum,
            'plain_text',
            'php-stream-v1',
            ['character_count' => mb_strlen($content)],
        );
    }

    private function parsePdf(string $path, string $mimeType, string $originalChecksum): ParsedKnowledgeDocument
    {
        $startedAt = hrtime(true);
        $document = (new PdfParser)->parseFile($path, false);
        $this->assertWithinTimeLimit($startedAt);
        $pageCount = $document->getNumberOfPages();
        if ($pageCount > (int) config('rag.uploads.parsing.maximum_pdf_pages')) {
            return $this->suspicious($mimeType, $originalChecksum, 'pdf', 'page_count_limit_exceeded', ['page_count' => $pageCount]);
        }

        $pages = [];
        $characterCount = 0;
        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $this->assertWithinTimeLimit($startedAt);
            $pageText = $document->getPage($pageNumber)?->getText() ?? '';
            $characterCount += mb_strlen($pageText);
            if ($characterCount > $this->maximumExtractedCharacters()) {
                return $this->suspicious($mimeType, $originalChecksum, 'pdf', 'extracted_text_too_large', ['page_count' => $pageCount]);
            }
            if (trim($pageText) !== '') {
                $pages[] = trim($pageText);
            }
        }

        if ($pages === []) {
            return $this->textNotFound($mimeType, $originalChecksum, 'pdf_text', 'text_not_found', ['page_count' => $pageCount]);
        }

        return new ParsedKnowledgeDocument(
            KnowledgeExtractionStatus::Ready,
            implode("\n\n", $pages),
            $mimeType,
            $originalChecksum,
            'pdf_text',
            (string) config('rag.uploads.parsing.pdf_parser_version'),
            [
                'page_count' => $pageCount,
                'character_count' => $characterCount,
            ],
        );
    }

    private function parseOpenSpout(
        string $path,
        string $originalFilename,
        string $mimeType,
        string $originalChecksum,
        string $format,
    ): ParsedKnowledgeDocument {
        $startedAt = hrtime(true);
        if ($format !== 'csv') {
            $archiveResult = $this->inspectArchive($path, $format, $startedAt);
            if ($archiveResult !== null) {
                return $this->suspicious($mimeType, $originalChecksum, $format, $archiveResult['code'], $archiveResult['details']);
            }
        }

        $reader = $this->openOpenSpoutReader($format);
        $totalCells = 0;
        $reader->open($path);
        try {
            $this->assertWithinTimeLimit($startedAt);
            $sheets = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                if (count($sheets) >= $this->maximumSheets()) {
                    return $this->suspicious($mimeType, $originalChecksum, $format, 'sheet_count_limit_exceeded');
                }
                $sheets[] = $this->readOpenSpoutSheet($sheet, $originalFilename, $format, $startedAt, $totalCells);
            }
        } finally {
            $reader->close();
        }

        return $this->buildSpreadsheetResult($mimeType, $originalChecksum, $format, $sheets);
    }

    private function parseLegacyXls(
        string $path,
        string $originalFilename,
        string $mimeType,
        string $originalChecksum,
    ): ParsedKnowledgeDocument {
        $startedAt = hrtime(true);
        $reader = new LegacyXlsReader;
        $worksheetInfo = $reader->listWorksheetInfo($path);
        $this->assertWithinTimeLimit($startedAt);
        if (count($worksheetInfo) > $this->maximumSheets()) {
            return $this->suspicious($mimeType, $originalChecksum, 'xls', 'sheet_count_limit_exceeded');
        }
        if ($worksheetInfo === []) {
            return $this->textNotFound($mimeType, $originalChecksum, 'spreadsheet_xls', 'table_text_not_found');
        }

        foreach ($worksheetInfo as $info) {
            if ((int) ($info['totalRows'] ?? 0) > $this->maximumRows()
                || (int) ($info['totalColumns'] ?? 0) > $this->maximumColumns()) {
                return $this->suspicious($mimeType, $originalChecksum, 'xls', 'sheet_dimension_limit_exceeded', [
                    'sheet' => (string) ($info['worksheetName'] ?? ''),
                    'rows' => (int) ($info['totalRows'] ?? 0),
                    'columns' => (int) ($info['totalColumns'] ?? 0),
                ]);
            }
        }
        $this->assertWithinTimeLimit($startedAt);

        $reader
            ->setReadDataOnly(true)
            ->setIncludeCharts(false)
            ->setAllowExternalImages(false)
            ->setEnableDrawingPassThrough(false)
            ->setReadEmptyCells(false)
            ->setIgnoreRowsWithNoCells(true)
            ->setLoadSheetsOnly(array_column($worksheetInfo, 'worksheetName'))
            ->setReadFilter(new class($this->maximumRows(), $this->maximumColumns()) implements IReadFilter
            {
                public function __construct(private readonly int $maximumRows, private readonly int $maximumColumns) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->maximumRows
                        && Coordinate::columnIndexFromString($columnAddress) <= $this->maximumColumns;
                }
            });
        $spreadsheet = $reader->load($path);
        $this->assertWithinTimeLimit($startedAt);
        $totalCells = 0;
        $worksheetInfoByName = [];
        foreach ($worksheetInfo as $info) {
            $worksheetInfoByName[(string) ($info['worksheetName'] ?? '')] = $info;
        }
        try {
            $sheets = [];
            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $info = $worksheetInfoByName[(string) $worksheet->getTitle()] ?? [];
                $sheets[] = $this->readLegacySheet(
                    $worksheet,
                    $startedAt,
                    $totalCells,
                    max(1, min($this->maximumRows(), (int) ($info['totalRows'] ?? 1))),
                    max(1, min($this->maximumColumns(), (int) ($info['totalColumns'] ?? 1))),
                );
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return $this->buildSpreadsheetResult($mimeType, $originalChecksum, 'xls', $sheets);
    }

    private function openOpenSpoutReader(string $format): ReaderInterface
    {
        return match ($format) {
            'csv' => new CsvReader(new CsvOptions),
            'xlsx' => new XlsxReader(tap(new XlsxOptions, static function (XlsxOptions $options): void {
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            })),
            'ods' => new OdsReader(tap(new OdsOptions, static function (OdsOptions $options): void {
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            })),
            default => throw new \InvalidArgumentException('Unsupported OpenSpout format.'),
        };
    }

    /** @return array<string, mixed> */
    private function readOpenSpoutSheet(SheetInterface $sheet, string $originalFilename, string $format, int $startedAt, int &$totalCells): array
    {
        $rows = [];
        $rowIterator = $sheet->getRowIterator();
        foreach ($rowIterator as $sourceRowNumber => $row) {
            $sourceRowNumber = (int) $sourceRowNumber;
            if ($sourceRowNumber < 1 || $sourceRowNumber > $this->maximumRows()) {
                throw new \RuntimeException('sheet_row_limit_exceeded');
            }
            if (! $row instanceof Row) {
                continue;
            }
            $cells = $this->readOpenSpoutCells($row, $startedAt, $totalCells);
            if ($cells !== []) {
                $rows[] = ['source_row' => $sourceRowNumber, 'cells' => $cells];
            }
        }

        return [
            'name' => $sheet->getName() !== '' ? $sheet->getName() : pathinfo($originalFilename, PATHINFO_FILENAME),
            'source_filename' => $originalFilename,
            'rows' => $rows,
            'format' => $format,
        ];
    }

    /** @return array<int, string> */
    private function readOpenSpoutCells(Row $row, int $startedAt, int &$totalCells): array
    {
        $values = [];
        $totalCells += count($row->getCells());
        if ($totalCells > (int) config('rag.uploads.parsing.maximum_spreadsheet_cells')) {
            throw new \RuntimeException('cell_count_limit_exceeded');
        }
        foreach ($row->getCells() as $columnIndex => $cell) {
            $this->assertWithinTimeLimit($startedAt);
            $columnNumber = (int) $columnIndex + 1;
            if ($columnNumber > $this->maximumColumns()) {
                throw new \RuntimeException('sheet_column_limit_exceeded');
            }
            $value = $this->openSpoutCellValue($cell);
            if ($value !== '') {
                $values[$columnNumber] = $value;
            }
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function readLegacySheet(object $worksheet, int $startedAt, int &$totalCells, int $maximumRows, int $maximumColumns): array
    {
        $rows = [];
        $lastColumn = Coordinate::stringFromColumnIndex($maximumColumns);
        foreach ($worksheet->getRowIterator(1, $maximumRows) as $row) {
            $this->assertWithinTimeLimit($startedAt);
            $cells = [];
            $cellIterator = $row->getCellIterator('A', $lastColumn, false);
            $cellIterator->setIfNotExists(false);
            foreach ($cellIterator as $column => $cell) {
                $totalCells++;
                if ($totalCells > (int) config('rag.uploads.parsing.maximum_spreadsheet_cells')) {
                    throw new \RuntimeException('cell_count_limit_exceeded');
                }
                if ($cell === null) {
                    continue;
                }
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $value = '[формула не вычисляется]';
                } else {
                    $value = $this->scalarCellValue($cell->getValue());
                }
                if ($value !== '') {
                    $cells[Coordinate::columnIndexFromString((string) $column)] = $this->boundedCellValue($value);
                }
            }
            if ($cells !== []) {
                $rows[] = ['source_row' => $row->getRowIndex(), 'cells' => $cells];
            }
        }

        return [
            'name' => (string) $worksheet->getTitle(),
            'rows' => $rows,
            'format' => 'xls',
        ];
    }

    /** @param list<array<string, mixed>> $sheets */
    private function buildSpreadsheetResult(string $mimeType, string $originalChecksum, string $format, array $sheets): ParsedKnowledgeDocument
    {
        $rowCount = array_sum(array_map(static fn (array $sheet): int => count($sheet['rows'] ?? []), $sheets));
        if ($rowCount === 0) {
            return $this->textNotFound($mimeType, $originalChecksum, 'spreadsheet_'.$format, 'table_text_not_found');
        }
        $segments = [];
        $sheetDiagnostics = [];
        $totalCells = 0;
        foreach ($sheets as $sheet) {
            $rows = is_array($sheet['rows'] ?? null) ? $sheet['rows'] : [];
            $header = $rows[0]['cells'] ?? [];
            $maximumColumn = max(array_keys($header ?: [1 => 1]));
            foreach ($rows as $row) {
                $cells = is_array($row['cells'] ?? null) ? $row['cells'] : [];
                if ($cells !== []) {
                    $maximumColumn = max($maximumColumn, max(array_keys($cells)));
                }
            }
            $headerNames = [];
            foreach (range(1, min($this->maximumColumns(), $maximumColumn)) as $columnNumber) {
                if ($columnNumber > $this->maximumColumns()) {
                    break;
                }
                $headerNames[$columnNumber] = $this->boundedCellValue((string) ($header[$columnNumber] ?? 'Колонка '.$columnNumber));
            }
            $segments[] = 'Источник: '.($format === 'csv' ? 'таблица CSV' : 'таблица '.$format);
            $segments[] = 'Имя файла: '.basename((string) ($sheet['source_filename'] ?? ''));
            $segments[] = 'Имя листа: '.(string) ($sheet['name'] ?? 'Без названия');
            $segments[] = 'Заголовки: '.implode(' | ', array_values($headerNames));
            foreach ($rows as $row) {
                $sourceRow = (int) ($row['source_row'] ?? 0);
                $cells = is_array($row['cells'] ?? null) ? $row['cells'] : [];
                $totalCells += count($cells);
                $parts = [];
                foreach ($headerNames as $columnNumber => $headerName) {
                    $value = (string) ($cells[$columnNumber] ?? '');
                    if ($value !== '') {
                        $parts[] = $headerName.'='.$value;
                    }
                }
                if ($parts !== []) {
                    $segments[] = 'Строка '.$sourceRow.': '.implode('; ', $parts);
                    $segments[] = 'TSV '.$sourceRow.': '.implode("\t", array_map(
                        static fn (int $columnNumber): string => (string) ($cells[$columnNumber] ?? ''),
                        array_keys($headerNames),
                    ));
                }
            }
            $sheetDiagnostics[] = [
                'name' => (string) ($sheet['name'] ?? ''),
                'row_count' => count($rows),
                'cell_count' => array_sum(array_map(static fn (array $row): int => count($row['cells'] ?? []), $rows)),
                'source_rows' => array_values(array_map(static fn (array $row): int => (int) ($row['source_row'] ?? 0), $rows)),
            ];
        }

        $content = trim(implode("\n", $segments));
        if ($content === '') {
            return $this->textNotFound($mimeType, $originalChecksum, 'spreadsheet_'.$format, 'table_text_not_found', [
                'sheets' => $sheetDiagnostics,
            ]);
        }
        if ($totalCells > (int) config('rag.uploads.parsing.maximum_spreadsheet_cells')) {
            return $this->suspicious($mimeType, $originalChecksum, $format, 'cell_count_limit_exceeded', ['cell_count' => $totalCells]);
        }
        if (mb_strlen($content) > $this->maximumExtractedCharacters()) {
            return $this->suspicious($mimeType, $originalChecksum, $format, 'extracted_text_too_large', ['cell_count' => $totalCells]);
        }

        return new ParsedKnowledgeDocument(
            KnowledgeExtractionStatus::Ready,
            $content,
            $mimeType,
            $originalChecksum,
            $format === 'xls' ? 'spreadsheet_xls' : 'spreadsheet_'.$format,
            $format === 'xls'
                ? (string) config('rag.uploads.parsing.legacy_xls_parser_version')
                : (string) config('rag.uploads.parsing.spreadsheet_parser_version'),
            [
                'sheets' => $sheetDiagnostics,
                'cell_count' => $totalCells,
            ],
        );
    }

    private function openSpoutCellValue(Cell $cell): string
    {
        if ($cell instanceof Cell\FormulaCell) {
            return '[формула не вычисляется]';
        }

        return $this->boundedCellValue($this->scalarCellValue($cell->getValue()));
    }

    private function scalarCellValue(mixed $value): string
    {
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if ($value instanceof DateInterval) {
            return $value->format('P%yY%mM%dDT%hH%iM%sS');
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private function boundedCellValue(string $value): string
    {
        if (mb_strlen($value) > (int) config('rag.uploads.parsing.maximum_cell_characters')) {
            throw new \RuntimeException('cell_character_limit_exceeded');
        }

        return trim($value);
    }

    /** @return array{code: string, details: array<string, mixed>}|null */
    private function inspectArchive(string $path, string $format, int $startedAt): ?array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return ['code' => 'archive_open_failed', 'details' => []];
        }
        try {
            $totalBytes = 0;
            if ($zip->numFiles > (int) config('rag.uploads.parsing.maximum_archive_entries')) {
                return ['code' => 'archive_entry_limit_exceeded', 'details' => ['entries' => $zip->numFiles]];
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $this->assertWithinTimeLimit($startedAt);
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    return ['code' => 'archive_entry_unreadable', 'details' => ['index' => $index]];
                }
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                $lowerName = strtolower($name);
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '../') || str_contains($lowerName, 'vbaproject') || str_contains($lowerName, 'external-links') || str_contains($lowerName, 'externallinks') || str_contains($lowerName, 'embeddings/') || str_contains($lowerName, 'macros') || str_contains($lowerName, 'basic/') || str_contains($lowerName, 'scripts/') || str_contains($lowerName, 'activex') || preg_match('/\.(exe|dll|bat|cmd|ps1|jar|class)$/i', $name) === 1) {
                    return ['code' => 'archive_contains_unsafe_content', 'details' => ['entry' => basename($name)]];
                }
                $size = (int) ($stat['size'] ?? 0);
                $compressedSize = (int) ($stat['comp_size'] ?? 0);
                if ($size < 0 || $size > (int) config('rag.uploads.parsing.maximum_archive_entry_bytes')) {
                    return ['code' => 'archive_entry_size_limit_exceeded', 'details' => ['entry' => basename($name), 'size' => $size]];
                }
                if ($compressedSize > 0 && $size > ($compressedSize * (int) config('rag.uploads.parsing.maximum_compression_ratio'))) {
                    return ['code' => 'archive_compression_ratio_exceeded', 'details' => ['entry' => basename($name)]];
                }
                if (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== 0) {
                    return ['code' => 'encrypted_archive_not_supported', 'details' => ['entry' => basename($name)]];
                }
                $totalBytes += $size;
                if ($totalBytes > (int) config('rag.uploads.parsing.maximum_archive_uncompressed_bytes')) {
                    return ['code' => 'archive_uncompressed_size_limit_exceeded', 'details' => ['bytes' => $totalBytes]];
                }
            }

            $this->assertWithinTimeLimit($startedAt);

            return $this->inspectArchiveDimensions($zip, $format, $startedAt);
        } finally {
            $zip->close();
        }
    }

    /** @return array{code: string, details: array<string, mixed>}|null */
    private function inspectArchiveDimensions(ZipArchive $zip, string $format, int $startedAt): ?array
    {
        $xmlEntries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $this->assertWithinTimeLimit($startedAt);
            $stat = $zip->statIndex($index);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($format === 'xlsx' && preg_match('#^xl/worksheets/[^/]+\.xml$#i', $name) === 1) {
                $xmlEntries[] = $name;
            }
            if ($format === 'ods' && strtolower($name) === 'content.xml') {
                $xmlEntries[] = $name;
            }
        }
        $sheetCount = $format === 'ods' ? $this->countOdsSheets($zip) : $this->countArchiveSheets($zip, $format);
        if ($sheetCount < 1 || $sheetCount > $this->maximumSheets()) {
            return ['code' => 'sheet_count_limit_exceeded', 'details' => ['sheets' => $sheetCount]];
        }
        foreach ($xmlEntries as $entry) {
            $this->assertWithinTimeLimit($startedAt);
            $xml = $zip->getFromName($entry);
            if (! is_string($xml)) {
                return ['code' => 'archive_xml_unreadable', 'details' => ['entry' => basename($entry)]];
            }
            if ($format === 'xlsx') {
                if (preg_match('/<dimension\b[^>]*\bref=["\']([^"\']+)/i', $xml, $matches) === 1) {
                    $dimensions = str_contains($matches[1], ':') ? explode(':', $matches[1], 2)[1] : $matches[1];
                    if ($this->cellReferenceExceedsBounds($dimensions)) {
                        return ['code' => 'sheet_dimension_limit_exceeded', 'details' => ['entry' => basename($entry), 'reference' => $dimensions]];
                    }
                }
            } else {
                if (preg_match('/table:number-rows-repeated=["\'](\d+)/i', $xml, $matches) === 1 && (int) $matches[1] > $this->maximumRows()) {
                    return ['code' => 'sheet_row_limit_exceeded', 'details' => ['entry' => basename($entry)]];
                }
                if (preg_match('/table:number-columns-repeated=["\'](\d+)/i', $xml, $matches) === 1 && (int) $matches[1] > $this->maximumColumns()) {
                    return ['code' => 'sheet_column_limit_exceeded', 'details' => ['entry' => basename($entry)]];
                }
            }
        }

        return null;
    }

    private function countArchiveSheets(ZipArchive $zip, string $format): int
    {
        if ($format !== 'xlsx') {
            return 0;
        }
        $workbook = $zip->getFromName('xl/workbook.xml');
        if (! is_string($workbook)) {
            return 0;
        }

        return preg_match_all('/<sheet\b/i', $workbook) ?: 0;
    }

    private function countOdsSheets(ZipArchive $zip): int
    {
        $content = $zip->getFromName('content.xml');
        if (! is_string($content)) {
            return 0;
        }

        return preg_match_all('/<table:table\b/i', $content) ?: 0;
    }

    private function cellReferenceExceedsBounds(string $reference): bool
    {
        if (preg_match('/^([A-Z]+)(\d+)$/i', $reference, $matches) !== 1) {
            return true;
        }

        return Coordinate::columnIndexFromString(strtoupper($matches[1])) > $this->maximumColumns()
            || (int) $matches[2] > $this->maximumRows();
    }

    private function assertWithinTimeLimit(int $startedAt): void
    {
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        if ($elapsedSeconds > (int) config('rag.uploads.parsing.maximum_processing_seconds')) {
            throw new \RuntimeException('parser_time_limit_exceeded');
        }
    }

    private function sniffMimeType(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) ($finfo->file($path) ?: 'application/octet-stream');
    }

    private function checksum(string $path): string
    {
        $checksum = hash_file('sha256', $path);

        return is_string($checksum) ? $checksum : str_repeat('0', 64);
    }

    private function maximumExtractedCharacters(): int
    {
        return (int) config('rag.uploads.maximum_extracted_characters');
    }

    private function maximumSheets(): int
    {
        return (int) config('rag.uploads.parsing.maximum_spreadsheet_sheets');
    }

    private function maximumRows(): int
    {
        return (int) config('rag.uploads.parsing.maximum_spreadsheet_rows');
    }

    private function maximumColumns(): int
    {
        return (int) config('rag.uploads.parsing.maximum_spreadsheet_columns');
    }

    /** @param array<string, mixed> $extra */
    private function failed(string $mimeType, string $checksum, string $parserType, string $code, array $extra = []): ParsedKnowledgeDocument
    {
        return new ParsedKnowledgeDocument(KnowledgeExtractionStatus::Failed, null, $mimeType, $checksum, $parserType, $this->parserVersion($parserType), ['code' => $code, ...$extra]);
    }

    /** @param array<string, mixed> $extra */
    private function suspicious(string $mimeType, string $checksum, string $parserType, string $code, array $extra = []): ParsedKnowledgeDocument
    {
        return new ParsedKnowledgeDocument(KnowledgeExtractionStatus::Suspicious, null, $mimeType, $checksum, $parserType, $this->parserVersion($parserType), ['code' => $code, ...$extra]);
    }

    /** @param array<string, mixed> $extra */
    private function textNotFound(string $mimeType, string $checksum, string $parserType, string $code, array $extra = []): ParsedKnowledgeDocument
    {
        return new ParsedKnowledgeDocument(KnowledgeExtractionStatus::TextNotFound, null, $mimeType, $checksum, $parserType, $this->parserVersion($parserType), ['code' => $code, ...$extra]);
    }

    private function parserVersion(string $parserType): string
    {
        return match (true) {
            $parserType === 'pdf' || $parserType === 'pdf_text' => (string) config('rag.uploads.parsing.pdf_parser_version'),
            $parserType === 'xls' || $parserType === 'spreadsheet_xls' => (string) config('rag.uploads.parsing.legacy_xls_parser_version'),
            $parserType === 'csv' || $parserType === 'xlsx' || $parserType === 'ods' || str_starts_with($parserType, 'spreadsheet_') => (string) config('rag.uploads.parsing.spreadsheet_parser_version'),
            default => 'parser-v1',
        };
    }

    private function safeParserReason(string $message): string
    {
        return preg_match('/^[a-z0-9_]+$/', $message) === 1 ? $message : 'unexpected_parser_error';
    }
}
