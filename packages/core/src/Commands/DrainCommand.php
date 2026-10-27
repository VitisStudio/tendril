<?php

namespace VitisStudio\Tendril\Core\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Core\Contracts\BufferStore;
use VitisStudio\Tendril\Core\Transport\BufferDrainer;

final class DrainCommand extends Command
{
    protected $signature = 'tendril:drain
        {--batches=100 : Batches per iteration}
        {--loop : Keep draining until the buffer is empty}';

    protected $description = 'Drain the redis-buffer transport into batch inserts';

    public function handle(BufferDrainer $drainer, BufferStore $store): int
    {
        $total = ['persisted' => 0, 'dead_lettered' => 0, 'requeued' => 0];

        do {
            $result = $drainer->drain(max(1, (int) $this->option('batches')));

            foreach (['persisted', 'dead_lettered', 'requeued'] as $key) {
                $total[$key] += $result[$key];
            }

            if ($result['stopped']) {
                $this->error('Storage unavailable; claimed batches will be retried after the processing timeout.');

                return self::FAILURE;
            }
        } while ($this->option('loop') && $result['persisted'] + $result['dead_lettered'] > 0);

        $this->info(sprintf(
            'Persisted %d batches, dead-lettered %d, requeued %d stale; %d pending, %d in flight.',
            $total['persisted'], $total['dead_lettered'], $total['requeued'], $store->pending(), $store->inFlight(),
        ));

        return self::SUCCESS;
    }
}
