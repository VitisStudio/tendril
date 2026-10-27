<x-filament-panels::page>
    @php($results = $this->results())

    @if ($results !== null)
        @php($result = $results['result'])
        <x-filament::section>
            <x-slot name="heading">{{ $results['definition']->name }}</x-slot>
            <x-slot name="description">
                Last {{ $this->days }} days · window {{ round($result['window_seconds'] / 3600, 1) }} h ·
                completion {{ number_format($result['completion_rate'] * 100, 1) }}% ·
                median time to convert {{ $result['median_seconds'] !== null ? gmdate('H:i:s', (int) $result['median_seconds']) : '—' }}
            </x-slot>

            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="py-1 pr-2 text-left">Step</th>
                        <th class="py-1 pr-2 text-right">Reached</th>
                        <th class="py-1 pr-2 text-right">From previous</th>
                        <th class="py-1 text-right">From start</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result['steps'] as $i => $step)
                        <tr>
                            <td class="py-1 pr-2">{{ $i + 1 }}. {{ $step['label'] }} <span class="text-gray-500">({{ $step['event'] }})</span></td>
                            <td class="py-1 pr-2 text-right tabular-nums">{{ number_format($step['count']) }}</td>
                            <td class="py-1 pr-2 text-right tabular-nums">{{ number_format($step['from_previous'] * 100, 1) }}%</td>
                            <td class="py-1 text-right tabular-nums">{{ number_format($step['from_start'] * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
