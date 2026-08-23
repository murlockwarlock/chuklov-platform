<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

final class TelegramCompanionFormatter
{
    public const int SAFE_MESSAGE_LENGTH = 4090;

    /** @return list<string> */
    public function chunks(string $semanticText): array
    {
        $html = $this->markdownToHtml($semanticText);

        return $this->splitHtmlText($html);
    }

    public function markdownToHtml(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/<strong>(.*?)<\/strong>/is', '**$1**', $text) ?? $text;
        $text = preg_replace('/<b>(.*?)<\/b>/is', '**$1**', $text) ?? $text;
        $text = preg_replace('/<em>(.*?)<\/em>/is', '*$1*', $text) ?? $text;
        $text = preg_replace('/<i>(.*?)<\/i>/is', '*$1*', $text) ?? $text;
        $text = preg_replace('/<pre><code>(.*?)<\/code><\/pre>/is', '```$1```', $text) ?? $text;
        $text = preg_replace('/<code>(.*?)<\/code>/is', '`$1`', $text) ?? $text;
        $text = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $placeholders = [];
        $placeholder = static function (string $prefix, string $value) use (&$placeholders): string {
            $key = "\x01{$prefix}".count($placeholders)."\x01";
            $placeholders[$key] = $value;

            return $key;
        };

        $text = preg_replace_callback('/```(.*?)```/s', fn (array $match): string => $placeholder('CODE', $match[1]), $text) ?? $text;
        $text = preg_replace_callback('/`(.*?)`/s', fn (array $match): string => $placeholder('INLINE', $match[1]), $text) ?? $text;
        $text = preg_replace('/^\s*[-*_]{3,}\s*$/m', '———', $text) ?? $text;
        $text = preg_replace('/^\s*\*\s*$/m', '———', $text) ?? $text;
        $text = $this->formatBlockquotes($text);
        $text = preg_replace('/^\s*[-*]\s+/m', '• ', $text) ?? $text;
        $text = preg_replace('/^\s*#{1,6}\s+(.*)$/m', "\n\n<b>$1</b>\n", $text) ?? $text;
        $text = preg_replace('/\*\*\*(?=[^<>]*\*\*\*)((?:(?!\n\n)[^<>])+?)\*\*\*/s', '<b><i>$1</i></b>', $text) ?? $text;
        $text = preg_replace('/___((?:(?!\n\n)[^<>])+?)___/s', '<b><i>$1</i></b>', $text) ?? $text;
        $text = preg_replace('/\*\*(?=[^<>]*\*\*)((?:(?!\n\n)[^<>])+?)\*\*/s', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/__(?=[^<>]*__)((?:(?!\n\n)[^<>])+?)__/s', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/(?<!\w)\*(?!\s)([^<>\n]+?)(?<!\s)\*(?!\w)/u', '<i>$1</i>', $text) ?? $text;
        $text = preg_replace('/(?<!\w)_(?!\s)([^<>\n]+?)(?<!\s)_(?!\w)/u', '<i>$1</i>', $text) ?? $text;
        $text = preg_replace('/~~(?=[^<>\n]+~~)([^<>\n]+?)~~/u', '<s>$1</s>', $text) ?? $text;
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/u', function (array $match): string {
            $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (filter_var($url, FILTER_VALIDATE_URL) === false || ! preg_match('/^https?:\/\//i', $url)) {
                return $match[1];
            }

            return '<a href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$match[1].'</a>';
        }, $text) ?? $text;
        $text = str_replace(['**', '__', '~~'], '', $text);
        $text = preg_replace('/(?<![\w*])\*(?![\w*])/', '', $text) ?? $text;

        foreach ($placeholders as $key => $value) {
            $replacement = str_starts_with($key, "\x01CODE")
                ? '<pre><code>'.$value.'</code></pre>'
                : (str_starts_with($key, "\x01INLINE") ? '<code>'.$value.'</code>' : $value);
            $text = str_replace($key, $replacement, $text);
        }

        return trim($this->repairHtml($text));
    }

    public function repairHtml(string $html): string
    {
        $allowed = ['b', 'i', 'u', 's', 'code', 'pre', 'a', 'blockquote'];
        $result = [];
        $stack = [];
        $offset = 0;
        preg_match_all('/<\/?([a-z][a-z0-9]*)(?:\s[^>]*)?>/i', $html, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => [$raw, $position]) {
            $result[] = substr($html, $offset, $position - $offset);
            $offset = $position + strlen($raw);
            $tagName = strtolower((string) $matches[1][$index][0]);
            if (! in_array($tagName, $allowed, true)) {
                continue;
            }

            $closing = str_starts_with($raw, '</');
            if ($closing) {
                $stackIndex = null;
                for ($stackPosition = count($stack) - 1; $stackPosition >= 0; $stackPosition--) {
                    if ($stack[$stackPosition]['name'] === $tagName) {
                        $stackIndex = $stackPosition;
                        break;
                    }
                }
                if ($stackIndex === null) {
                    continue;
                }

                $reopen = [];
                while (count($stack) - 1 > $stackIndex) {
                    $entry = array_pop($stack);
                    if ($entry === null) {
                        break;
                    }
                    $result[] = '</'.$entry['name'].'>';
                    $reopen[] = $entry['raw'];
                }
                array_pop($stack);
                $result[] = '</'.$tagName.'>';
                foreach (array_reverse($reopen) as $entry) {
                    $result[] = $entry;
                    preg_match('/<([a-z][a-z0-9]*)/i', $entry, $reopenedTag);
                    $stack[] = ['name' => strtolower((string) ($reopenedTag[1] ?? $tagName)), 'raw' => $entry];
                }

                continue;
            }

            if (array_filter($stack, static fn (array $entry): bool => $entry['name'] === $tagName) !== []) {
                continue;
            }

            $safeTag = '<'.$tagName;
            if ($tagName === 'a') {
                preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $raw, $href);
                $url = $href[1] ?? '';
                if (filter_var($url, FILTER_VALIDATE_URL) === false || ! preg_match('/^https?:\/\//i', $url)) {
                    continue;
                }
                $safeTag .= ' href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            }
            $safeTag .= '>';
            $result[] = $safeTag;
            $stack[] = ['name' => $tagName, 'raw' => $safeTag];
        }

        $result[] = substr($html, $offset);
        while ($stack !== []) {
            $entry = array_pop($stack);
            $result[] = '</'.$entry['name'].'>';
        }

        return implode('', $result);
    }

    /** @return list<string> */
    public function splitHtmlText(string $html, int $maxLength = self::SAFE_MESSAGE_LENGTH): array
    {
        $html = $this->repairHtml($html);
        if ($html === '' || mb_strlen($html) <= $maxLength) {
            return $html === '' ? [] : [$html];
        }

        $parts = preg_split('/(<\/?[a-z][a-z0-9]*(?:\s[^>]*)?>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = [];
        $current = '';
        $open = [];

        foreach ($parts as $part) {
            if (str_starts_with($part, '<')) {
                preg_match('/<\/?([a-z][a-z0-9]*)/i', $part, $tagMatch);
                $tag = strtolower($tagMatch[1] ?? '');
                if ($tag === '') {
                    continue;
                }
                $isClosing = str_starts_with($part, '</');
                if ($isClosing) {
                    $index = array_key_last(array_filter($open, static fn (array $entry): bool => $entry['name'] === $tag));
                    if ($index !== null) {
                        array_splice($open, $index, 1);
                    }
                }
                $suffix = $this->closingTags($open);
                if (mb_strlen($current.$part.$suffix) > $maxLength && trim(strip_tags($current)) !== '') {
                    $chunks[] = $this->repairHtml($current.$this->closingTags($open));
                    $current = $this->openingTags($open);
                }
                $current .= $part;
                if (! $isClosing && ! in_array($tag, ['br', 'hr', 'img'], true)) {
                    $open[] = ['name' => $tag, 'raw' => $part];
                }

                continue;
            }

            $remaining = $part;
            while ($remaining !== '') {
                $available = $maxLength - mb_strlen($current) - mb_strlen($this->closingTags($open));
                if ($available < 1) {
                    if (trim(strip_tags($current)) !== '') {
                        $chunks[] = $this->repairHtml($current.$this->closingTags($open));
                    }
                    $current = $this->openingTags($open);
                    $available = $maxLength - mb_strlen($current) - mb_strlen($this->closingTags($open));
                }

                if (mb_strlen($remaining) <= $available) {
                    $current .= $remaining;
                    break;
                }

                $cut = $this->preferredBreak($remaining, $available);
                $piece = mb_substr($remaining, 0, $cut);
                $current .= $piece;
                $chunks[] = $this->repairHtml($current.$this->closingTags($open));
                $current = $this->openingTags($open);
                $remaining = ltrim(mb_substr($remaining, $cut));
            }
        }

        if (trim(strip_tags($current)) !== '') {
            $chunks[] = $this->repairHtml($current.$this->closingTags($open));
        }

        return array_values(array_filter($chunks, static fn (string $chunk): bool => trim(strip_tags($chunk)) !== ''));
    }

    public function plainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($this->repairHtml($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function formatBlockquotes(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $result = [];
        $quote = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*&gt;\s?(.*)$/u', $line, $match) === 1) {
                $quote[] = $match[1];

                continue;
            }
            if ($quote !== []) {
                $result[] = '<blockquote>'.implode("\n", $quote).'</blockquote>';
                $quote = [];
            }
            $result[] = $line;
        }
        if ($quote !== []) {
            $result[] = '<blockquote>'.implode("\n", $quote).'</blockquote>';
        }

        return implode("\n", $result);
    }

    /** @param list<array{name: string, raw: string}> $open */
    private function closingTags(array $open): string
    {
        return implode('', array_map(static fn (array $entry): string => '</'.$entry['name'].'>', array_reverse($open)));
    }

    /** @param list<array{name: string, raw: string}> $open */
    private function openingTags(array $open): string
    {
        return implode('', array_map(static fn (array $entry): string => $entry['raw'], $open));
    }

    private function preferredBreak(string $text, int $available): int
    {
        $prefix = mb_substr($text, 0, $available);
        foreach (["\n\n", '. ', '! ', '? ', '。', '！', '？', "\n", ' '] as $separator) {
            $position = mb_strrpos($prefix, $separator);
            if ($position !== false && $position > (int) floor($available / 3)) {
                return max(1, $position + mb_strlen(rtrim($separator)));
            }
        }

        $ampersand = mb_strrpos($prefix, '&');
        $semicolon = $ampersand === false ? false : mb_strpos($prefix, ';', $ampersand);
        if ($ampersand !== false && $semicolon === false) {
            return max(1, $ampersand);
        }

        return max(1, $available);
    }
}
