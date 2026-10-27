<?php

namespace VitisStudio\Tendril\Core\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use VitisStudio\Tendril\Core\Contracts\ActorResolver;

/**
 * Default actor resolver: the first configured guard with an authenticated user.
 */
final class AuthActorResolver implements ActorResolver
{
    public function resolve(): ?ActorRef
    {
        /** @var array<int, string|null> $guards */
        $guards = (array) config('tendril.resolvers.guards', [null]);
        $auth = app('auth');

        foreach ($guards as $guard) {
            $user = $auth->guard($guard)->user();

            if ($user instanceof Authenticatable) {
                return ActorRef::tryFrom($user->getAuthIdentifier() !== null ? $user : null);
            }
        }

        return null;
    }
}
