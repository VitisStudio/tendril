<?php

namespace VitisStudio\Tendril\Core\Privacy;

/**
 * Optional IP handling. Default mode "none" stores nothing. "truncated" keeps
 * the /24 (IPv4) or /48 (IPv6) network; "hashed" keeps a daily-rotating keyed
 * hash usable for abuse/bot filtering but not for re-identification across days.
 */
final class IpAnonymizer
{
    public static function apply(?string $ip, string $mode): ?string
    {
        if ($ip === null || $ip === '' || $mode === 'none') {
            return null;
        }

        if ($mode === 'truncated') {
            $packed = @inet_pton($ip);

            if ($packed === false) {
                return null;
            }

            $keep = strlen($packed) === 4 ? 3 : 6;
            $truncated = substr($packed, 0, $keep).str_repeat("\0", strlen($packed) - $keep);
            $result = inet_ntop($truncated);

            return $result === false ? null : $result;
        }

        if ($mode === 'hashed') {
            $key = (string) config('app.key', '');

            return substr(hash_hmac('sha256', $ip.'|'.gmdate('Y-m-d'), $key !== '' ? $key : 'tendril'), 0, 16);
        }

        return null;
    }
}
