<?php

namespace VitisStudio\Tendril\Core\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Core\Diagnostics\CheckResult;
use VitisStudio\Tendril\Core\Diagnostics\Diagnostics;

final class DoctorCommand extends Command
{
    protected $signature = 'tendril:doctor
        {--json : Output machine-readable JSON}
        {--alert : Dispatch HealthThresholdExceeded for every non-OK check}';

    protected $description = 'Validate Tendril migrations, storage, transport, presence, aggregation freshness, retention and cardinality';

    public function handle(Diagnostics $diagnostics): int
    {
        $results = $diagnostics->run((bool) $this->option('alert'));

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (CheckResult $r) => [
                'name' => $r->name,
                'status' => $r->status,
                'message' => $r->message,
                'data' => $r->data,
            ], $results), JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Status', 'Details'], array_map(fn (CheckResult $r) => [
                $r->name,
                match ($r->status) {
                    CheckResult::OK => '<info>ok</info>',
                    CheckResult::WARN => '<comment>warn</comment>',
                    default => '<error>fail</error>',
                },
                $r->message,
            ], $results));
        }

        foreach ($results as $result) {
            if ($result->status === CheckResult::FAIL) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
