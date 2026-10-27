<?php

namespace VitisStudio\Tendril\Core\Livewire;

use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use VitisStudio\Tendril\Core\Capture\Buffer;
use VitisStudio\Tendril\Core\Capture\DedupeKey;
use VitisStudio\Tendril\Core\Capture\Recorder;
use VitisStudio\Tendril\Core\Context\ContextStore;

/**
 * Turns a Livewire action call into a fact: component name + action name,
 * never parameters. Non-intent traffic is excluded: magic/framework methods
 * ($refresh, $set, $sync, __lazyLoad, _startUpload…), calls flagged as poll,
 * lazy or island loads by Livewire metadata, and #[Untracked] targets.
 */
final class LivewireRecorder
{
    /** @var array<string, bool> Reflection results per class/method (metadata only). */
    private static array $untracked = [];

    /**
     * @param  list<mixed>  $metadata  extra arguments Livewire passes to the call hook
     */
    public function action(object $component, string $method, array $metadata = []): ?string
    {
        if (! Recorder::enabled() || ! config('tendril.livewire.enabled', true)) {
            return null;
        }

        try {
            if ($this->excluded($component, $method, $metadata)) {
                return null;
            }

            $name = $this->componentName($component);

            if ($name === null) {
                return null;
            }

            $requestId = app(ContextStore::class)->ensureRoot('livewire');
            $ordinal = app(Buffer::class)->nextOrdinal("lw:{$name}:{$method}");

            return app(Recorder::class)->record((string) config('tendril.livewire.event', 'livewire.action'), [], [
                'source' => 'livewire',
                'context' => ['component' => $name, 'action' => $method],
                'dedupe_key' => DedupeKey::livewire($requestId, $name, $method, $ordinal),
            ]);
        } catch (Throwable $e) {
            if (config('tendril.debug.throw')) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * @param  list<mixed>  $metadata
     */
    private function excluded(object $component, string $method, array $metadata): bool
    {
        if ($method === '' || str_starts_with($method, '$') || str_starts_with($method, '_')) {
            return true;
        }

        if (in_array($method, (array) config('tendril.livewire.exclude_actions', []), true)) {
            return true;
        }

        foreach ($metadata as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $type = $meta['type'] ?? null;

            if ((is_string($type) && in_array(strtolower($type), ['poll', 'lazy', 'island', 'upload'], true))
                || ! empty($meta['poll']) || ! empty($meta['lazy']) || ! empty($meta['island'])) {
                return true;
            }
        }

        $name = $this->componentName($component);
        $patterns = array_values(array_filter((array) config('tendril.livewire.exclude_components', []), 'is_string'));

        if ($name !== null && $patterns !== [] && Str::is($patterns, $name)) {
            return true;
        }

        return $this->isUntracked($component, $method);
    }

    private function isUntracked(object $component, string $method): bool
    {
        $class = $component::class;
        $key = $class.'@'.$method;

        if (! array_key_exists($key, self::$untracked)) {
            $untracked = false;

            try {
                $reflection = new ReflectionClass($component);
                $untracked = $reflection->getAttributes(Untracked::class) !== [];

                if (! $untracked && $reflection->hasMethod($method)) {
                    $untracked = (new ReflectionMethod($component, $method))->getAttributes(Untracked::class) !== [];
                }
            } catch (Throwable) {
                $untracked = false;
            }

            self::$untracked[$key] = $untracked;
        }

        return self::$untracked[$key];
    }

    private function componentName(object $component): ?string
    {
        if (method_exists($component, 'getName')) {
            $name = $component->getName();

            if (is_string($name) && $name !== '') {
                return substr($name, 0, 150);
            }
        }

        $class = $component::class;

        return str_contains($class, '@anonymous') ? null : substr($class, 0, 150);
    }
}
