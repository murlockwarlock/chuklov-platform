<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\MedicalProfileData;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Filament\Infolists\Components\TextEntry;

final class ClinicalProfileInfolist
{
    /** @return list<TextEntry> */
    public static function profile(): array
    {
        $fields = [
            'complaints' => 'Жалобы', 'goals' => 'Задачи по здоровью',
            'chronicConditions' => 'Хронические заболевания', 'operations' => 'Операции',
            'injuries' => 'Травмы', 'medicines' => 'Лекарственные препараты', 'supplements' => 'Нутрицевтики и БАДы',
        ];

        return [
            ...array_map(static fn (string $field): TextEntry => TextEntry::make('profile_'.$field)
                ->label(__($fields[$field]))->state(fn (MedicalSession $record): ?string => self::resolve($record)?->{$field})
                ->placeholder(__('Не указано'))->wrap(), array_keys($fields)),
            TextEntry::make('profile_symptoms')->label(__('Главные жалобы (симптомы)'))
                ->state(function (MedicalSession $record): ?string {
                    $symptoms = MedicalProfileForm::answers(self::resolve($record))['symptoms'] ?? null;

                    return is_array($symptoms) ? implode(', ', array_map(
                        static fn (string $key): string => __(MedicalProfileForm::SYMPTOMS[$key]),
                        array_values(array_filter($symptoms, static fn (mixed $key): bool => is_string($key) && isset(MedicalProfileForm::SYMPTOMS[$key]))),
                    )) : null;
                })->placeholder(__('Не указаны'))->wrap(),
            TextEntry::make('profile_pain_vas')->label(__('Шкала боли (ВАШ)'))
                ->state(function (MedicalSession $record): ?string {
                    $value = MedicalProfileForm::answers(self::resolve($record))['pain_vas'] ?? null;

                    return is_int($value) && $value >= 0 && $value <= 10 ? (string) __(':value / 10', ['value' => $value]) : null;
                })->placeholder(__('Не указана')),
            ...self::answers(),
        ];
    }

    /** @return list<TextEntry> */
    public static function answers(): array
    {
        $labels = [
            'no_chronic_conditions' => 'Хронических заболеваний нет',
            'had_operations' => 'Были операции', 'operation_year' => 'Год операции',
            'had_injuries' => 'Были травмы', 'injury_year' => 'Год травмы',
        ];

        return array_map(static fn (string $field): TextEntry => TextEntry::make('profile_'.$field)
            ->label(__($labels[$field]))
            ->state(function (Client|MedicalSession $record) use ($field): ?string {
                $value = MedicalProfileForm::answers(self::resolve($record))[$field] ?? null;

                return match (true) {
                    is_bool($value) => (string) ($value ? __('Да') : __('Нет')),
                    is_int($value) => (string) $value,
                    default => null,
                };
            })->placeholder(__('Не указано')), array_keys($labels));
    }

    private static function resolve(Client|MedicalSession $record): ?MedicalProfileData
    {
        $client = $record instanceof Client ? $record : $record->client;
        $actor = auth()->user();

        return $actor instanceof User
            ? app(GetMedicalProfile::class)->handle($actor, $client)
            : null;
    }
}
