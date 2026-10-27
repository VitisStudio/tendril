<?php

namespace VitisStudio\Tendril\Core\Acquisition;

/**
 * Coarse referrer classes: search, social, email, referral.
 */
final class ReferrerClassifier
{
    private const array SEARCH = ['google.', 'bing.', 'duckduckgo.', 'yahoo.', 'baidu.', 'yandex.', 'ecosia.', 'search.brave.', 'startpage.', 'qwant.', 'kagi.'];

    private const array SOCIAL = ['facebook.', 'fb.', 'instagram.', 't.co', 'twitter.', 'x.com', 'linkedin.', 'lnkd.in', 'reddit.', 'pinterest.', 'tiktok.', 'youtube.', 'threads.', 'mastodon.', 'bsky.', 'news.ycombinator.'];

    private const array EMAIL = ['mail.google.', 'outlook.', 'mail.yahoo.', 'mail.', 'webmail.', 'proton.me'];

    public static function classify(string $host): string
    {
        $host = strtolower($host);

        foreach (self::EMAIL as $needle) {
            if (str_starts_with($host, $needle) || str_contains($host, '.'.$needle)) {
                return 'email';
            }
        }

        foreach (self::SEARCH as $needle) {
            if (self::matches($host, $needle)) {
                return 'search';
            }
        }

        foreach (self::SOCIAL as $needle) {
            if (self::matches($host, $needle)) {
                return 'social';
            }
        }

        return 'referral';
    }

    private static function matches(string $host, string $needle): bool
    {
        if (! str_ends_with($needle, '.')) {
            return $host === $needle || str_ends_with($host, '.'.$needle);
        }

        return str_starts_with($host, $needle) || str_contains($host, '.'.$needle);
    }
}
