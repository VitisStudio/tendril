<x-filament-panels::page>
    <p class="text-sm text-gray-500">
        Infrastructure settings live in <code>config/tendril.php</code> (and environment variables); business definitions live in data. This page shows the effective configuration.
    </p>

    @foreach ($this->settings() as $section => $values)
        <x-filament::section collapsible>
            <x-slot name="heading">{{ $section }}</x-slot>

            <table class="w-full text-sm">
                <tbody>
                    @foreach ($values as $path => $value)
                        <tr>
                            <td class="py-1 pr-4 font-mono whitespace-nowrap">{{ $path }}</td>
                            <td class="py-1 break-all text-gray-600">{{ $value }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
