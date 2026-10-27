<x-filament-panels::page>
    @php($presence = $this->presence())

    <x-filament::section>
        <x-slot name="heading">{{ number_format($presence['count']) }} active now</x-slot>
        <x-slot name="description">Actors with meaningful activity in the last {{ (int) round($presence['ttl'] / 60) }} minutes. Presence is ephemeral and never stored as analytics.</x-slot>

        <table class="w-full text-sm">
            <thead>
                <tr>
                    <th class="py-1 pr-2 text-left">Actor</th>
                    <th class="py-1 pr-2 text-left">Type</th>
                    <th class="py-1 text-right">Last seen</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($presence['members'] as $member)
                    <tr>
                        <td class="py-1 pr-2">{{ $member['label'] }}</td>
                        <td class="py-1 pr-2 text-gray-500">{{ $member['type'] }}</td>
                        <td class="py-1 text-right text-gray-500">{{ $member['last_seen'] }}</td>
                    </tr>
                @empty
                    <tr><td class="py-1 text-gray-500" colspan="3">Nobody is active right now.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
