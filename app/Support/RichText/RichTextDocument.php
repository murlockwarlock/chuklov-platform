<?php

namespace App\Support\RichText;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Tiptap\Editor;
use Tiptap\Extensions\StarterKit;
use Tiptap\Marks\Link;
use Tiptap\Marks\Underline;

final class RichTextDocument
{
    public const TELEGRAM_TEXT_LIMIT = 4096;

    public const TELEGRAM_CAPTION_LIMIT = 1024;

    public static function canonicalHtml(string $content): string
    {
        $content = trim(self::normalizeMergeTags($content));

        if ($content === '') {
            return '';
        }

        $sanitized = self::sanitizer()->sanitize(self::isHtml($content)
            ? $content
            : '<p>'.nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false), false).'</p>');

        try {
            $html = self::editor()->sanitize($sanitized);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('The rich text content is invalid.', previous: $exception);
        }

        if (! is_string($html) || self::plainTextFromHtml($html) === '') {
            throw new \InvalidArgumentException('The rich text content is empty.');
        }

        return $html;
    }

    public static function canonicalHtmlFromState(mixed $content): string
    {
        if ($content === null || $content === '') {
            return '';
        }

        if (is_array($content)) {
            try {
                $content = self::editor()->setContent($content)->getHTML();
            } catch (\Throwable $exception) {
                throw new \InvalidArgumentException('The rich text content is invalid.', previous: $exception);
            }
        }

        if (! is_string($content)) {
            throw new \InvalidArgumentException('The rich text content is invalid.');
        }

        return self::canonicalHtml($content);
    }

    public static function normalizeMergeTags(string $content): string
    {
        $normalized = preg_replace_callback(
            '~<span\b(?=[^>]*\bdata-type\s*=\s*(["\'])mergeTag\1)(?=[^>]*\bdata-id\s*=\s*(["\'])([a-z][a-z0-9_.]*)\2)[^>]*>.*?</span>~isu',
            static fn (array $matches): string => '{{ '.$matches[3].' }}',
            $content,
        );

        return is_string($normalized) ? $normalized : $content;
    }

    public static function telegramHtml(string $content): string
    {
        $html = self::canonicalHtml($content);
        if ($html === '') {
            return '';
        }

        $document = self::document($html);
        $result = '';

        if ($document->documentElement !== null) {
            foreach ($document->documentElement->childNodes as $node) {
                $result .= self::telegramNode($node);
            }
        }

        $result = preg_replace('/\n{3,}/u', "\n\n", trim($result));

        return is_string($result) ? $result : '';
    }

    public static function plainText(string $content): string
    {
        $html = self::canonicalHtml($content);
        if ($html === '') {
            return '';
        }

        $text = self::plainTextFromHtml($html);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n[ \t]+/u', "\n", (string) $text);

        return trim((string) $text);
    }

    public static function telegramLength(string $content): int
    {
        return self::renderedTelegramLength(self::telegramHtml($content));
    }

    public static function renderedTelegramLength(string $content): int
    {
        $text = trim(self::renderedTextFromHtml($content));
        $encoded = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return intdiv(strlen($encoded), 2);
    }

    public static function exceedsTelegramLimit(string $content, bool $caption = false): bool
    {
        return self::telegramLength($content) > ($caption ? self::TELEGRAM_CAPTION_LIMIT : self::TELEGRAM_TEXT_LIMIT);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        $config = new HtmlSanitizerConfig;

        foreach (['p', 'br', 'strong', 'em', 'u', 's', 'code', 'pre', 'blockquote', 'h2', 'h3', 'ul', 'ol', 'li'] as $element) {
            $config = $config->allowElement($element);
        }

        return new HtmlSanitizer(
            $config
                ->allowElement('a', ['href'])
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow'),
        );
    }

    private static function editor(): Editor
    {
        return new Editor([
            'extensions' => [new StarterKit, new Underline, new Link],
        ]);
    }

    public static function isHtml(string $content): bool
    {
        return preg_match('/<\/?(?:p|br|strong|b|em|i|u|s|strike|a|code|pre|blockquote|h[2-3]|ul|ol|li)(?:\s|\/?>)/i', $content) === 1;
    }

    private static function document(string $html): \DOMDocument
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        return $document;
    }

    private static function plainTextFromHtml(string $html): string
    {
        $document = self::document($html);

        return trim(self::plainNode($document->documentElement));
    }

    private static function renderedTextFromHtml(string $html): string
    {
        $document = self::document($html);

        return self::textNodes($document->documentElement);
    }

    private static function telegramNode(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars((string) $node->nodeValue, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (! $node instanceof \DOMElement) {
            return '';
        }

        $children = '';
        foreach ($node->childNodes as $child) {
            $children .= self::telegramNode($child);
        }

        return match (strtolower($node->tagName)) {
            'strong', 'b' => '<b>'.$children.'</b>',
            'em', 'i' => '<i>'.$children.'</i>',
            'u' => '<u>'.$children.'</u>',
            's', 'strike' => '<s>'.$children.'</s>',
            'a' => self::telegramLink($node, $children),
            'code' => '<code>'.$children.'</code>',
            'pre' => '<pre>'.$children.'</pre>',
            'blockquote' => '<blockquote>'.$children.'</blockquote>'."\n",
            'br' => "\n",
            'li' => '• '.$children."\n",
            'p', 'h2', 'h3' => $children."\n",
            'ul', 'ol' => $children."\n",
            default => $children,
        };
    }

    private static function telegramLink(\DOMElement $node, string $children): string
    {
        $href = $node->getAttribute('href');
        $parts = parse_url($href);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if ($href === ''
            || preg_match('/[\x00-\x1F\x7F]/', $href) === 1
            || ! in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)
            || (in_array($scheme, ['http', 'https'], true) && filter_var($href, FILTER_VALIDATE_URL) === false)
            || (in_array($scheme, ['mailto', 'tel'], true) && trim((string) ($parts['path'] ?? '')) === '')) {
            return $children;
        }

        return '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$children.'</a>';
    }

    private static function plainNode(?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        if ($node->nodeType === XML_TEXT_NODE) {
            return (string) $node->nodeValue;
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::plainNode($child);
        }

        if ($node instanceof \DOMElement && in_array(strtolower($node->tagName), ['br', 'p', 'h2', 'h3', 'li', 'blockquote', 'pre'], true)) {
            $text .= "\n";
        }

        return $text;
    }

    private static function textNodes(?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        if ($node->nodeType === XML_TEXT_NODE) {
            return (string) $node->nodeValue;
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::textNodes($child);
        }

        return $text;
    }
}
