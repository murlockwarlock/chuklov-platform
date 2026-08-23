<?php

namespace Tests\Unit\ClientCompanion;

use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Infrastructure\Telegram\TelegramCompanionFormatter;
use App\Modules\Channels\Infrastructure\Telegram\TelegramMessagingChannel;
use GuzzleHttp\Psr7\Response;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

final class TelegramCompanionFormatterTest extends TestCase
{
    public function test_text_at_and_below_the_safe_limit_is_not_split(): void
    {
        $formatter = new TelegramCompanionFormatter;

        self::assertCount(1, $formatter->chunks(str_repeat('x', TelegramCompanionFormatter::SAFE_MESSAGE_LENGTH)));
        self::assertCount(1, $formatter->chunks(str_repeat('x', TelegramCompanionFormatter::SAFE_MESSAGE_LENGTH - 1)));
    }

    public function test_text_above_the_limit_is_split_into_independently_valid_chunks(): void
    {
        $formatter = new TelegramCompanionFormatter;
        $chunks = $formatter->chunks(str_repeat('слово ', 1800));

        self::assertGreaterThanOrEqual(3, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(TelegramCompanionFormatter::SAFE_MESSAGE_LENGTH, mb_strlen($chunk));
            self::assertSame($chunk, $formatter->repairHtml($chunk));
            self::assertDoesNotMatchRegularExpression('/<a\b(?![^>]*href=)[^>]*>/i', $chunk);
        }
    }

    public function test_formatting_is_reopened_across_chunk_boundaries(): void
    {
        $formatter = new TelegramCompanionFormatter;
        $chunks = $formatter->chunks('**'.str_repeat('ж', 5000).'**');

        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertStringContainsString('<b>', $chunk);
            self::assertStringContainsString('</b>', $chunk);
        }
    }

    public function test_nested_formatting_code_links_and_human_boundaries_are_preserved(): void
    {
        $formatter = new TelegramCompanionFormatter;
        $html = $formatter->markdownToHtml("> цитата\n\n**важно _срочно_**\n\n[организация](https://example.com)\n\n`code`\n\n```\npre <tag>\n```");

        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('<b>важно <i>срочно</i></b>', $html);
        self::assertStringContainsString('href="https://example.com"', $html);
        self::assertStringContainsString('<code>code</code>', $html);
        self::assertStringContainsString('<pre><code>', $html);
        self::assertStringContainsString('&lt;tag&gt;', $html);
    }

    public function test_malformed_and_unsafe_model_markup_is_repaired_without_executable_html(): void
    {
        $formatter = new TelegramCompanionFormatter;
        $html = $formatter->repairHtml('<b>one <i>two</b> three</i><script>alert(1)</script><a href="javascript:alert(1)">bad</a></u>');

        self::assertStringNotContainsString('<script', strtolower($html));
        self::assertStringNotContainsString('javascript:', strtolower($html));
        self::assertSame($html, $formatter->repairHtml($html));
        self::assertSame('one two threealert(1)bad', $formatter->plainText($html));
    }

    public function test_russian_english_emoji_and_escaped_text_remain_readable(): void
    {
        $formatter = new TelegramCompanionFormatter;
        $html = $formatter->markdownToHtml('Привет, hello 👋 & < >');

        self::assertStringContainsString('Привет, hello 👋 &amp; &lt; &gt;', $html);
        self::assertSame('Привет, hello 👋 & < >', $formatter->plainText($html));
    }

    public function test_telegram_parse_failure_retries_repaired_html_for_the_same_chunk(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $success = json_encode(['ok' => true, 'result' => ['message_id' => 901, 'date' => 1703892479, 'chat' => ['id' => 1, 'type' => 'private'], 'text' => 'Ответ']], JSON_THROW_ON_ERROR);
        $bot = FakeNutgram::instance(null, [
            new Response(400, [], json_encode(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"], JSON_THROW_ON_ERROR)),
            new Response(200, [], $success),
        ]);
        $channel = new TelegramMessagingChannel($bot);

        $result = $channel->sendCompanionChunk(new CompanionOutboundChunk('telegram-chat', '**Ответ**', 0, 1, 'ru'));

        self::assertSame(NotificationDeliveryOutcome::Delivered, $result->outcome);
        self::assertCount(2, $bot->getRequestHistory());
    }

    public function test_telegram_repaired_html_failure_falls_back_to_plain_text_without_losing_answer(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $failure = json_encode(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"], JSON_THROW_ON_ERROR);
        $success = json_encode(['ok' => true, 'result' => ['message_id' => 902, 'date' => 1703892479, 'chat' => ['id' => 1, 'type' => 'private'], 'text' => 'Ответ']], JSON_THROW_ON_ERROR);
        $bot = FakeNutgram::instance(null, [
            new Response(400, [], $failure),
            new Response(400, [], $failure),
            new Response(200, [], $success),
        ]);
        $channel = new TelegramMessagingChannel($bot);

        $result = $channel->sendCompanionChunk(new CompanionOutboundChunk('telegram-chat', '**Ответ**', 0, 1, 'ru'));

        self::assertSame(NotificationDeliveryOutcome::Delivered, $result->outcome);
        self::assertCount(3, $bot->getRequestHistory());
        $lastRequest = array_values($bot->getRequestHistory())[2];
        $lastBody = json_decode((string) array_values($lastRequest)[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Ответ', $lastBody['text']);
    }

    public function test_transport_failure_is_unknown_instead_of_automatically_retryable(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $bot = FakeNutgram::instance(null, [
            new Response(500, [], json_encode(['ok' => false, 'error_code' => 500, 'description' => 'gateway timeout'], JSON_THROW_ON_ERROR)),
        ]);
        $channel = new TelegramMessagingChannel($bot);

        $result = $channel->sendCompanionChunk(new CompanionOutboundChunk('telegram-chat', 'Ответ', 0, 1, 'ru'));

        self::assertSame(NotificationDeliveryOutcome::Unknown, $result->outcome);
        self::assertSame('telegram_api_error', $result->errorCode);
    }

    public function test_provider_rate_limit_is_a_confirmed_bounded_retryable_rejection(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $bot = FakeNutgram::instance(null, [
            new Response(429, [], json_encode(['ok' => false, 'error_code' => 429, 'description' => 'too many requests'], JSON_THROW_ON_ERROR)),
        ]);
        $channel = new TelegramMessagingChannel($bot);

        $result = $channel->sendCompanionChunk(new CompanionOutboundChunk('telegram-chat', 'Ответ', 0, 1, 'ru'));

        self::assertSame(NotificationDeliveryOutcome::Retryable, $result->outcome);
        self::assertSame('telegram_rate_limited', $result->errorCode);
    }
}
