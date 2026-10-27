<?php

namespace VitisStudio\Tendril\Core\Identity;

/**
 * The known actor (usually a user) behind an event.
 */
final class ActorRef extends Reference
{
    /** Actor type used in derived tables for identities that are still anonymous. */
    public const string ANONYMOUS = '@anonymous';

    public static function anonymous(string $anonymousId): self
    {
        return new self(self::ANONYMOUS, $anonymousId);
    }

    public function isAnonymous(): bool
    {
        return $this->type === self::ANONYMOUS;
    }
}
