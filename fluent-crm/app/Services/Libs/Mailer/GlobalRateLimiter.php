<?php

namespace FluentCrm\App\Services\Libs\Mailer;

use FluentCrm\App\Services\Helper;

/**
 * Cross-process global send-rate limiter (GCRA, evenly spaced sends).
 * Each send takes slot = max(TAT, now), sets TAT = slot + 1/limit, and sleeps until slot.
 * With multi_threading_emails off the TAT lives in memory; with it on, in one wp_options row advanced by compare-and-swap.
 * Sends that skip the sender lock (double opt-in, ExternalPages emails) are not coordinated in memory mode.
 * Toggling the flag mid-send leaves a worker pacing via the DB beside a cron loop pacing in memory for up to one batch; accepted.
 * Callers must not hold an open DB transaction: the CAS UPDATE's row lock would be held until commit.
 * Spacing across app servers relies on synchronized clocks, since each process sleeps against its local now.
 * Fails open (logged) when the store is unavailable. wp_options is per-blog, so multisite caps are independent.
 */
class GlobalRateLimiter
{
    const DB_OPTION = '_fc_email_rate_tat';

    // A TAT further ahead than this is corrupt and reset to now. Must keep wait + send + heartbeat gap under the lock TTL.
    const GARBAGE_AHEAD_MICRO = 15000000; // 15s

    // Fails open (logged) after this many lost CAS races.
    const MAX_CAS_ATTEMPTS = 50;

    private static $dbRowReady = false;
    private static $cachedLimit = null;
    private static $failOpenCount = 0;

    // Multi-threading flag, memoized per process.
    private static $multiThread = null;

    // In-memory TAT (microseconds) for the single-sender path.
    private static $lastSlotMicro = 0;

    /**
     * Throttle one outgoing email against the global per-second cap.
     *
     * @param array $data The email payload, passed to the enable filter.
     */
    public static function throttle($data = [])
    {
        if (!apply_filters('fluent_crm/enable_global_rate_limit', true, $data)) {
            return;
        }

        $limit = self::getLimit();
        if ($limit < 1) {
            return;
        }

        // 32-bit PHP cannot hold a microsecond timestamp.
        if (PHP_INT_SIZE < 8) {
            self::logFailOpen('php_32bit');
            return;
        }

        $intervalMicro = (int)ceil(1000000 / $limit);

        if (self::isMultiThreadMode()) {
            self::reserveViaDb($intervalMicro);
        } else {
            self::reserveViaMemory($intervalMicro);
        }
    }

    /**
     * Whether to use the DB (cross-process) path instead of in-memory pacing.
     *
     * @return bool
     */
    private static function isMultiThreadMode()
    {
        if (self::$multiThread === null) {
            self::$multiThread = Helper::isExperimentalEnabled('multi_threading_emails');
        }

        return self::$multiThread;
    }

    /**
     * In-memory even pacing for the single-sender case.
     *
     * @param int $intervalMicro Spacing between sends in microseconds (1e6/limit).
     */
    private static function reserveViaMemory($intervalMicro)
    {
        $nowMicro = (int)round(microtime(true) * 1000000);

        $slot = max(self::$lastSlotMicro, $nowMicro);

        // A backward clock step can strand the TAT far ahead; reset it to now.
        if ($slot - $nowMicro > self::GARBAGE_AHEAD_MICRO) {
            $slot = $nowMicro;
        }

        self::$lastSlotMicro = $slot + $intervalMicro;

        $waitMicro = $slot - (int)round(microtime(true) * 1000000);
        if ($waitMicro > 0) {
            usleep($waitMicro);
        }
    }

    /**
     * DB-backed pacing for the multi-sender case: reserve a slot by CAS, then sleep until it.
     *
     * @param int $intervalMicro Spacing between sends in microseconds (1e6/limit).
     */
    private static function reserveViaDb($intervalMicro)
    {
        $slot = self::reserveTat($intervalMicro);
        if ($slot === null) {
            return; // fail open (already logged)
        }

        $waitMicro = $slot - (int)round(microtime(true) * 1000000);
        if ($waitMicro > 0) {
            usleep($waitMicro);
        }
    }

    /**
     * The global per-second send cap (emails_per_second minus 3, floor 4), memoized per process.
     *
     * @return int
     */
    public static function getLimit()
    {
        if (self::$cachedLimit !== null) {
            return self::$cachedLimit;
        }

        $emailSettings = fluentcrmGetGlobalSettings('email_settings', []);

        if (!empty($emailSettings['emails_per_second'])) {
            $limit = (int)$emailSettings['emails_per_second'] - 3; // 3 is buffer
        } else {
            $limit = 14;
        }

        if (!$limit || $limit < 4) {
            $limit = 4;
        }

        self::$cachedLimit = (int)apply_filters('fluent_crm/global_email_limit_per_second', $limit, $emailSettings);

        return self::$cachedLimit;
    }

    /**
     * Atomically advance the shared TAT and return this caller's slot
     * (microseconds), using an optimistic compare-and-swap loop.
     *
     * @return int|null Slot timestamp in microseconds, or null to fail open.
     */
    private static function reserveTat($intervalMicro)
    {
        global $wpdb;

        self::ensureDbRow();

        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $nowMicro = (int)round(microtime(true) * 1000000);

            $currentRaw = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                self::DB_OPTION
            ));

            if ($currentRaw === null) {
                self::logFailOpen('row_missing');
                return null;
            }

            $tat = (int)$currentRaw;

            // Reset a corrupt or runaway TAT to now.
            if ($tat > $nowMicro + self::GARBAGE_AHEAD_MICRO) {
                $tat = $nowMicro;
            }

            $slot = max($tat, $nowMicro);
            $newTat = (string)($slot + $intervalMicro);

            // Advance only if nobody moved the TAT since the read.
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $newTat, self::DB_OPTION, $currentRaw
            ));

            if ($affected === false) {
                self::logFailOpen('db_error');
                return null;
            }

            if ($affected > 0) {
                return $slot; // won the slot
            }

            // Lost the race; jittered backoff before retrying.
            usleep(500 + (($attempt * 211) % 1500));
        }

        self::logFailOpen('cas_contention');
        return null;
    }

    /**
     * Ensure the single TAT row exists (idempotent, once per process).
     * INSERT IGNORE is translated to INSERT OR IGNORE by the WP SQLite plugin.
     */
    private static function ensureDbRow()
    {
        if (self::$dbRowReady) {
            return;
        }

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')",
            self::DB_OPTION
        ));

        self::$dbRowReady = true;
    }

    /**
     * Log a fail-open event (sampled) so a degraded limiter is detectable.
     *
     * @param string $reason
     */
    private static function logFailOpen($reason)
    {
        self::$failOpenCount++;

        // First three, then every 100th.
        if (self::$failOpenCount <= 3 || self::$failOpenCount % 100 === 0) {
            Helper::debugLog(
                'GlobalRateLimiter fail-open',
                'reason: ' . $reason . ' (occurrence ' . self::$failOpenCount . ') — per-second rate limit NOT enforced for this send',
                'extended'
            );
        }
    }
}
