<?php

namespace App\Scraping;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Adaptive per-host request pace for the chapter scraper: as fast as the
 * site tolerates, slower by itself when it objects.
 *
 * Each host has a pace (seconds). The wait before its next request is a
 * random value in [pace, pace x 1.5]. After every CLEAN_STREAK clean answers
 * in a row the pace drops by SPEED_UP, never below the effective floor: the
 * "fastest delay" setting, or a learned floor if one is in force. A rate
 * limit or Cloudflare challenge doubles the pace (capped at MAX_PACE) and
 * learns a floor of 1.5x the pace that provoked it, kept for FLOOR_DAYS; a
 * failed fetch slows it by 1.5x without learning anything.
 *
 * "Clean" means the site answered and it was not a blocking failure: a
 * downloaded chapter, and also a 404 / empty / short answer (those say
 * nothing about rate limiting). Other results (parse exceptions) leave the
 * state alone.
 *
 * With the scrape_adaptive setting off, delay() is exactly the old
 * rand(scrape_min_delay, scrape_max_delay) and no state is kept.
 *
 * State lives in the cache for good (key prefix KEY_PREFIX + host). All the
 * arithmetic is in pure static methods; randomness comes from an injectable
 * callable so tests can pin it.
 */
class HostPace
{
    public const KEY_PREFIX = 'scrape:host-pace:';

    /** Cache key listing every host with a pace state (for overview()). */
    public const INDEX_KEY = 'scrape:host-pace-index';

    /** Clean answers in a row that earn a speed-up. */
    public const CLEAN_STREAK = 10;

    /** Pace multiplier per speed-up. */
    public const SPEED_UP = 0.85;

    /** The random wait is drawn from [pace, pace x SPREAD]. */
    public const SPREAD = 1.5;

    /** Slowest pace the back-off can reach (seconds). */
    public const MAX_PACE = 300;

    /** A learned floor is this multiple of the pace that drew a rate limit. */
    public const FLOOR_FACTOR = 1.5;

    /** How long a learned floor stays in force. */
    public const FLOOR_DAYS = 7;

    /** Lowest accepted "fastest delay" setting. */
    public const MIN_FASTEST = 3;

    public const DEFAULT_FASTEST = 15;

    /** @var callable(int, int): int */
    private $random;

