<x-filament-panels::page>
    @php($stats = $this->stats())

    <x-filament::section>
        <x-slot name="heading">Ingestion today (UTC)</x-slot>

        <dl class="grid grid-cols-2 gap-4 md:grid-cols-5">
            @foreach (['accepted' => 'Accepted', 'persisted' => 'Persisted', 'duplicates' => 'Duplicates', 'rejected' => 'Rejected', 'dropped' => 'Dropped', 'failed' => 'Failed', 'dead_lettered' => 'Dead-lettered', 'batches' => 'Batches', 'lag_ms_max' => 'Max lag (ms)'] as $key => $label)
                <div>
                    <dt class="text-sm text-gray-500">{{ $label }}</dt>
                    <dd class="text-xl font-semibold tabular-nums">{{ number_format($stats[$key] ?? 0) }}</dd>
                </div>
            @endforeach
            <div>
                <dt class="text-sm text-gray-500">Avg lag (ms)</dt>
                <dd class="text-xl font-semibold tabular-nums">{{ ($stats['batches'] ?? 0) > 0 ? number_format(($stats['lag_ms_total'] ?? 0) / $stats['batches']) : 0 }}</dd>
            </div>
        </dl>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Checks</x-slot>
        <x-slot name="description">Same checks as <code>php artisan tendril:doctor</code></x-slot>

        <table class="w-full text-sm">
            <tbody>
                @foreach ($this->checks() as $check)
                    @php($badgeColor = ['ok' => 'success', 'warn' => 'warning'][$check->status] ?? 'danger')
                    <tr>
                        <td class="py-1 pr-2 font-medium whitespace-nowrap">{{ $check->name }}</td>
                        <td class="py-1 pr-2">
                            <x-filament::badge :color="$badgeColor">
                                {{ $check->status }}
                            </x-filament::badge>
                        </td>
                        <td class="py-1 text-gray-600">{{ $check->message }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
