<?php

namespace VitisStudio\Tendril\Core\Query;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use VitisStudio\Tendril\Core\Identity\TenantRef;

/**
 * The tenant boundary for every read. Treat as a security boundary:
 * - all():    no tenant filter (single-tenant apps / global admins)
 * - tenant(): exactly one tenant
 * - none():   matches nothing (fail closed when a tenant was expected but missing)
 *
 * Raw event/session/touchpoint tables store NULL for "no tenant"; derived
 * tables (rollups, activity) store '' so unique indexes work on PostgreSQL.
 * applyToRaw() and applyToDerived() emit the same SQL today and are kept
 * separate so a scope for the untenanted slice can diverge later.
 */
final class TenantScope
{
    private function __construct(
        public readonly string $mode,
        public readonly ?TenantRef $tenant = null,
    ) {}

    public static function all(): self
    {
        return new self('all');
    }

    public static function tenant(TenantRef $tenant): self
    {
        return new self('tenant', $tenant);
    }

    public static function none(): self
    {
        return new self('none');
    }

    public static function fromNullable(?TenantRef $tenant): self
    {
        return $tenant === null ? self::all() : self::tenant($tenant);
    }

    public function isAll(): bool
    {
        return $this->mode === 'all';
    }

    /**
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public function applyToRaw(BuilderContract $query, ?string $table = null): BuilderContract
    {
        $prefix = $table !== null ? $table.'.' : '';

        match ($this->mode) {
            'tenant' => $query->where($prefix.'tenant_type', $this->tenant?->type)->where($prefix.'tenant_id', $this->tenant?->id),
            'none' => $query->whereRaw('1 = 0'),
            default => null,
        };

        return $query;
    }

    /**
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public function applyToDerived(BuilderContract $query, ?string $table = null): BuilderContract
    {
        $prefix = $table !== null ? $table.'.' : '';

        match ($this->mode) {
            'tenant' => $query->where($prefix.'tenant_type', $this->tenant?->type)->where($prefix.'tenant_id', $this->tenant?->id),
            'none' => $query->whereRaw('1 = 0'),
            default => null,
        };

        return $query;
    }

    public function cacheKey(): string
    {
        return match ($this->mode) {
            'tenant' => 't:'.sha1((string) $this->tenant?->key()),
            'none' => 'none',
            default => 'all',
        };
    }
}
