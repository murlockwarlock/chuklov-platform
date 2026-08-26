<?php

namespace Tests\Unit;

use App\Modules\Broadcasts\Application\SegmentDefinition;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BroadcastSegmentDefinitionTest extends TestCase
{
    public function test_allowlisted_filters_are_typed_and_normalized(): void
    {
        $filters = app(SegmentDefinition::class)->validate([
            ['key' => 'language', 'operator' => 'in', 'value' => ['ru', 'en']],
            ['key' => 'visit_count', 'operator' => 'gte', 'value' => '2'],
            ['key' => 'no_future_booking', 'operator' => 'equals', 'value' => true],
        ]);

        self::assertSame(2, $filters[1]['value']);
        self::assertTrue($filters[2]['value']);
    }

    public function test_visit_count_cannot_be_negative(): void
    {
        $this->expectException(ValidationException::class);
        app(SegmentDefinition::class)->validate([
            ['key' => 'visit_count', 'operator' => 'gte', 'value' => -1],
        ]);
    }

    #[DataProvider('forbiddenFilters')]
    public function test_arbitrary_and_sensitive_health_filters_fail_closed(string $key): void
    {
        $this->expectException(ValidationException::class);
        app(SegmentDefinition::class)->validate([['key' => $key, 'operator' => 'equals', 'value' => 'x']]);
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenFilters(): iterable
    {
        yield 'raw SQL' => ['clients.id) OR 1=1 --'];
        yield 'anamnesis' => ['medical.anamnesis'];
        yield 'diagnosis' => ['medical.diagnosis'];
        yield 'medicine' => ['medical.medicines'];
        yield 'attachments' => ['medical.attachments'];
        yield 'AI medical output' => ['ai.medical_output'];
        yield 'survey result category' => ['survey_result_category'];
    }
}
