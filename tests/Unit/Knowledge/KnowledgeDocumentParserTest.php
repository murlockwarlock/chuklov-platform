<?php

namespace Tests\Unit\Knowledge;

use App\Modules\Knowledge\Domain\Enums\KnowledgeExtractionStatus;
use App\Modules\Knowledge\Infrastructure\Parsing\KnowledgeDocumentParser;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\Common\Creator\WriterFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use Tests\TestCase;

final class KnowledgeDocumentParserTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_text_pdf_is_extracted_deterministically_without_ai(): void
    {
        $result = app(KnowledgeDocumentParser::class)->parse($this->pdf('Booking guide'), 'guide.pdf');

        self::assertSame(KnowledgeExtractionStatus::Ready, $result->status);
        self::assertStringContainsString('Booking guide', (string) $result->content);
        self::assertSame('pdf_text', $result->parserType);
        self::assertArrayHasKey('page_count', $result->diagnostics);
    }

    public function test_scanned_pdf_returns_text_not_found_without_starting_ai(): void
    {
        $result = app(KnowledgeDocumentParser::class)->parse($this->pdf(' '), 'scan.pdf');

        self::assertSame(KnowledgeExtractionStatus::TextNotFound, $result->status, json_encode($result->diagnostics, JSON_THROW_ON_ERROR));
        self::assertNull($result->content);
        self::assertSame('text_not_found', $result->diagnostics['code']);
    }

    public function test_csv_xlsx_ods_and_xls_keep_sheet_and_source_row_provenance(): void
    {
        $parser = app(KnowledgeDocumentParser::class);
        $csv = $parser->parse($this->file("Name,Amount\nAlice,12\n"), 'visits.csv');
        $xlsx = $parser->parse($this->spreadsheet('xlsx'), 'visits.xlsx');
        $ods = $parser->parse($this->spreadsheet('ods'), 'visits.ods');
        $xls = $parser->parse($this->legacyXls(), 'visits.xls');

        foreach ([$csv, $xlsx, $ods, $xls] as $result) {
            self::assertSame(KnowledgeExtractionStatus::Ready, $result->status, $result->parserType.' '.json_encode($result->diagnostics, JSON_THROW_ON_ERROR));
            self::assertStringContainsString('Имя файла:', (string) $result->content);
            self::assertStringContainsString('Имя листа:', (string) $result->content);
            self::assertStringContainsString('Строка 2:', (string) $result->content);
            self::assertStringContainsString('TSV 2:', (string) $result->content);
            self::assertNotEmpty($result->diagnostics['sheets']);
        }

        self::assertStringContainsString('Alice', (string) $csv->content);
        self::assertStringContainsString('Visits', (string) $xlsx->content);
        self::assertStringContainsString('Visits', (string) $ods->content);
        self::assertStringContainsString('Visits', (string) $xls->content);
    }

    public function test_spreadsheet_bounds_and_formula_handling_fail_closed(): void
    {
        config()->set('rag.uploads.parsing.maximum_spreadsheet_cells', 2);
        $result = app(KnowledgeDocumentParser::class)->parse($this->spreadsheet('xlsx'), 'unsafe.xlsx');

        self::assertSame(KnowledgeExtractionStatus::Suspicious, $result->status, json_encode($result->diagnostics, JSON_THROW_ON_ERROR));
        self::assertSame('cell_count_limit_exceeded', $result->diagnostics['code']);

        config()->set('rag.uploads.parsing.maximum_spreadsheet_cells', 100000);
        $safe = app(KnowledgeDocumentParser::class)->parse($this->spreadsheet('xlsx', withFormula: true), 'formula.xlsx');
        self::assertSame(KnowledgeExtractionStatus::Ready, $safe->status);
        self::assertStringContainsString('[формула не вычисляется]', (string) $safe->content);
    }

    public function test_archive_with_embedded_or_external_content_is_not_ingested(): void
    {
        $path = $this->spreadsheet('xlsx');
        $zip = new \ZipArchive;
        self::assertTrue($zip->open($path) === true);
        $zip->addFromString('xl/externalLinks/externalLink1.xml', '<externalLink/>');
        $zip->close();

        $result = app(KnowledgeDocumentParser::class)->parse($path, 'external.xlsx');

        self::assertSame(KnowledgeExtractionStatus::Suspicious, $result->status);
        self::assertSame('archive_contains_unsafe_content', $result->diagnostics['code']);
    }

    public function test_ods_sheet_count_is_bounded_before_ingestion(): void
    {
        config()->set('rag.uploads.parsing.maximum_spreadsheet_sheets', 1);

        $result = app(KnowledgeDocumentParser::class)->parse(
            $this->spreadsheet('ods', withSecondSheet: true),
            'many-sheets.ods',
        );

        self::assertSame(KnowledgeExtractionStatus::Suspicious, $result->status);
        self::assertSame('sheet_count_limit_exceeded', $result->diagnostics['code']);
    }

    private function file(string $content): string
    {
        $path = $this->path('knowledge-');
        file_put_contents($path, $content);

        return $path;
    }

    private function spreadsheet(string $format, bool $withFormula = false, bool $withSecondSheet = false): string
    {
        $path = $this->filePath('knowledge-', $format);
        $writer = WriterFactory::createFromFile($path);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Visits');
        $writer->addRow(Row::fromValues(['Name', 'Amount']));
        $writer->addRow(Row::fromValues($withFormula ? ['Alice', '=SUM(5,7)'] : ['Alice', 12]));
        if ($withSecondSheet) {
            $writer->addNewSheetAndMakeItCurrent()->setName('Second');
            $writer->addRow(Row::fromValues(['Name', 'Amount']));
            $writer->addRow(Row::fromValues(['Bob', 8]));
        }
        $writer->close();

        return $path;
    }

    private function legacyXls(): string
    {
        $path = $this->filePath('knowledge-', 'xls');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Visits');
        $spreadsheet->getActiveSheet()->fromArray([
            ['Name', 'Amount'],
            ['Alice', 12],
        ]);
        (new XlsWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function pdf(string $text): string
    {
        $stream = $text === '' ? '' : 'BT /F1 12 Tf 72 720 Td ('.$text.') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >> stream\n".$stream."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xrefOffset."\n%%EOF\n";

        return $this->file($pdf);
    }

    private function path(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($path);
        $this->paths[] = $path;

        return $path;
    }

    private function filePath(string $prefix, string $extension): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8)).'.'.$extension;
        $this->paths[] = $path;

        return $path;
    }
}
