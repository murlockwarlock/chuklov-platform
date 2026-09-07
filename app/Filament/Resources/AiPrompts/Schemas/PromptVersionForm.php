<?php

namespace App\Filament\Resources\AiPrompts\Schemas;

use App\Filament\Support\AiPromptTextSections;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\ValueObjects\AiParameterConfig;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class PromptVersionForm
{
    public static function components(?AiPromptVersion $source): array
    {
        $parameters = AiParameterConfig::fromArray((array) ($source?->parameter_config ?? []));

        return [
            Textarea::make('system_prompt')
                ->label('Полный исходный prompt')
                ->helperText('Полный текст новой версии. Активная версия не изменяется до явной активации черновика.')
                ->default($source?->system_prompt)
                ->required()
                ->live(debounce: 500)
                ->rows(18)
                ->columnSpanFull(),
            Section::make('Состав инструкции')
                ->schema([
                    Placeholder::make('source_text_preview')
                        ->label('SOURCE PRODUCT PROMPT')
                        ->content(fn (Get $get): string => AiPromptTextSections::source((string) $get('system_prompt'))),
                    Placeholder::make('guardrails_preview')
                        ->label('PLATFORM SAFETY GUARDRAILS')
                        ->content(fn (Get $get): string => AiPromptTextSections::guardrails((string) $get('system_prompt'))),
                    Placeholder::make('runtime_contract_preview')
                        ->label('RUNTIME CONTRACT')
                        ->content(fn (Get $get): string => AiPromptTextSections::runtimeContract((string) $get('system_prompt'))),
                ])
                ->columns(1)
                ->columnSpanFull(),
            Textarea::make('user_prompt_template')
                ->label('Шаблон запроса')
                ->helperText('Используйте переменные текущей версии, например {{query}}.')
                ->default($source?->user_prompt_template)
                ->required()
                ->rows(4)
                ->columnSpanFull(),
            Section::make('Настройки ответа')
                ->collapsed()
                ->schema([
                    TextInput::make('temperature')->label('Креативность')->numeric()->minValue(0)->maxValue(2)->default($parameters->temperature)->required(),
                    TextInput::make('max_tokens')->label('Максимальная длина ответа')->numeric()->minValue(1)->maxValue(8192)->default($parameters->maxTokens)->required(),
                    TextInput::make('top_p')->label('Top P')->numeric()->minValue(0)->maxValue(1)->default($parameters->topP)->required(),
                    TextInput::make('frequency_penalty')->label('Штраф за повторение')->numeric()->minValue(-2)->maxValue(2)->default($parameters->frequencyPenalty)->required(),
                    TextInput::make('presence_penalty')->label('Штраф за однообразие')->numeric()->minValue(-2)->maxValue(2)->default($parameters->presencePenalty)->required(),
                    TextInput::make('timeout_seconds')->label('Время ожидания, секунд')->numeric()->minValue(1)->maxValue(120)->default($parameters->timeoutSeconds)->required(),
                    TextInput::make('change_notes')->label('Что изменилось')->default($source?->status->value === 'draft' ? $source->change_notes : null)->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ];
    }

    public static function data(AiPromptVersion $source): array
    {
        $parameters = AiParameterConfig::fromArray((array) $source->parameter_config);

        return [
            'system_prompt' => $source->system_prompt,
            'user_prompt_template' => $source->user_prompt_template,
            'temperature' => $parameters->temperature,
            'max_tokens' => $parameters->maxTokens,
            'top_p' => $parameters->topP,
            'frequency_penalty' => $parameters->frequencyPenalty,
            'presence_penalty' => $parameters->presencePenalty,
            'timeout_seconds' => $parameters->timeoutSeconds,
            'change_notes' => $source->status->value === 'draft' ? $source->change_notes : null,
        ];
    }
}
