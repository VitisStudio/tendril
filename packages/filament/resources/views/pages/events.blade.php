<x-filament-panels::page>
    @php($inspection = $this->inspection())

    @if ($inspection !== null)
        <x-filament::section>
            <x-slot name="heading">{{ $inspection['name'] }}</x-slot>
            <x-slot name="description">{{ number_format($inspection['count']) }} events in the last {{ $this->days }} days · schema inferred from the latest 500</x-slot>

            <div class="grid gap-6 lg:grid-cols-3">
                <div>
                    <h3 class="mb-2 text-sm font-medium">Versions</h3>
                    @foreach ($inspection['versions'] as $version => $count)
                        <x-filament::badge class="inline-flex">v{{ $version }}: {{ number_format($count) }}</x-filament::badge>
                    @endforeach
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-medium">Sources</h3>
                    @foreach ($inspection['sources'] as $source => $count)
                        <x-filament::badge color="gray" class="inline-flex">{{ $source }}: {{ number_format($count) }}</x-filament::badge>
                    @endforeach
                </div>
                <div class="lg:col-span-3">
                    <h3 class="mb-2 text-sm font-medium">Properties</h3>
                    <table class="w-full text-sm">
                        <thead>
                            <tr>
                                <th class="py-1 pr-2 text-left">Property</th>
                                <th class="py-1 pr-2 text-left">Types</th>
                                <th class="py-1 pr-2 text-right">Present</th>
                                <th class="py-1 text-right">Distinct (sample)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($inspection['schema'] as $key => $info)
                                <tr>
                                    <td class="py-1 pr-2 font-mono">{{ $key }}</td>
                                    <td class="py-1 pr-2">{{ implode(' | ', $info['types']) }}</td>
                                    <td class="py-1 pr-2 text-right tabular-nums">{{ $info['present'] }}</td>
                                    <td class="py-1 text-right tabular-nums">{{ $info['distinct'] }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-1 text-gray-500" colspan="4">No properties recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
