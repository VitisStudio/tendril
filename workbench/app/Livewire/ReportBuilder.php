<?php

namespace Workbench\App\Livewire;

use Livewire\Component;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Livewire\Untracked;

/**
 * Demo/test component: actions become livewire.action facts; property syncs,
 * polls and #[Untracked] handlers do not.
 */
class ReportBuilder extends Component
{
    public string $format = 'xlsx';

    public string $filter = '';

    public int $refreshes = 0;

    public function export(string $secretParameter = ''): void
    {
        Tendril::track('report.exported', ['format' => $this->format]);
    }

    public function applyFilter(): void
    {
        // No explicit fact: the action itself is captured by the hook.
    }

    #[Untracked]
    public function pollStats(): void
    {
        $this->refreshes++;
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <input wire:model.live="filter">
                <button wire:click="export">Export</button>
                <button wire:click="applyFilter">Apply</button>
                <div wire:poll.5s="pollStats">{{ $refreshes }}</div>
            </div>
            BLADE;
    }
}
