<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use VitisStudio\Tendril\Core\Facades\Tendril;

/**
 * Records a fact from inside a queued job (context propagation tests).
 */
class TrackFromJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $name = 'job.ran') {}

    public function handle(): void
    {
        Tendril::track($this->name, ['job' => class_basename($this)]);
    }
}
