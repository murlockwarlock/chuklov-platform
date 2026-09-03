<?php

namespace App\Filament\Support;

use App\Support\RichText\RichTextDocument;
use Closure;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichEditorTool;

final class RichTextEditor
{
    /** @param array<string, string>|Closure|null $mergeTags */
    public static function make(string $name, array|Closure|null $mergeTags = null): RichEditor
    {
        $toolbarButtons = [
            ['bold', 'italic', 'underline', 'strike', 'link'],
            ['blockquote', 'code', 'codeBlock', ...($mergeTags !== null ? ['mergeTags'] : [])],
            ['undo', 'redo'],
        ];

        $editor = RichEditor::make($name)
            ->toolbarButtons($toolbarButtons)
            ->disableToolbarButtons(['attachFiles'])
            ->linkProtocols(['http', 'https', 'mailto', 'tel'])
            ->maxLength(100000);

        if ($mergeTags !== null) {
            $editor
                ->mergeTags($mergeTags)
                ->noMergeTagSearchResultsMessage('Доступные данные не найдены.')
                ->tools([
                    RichEditorTool::make('mergeTags')
                        ->label('Добавить данные')
                        ->jsHandler('togglePanel(\'mergeTags\')')
                        ->activeJsExpression('isPanelActive(\'mergeTags\')')
                        ->icon('fi-o-merge-tag'),
                ])
                ->dehydrateStateUsing(fn (mixed $state): mixed => is_string($state)
                    ? RichTextDocument::normalizeMergeTags($state)
                    : $state);
        }

        return $editor;
    }
}
