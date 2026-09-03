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
            ['bold', 'italic', 'underline', 'strike', 'link', 'emoji'],
            ['blockquote', 'code', 'codeBlock'],
        ];

        if ($mergeTags !== null) {
            $toolbarButtons[] = ['mergeTags'];
        }

        $toolbarButtons[] = ['clearFormatting', 'undo', 'redo'];

        $editor = RichEditor::make($name)
            ->toolbarButtons($toolbarButtons)
            ->disableToolbarButtons(['attachFiles'])
            ->linkProtocols(['http', 'https', 'mailto', 'tel'])
            ->extraInputAttributes([
                'x-on:keydown' => 'window.ChuklovRichTextEditor?.handleKeydown($event, $getEditor())',
            ])
            ->maxLength(100000);

        $tools = [
            RichEditorTool::make('emoji')
                ->label('😊 Смайлик')
                ->hiddenLabel(false)
                ->jsHandler('window.ChuklovRichTextEditor?.toggleEmojiPicker($event, $getEditor())'),
        ];

        if ($mergeTags !== null) {
            $tools[] = RichEditorTool::make('mergeTags')
                ->label('Добавить данные')
                ->hiddenLabel(false)
                ->icon(Heroicon::OutlinedTag)
                ->jsHandler('togglePanel(\'mergeTags\')')
                ->activeJsExpression('isPanelActive(\'mergeTags\')');

            $editor
                ->mergeTags($mergeTags)
                ->noMergeTagSearchResultsMessage('Доступные данные не найдены.')
                ->dehydrateStateUsing(fn (mixed $state): mixed => is_string($state)
                    ? RichTextDocument::normalizeMergeTags($state)
                    : $state);
        }

        $editor->tools($tools);

        return $editor;
    }
}