    /** @param ?callable(int, int): int $random inclusive integer random, defaults to rand() */
    public function __construct(?callable $random = null)
    {
        $this->random = $random ?? fn(int $lo, int $hi): int => rand($lo, $hi);
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public static function adaptive(): bool
    {
        $value = setting('scrape_adaptive', config('novarr.defaults.scrape_adaptive', true) ? '1' : '0');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function minDelay(): int
    {
        return max(1, (int) setting('scrape_min_delay', config('novarr.defaults.scrape_min_delay', 30)));
    }

    public static function maxDelay(): int
    {
        return max(self::minDelay(), (int) setting('scrape_max_delay', config('novarr.defaults.scrape_max_delay', 90)));
    }

    public static function fastestDelay(): int
    {
        return max(self::MIN_FASTEST, (int) setting('scrape_fastest_delay', config('novarr.defaults.scrape_fastest_delay', self::DEFAULT_FASTEST)));
    }

    // ------------------------------------------------------------------
    // Pure arithmetic
    // ------------------------------------------------------------------

    /** A host's state before its first request. */
    public static function initialState(int $minDelay): array
    {
        return [
            'pace' => (float) max(1, $minDelay),
            'clean' => 0,
            'floor' => null,
            'floor_until' => null,
            'last_slowdown_at' => null,
            'last_slowdown_reason' => null,
        ];
    }

    /**
     * The floor in force: the fastest-delay setting, or the learned floor
     * when it is higher and not yet expired ($until and $now are Unix seconds).
     */
    public static function effectiveFloor(int $fastest, ?float $learned, ?int $until, int $now): float
    {
        $floor = (float) max(self::MIN_FASTEST, $fastest);
        if ($learned !== null && $until !== null && $until > $now) {
            $floor = max($floor, $learned);
        }

        return $floor;
    }

    /**
     * Integer bounds of the random wait for a pace: [ceil(pace), floor(pace x 1.5)],
     * so a drawn wait is never under the pace.
     *
     * @return array{0: int, 1: int}
     */
    public static function waitRange(float $pace): array
    {
        $lo = (int) ceil($pace);
        $hi = max($lo, (int) floor($pace * self::SPREAD));

        return [$lo, $hi];
    }

    /**
     * A clean answer: count it, and every CLEAN_STREAK in a row lower the
     * pace by SPEED_UP down to $floor. A pace already at or under the floor
     * (e.g. scrape_min_delay below the fastest delay) is never raised by a
     * speed-up.
     */
    public static function afterClean(array $state, float $floor): array
    {
        $state['clean'] = (int) ($state['clean'] ?? 0) + 1;

        if ($state['clean'] >= self::CLEAN_STREAK) {
            $state['clean'] = 0;
            $pace = (float) $state['pace'];
            if ($pace > $floor) {
                $state['pace'] = max($floor, $pace * self::SPEED_UP);
            }
        }

        return $state;
    }

    /**
     * A rate limit or Cloudflare challenge: learn a floor of 1.5x the pace in
     * force (kept FLOOR_DAYS, never lowered while valid), double the pace (at
     * least scrape_min_delay, at most MAX_PACE) and restart the clean count.
     */
    public static function afterThrottle(array $state, string $reason, int $minDelay, int $now): array
    {
        $pace = (float) $state['pace'];
        $learned = $pace * self::FLOOR_FACTOR;

        $current = isset($state['floor'], $state['floor_until']) && (int) $state['floor_until'] > $now
            ? (float) $state['floor']
            : null;

        $state['floor'] = $current !== null ? max($current, $learned) : $learned;
        $state['floor_until'] = $now + self::FLOOR_DAYS * 86400;
        $state['pace'] = (float) min(max($pace * 2, $minDelay), self::MAX_PACE);
        $state['clean'] = 0;
        $state['last_slowdown_at'] = $now;
        $state['last_slowdown_reason'] = $reason;

        return $state;
    }

    /** A failed fetch: 1.5x slower (capped), clean count restarted, no floor change. */
    public static function afterFetchFailed(array $state, int $now): array
    {
        $state['pace'] = (float) min((float) $state['pace'] * 1.5, self::MAX_PACE);
        $state['clean'] = 0;
        $state['last_slowdown_at'] = $now;
        $state['last_slowdown_reason'] = 'fetch_failed';

        return $state;
    }

    /** Is a result category a clean answer for pace purposes? null = success. */
    public static function isClean(?string $category): bool
    {
        return $category === null || in_array($category, SourceUnavailable::CONTENT_REASONS, true);
    }

    // ------------------------------------------------------------------
    // Stateful API
    // ------------------------------------------------------------------

    /**
     * Seconds to wait before the next request to $host. Adaptive off: the
     * old rand(min, max). On: a random value in the host's pace range.
     */
    public function delay(?string $host): int
    {
        if (!self::adaptive()) {
            return ($this->random)(self::minDelay(), self::maxDelay());
        }

        [$lo, $hi] = self::waitRange((float) $this->state($host)['pace']);

        return ($this->random)($lo, $hi);
    }

    /**
     * Feed one fetch result into the host's pace. $category is null for a
     * downloaded chapter, else the failure category. No-op with adaptive off
     * or without a host.
     */
    public function record(?string $host, ?string $category): void
    {
        if ($host === null || $host === '' || !self::adaptive()) {
            return;
        }

        $state = $this->state($host);
        $now = Carbon::now()->getTimestamp();

        if (self::isClean($category)) {
            $floor = self::effectiveFloor(
                self::fastestDelay(),
                isset($state['floor']) ? (float) $state['floor'] : null,
                isset($state['floor_until']) ? (int) $state['floor_until'] : null,
                $now
            );
            $state = self::afterClean($state, $floor);
        } elseif ($category === 'rate_limited' || $category === 'cloudflare') {
            $state = self::afterThrottle($state, $category, self::minDelay(), $now);
        } elseif ($category === 'fetch_failed') {
            $state = self::afterFetchFailed($state, $now);
        } else {
            return;
        }

        $this->save($host, $state);
    }

    /** A host's saved state, or its initial one. */
    public function state(?string $host): array
    {
        $initial = self::initialState(self::minDelay());
        if ($host === null || $host === '') {
            return $initial;
        }

        $saved = Cache::get(self::key($host));

        return is_array($saved) ? array_merge($initial, $saved) : $initial;
    }

    private function save(string $host, array $state): void
    {
        $host = strtolower($host);
        Cache::forever(self::key($host), $state);

        $hosts = Cache::get(self::INDEX_KEY, []);
        if (!is_array($hosts)) {
            $hosts = [];
        }
        if (!in_array($host, $hosts, true)) {
            $hosts[] = $host;
            sort($hosts);
            Cache::forever(self::INDEX_KEY, $hosts);
        }
    }

    public static function key(string $host): string
    {
        return self::KEY_PREFIX . strtolower($host);
    }

    /**
     * Per known host, for the Health page: the current wait range, the
     * fastest pace allowed right now, the learned floor and its expiry, and
     * the last slowdown.
     *
     * @return list<array{host: string, adaptive: bool, pace: float, range: array{0: int, 1: int}, fastest: float, floor: ?float, floor_until: ?Carbon, last_slowdown_at: ?Carbon, last_slowdown_reason: ?string}>
     */
    public function overview(): array
    {
        $hosts = Cache::get(self::INDEX_KEY, []);
        if (!is_array($hosts)) {
            return [];
        }

        $adaptive = self::adaptive();
        $now = Carbon::now()->getTimestamp();
        $rows = [];
        foreach ($hosts as $host) {
            $state = $this->state($host);
            $floorUntil = isset($state['floor_until']) ? (int) $state['floor_until'] : null;
            $floorValid = $floorUntil !== null && $floorUntil > $now && isset($state['floor']);

            $rows[] = [
                'host' => $host,
                'adaptive' => $adaptive,
                'pace' => round((float) $state['pace'], 1),
                'range' => $adaptive ? self::waitRange((float) $state['pace']) : [self::minDelay(), self::maxDelay()],
                'fastest' => self::effectiveFloor(self::fastestDelay(), $floorValid ? (float) $state['floor'] : null, $floorUntil, $now),
                'floor' => $floorValid ? round((float) $state['floor'], 1) : null,
                'floor_until' => $floorValid ? self::at($floorUntil) : null,
                'last_slowdown_at' => isset($state['last_slowdown_at']) ? self::at((int) $state['last_slowdown_at']) : null,
                'last_slowdown_reason' => $state['last_slowdown_reason'] ?? null,
            ];
        }

        return $rows;
    }

    /** A stored Unix time as a Carbon in the app's timezone. */
    private static function at(int $timestamp): Carbon
    {
        return Carbon::createFromTimestamp($timestamp)->setTimezone(config('app.timezone'));
    }
}
