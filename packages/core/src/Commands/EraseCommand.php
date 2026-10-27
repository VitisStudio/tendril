<?php

namespace VitisStudio\Tendril\Core\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Privacy\ErasureManager;

final class EraseCommand extends Command
{
    protected $signature = 'tendril:erase
        {--actor-type= : Actor morph type (e.g. App\\Models\\User or a morph alias)}
        {--actor-id= : Actor id}
        {--anonymous-id= : Anonymous visitor id}
        {--by= : Who requested the erasure (stored in the audit log)}
        {--force : Skip confirmation}';

    protected $description = 'Queue an audited privacy erasure for an actor or anonymous visitor';

    public function handle(ErasureManager $erasures): int
    {
        $type = $this->option('actor-type');
        $id = $this->option('actor-id');
        $anonymous = $this->option('anonymous-id');

        if (! $this->option('force') && ! $this->confirm('Erasure rewrites stored events and cannot be undone. Continue?')) {
            return self::FAILURE;
        }

        if (is_string($type) && $type !== '' && is_string($id) && $id !== '') {
            $erasureId = $erasures->eraseActor(new ActorRef($type, $id), is_string($this->option('by')) ? $this->option('by') : null);
        } elseif (is_string($anonymous) && $anonymous !== '') {
            $erasureId = $erasures->eraseAnonymous($anonymous, is_string($this->option('by')) ? $this->option('by') : null);
        } else {
            $this->error('Provide --actor-type and --actor-id, or --anonymous-id.');

            return self::FAILURE;
        }

        $this->info("Erasure {$erasureId} queued.");

        return self::SUCCESS;
    }
}
