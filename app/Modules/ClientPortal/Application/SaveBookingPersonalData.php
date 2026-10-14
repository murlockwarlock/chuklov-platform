<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Identity\Application\ProfileReferenceData;
use App\Modules\Identity\Application\UpdateClientProfileFromPortal;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class SaveBookingPersonalData
{
    public const FIELDS = ['first_name', 'last_name', 'middle_name', 'gender', 'birth_date', 'country', 'city', 'phone', 'email'];

    public function __construct(private UpdateClientProfileFromPortal $profiles, private ProfileReferenceData $reference) {}

    /** @param array<string, mixed>|null $data */
    public function handle(Client $client, ?array $data): void
    {
        $current = $client->only(self::FIELDS);
        $current['birth_date'] = $client->birth_date?->toDateString();
        $data = array_intersect_key($data ?? [], array_flip(self::FIELDS));
        $personal = [...$current, ...$data];
        $validated = Validator::make(['personal_data' => $personal], [
            'personal_data.first_name' => ['required', 'string', 'max:100'],
            'personal_data.last_name' => ['required', 'string', 'max:100'],
            'personal_data.middle_name' => ['nullable', 'string', 'max:100'],
            'personal_data.gender' => ['required', 'in:MALE,FEMALE'],
            'personal_data.birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'personal_data.country' => ['required', 'string', 'max:100'],
            'personal_data.city' => ['required', 'string', 'max:100'],
            'personal_data.phone' => ['required', 'string', 'max:32'],
            'personal_data.email' => ['nullable', 'email', 'max:320'],
        ], [
            'required' => __('Заполните поле «:attribute».'),
            'string' => __('Проверьте поле «:attribute».'),
            'in' => __('Проверьте поле «:attribute».'),
            'date_format' => __('Проверьте поле «:attribute».'),
            'before_or_equal' => __('Проверьте поле «:attribute».'),
            'email' => __('Проверьте поле «:attribute».'),
            'max' => __('Проверьте поле «:attribute».'),
        ], [
            'personal_data.first_name' => __('Имя'), 'personal_data.last_name' => __('Фамилия'),
            'personal_data.middle_name' => __('Отчество'), 'personal_data.gender' => __('Пол'),
            'personal_data.birth_date' => __('Дата рождения'), 'personal_data.country' => __('Страна'),
            'personal_data.city' => __('Город'), 'personal_data.phone' => __('Номер телефона'), 'personal_data.email' => __('E-mail'),
        ])->validate()['personal_data'];
        try {
            $validated['phone'] = $this->reference->phone($validated['phone'], $validated['country']);
            $this->profiles->handle($client, $validated, array_keys($validated));
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors['personal_data.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
