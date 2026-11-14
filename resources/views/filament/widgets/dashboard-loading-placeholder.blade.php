@props([
    'columnSpan' => [],
    'columnStart' => [],
])

@php
    use Filament\Support\View\ComponentAttributeBag;
@endphp

<div
    role="status"
    aria-busy="true"
    {{
        (new ComponentAttributeBag)
            ->gridColumn($columnSpan, $columnStart)
            ->class(['fi-section', 'crm-dashboard-loading'])
    }}
>
    <span class="fi-sr-only">{{ __('Показатели загружаются') }}</span>
    <div aria-hidden="true" class="crm-dashboard-loading__content">
        <span class="crm-dashboard-loading__bar crm-dashboard-loading__bar--title"></span>
        <span class="crm-dashboard-loading__bar crm-dashboard-loading__bar--value"></span>
        <span class="crm-dashboard-loading__label">{{ __('Показатели загружаются') }}</span>
    </div>
</div>
