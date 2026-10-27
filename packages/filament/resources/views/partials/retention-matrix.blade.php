@php
    $matrix = $matrix ?? [];
    $periodLabel = $periodLabel ?? 'Week';
@endphp

@if ($matrix === [])
    <p class="text-sm text-gray-500">No cohorts in this range yet.</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr>
                    <th class="py-1 pr-2 text-left">Cohort</th>
                    <th class="py-1 pr-2 text-right">Size</th>
                    @foreach ($matrix[0]['rates'] as $offset => $rate)
                        <th class="py-1 pr-2 text-right">{{ $periodLabel }} {{ $offset }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($matrix as $row)
                    <tr>
                        <td class="py-1 pr-2 whitespace-nowrap">{{ $row['cohort'] }}</td>
                        <td class="py-1 pr-2 text-right tabular-nums">{{ $row['size'] }}</td>
                        @foreach ($row['rates'] as $offset => $rate)
                            <td class="py-1 pr-2 text-right tabular-nums" title="{{ $row['counts'][$offset] }} actors">{{ number_format($rate * 100, 1) }}%</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
