<?php

namespace App\Filament\Support;

use Filament\Resources\RelationManagers\RelationManager as BaseRelationManager;
use Illuminate\Database\Eloquent\Model;

abstract class LocalizedRelationManager extends BaseRelationManager
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(parent::getTitle($ownerRecord, $pageClass));
    }
}
