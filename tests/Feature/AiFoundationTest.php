<?php

namespace Tests\Feature;

use App\Ai\Agents\FoundationAgent;
use Tests\TestCase;

class AiFoundationTest extends TestCase
{
    public function test_ai_sdk_uses_a_fake_without_an_external_call(): void
    {
        FoundationAgent::fake(['foundation-ok'])->preventStrayPrompts();

        $response = (new FoundationAgent)->prompt('Verify the foundation.');

        self::assertSame('foundation-ok', (string) $response);
        FoundationAgent::assertPrompted('Verify the foundation.');
    }
}
