<?php

namespace VitisStudio\Tendril\Marketing\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRepository;

final class ConversionsCommand extends Command
{
    protected $signature = 'tendril:conversions
        {action : export or import}
        {path? : File path (stdout when omitted for export)}
        {--overwrite : Replace existing data definitions on import}';

    protected $description = 'Export or import Tendril conversion definitions as JSON';

    public function handle(ConversionRepository $conversions): int
    {
        $path = $this->argument('path');

        return match ($this->argument('action')) {
            'export' => $this->export($conversions, is_string($path) ? $path : null),
            'import' => $this->import($conversions, is_string($path) ? $path : null),
            default => $this->fail('Action must be export or import.'),
        };
    }

    private function export(ConversionRepository $conversions, ?string $path): int
    {
        $json = $conversions->export();

        if ($path !== null && $path !== '') {
            file_put_contents($path, $json);
            $this->info("Exported to {$path}.");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }

    private function import(ConversionRepository $conversions, ?string $path): int
    {
        if ($path === null || ! is_file($path)) {
            $this->error('Provide a readable JSON file path.');

            return self::FAILURE;
        }

        $count = $conversions->import((string) file_get_contents($path), (bool) $this->option('overwrite'));
        $this->info("Imported {$count} conversions.");

        return self::SUCCESS;
    }
}
