<?php

namespace Tests\Unit;

use App\Support\RichText\RichTextDocument;
use PHPUnit\Framework\TestCase;

final class RichTextDocumentTest extends TestCase
{
    public function test_formatting_and_emoji_have_one_safe_telegram_projection(): void
    {
        $html = RichTextDocument::telegramHtml('<p><strong>Bold</strong> <em>italic</em> <u>under</u> <s>strike</s> <a href="https://example.test">link</a> <code>code</code></p><blockquote>quote</blockquote><p>😀 ❤️ 👍 👋<br>next</p>');

        self::assertStringContainsString('<b>Bold</b>', $html);
        self::assertStringContainsString('<i>italic</i>', $html);
        self::assertStringContainsString('<u>under</u>', $html);
        self::assertStringContainsString('<s>strike</s>', $html);
        self::assertStringContainsString('<a href="https://example.test">link</a>', $html);
        self::assertStringContainsString('<code>code</code>', $html);
        self::assertStringContainsString('<blockquote>quote</blockquote>', $html);
        self::assertStringContainsString("😀 ❤️ 👍 👋\nnext", $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<script', RichTextDocument::canonicalHtml('<p>safe</p><script>alert(1)</script>'));
    }

    public function test_tiptap_editor_state_keeps_link_marks_in_the_safe_projection(): void
    {
        $state = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['textAlign' => 'start'],
                'content' => [[
                    'type' => 'text',
                    'text' => 'Закрытое сообщество 😀',
                    'marks' => [
                        [
                            'type' => 'link',
                            'attrs' => [
                                'href' => 'https://t.me/test_community',
                                'target' => '_blank',
                                'rel' => 'noopener noreferrer nofollow',
                            ],
                        ],
                        ['type' => 'underline'],
                    ],
                ]],
            ]],
        ];

        $html = RichTextDocument::canonicalHtmlFromState($state);

        self::assertStringContainsString('href="https://t.me/test_community"', $html);
        self::assertStringContainsString('Закрытое сообщество 😀', $html);
        self::assertStringContainsString('<u>', $html);
        self::assertStringContainsString(
            '<a href="https://t.me/test_community">',
            RichTextDocument::telegramHtml($html),
        );
    }

    public function test_telegram_limits_count_rendered_text_in_utf16_units(): void
    {
        self::assertSame(4096, RichTextDocument::telegramLength(str_repeat('a', 4096)));
        self::assertTrue(RichTextDocument::exceedsTelegramLimit(str_repeat('a', 4097)));
        self::assertSame(1024, RichTextDocument::telegramLength(str_repeat('a', 1024)));
        self::assertTrue(RichTextDocument::exceedsTelegramLimit(str_repeat('a', 1025), true));
        self::assertSame(2, RichTextDocument::telegramLength('😀'));
        self::assertSame(3, RichTextDocument::telegramLength('<p>a<br>b</p>'));
    }

    public function test_pre_escaped_template_values_are_not_encoded_twice(): void
    {
        $telegramHtml = RichTextDocument::telegramHtml('<p>Здравствуйте, A &amp; B!</p>');

        self::assertSame('<p>Здравствуйте, A &amp; B!</p>', RichTextDocument::canonicalHtml('<p>Здравствуйте, A &amp; B!</p>'));
        self::assertSame(mb_strlen('Здравствуйте, A & B!'), RichTextDocument::telegramLength('<p>Здравствуйте, A &amp; B!</p>'));
        self::assertStringNotContainsString('&amp;amp;', $telegramHtml);
    }

    public function test_allowed_mailto_and_tel_links_are_preserved_in_telegram_html(): void
    {
        $telegramHtml = RichTextDocument::telegramHtml('<p><a href="mailto:help@example.test">email</a> <a href="tel:+77001234567">phone</a></p>');

        self::assertStringContainsString('<a href="mailto:help@example.test">email</a>', $telegramHtml);
        self::assertStringContainsString('<a href="tel:+77001234567">phone</a>', $telegramHtml);
    }

    public function test_filament_merge_tags_are_normalized_to_supported_template_tokens(): void
    {
        $content = '<p>Здравствуйте, <span data-type="mergeTag" data-id="client.full_name"></span>!</p>';

        self::assertSame(
            '<p>Здравствуйте, {{ client.full_name }}!</p>',
            RichTextDocument::canonicalHtml($content),
        );
    }
}
