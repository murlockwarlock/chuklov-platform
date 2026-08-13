<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateRenderer;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\RenderedNotification;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class ScenarioTemplateRenderer implements NotificationTemplateRenderer
{
    /** @param array<string, mixed> $context */
    public function render(NotificationTemplateVersion $template, array $context, string $locale): RenderedNotification
    {
        $variables = array_values(array_filter(array_map('strval', $template->variables)));
        $body = $this->renderString($template->body, $variables, $context);
        $subject = $template->subject === null ? null : $this->renderString($template->subject, $variables, $context);

        return new RenderedNotification($body, $subject, $locale);
    }

    /**
     * @param  list<string>  $variables
     * @param  array<string, mixed>  $context
     */
    private function renderString(string $content, array $variables, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/',
            function (array $matches) use ($variables, $context): string {
                $variable = $matches[1];

                if (! in_array($variable, ScenarioTemplateVariableCatalog::allowed(), true) || ! in_array($variable, $variables, true)) {
                    throw new InvalidArgumentException('The notification template contains an unsupported variable.');
                }

                $value = Arr::get($context, $variable);

                if (! is_scalar($value) && $value !== null) {
                    throw new InvalidArgumentException('The notification template variable value is invalid.');
                }

                return (string) ($value ?? '');
            },
            $content,
        ) ?? throw new InvalidArgumentException('The notification template could not be rendered.');
    }
}
