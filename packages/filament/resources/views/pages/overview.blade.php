<x-filament-panels::page>
    @php
        $data = $this->overviewData();
        $newVersusReturning = $data['newVersusReturning'];
        $topEvents = $data['topEvents'];
        $loginFrequency = $data['loginFrequency'];
    @endphp

    <div class="grid gap-6 md:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">New vs returning</x-slot>
            <x-slot name="description">Last {{ $this->days }} days, known actors</x-slot>

            <dl class="grid grid-cols-3 gap-4">
                <div>
                    <dt class="text-sm text-gray-500">New</dt>
                    <dd class="text-2xl font-semibold">{{ number_format($newVersusReturning['new']) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Returning</dt>
                    <dd class="text-2xl font-semibold">{{ number_format($newVersusReturning['returning']) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500">Logins per active user</dt>
                    <dd class="text-2xl font-semibold">{{ $loginFrequency }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Top events</x-slot>
            <x-slot name="description">From daily rollups</x-slot>

            @if ($topEvents === [])
                <p class="text-sm text-gray-500">No aggregated events yet. Schedule <code>tendril:aggregate</code>.</p>
            @else
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ($topEvents as $row)
                            <tr>
                                <td class="py-1">{{ $row['name'] }}</td>
                                <td class="py-1 text-right tabular-nums">{{ number_format($row['count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
