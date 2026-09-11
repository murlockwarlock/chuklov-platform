<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\Actions\BookingLifecycleActions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Support\FinancePaymentActions;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Url;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected static ?string $title = 'Запись на приём';

    #[Url(as: 'return_to_journal', history: true)]
    public bool $returnToJournal = false;

    #[Url(as: 'specialist_id', history: true, nullable: true)]
    public ?int $journalSpecialistId = null;

    #[Url(as: 'week', history: true, nullable: true)]
    public ?string $journalWeek = null;

    #[Url(as: 'view', history: true, nullable: true)]
    public ?string $journalView = null;

    public function mount(int|string $record): void
    {
        $this->returnToJournal = request()->boolean('return_to_journal');
        $this->journalSpecialistId = $this->validSpecialistId(request()->query('specialist_id'));
        $this->journalWeek = $this->validWeek(request()->query('week'));
        $this->journalView = request()->query('view') === 'list' ? 'list' : 'week';

        parent::mount($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back_to_journal')
                ->label('Вернуться в журнал')
                ->icon('heroicon-o-arrow-left')
                ->url(fn (): string => $this->journalReturnUrl())
                ->visible(fn (): bool => $this->returnToJournal),
            ActionGroup::make([
                ...BookingLifecycleActions::all(),
                FinancePaymentActions::openForBooking(),
                FinancePaymentActions::forBooking(),
            ])
                ->label('Действия')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button()
                ->dropdownAutoPlacement(),
        ];
    }

    private function journalReturnUrl(): string
    {
        $parameters = [];
        if ($this->journalWeek !== null) {
            $parameters['week'] = $this->journalWeek;
        }
        $parameters['view'] = $this->journalView === 'list' ? 'list' : 'week';
        if ($this->journalSpecialistId !== null) {
            $parameters['specialist_id'] = $this->journalSpecialistId;
        }

        return ListBookings::getUrl().($parameters === [] ? '' : '?'.http_build_query($parameters));
    }

    private function validSpecialistId(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        $specialistId = (int) $value;

        return Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->whereKey($specialistId)
            ->exists()
            ? $specialistId
            : null;
    }

    private function validWeek(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? $value
            : null;
    }
}
