<?php

namespace App\Filament\Support;

use App\Support\RichText\RichTextDocument;

final class RichTextPresentation
{
    public static function text(?string $content): string
    {
        $content = trim((string) $content);

        if ($content === '') {
            return '';
        }

        try {
            return RichTextDocument::plainText($content);
        } catch (\InvalidArgumentException) {
            $plainText = strip_tags($content);
            $normalized = preg_replace('/\s+/u', ' ', $plainText);

            return trim(is_string($normalized) ? $normalized : $plainText);
        }
    }

    public static function html(?string $content): string
    {
        $content = trim((string) $content);

        if ($content === '') {
            return '';
        }

        try {
            return RichTextDocument::canonicalHtml($content);
        } catch (\InvalidArgumentException) {
            return htmlspecialchars(self::text($content), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}
