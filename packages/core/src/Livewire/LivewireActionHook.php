<?php

namespace VitisStudio\Tendril\Core\Livewire;

use Closure;
use Livewire\ComponentHook;

/**
 * Server-side Livewire 4 capture via the PHP ComponentHook lifecycle
 * (registered once from the service provider). The JavaScript interceptors
 * run in the browser and are never used for facts.
 *
 * Only the "call" hook records anything: property updates (wire:model.live)
 * go through "update" and are ignored, as are hydrate/dehydrate.
 */
class LivewireActionHook extends ComponentHook
{
    /**
     * Livewire invokes the returned callback after the action succeeded.
     *
     * @param  mixed  $method
     * @param  mixed  $params  never recorded
     * @param  mixed  $returnEarly
     */
    public function call($method, $params, $returnEarly, mixed ...$metadata): Closure
    {
        $component = $this->component;
        $method = is_string($method) ? $method : '';

        return static function () use ($component, $method, $metadata): void {
            if (is_object($component)) {
                app(LivewireRecorder::class)->action($component, $method, array_values($metadata));
            }
        };
    }
}
