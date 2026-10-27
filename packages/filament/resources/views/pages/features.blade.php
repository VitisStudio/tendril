<x-filament-panels::page>
    @if ($this->feature !== null)
        <x-filament::section>
            <x-slot name="heading">Retention: {{ $this->feature }}</x-slot>
            <x-slot name="description">Weekly cohorts by first activity; returning = used this feature (activity table)</x-slot>

            @include('tendril-filament::partials.retention-matrix', ['matrix' => $this->retention(), 'periodLabel' => 'Week'])
        </x-filament::section>
    @endif

    {{ $this->table }}

    @php($code = $this->codeFeatures())

    @if ($code !== [])
        <x-filament::section>
            <x-slot name="heading">Defined in code</x-slot>
            <x-slot name="description">Version-controlled via Tendril::feature(); read-only here</x-slot>

            <table class="w-full text-sm">
                <tbody>
                    @foreach ($code as $definition)
                        @php($m = $this->metrics($definition->key))
                        <tr>
                            <td class="py-1 pr-2 font-medium">
                                <a href="{{ $this::getUrl(['feature' => $definition->key]) }}">{{ $definition->key }}</a>
                            </td>
                            <td class="py-1 pr-2 text-gray-500">{{ \VitisStudio\Tendril\Filament\Pages\Features::summary($definition) }}</td>
                            <td class="py-1 pr-2 text-right tabular-nums">{{ $m['uniques'] }} actors</td>
                            <td class="py-1 text-right tabular-nums">{{ number_format($m['adoption'] * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
