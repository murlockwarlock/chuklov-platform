<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Services\Application\UpdateService;
use App\Modules\Services\Domain\Models\Service;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected static ?string $title = 'Редактировать услугу';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['price'] = null;

        if (($data['price_minor'] ?? null) !== null && is_string($data['price_currency'] ?? null)) {
            try {
                $data['price'] = Money::ofMinor($data['price_minor'], $data['price_currency'])->toDecimalString();
            } catch (InvalidArgumentException) {
            }
        }

        unset($data['price_minor'], $data['image_path']);
        $data['service_image'] = null;
        $data['remove_image'] = false;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Service, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $acknowledgeImpact = (bool) ($data['acknowledge_impact'] ?? false);
        $impactDigest = isset($data['impact_digest']) ? (string) $data['impact_digest'] : null;
        unset($data['acknowledge_impact']);
        unset($data['impact_digest']);

        try {
            return app(UpdateService::class)->handle(
                actor: $actor,
                service: $record,
                name: $data,
                acknowledgeImpact: $acknowledgeImpact,
                acknowledgedImpactDigest: $impactDigest,
            );
        } catch (ValidationException $exception) {
            $this->form->fill(ScheduleImpactPreview::mergeValidationPreview([...$data, 'acknowledge_impact' => $acknowledgeImpact, 'impact_digest' => $impactDigest], $exception));

            throw $exception;
        }
    }
}
