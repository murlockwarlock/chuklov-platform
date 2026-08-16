<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Surveys\Application\CreateSurveyDefinition;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

#[Signature('surveys:import {file} {--organization=} {--user=}')]
#[Description('Import a validated survey definition JSON file as a draft version')]
class ImportSurveyDefinition extends Command
{
    public function handle(CreateSurveyDefinition $create): int
    {
        $organization = Organization::query()->findOrFail((int) $this->option('organization'));
        $user = User::query()->findOrFail((int) $this->option('user'));
        app(OrganizationContext::class)->set($organization);
        $path = (string) $this->argument('file');
        if (! File::isFile($path)) {
            $this->error('Файл определения не найден.');

            return self::FAILURE;
        }

        try {
            $data = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('Файл содержит некорректный JSON.');

            return self::FAILURE;
        }
        if (! is_array($data)) {
            $this->error('Корень файла должен быть объектом.');

            return self::FAILURE;
        }

        $definition = $create->handle($user, $data);
        $this->info('Создан черновик опросника: '.$definition->title);

        return self::SUCCESS;
    }
}
