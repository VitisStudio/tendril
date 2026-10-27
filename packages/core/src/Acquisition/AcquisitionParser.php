<?php

namespace VitisStudio\Tendril\Core\Acquisition;

use Illuminate\Http\Request;

/**
 * Extracts acquisition data from a request (or from beacon-supplied values).
 */
final class AcquisitionParser
{
    public const string CLICK_ID_PATTERN = '/^[A-Za-z0-9_\-.~]{1,255}$/';

    public function fromRequest(Request $request, bool $consent): Acquisition
    {
        /** @var array<string, mixed> $query */
        $query = $request->query();

        return $this->parse($query, $request->headers->get('referer'), $request->getHost(), $consent);
    }

    /**
     * Query parameter captured for each acquisition role. tendril.acquisition.utm
     * is a role => parameter map; a plain list is read in
     * source, medium, campaign, content, term order.
     *
     * @return array{source: string, medium: string, campaign: string, content: string, term: string}
     */
    public static function parameterNames(): array
    {
        $configured = config('tendril.acquisition.utm');
        $configured = is_array($configured) ? $configured : [];

        $name = static function (string $role, int $index) use ($configured): string {
            $value = $configured[$role] ?? $configured[$index] ?? null;

            return is_string($value) && $value !== '' ? $value : "utm_{$role}";
        };

        return [
            'source' => $name('source', 0),
            'medium' => $name('medium', 1),
            'campaign' => $name('campaign', 2),
            'content' => $name('content', 3),
            'term' => $name('term', 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function parse(array $params, ?string $referrer, string $currentHost, bool $consent): Acquisition
    {
        $utm = [];

        foreach (self::parameterNames() as $role => $key) {
            $utm[$role] = $this->clean($params[$key] ?? null, in_array($role, ['source', 'medium'], true));
        }

        [$referrerHost, $referrerClass] = $this->referrer($referrer, $currentHost);

        return new Acquisition(
            source: $utm['source'],
            medium: $utm['medium'],
            campaign: $utm['campaign'],
            content: $utm['content'],
            term: $utm['term'],
            referrerHost: $referrerHost,
            referrerClass: $referrerClass,
            clickIds: $this->clickIds($params, $consent),
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function referrer(?string $referrer, string $currentHost): array
    {
        if ($referrer === null || $referrer === '') {
            return [null, null];
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return [null, null];
        }

        $host = strtolower($host);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $current = strtolower($currentHost);
        $current = str_starts_with($current, 'www.') ? substr($current, 4) : $current;

        $internal = array_map('strtolower', array_filter((array) config('tendril.acquisition.internal_hosts', []), 'is_string'));

        if ($host === $current || in_array($host, $internal, true)) {
            return [null, null];
        }

        return [substr($host, 0, 191), ReferrerClassifier::classify($host)];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function clickIds(array $params, bool $consent): array
    {
        if (! config('tendril.acquisition.click_ids.enabled', false)) {
            return [];
        }

        if (config('tendril.acquisition.click_ids.require_consent', true) && ! $consent) {
            return [];
        }

        $out = [];

        foreach ((array) config('tendril.acquisition.click_ids.params', []) as $param) {
            if (! is_string($param)) {
                continue;
            }

            $value = $params[$param] ?? null;

            if (is_string($value) && preg_match(self::CLICK_ID_PATTERN, $value) === 1) {
                $out[$param] = $value;
            }
        }

        return $out;
    }

    private function clean(mixed $value, bool $lower): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');

        if ($value === '') {
            return null;
        }

        $value = mb_substr($value, 0, 191);

        return $lower ? mb_strtolower($value) : $value;
    }
}
