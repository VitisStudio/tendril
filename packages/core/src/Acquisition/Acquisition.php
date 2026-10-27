<?php

namespace VitisStudio\Tendril\Core\Acquisition;

/**
 * Acquisition snapshot for a session arrival: UTM parameters, external
 * referrer (host + class only, never the full URL) and opt-in click IDs.
 */
final class Acquisition
{
    /**
     * @param  array<string, string>  $clickIds
     */
    public function __construct(
        public readonly ?string $source = null,
        public readonly ?string $medium = null,
        public readonly ?string $campaign = null,
        public readonly ?string $content = null,
        public readonly ?string $term = null,
        public readonly ?string $referrerHost = null,
        public readonly ?string $referrerClass = null,
        public readonly array $clickIds = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Campaign parameters present (UTMs or click IDs): a new arrival.
     */
    public function hasCampaign(): bool
    {
        return $this->source !== null || $this->medium !== null || $this->campaign !== null
            || $this->content !== null || $this->term !== null || $this->clickIds !== [];
    }

    /**
     * Anything worth a touchpoint row.
     */
    public function hasData(): bool
    {
        return $this->hasCampaign() || $this->referrerHost !== null;
    }

    public function fingerprint(): ?string
    {
        if (! $this->hasCampaign()) {
            return null;
        }

        $clickKeys = array_keys($this->clickIds);
        sort($clickKeys);

        return substr(sha1(implode('|', [
            $this->source, $this->medium, $this->campaign, $this->content, $this->term,
            implode(',', array_map(fn ($k) => $k.'='.$this->clickIds[$k], $clickKeys)),
        ])), 0, 16);
    }

    /**
     * Effective source/medium: explicit UTMs win, then the referrer class.
     *
     * @return array{source: ?string, medium: ?string}
     */
    public function effectiveSourceMedium(): array
    {
        if ($this->source !== null || $this->medium !== null) {
            return ['source' => $this->source, 'medium' => $this->medium];
        }

        if ($this->referrerHost !== null) {
            $medium = match ($this->referrerClass) {
                'search' => 'organic',
                'social' => 'social',
                'email' => 'email',
                default => 'referral',
            };

            return ['source' => $this->referrerHost, 'medium' => $medium];
        }

        if ($this->clickIds !== []) {
            return ['source' => array_key_first($this->clickIds), 'medium' => 'cpc'];
        }

        return ['source' => null, 'medium' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSessionColumns(): array
    {
        return [
            'utm_source' => $this->source,
            'utm_medium' => $this->medium,
            'utm_campaign' => $this->campaign,
            'utm_content' => $this->content,
            'utm_term' => $this->term,
            'referrer_host' => $this->referrerHost,
            'referrer_class' => $this->referrerClass,
            'click_ids' => $this->clickIds,
        ];
    }
}
