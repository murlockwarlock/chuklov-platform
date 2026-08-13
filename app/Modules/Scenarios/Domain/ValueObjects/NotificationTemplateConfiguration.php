<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use InvalidArgumentException;

final readonly class NotificationTemplateConfiguration
{
    /** @param list<string> $variables */
    public function __construct(
        public string $templateKey,
        public string $name,
        public string $locale,
        public ScenarioRulePurpose $purpose,
        public bool $isActive,
        public ?string $subject,
        public string $body,
        public array $variables,
    ) {}

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        $templateKey = trim((string) ($data['template_key'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $locale = strtolower(trim((string) ($data['locale'] ?? '')));
        $purpose = ScenarioRulePurpose::tryFrom((string) ($data['purpose'] ?? ''));
        $subject = isset($data['subject']) ? trim((string) $data['subject']) : null;
        $body = trim((string) ($data['body'] ?? ''));
        $variables = is_array($data['variables'] ?? null) ? array_values($data['variables']) : [];

        if ($templateKey === '' || mb_strlen($templateKey) > 120 || preg_match('/^[a-z0-9._-]+$/', $templateKey) !== 1
            || $name === '' || mb_strlen($name) > 160
            || preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) !== 1
            || $purpose === null
            || $body === '' || mb_strlen($body) > 100000
            || ($subject !== null && mb_strlen($subject) > 255)
            || count($variables) > 32) {
            throw new InvalidArgumentException('The notification template configuration is invalid.');
        }

        $normalizedVariables = [];

        foreach ($variables as $variable) {
            $variable = trim((string) $variable);

            if ($variable === '' || mb_strlen($variable) > 64 || preg_match('/^[a-z][a-z0-9_.]*$/', $variable) !== 1 || in_array($variable, $normalizedVariables, true)) {
                throw new InvalidArgumentException('The notification template variable list is invalid.');
            }

            $normalizedVariables[] = $variable;
        }

        $normalizedVariables = array_values(array_unique($normalizedVariables));
        $usedVariables = ScenarioTemplateVariableCatalog::used($body, $subject ?? '');

        if (array_diff($usedVariables, $normalizedVariables) !== []) {
            throw new InvalidArgumentException('The notification template must declare every used variable.');
        }

        return new self(
            templateKey: $templateKey,
            name: $name,
            locale: $locale,
            purpose: $purpose,
            isActive: self::booleanValue($data['is_active'] ?? true),
            subject: $subject === '' ? null : $subject,
            body: $body,
            variables: $normalizedVariables,
        );
    }

    private static function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new InvalidArgumentException('The notification template active state is invalid.');
        }

        return $parsed;
    }
}
