<?php

namespace VitisStudio\Tendril\Core\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Core\Acquisition\Acquisition;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Touchpoints are derived from session rows; rebuild them (chunked, idempotent).
 */
final class RebuildTouchpointsCommand extends Command
{
    protected $signature = 'tendril:rebuild-touchpoints {--chunk=1000}';

    protected $description = 'Rebuild tendril_touchpoints from session acquisition snapshots';

    public function handle(Database $db): int
    {
        $written = 0;

        $db->table(Tables::SESSIONS)->orderBy('id')->chunk(max(1, (int) $this->option('chunk')), function ($sessions) use ($db, &$written): void {
            $rows = [];

            foreach ($sessions as $session) {
                $clickIds = is_string($session->click_ids) ? (json_decode($session->click_ids, true) ?: []) : [];

                $acquisition = new Acquisition(
                    $session->utm_source, $session->utm_medium, $session->utm_campaign, $session->utm_content,
                    $session->utm_term, $session->referrer_host, $session->referrer_class, is_array($clickIds) ? $clickIds : [],
                );

                if (! $acquisition->hasData()) {
                    continue;
                }

                $effective = $acquisition->effectiveSourceMedium();

                $rows[] = BatchWriter::touchpointRow([
                    'session_id' => $session->id,
                    'occurred_at' => $session->started_at,
                    'anonymous_id' => $session->anonymous_id,
                    'tenant_type' => $session->tenant_type,
                    'tenant_id' => $session->tenant_id,
                    'source' => $effective['source'],
                    'medium' => $effective['medium'],
                    'campaign' => $acquisition->campaign,
                    'content' => $acquisition->content,
                    'term' => $acquisition->term,
                    'referrer_host' => $acquisition->referrerHost,
                    'referrer_class' => $acquisition->referrerClass,
                    'landing_route' => $session->landing_route,
                    'click_ids' => $acquisition->clickIds,
                    'environment' => $session->environment,
                ], $db);
            }

            if ($rows !== []) {
                $written += $db->table(Tables::TOUCHPOINTS)->insertOrIgnore($rows);
            }
        });

        $this->info("Inserted {$written} missing touchpoints.");

        return self::SUCCESS;
    }
}
