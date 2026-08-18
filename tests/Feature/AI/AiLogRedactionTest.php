<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Services\AiErrorSanitizer;
use App\Modules\Security\Infrastructure\Logging\RedactSensitiveLogData;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class AiLogRedactionTest extends TestCase
{
    public function test_processor_redacts_sensitive_ai_prompts_chunks_and_credentials_from_context(): void
    {
        $processor = new RedactSensitiveLogData;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'testing',
            level: Level::Info,
            message: 'AI workflow executed',
            context: [
                'organization_id' => 1,
                'run_id' => 10,
                'system_prompt' => 'You are an internal clinical assistant for patient 123',
                'user_prompt' => 'Patient has stage 2 hypertension',
                'rag_chunk' => 'Confidential patient clinical guidelines',
                'retrieved_content' => 'Full text of chunk',
                'tool_payload' => ['client_ssn' => '000-00-0000'],
                'api_key' => 'sk-live-secret-key-12345',
                'status' => 'succeeded',
            ],
        );

        $redacted = $processor($record);

        $this->assertSame(1, $redacted->context['organization_id']);
        $this->assertSame(10, $redacted->context['run_id']);
        $this->assertSame('succeeded', $redacted->context['status']);

        // Sensitive keys must be masked with [REDACTED]
        $this->assertSame('[REDACTED]', $redacted->context['system_prompt']);
        $this->assertSame('[REDACTED]', $redacted->context['user_prompt']);
        $this->assertSame('[REDACTED]', $redacted->context['rag_chunk']);
        $this->assertSame('[REDACTED]', $redacted->context['retrieved_content']);
        $this->assertSame('[REDACTED]', $redacted->context['tool_payload']);
        $this->assertSame('[REDACTED]', $redacted->context['api_key']);
    }

    public function test_error_sanitizer_masks_sensitive_provider_exception_text_and_api_keys(): void
    {
        $sensitiveException = new \RuntimeException('Failed to call https://api.openai.com with key sk-live-secret-12345 for patient John Doe medical report');

        $sanitized = AiErrorSanitizer::sanitize($sensitiveException);

        $this->assertSame(AiErrorCategory::InternalError, $sanitized['category']);
        // Must NOT leak prompt, patient name, API key, or provider URL
        $this->assertStringNotContainsString('sk-live-secret-12345', $sanitized['message']);
        $this->assertStringNotContainsString('John Doe', $sanitized['message']);
        $this->assertStringNotContainsString('medical report', $sanitized['message']);
        $this->assertSame('An internal error occurred during AI execution.', $sanitized['message']);
    }
}
