<?php

namespace App\Filament\Support;

use App\Support\RichText\RichTextDocument;
use Closure;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Support\Icons\Heroicon;

final class RichTextEditor
{
    /** @param array<string, string>|Closure|null $mergeTags */
    public static function make(string $name, array|Closure|null $mergeTags = null): RichEditor
    {
        $toolbarButtons = [
            ['bold', 'italic', 'underline', 'strike', 'link'],
            ['blockquote', 'code', 'codeBlock'],
        ];

        if ($mergeTags !== null) {
            $toolbarButtons[] = ['mergeTags'];
        }

        $toolbarButtons[] = ['undo', 'redo'];

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
                        ->hiddenLabel(false)
                        ->icon(Heroicon::OutlinedTag)
                        ->jsHandler('togglePanel(\'mergeTags\')')
                        ->activeJsExpression('isPanelActive(\'mergeTags\')'),
                ])
                ->dehydrateStateUsing(fn (mixed $state): mixed => is_string($state)
                    ? RichTextDocument::normalizeMergeTags($state)
                    : $state);
        }

        return $editor;
    }
}
