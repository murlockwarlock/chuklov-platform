<?php

namespace Tests\Feature\Knowledge;

use App\Models\User;
use App\Modules\Knowledge\Application\CreateKnowledgeSource;
use App\Modules\Knowledge\Application\RequestKnowledgeAiParsing;
use App\Modules\Knowledge\Domain\Enums\KnowledgeExtractionStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class KnowledgeExtractionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('private');
    }

    public function test_scanned_pdf_stays_out_of_search_until_owner_requests_ai_parsing(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        $source = app(CreateKnowledgeSource::class)->handle($actor, [
            'title' => 'Scanned guide',
            'type' => 'uploaded_text',
            'file' => UploadedFile::fake()->createWithContent('scan.pdf', $this->pdf(' ')),
        ]);
        $revision = $source->revisions()->sole();

        self::assertSame(KnowledgeExtractionStatus::TextNotFound->value, $revision->extraction_status);
        self::assertSame('failed', $revision->status->value);
        self::assertNull($source->active_revision_id);
        self::assertCount(0, Queue::pushedJobs());
        Storage::disk('private')->assertExists($revision->storage_path);

        $requested = app(RequestKnowledgeAiParsing::class)->handle($actor, $source, $revision->getKey());

        self::assertSame(KnowledgeExtractionStatus::AiParseRequested->value, $requested->extraction_status);
        self::assertNull($requested->content);
        self::assertSame(1, AuditEvent::query()->where('action', 'knowledge.revision.ai_parse_requested')->count());
        self::assertNull($requested->ready_at);
        self::assertNull($source->fresh()->active_revision_id);
        self::assertCount(0, Queue::pushedJobs());
    }

    public function test_ai_parsing_request_is_tenant_scoped_and_cannot_be_repeated_for_ready_text(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        $source = KnowledgeSource::query()->create([
            'organization_id' => $organization->getKey(),
            'type' => 'uploaded_text',
            'title' => 'Text guide',
            'status' => 'active',
        ]);
        $revision = KnowledgeRevision::query()->create([
            'organization_id' => $organization->getKey(),
            'knowledge_source_id' => $source->getKey(),
            'version' => 1,
            'status' => 'ready',
            'original_filename' => 'guide.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 4,
            'content_checksum' => hash('sha256', 'text'),
            'content' => 'text',
            'extraction_status' => KnowledgeExtractionStatus::Ready->value,
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        $this->expectException(ValidationException::class);
        app(RequestKnowledgeAiParsing::class)->handle($actor, $source, $revision->getKey());
    }

    private function pdf(string $text): string
    {
        $stream = 'BT /F1 12 Tf 72 720 Td ('.$text.') Tj ET';
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

        return $pdf;
    }
}
