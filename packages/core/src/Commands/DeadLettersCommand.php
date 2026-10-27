<?php

namespace VitisStudio\Tendril\Core\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Core\Storage\DeadLetters;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

final class DeadLettersCommand extends Command
{
    protected $signature = 'tendril:dead-letters
        {--retry : Retry persisting dead-lettered batches/rows}
        {--purge-days= : Delete dead letters older than N days}
        {--limit=100 : Maximum dead letters to retry}';

    protected $description = 'List, retry or purge dead-lettered analytics batches';

    public function handle(DeadLetters $deadLetters, Database $db): int
    {
        if ($this->option('retry')) {
            [$retried, $failed] = $deadLetters->retry(max(1, (int) $this->option('limit')));
            $this->info("Retried {$retried}, still failing {$failed}.");
        }

        if (is_numeric($this->option('purge-days'))) {
            $purged = $deadLetters->purgeBefore(Instant::now()->subDays((int) $this->option('purge-days')));
            $this->info("Purged {$purged} dead letters.");
        }

        $rows = $db->table(Tables::DEAD_LETTERS)->orderByDesc('failed_at')->limit(20)->get(['id', 'reason', 'events', 'attempts', 'failed_at']);

        $this->table(['Id', 'Failed at', 'Events', 'Attempts', 'Reason'], $rows->map(fn ($r) => [
            $r->id, $r->failed_at, $r->events, $r->attempts, mb_strimwidth((string) $r->reason, 0, 80, '…'),
        ])->all());

        $this->line($deadLetters->count().' dead letters in total.');

        return self::SUCCESS;
    }
}
