<?php

namespace VitisStudio\Tendril\Core\Livewire;

use Attribute;

/**
 * Mark a Livewire component class or action method as non-intent traffic
 * (e.g. a named wire:poll handler) so it is never recorded.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Untracked {}
