<?php

namespace VitisStudio\Tendril\Filament\Pages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;
use VitisStudio\Tendril\Core\Presence\PresenceManager;

/**
 * Recently active actors in the current tenant scope (ephemeral presence store).
 */
class Presence extends TendrilPage
{
    public static function tendrilKey(): string
    {
        return 'presence';
    }

    public static function tendrilLabel(): string
    {
        return 'Presence';
    }

    public static function tendrilIcon(): string
    {
        return 'heroicon-o-signal';
    }

    public static function tendrilSort(): int
    {
        return 7;
    }

    /**
     * @return array{count: int, members: list<array{type: string, id: string, label: string, last_seen: string}>, ttl: int}
     */
    public function presence(): array
    {
        $scope = $this->tenantScope();

        if ($scope->mode === 'none') {
            return ['count' => 0, 'members' => [], 'ttl' => (int) config('tendril.presence.ttl_seconds', 300)];
        }

        $manager = app(PresenceManager::class);
        $tenant = $scope->mode === 'tenant' ? $scope->tenant : null;
        $members = [];

        foreach ($manager->members($tenant, 100) as $member) {
            $members[] = [
                'type' => class_basename($member['actor']->type),
                'id' => $member['actor']->id,
                'label' => $this->label($member['actor']->type, $member['actor']->id),
                'last_seen' => $member['last_seen']->diffForHumans(),
            ];
        }

        return ['count' => $manager->count($tenant), 'members' => $members, 'ttl' => (int) config('tendril.presence.ttl_seconds', 300)];
    }

    /**
     * Resolve a display label from the host model when it exists (name/email),
     * otherwise the id. Only ids are stored.
     */
    private function label(string $type, string $id): string
    {
        try {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (is_string($class) && is_subclass_of($class, Model::class)) {
                $model = $class::query()->find($id);

                if ($model !== null) {
                    foreach (['name', 'email'] as $attribute) {
                        $value = $model->getAttribute($attribute);

                        if (is_string($value) && $value !== '') {
                            return $value;
                        }
                    }
                }
            }
        } catch (Throwable) {
            // Host model unavailable.
        }

        return '#'.$id;
    }
}
