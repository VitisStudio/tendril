<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Retention</x-slot>
        <x-slot name="description">
            Cohorts by first activity{{ $this->retentionCohort ? ', restricted to cohort "'.$this->retentionCohort.'"' : '' }} · distinct actors from the activity table
        </x-slot>

        <div class="mb-4 flex flex-wrap items-center gap-4">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="retentionPeriod">
                    <option value="day">Daily</option>
                    <option value="week">Weekly</option>
                    <option value="month">Monthly</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>

            @if ($this->retentionCohort)
                <x-filament::button color="gray" size="sm" wire:click="$set('retentionCohort', null)">
                    Show all actors
                </x-filament::button>
            @endif
        </div>

        @include('tendril-filament::partials.retention-matrix', [
            'matrix' => $this->matrix(),
            'periodLabel' => ['day' => 'Day', 'week' => 'Week', 'month' => 'Month'][$this->retentionPeriod] ?? 'Week',
        ])
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
