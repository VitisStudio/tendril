<x-filament-panels::page>
    @php($report = $this->report())

    <div class="flex flex-wrap items-center gap-4">
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="attributionModel">
                @foreach ($this->modelOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="lookback">
                @foreach ([7, 30, 60, 90, 180] as $days)
                    <option value="{{ $days }}">{{ $days }}-day lookback</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="conversion">
                <option value="">All conversions</option>
                @foreach ($this->conversionOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    <x-filament::section>
        <x-slot name="heading">Campaigns</x-slot>
        <x-slot name="description">
            Attribution: {{ $report['stamp'] }} · last {{ $this->days }} days · {{ number_format($report['total']) }} conversions
            @foreach ($report['values'] as $currency => $minor)
                · {{ $this::money($minor, $currency) }}
            @endforeach
        </x-slot>

        <table class="w-full text-sm">
            <thead>
                <tr>
                    <th class="py-1 pr-2 text-left">Source</th>
                    <th class="py-1 pr-2 text-left">Medium</th>
                    <th class="py-1 pr-2 text-left">Campaign</th>
                    <th class="py-1 pr-2 text-right">Sessions</th>
                    <th class="py-1 pr-2 text-right">Conversions</th>
                    <th class="py-1 text-right">Value</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['rows'] as $row)
                    <tr>
                        <td class="py-1 pr-2">{{ $row['source'] }}</td>
                        <td class="py-1 pr-2">{{ $row['medium'] }}</td>
                        <td class="py-1 pr-2">{{ $row['campaign'] }}</td>
                        <td class="py-1 pr-2 text-right tabular-nums">{{ number_format($row['sessions']) }}</td>
                        <td class="py-1 pr-2 text-right tabular-nums">{{ number_format($row['conversions'], 2) }}</td>
                        <td class="py-1 text-right tabular-nums">
                            @foreach ($row['values'] as $currency => $minor)
                                {{ $this::money($minor, $currency) }}@if (! $loop->last), @endif
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-1 text-gray-500" colspan="6">No acquisition data in this range.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Landing pages</x-slot>

            <table class="w-full text-sm">
                <tbody>
                    @forelse ($report['landing'] as $row)
                        <tr>
                            <td class="py-1 pr-2">{{ $row['landing_route'] }}</td>
                            <td class="py-1 text-right tabular-nums">{{ number_format($row['sessions']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-1 text-gray-500">No landing pages yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Conversion paths</x-slot>
            <x-slot name="description">
                @if ($report['paths'] === null)
                    Select a single conversion to see its touchpoint paths.
                @else
                    {{ $report['paths']['anonymous_to_known'] }} of {{ $report['paths']['total'] }} conversions started anonymously ({{ $report['stamp'] }})
                @endif
            </x-slot>

            @if ($report['paths'] !== null)
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ($report['paths']['paths'] as $path)
                            <tr>
                                <td class="py-1 pr-2">{{ implode(' → ', $path['path']) }}</td>
                                <td class="py-1 text-right tabular-nums">{{ $path['conversions'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
