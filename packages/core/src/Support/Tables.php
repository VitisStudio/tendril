<?php

namespace VitisStudio\Tendril\Core\Support;

/**
 * Table names for every Tendril package. The tendril_ prefix is settled.
 */
final class Tables
{
    public const string EVENTS = 'tendril_events';

    public const string SESSIONS = 'tendril_sessions';

    public const string TOUCHPOINTS = 'tendril_touchpoints';

    public const string ALIASES = 'tendril_aliases';

    public const string DIRTY_BUCKETS = 'tendril_dirty_buckets';

    public const string DEAD_LETTERS = 'tendril_dead_letters';

    public const string STATE = 'tendril_state';

    public const string ERASURES = 'tendril_erasures';

    public const string AUDIT = 'tendril_audit_log';

    public const string ROLLUPS = 'tendril_rollups';

    public const string ACTIVITY = 'tendril_actor_activity';

    public const string FEATURES = 'tendril_features';

    public const string FUNNELS = 'tendril_funnels';

    public const string COHORTS = 'tendril_cohorts';

    public const string CONVERSIONS = 'tendril_conversions';

    public const string DELIVERIES = 'tendril_destination_deliveries';

    /**
     * Tables owned by tendril-core.
     *
     * @return list<string>
     */
    public static function core(): array
    {
        return [
            self::EVENTS, self::SESSIONS, self::TOUCHPOINTS, self::ALIASES,
            self::DIRTY_BUCKETS, self::DEAD_LETTERS, self::STATE, self::ERASURES, self::AUDIT,
        ];
    }
}
