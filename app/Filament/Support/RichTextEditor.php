<?php

namespace App\Filament\Support;

use Filament\Forms\Components\RichEditor;

final class RichTextEditor
{
    public static function make(string $name): RichEditor
    {
        return RichEditor::make($name)
            ->toolbarButtons([
                ['bold', 'italic', 'underline', 'strike', 'link'],
                ['blockquote', 'code', 'codeBlock'],
                ['undo', 'redo'],
            ])
            ->disableToolbarButtons(['attachFiles'])
            ->linkProtocols(['http', 'https', 'mailto', 'tel'])
            ->maxLength(100000);
    }
}
