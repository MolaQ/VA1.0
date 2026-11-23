<?php

namespace App\Analytics;

/**
 * Tiny user-agent classifier: enough for device / browser / OS breakdowns,
 * without storing the user agent itself.
 */
final class UserAgent
{
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|headless|lighthouse|pingdom|uptime|curl|wget|python-requests|httpclient|go-http|java\/|axios|node-fetch|scrapy|monitor/i';

    public function __construct(private readonly string $ua) {}

    public function isBot(): bool
    {
        return $this->ua === '' || (bool) preg_match(self::BOTS, $this->ua);
    }

    public function device(): string
    {
        return match (true) {
            (bool) preg_match('/smart-?tv|googletv|appletv|hbbtv|netcast|web0s|webos|tizen|bravia|roku|aftb|crkey|tv\b/i', $this->ua) => 'tv',
            (bool) preg_match('/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $this->ua) => 'tablet',
            (bool) preg_match('/mobi|iphone|ipod|android.*mobile|windows phone|blackberry/i', $this->ua) => 'mobile',
            default => 'desktop',
        };
    }

    public function browser(): ?string
    {
        return match (true) {
            str_contains($this->ua, 'Edg/') => 'Edge',
            str_contains($this->ua, 'OPR/') || str_contains($this->ua, 'Opera') => 'Opera',
            str_contains($this->ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($this->ua, 'Firefox/') || str_contains($this->ua, 'FxiOS') => 'Firefox',
            str_contains($this->ua, 'CriOS') || str_contains($this->ua, 'Chrome/') => 'Chrome',
            str_contains($this->ua, 'Safari/') => 'Safari',
            default => null,
        };
    }

    public function os(): ?string
    {
        return match (true) {
            (bool) preg_match('/iphone|ipad|ipod/i', $this->ua) => 'iOS',
            str_contains($this->ua, 'Android') => 'Android',
            str_contains($this->ua, 'Windows') => 'Windows',
            str_contains($this->ua, 'Mac OS X') || str_contains($this->ua, 'Macintosh') => 'macOS',
            str_contains($this->ua, 'CrOS') => 'ChromeOS',
            (bool) preg_match('/tizen|webos|web0s/i', $this->ua) => 'Smart TV',
            str_contains($this->ua, 'Linux') => 'Linux',
            default => null,
        };
    }
}
