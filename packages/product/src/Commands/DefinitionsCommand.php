<?php

namespace VitisStudio\Tendril\Product\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Product\Features\FeatureRepository;

/**
 * JSON import/export of feature definitions for teams that version them.
 */
final class DefinitionsCommand extends Command
{
    protected $signature = 'tendril:features
        {action : export or import}
        {path? : File path (stdout/stdin when omitted for export)}
        {--overwrite : Replace existing data definitions on import}';

    protected $description = 'Export or import Tendril feature definitions as JSON';

    public function handle(FeatureRepository $features): int
    {
        $path = $this->argument('path');

        if ($this->argument('action') === 'export') {
            $json = $features->export();

            if (is_string($path) && $path !== '') {
                file_put_contents($path, $json);
                $this->info("Exported to {$path}.");
            } else {
                $this->line($json);
            }

            return self::SUCCESS;
        }

        if ($this->argument('action') === 'import') {
            if (! is_string($path) || ! is_file($path)) {
                $this->error('Provide a readable JSON file path.');

                return self::FAILURE;
            }

            $count = $features->import((string) file_get_contents($path), (bool) $this->option('overwrite'));
            $this->info("Imported {$count} features.");

            return self::SUCCESS;
        }

        $this->error('Action must be export or import.');

        return self::FAILURE;
    }
}
