<x-filament-panels::page>
    @php($timeline = $this->timeline())

    @if ($timeline !== null)
        <x-filament::section>
            <x-slot name="heading">
                {{ class_basename($timeline['actor']->type) }} #{{ $timeline['actor']->id }}
            </x-slot>
            <x-slot name="description">
                Last seen {{ $timeline['last_seen'] ?? '—' }} · Features used (last {{ $this->days }} days):
                @forelse ($timeline['features'] as $feature)
                    <x-filament::badge class="inline-flex">{{ $feature }}</x-filament::badge>
                @empty
                    none
                @endforelse
            </x-slot>

            <div class="grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="mb-2 text-sm font-medium">Recent activity</h3>
                    <table class="w-full text-sm">
                        <tbody>
                            @forelse ($timeline['events'] as $event)
                                @php($context = is_string($event->context) ? (json_decode($event->context, true) ?: []) : [])
                                <tr>
                                    <td class="py-1 pr-2 whitespace-nowrap text-gray-500">{{ \Illuminate\Support\Carbon::parse($event->occurred_at)->utc()->format('Y-m-d H:i') }}</td>
                                    <td class="py-1 pr-2">{{ $event->name }}</td>
                                    <td class="py-1 text-gray-500">{{ $context['route'] ?? ($context['component'] ?? '') }}{{ isset($context['action']) ? '@'.$context['action'] : '' }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-1 text-gray-500">No events in the last 90 days.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-medium">Sessions</h3>
                    <table class="w-full text-sm">
                        <tbody>
                            @forelse ($timeline['sessions'] as $session)
                                <tr>
                                    <td class="py-1 pr-2 whitespace-nowrap text-gray-500">{{ \Illuminate\Support\Carbon::parse($session->started_at)->utc()->format('Y-m-d H:i') }}</td>
                                    <td class="py-1 pr-2">{{ $session->landing_route ?? '—' }}</td>
                                    <td class="py-1 pr-2 text-gray-500">{{ $session->utm_source ? $session->utm_source.' / '.($session->utm_medium ?? '—') : '' }}</td>
                                    <td class="py-1 text-right tabular-nums">{{ $session->page_view_count }} pv · {{ $session->event_count }} ev</td>
                                </tr>
                            @empty
                                <tr><td class="py-1 text-gray-500">No sessions.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
