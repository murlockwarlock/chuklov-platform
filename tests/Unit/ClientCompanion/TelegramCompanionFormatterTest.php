<?php

namespace Tests\Unit\ClientCompanion;

use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\CompanionActionButton;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Infrastructure\Telegram\TelegramCompanionFormatter;
use App\Modules\Channels\Infrastructure\Telegram\TelegramMessagingChannel;
use GuzzleHttp\Psr7\Response;
use JsonException;
use ReflectionClass;
use SergiX44\Nutgram\Nutgram;
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

    public function test_long_text_prefers_paragraph_boundaries(): void
    {
        $formatter = new TelegramCompanionFormatter;
        $chunks = $formatter->chunks(
            str_repeat('первый ', 550)."\n\n".str_repeat('второй ', 550),
        );

        self::assertCount(2, $chunks);
        self::assertStringContainsString('первый', $formatter->plainText($chunks[0]));
        self::assertStringNotContainsString('второй', $formatter->plainText($chunks[0]));
        self::assertStringNotContainsString('первый', $formatter->plainText($chunks[1]));
        self::assertStringContainsString('второй', $formatter->plainText($chunks[1]));
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

    public function test_cyrillic_ha_is_not_treated_as_a_newline(): void
    {
        $formatter = new TelegramCompanionFormatter;
        $text = 'Похоже, новые сообщения сохранены.';
        $html = $formatter->markdownToHtml($text);

        self::assertTrue(mb_check_encoding($html, 'UTF-8'));
        self::assertStringNotContainsString("\n", $html);
        self::assertSame($text, $formatter->plainText($html));
    }

    public function test_malformed_utf8_in_semantic_text_is_repaired_before_send(): void
    {
        $body = $this->sendAndReadRequest(new CompanionOutboundChunk('telegram-chat', "Привет\xB1", 0, 1, 'ru'));

        self::assertSame('Привет?', $body['text']);
    }

    public function test_malformed_utf8_in_recipient_is_repaired_before_send(): void
    {
        $body = $this->sendAndReadRequest(new CompanionOutboundChunk("telegram-chat\xB1", 'Ответ', 0, 1, 'ru'));

        self::assertSame('telegram-chat?', $body['chat_id']);
    }

    public function test_malformed_utf8_in_button_text_is_repaired_before_send(): void
    {
        $body = $this->sendAndReadRequest(new CompanionOutboundChunk(
            recipientExternalId: 'telegram-chat',
            semanticText: 'Ответ',
            chunkIndex: 0,
            chunkCount: 1,
            locale: 'ru',
            buttons: [$this->button("Кнопка\xB1", callbackData: 'cc:human:1')],
        ));

        self::assertSame('Кнопка?', $body['reply_markup']['inline_keyboard'][0][0]['text']);
    }

    public function test_malformed_utf8_in_button_url_is_repaired_before_send(): void
    {
        $body = $this->sendAndReadRequest(new CompanionOutboundChunk(
            recipientExternalId: 'telegram-chat',
            semanticText: 'Ответ',
            chunkIndex: 0,
            chunkCount: 1,
            locale: 'ru',
            buttons: [$this->button('Открыть', url: "https://example.com/path/\xB1")],
        ));

        self::assertSame('https://example.com/path/?', $body['reply_markup']['inline_keyboard'][0][0]['url']);
    }

    public function test_malformed_utf8_in_button_callback_is_repaired_before_send(): void
    {
        $body = $this->sendAndReadRequest(new CompanionOutboundChunk(
            recipientExternalId: 'telegram-chat',
            semanticText: 'Ответ',
            chunkIndex: 0,
            chunkCount: 1,
            locale: 'ru',
            buttons: [$this->button('Открыть', callbackData: "cc:human:\xB1")],
        ));

        self::assertSame('cc:human:?', $body['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function test_valid_unicode_and_emoji_are_sent_without_boundary_changes(): void
    {
        $body = $this->sendAndReadRequest(new CompanionOutboundChunk(
            recipientExternalId: 'telegram-chat',
            semanticText: 'Привет 👋',
            chunkIndex: 0,
            chunkCount: 1,
            locale: 'ru',
            buttons: [new CompanionActionButton('Открыть 👋', callbackData: 'cc:human:1')],
        ));

        self::assertSame('Привет 👋', $body['text']);
        self::assertSame('Открыть 👋', $body['reply_markup']['inline_keyboard'][0][0]['text']);
        self::assertSame('cc:human:1', $body['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function test_rich_text_chunks_keep_formatting_and_safe_links(): void
    {
        $chunks = (new TelegramCompanionFormatter)->richTextChunks(
            '<p><strong>Привет</strong> <em>мир</em> 😊 <a href="https://example.test">ссылка</a></p><ul><li>пункт</li></ul>',
        );

        self::assertCount(1, $chunks);
        self::assertStringContainsString('<b>Привет</b>', $chunks[0]);
        self::assertStringContainsString('<i>мир</i>', $chunks[0]);
        self::assertStringContainsString('<a href="https://example.test">ссылка</a>', $chunks[0]);
        self::assertStringContainsString('• пункт', $chunks[0]);
    }

    public function test_rich_text_semantic_payload_is_sent_as_formatted_telegram_html(): void
    {
        $body = $this->sendAndReadRequest(new CompanionOutboundChunk(
            recipientExternalId: 'telegram-chat',
            semanticText: '<p><strong>Привет</strong> <em>мир</em></p>',
            chunkIndex: 0,
            chunkCount: 1,
            locale: 'ru',
        ));

        self::assertSame('<b>Привет</b> <i>мир</i>', $body['text']);
        self::assertSame('HTML', $body['parse_mode']);
    }

    public function test_json_utf8_failure_is_recorded_as_a_local_payload_failure(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $bot = $this->createMock(Nutgram::class);
        $bot->expects(self::once())
            ->method('sendMessage')
            ->willThrowException(new JsonException('Malformed UTF-8 characters, possibly incorrectly encoded', JSON_ERROR_UTF8));
        $channel = new TelegramMessagingChannel($bot);

        $result = $channel->sendCompanionChunk(new CompanionOutboundChunk('telegram-chat', 'Ответ', 0, 1, 'ru'));

        self::assertSame(NotificationDeliveryOutcome::PermanentFailure, $result->outcome);
        self::assertSame('telegram_payload_invalid_utf8', $result->errorCode);
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
        self::assertSame('901', $result->providerReference);
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

    public function test_provider_server_failure_is_retryable(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $bot = FakeNutgram::instance(null, [
            new Response(500, [], json_encode(['ok' => false, 'error_code' => 500, 'description' => 'gateway timeout'], JSON_THROW_ON_ERROR)),
        ]);
        $channel = new TelegramMessagingChannel($bot);

        $result = $channel->sendCompanionChunk(new CompanionOutboundChunk('telegram-chat', 'Ответ', 0, 1, 'ru'));

        self::assertSame(NotificationDeliveryOutcome::Retryable, $result->outcome);
        self::assertSame('telegram_server_error', $result->errorCode);
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

    private function sendAndReadRequest(CompanionOutboundChunk $chunk): array
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        $success = json_encode([
            'ok' => true,
            'result' => [
                'message_id' => 901,
                'date' => 1703892479,
                'chat' => ['id' => 1, 'type' => 'private'],
                'text' => 'Ответ',
            ],
        ], JSON_THROW_ON_ERROR);
        $bot = FakeNutgram::instance(null, [new Response(200, [], $success)]);
        $channel = new TelegramMessagingChannel($bot);

        $result = $channel->sendCompanionChunk($chunk);
        self::assertSame(NotificationDeliveryOutcome::Delivered, $result->outcome);
        self::assertSame('901', $result->providerReference);

        $request = array_values($bot->getRequestHistory())[0];

        return json_decode((string) array_values($request)[0]->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function button(string $text, ?string $callbackData = null, ?string $url = null): CompanionActionButton
    {
        $reflection = new ReflectionClass(CompanionActionButton::class);
        $button = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('text')->setValue($button, $text);
        $reflection->getProperty('callbackData')->setValue($button, $callbackData);
        $reflection->getProperty('url')->setValue($button, $url);

        return $button;
    }
}
