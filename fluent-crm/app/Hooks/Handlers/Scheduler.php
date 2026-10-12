<?php

namespace FluentCrm\App\Hooks\Handlers;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Services\CampaignProcessor;
use FluentCrm\App\Services\ExternalIntegrations\Maintenance;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Libs\FileSystem;
use FluentCrm\App\Services\Libs\Mailer\Handler;
use FluentCrm\App\Services\Libs\Mailer\MultiThreadHandler;

/**
 *  Scheduler Class
 *
 * @package FluentCrm\App\Hooks
 *
 * @version 1.0.0
 */
class Scheduler
{

    public static function register()
    {
        /*
         * Migrating from CRON to Action Scheduler for Every Minutes Tasks
         */
        add_action('fluentcrm_scheduled_minute_tasks', function () {
            // Auto-migration: ensure the Action Scheduler recurring action exists.
            if (!as_has_scheduled_action('fluentcrm_scheduled_every_minute_tasks', [], 'fluent-crm')) {
                Helper::debugLog('Migrating Every Minute CRON to Action Scheduler for FluentCRM');
                as_schedule_recurring_action(time(), 60, 'fluentcrm_scheduled_every_minute_tasks', [], 'fluent-crm');
                return;
            }

            // WP-Cron only takes over when Action Scheduler has not ticked in 70s.
            $lastScheduler = fluentCrmGetOptionCache('_fcrm_last_scheduler');
            if ($lastScheduler && (time() - $lastScheduler) <= 70) {
                return;
            }

            self::process();
        });

        // Sends emails instantly. Handler::isSystemOk() holds the concurrency lock.
        add_action('wp_ajax_nopriv_fluentcrm-post-campaigns-send-now', function () {
            (new \FluentCrm\App\Services\Libs\Mailer\Handler())->handle();

            nocache_headers();
            wp_send_json_success([
                'message'   => 'success',
                'timestamp' => time()
            ]);
        });

        // Multi-threaded emails internal AJAX. MultiThreadHandler::isSystemOk() holds the lock.
        add_action('wp_ajax_nopriv_fluentcrm-post-multi-thread-send-now', function () {
            if (Helper::isExperimentalEnabled('multi_threading_emails')) {
                (new MultiThreadHandler())->handle();
            }

            nocache_headers();
            wp_send_json_success([
                'message'   => 'success',
                'timestamp' => time()
            ]);
        });

        add_action('fluentcrm_scheduled_every_minute_tasks', array(__CLASS__, 'process'));
        add_action('fluentcrm_scheduled_hourly_tasks', array(__CLASS__, 'processHourly'));
        add_action('fluentcrm_scheduled_five_minute_tasks', array(__CLASS__, 'processFiveMinutes'));
        add_action('fluentcrm_process_contact_jobs', array(__CLASS__, 'processForSubscriber'), 999, 1);
        add_action('fluentcrm_scheduled_weekly_tasks', array(__CLASS__, 'processWeekly'));
        add_action('fluent_crm_send_multi_thread_emails', array(__CLASS__, 'processMultiThreadEmails'), 10);

        add_action('fluent_crm_cancel_multi_thread_mailing', function () {
            as_unschedule_all_actions('fluent_crm_send_multi_thread_emails');
            return true;
        });

        // Daily: delete Action Scheduler logs older than 7 days.
        add_action('fluent_crm_ascheduler_runs_daily', function () {
            Cleanup::maybeRemoveOldScheuledActionLogs();
        });

    }

    public static function process()
    {
        wp_raise_memory_limit('admin');

        // In-process re-entrance guard (cheap; complements the cross-process lock below).
        if (did_action('fluentcrm_process_scheduled_tasks_init')) {
            return false;
        }

        // Atomic cross-process mutex against concurrent AS, WP-Cron and AJAX runners.
        if (!self::acquireLock('minute_scheduler', 90)) {
            return false;
        }

        try {
            // Success timestamp read by the stall-detection fallbacks.
            fluentCrmSetOptionCache('_fcrm_last_scheduler', time(), 50);
            do_action('fluentcrm_process_scheduled_tasks_init');

            (new Handler)->handle();

            self::maybeQueueStalledFiveMinuteTasks();
        } finally {
            self::releaseLock('minute_scheduler');
        }

        return true;
    }

    /**
     * Queue one Action Scheduler run of the five-minute tasks when WP-Cron has stopped running them.
     *
     * Scheduled campaigns move into sending only from the five-minute tasks, which are a WP-Cron
     * event. Where WP-Cron rarely fires, a scheduled campaign waited until someone opened its screen.
     * The every-minute task runs on Action Scheduler, so it hands the five-minute hook to Action
     * Scheduler as a separate async action once the last run is 10 minutes old. That keeps a long
     * queue build out of the every-minute lock; processFiveMinutes() has its own lock and throttle.
     *
     * @return bool True when an async run was queued.
     */
    public static function maybeQueueStalledFiveMinuteTasks()
    {
        if (!function_exists('as_enqueue_async_action')) {
            return false;
        }

        $lastRun = (int)fluentCrmGetOptionCache('_fcrm_last_five_minutes_run', 30);
        if ($lastRun && (time() - $lastRun) < 600) {
            return false;
        }

        $hook = 'fluentcrm_scheduled_five_minute_tasks';
        if (as_has_scheduled_action($hook, [], 'fluent-crm')) {
            return false;
        }

        as_enqueue_async_action($hook, [], 'fluent-crm');

        return true;
    }

    /**
     * Browser-ping fallback for the every-minute task.
     * Runs only when Action Scheduler has not ticked in 70s; process() holds the lock.
     *
     * @return bool True if it took over and ran the minute task, false otherwise.
     */
    public static function maybeProcessFromBrowserPing()
    {
        $lastScheduler = fluentCrmGetOptionCache('_fcrm_last_scheduler');
        if ($lastScheduler && (time() - $lastScheduler) <= 70) {
            return false;
        }

        return self::process();
    }

    public static function processForSubscriber($subscriber)
    {
        if (!is_object($subscriber) || empty($subscriber->id)) {
            return false;
        }

        if (!defined('FLUENTCRM_DOING_BULK_IMPORT')) {
            // @todo: Implement this immediately
            (new Handler)->processSubscriberEmail($subscriber->id);
        }

        return true;
    }

    public static function processHourly()
    {
        // Atomic mutex, so fluent_crm/campaign_archived fires once per campaign.
        if (!self::acquireLock('hourly_scheduler', 300)) {
            return;
        }

        try {
            self::markArchiveCampaigns();
            self::maybeCleanupCsvFiles();
            do_action('fluent_crm_process_automation');
        } finally {
            self::releaseLock('hourly_scheduler');
        }
    }


    public static function markArchiveCampaigns()
    {
        // get the scheduled or working  campaigns where scheduled_at is five minutes ago
        $campaigns = Campaign::whereIn('status', ['working', 'scheduled'])
            ->whereDoesntHave('emails', function ($query) {
                $query->whereIn('status', ['scheduling', 'pending', 'scheduled', 'processing', 'draft']);
                return $query;
            })
            ->withoutGlobalScope('type')
            ->whereIn('type', fluentCrmAutoProcessCampaignTypes())
            ->where('scheduled_at', '<', gmdate('Y-m-d H:i:s', current_time('timestamp') - 300))
            ->get();

        if (!$campaigns->isEmpty()) {

            Campaign::whereIn('id', array_unique($campaigns->pluck('id')->toArray()))
                ->withoutGlobalScope('type')
                ->update([
                    'status' => 'archived'
                ]);

            foreach ($campaigns as $campaign) {
                do_action('fluent_crm/campaign_archived', $campaign);
            }

            return true;
        }

        return false;
    }

    /**
     * @return void
     */
    public static function processWeekly()
    {
        (new Maintenance())->maybeProcessData();

        // Clear email_body from sent, cancelled and failed rows to reclaim disk space.
        // Walks a primary-key cursor with a non-locking SELECT, then a PK-targeted UPDATE.
        try {
            global $wpdb;
            $table         = $wpdb->prefix . 'fc_campaign_emails';
            $chunkSize     = 2000;
            $maxIterations = 2500; // safety cap — up to ~5M dirty rows per weekly tick
            $cursor        = 0;

            for ($i = 0; $i < $maxIterations; $i++) {
                $ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE id > %d AND status IN ('sent', 'cancelled', 'failed') AND email_body != '' ORDER BY id ASC LIMIT %d",
                    $cursor, $chunkSize
                ));

                if (!$ids) {
                    break;
                }

                $idList = implode(',', array_map('intval', $ids));
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids are intval()'d above
                $wpdb->query("UPDATE {$table} SET email_body = '' WHERE id IN ({$idList})");

                $cursor = (int) end($ids);

                if (count($ids) < $chunkSize || fluentCrmIsMemoryExceeded()) {
                    break;
                }
            }
        } catch (\Exception $e) {
            Helper::debugLog('processWeekly', 'email_body cleanup deferred: ' . $e->getMessage(), 'extended');
        }
    }

    /**
     * Discover and process pending campaigns. Called by cron/Action Scheduler.
     *
     * @return bool
     */
    public static function processFiveMinutes()
    {
        wp_raise_memory_limit('admin');

        // Non-atomic throttle before the lock.
        $lastRun = fluentCrmGetOptionCache('_fcrm_last_five_minutes_run', 30);
        if ($lastRun && (time() - $lastRun) < 60) {
            return false;
        }

        // Atomic mutex.
        if (!self::acquireLock('five_minute_scheduler', 180)) {
            return false;
        }

        try {
            fluentCrmSetOptionCache('_fcrm_last_five_minutes_run', time(), 60);

            self::resetStaleProcessingEmails(100, 'processFiveMinutes');

            // Run automations on every tick so they do not starve during a large send.
            do_action('fluent_crm_process_automation');

            $cutOutTime = gmdate('Y-m-d H:i:s', current_time('timestamp') + 360);

            $campaigns = Campaign::whereIn('status', ['pending-scheduled', 'processing'])
                ->withoutGlobalScope('type')
                ->whereIn('type', fluentCrmAutoProcessCampaignTypes())
                ->orderBy('scheduled_at', 'ASC')
                ->where('scheduled_at', '<=', $cutOutTime)
                ->limit(2)
                ->get();

            if ($campaigns->isEmpty()) {
                // Idle tick: run the hourly tasks. Listeners must be idempotent;
                // processHourly() holds its own lock.
                do_action('fluentcrm_scheduled_hourly_tasks');
                return false;
            }

            $firstCampaign = $campaigns->first();

            if ($firstCampaign->status == 'pending-scheduled') {
                $firstCampaign->status = 'processing';
                $firstCampaign->save();
            }

            $result = self::processCampaignById($firstCampaign->id);

            // If first campaign is done and there are more queued, chain the next one.
            // Skip if memory is low (aborted) to avoid cascading failures.
            if (!$result && count($campaigns) > 1 && !fluentCrmIsMemoryExceeded()) {
                // Verify first campaign actually finished (not just aborted)
                $firstCampaign = Campaign::withoutGlobalScope('type')->find($firstCampaign->id);
                if ($firstCampaign && $firstCampaign->status != 'processing') {
                    $nextCampaign = $campaigns->last();
                    if ($nextCampaign->status == 'pending-scheduled') {
                        $nextCampaign->status = 'processing';
                        $nextCampaign->save();
                    }
                    self::fireCampaignProcessingChain($nextCampaign->id);
                }
            }

            return $result;
        } finally {
            self::releaseLock('five_minute_scheduler');
        }
    }

    /**
     * Reset rows stuck in 'processing' back to 'pending' so they get re-claimed.
     * Drains in bounded PK chunks without ORDER BY, so MySQL keeps the status index
     * and rarely deadlocks against the senders' FOR UPDATE claims. A deadlock that
     * still happens is harmless; the remaining rows are reset on the next tick.
     *
     * @param int    $maxAgeSeconds Rows older than this (in 'processing') get reset.
     * @param string $callerContext Used in the deferred-log message.
     * @return int Number of rows reset back to pending.
     */
    public static function resetStaleProcessingEmails($maxAgeSeconds = 100, $callerContext = '')
    {
        try {
            // Do not reset rows while a sender lock is still fresh.
            if (self::hasFreshEmailSenderLock($maxAgeSeconds)) {
                return 0;
            }

            $staleCutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - (int) $maxAgeSeconds);
            $chunkSize   = 200;
            $maxChunks   = 50; // up to 10k rows per call; subsequent calls drain the rest
            $recovered   = 0;

            for ($i = 0; $i < $maxChunks; $i++) {
                $staleIds = CampaignEmail::where('status', 'processing')
                    ->where('updated_at', '<', $staleCutoff)
                    ->limit($chunkSize)
                    ->pluck('id')
                    ->toArray();

                if (empty($staleIds)) {
                    break;
                }

                $updated = CampaignEmail::whereIn('id', $staleIds)
                    ->where('status', 'processing')
                    ->update([
                        'status' => 'pending'
                    ]);

                if ($updated === false) {
                    global $wpdb;
                    Helper::debugLog($callerContext ?: 'resetStaleProcessingEmails', 'Stale email reset deferred: ' . $wpdb->last_error, 'extended');
                    break;
                }

                $recovered += (int) $updated;

                if (count($staleIds) < $chunkSize || fluentCrmIsMemoryExceeded()) {
                    break;
                }
            }

            if ($recovered) {
                Helper::debugLog($callerContext ?: 'resetStaleProcessingEmails', 'Recovered ' . $recovered . ' stale processing emails older than ' . (int) $maxAgeSeconds . ' seconds', 'extended');
            }

            return $recovered;
        } catch (\Exception $e) {
            Helper::debugLog($callerContext ?: 'resetStaleProcessingEmails', 'Stale email reset deferred: ' . $e->getMessage(), 'extended');
            return 0;
        }
    }

    /**
     * Whether any sender lock (regular, multi-threaded or CLI) is still fresh.
     *
     * @param int $maxAgeSeconds
     * @return bool
     */
    private static function hasFreshEmailSenderLock($maxAgeSeconds)
    {
        $freshWindow = max(60, (int) $maxAgeSeconds);

        $now = time();

        global $wpdb;

        // Every sender lock, including custom --option_key CLI workers, is a fluentcrm_is_sending_* option.
        // Read from the table directly; the DB lock never writes to the object cache.
        $lockValues = $wpdb->get_col(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'fluentcrm\\_is\\_sending\\_%'"
        );

        foreach ((array) $lockValues as $lockedAt) {
            if ($lockedAt && ($now - (int) $lockedAt) <= $freshWindow) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process a specific campaign by ID. Called directly by the AJAX chain.
     *
     * @param int $campaignId
     * @return bool True if more processing is needed, false if done.
     */
    public static function processCampaignById($campaignId)
    {
        // Per-campaign lock: the AJAX chain bypasses processFiveMinutes' lock.
        // TTL matches the set_time_limit(120) below.
        $lockName = 'campaign_chain_' . (int)$campaignId;
        if (!self::acquireLock($lockName, 120)) {
            return false;
        }

        // Decide the continuation inside the lock, fire it after release:
        // fireCampaignProcessingChain re-enters this method and needs the same lock.
        $continuation = '';

        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }

            $campaign = Campaign::withoutGlobalScope('type')->find($campaignId);
            if (!$campaign) {
                return false;
            }

            $campaignProcessingChunk = (int)apply_filters('fluent_crm/five_minute_campaign_processing_chunk', 50, $campaign);
            if ($campaignProcessingChunk < 1) {
                $campaignProcessingChunk = 1;
            }

            $runTime = fluentCrmMaxRunTime() - 5;
            $campaign = (new CampaignProcessor($campaignId))->processEmails($campaignProcessingChunk, $runTime);

            if (fluentCrmIsMemoryExceeded()) {
                return false;
            }

            if ($campaign && $campaign->status == 'processing') {
                $continuation = 'chain';
            } elseif ($campaign && $campaign->status == 'scheduled' && self::isCampaignDue($campaign)) {
                // Materialization just finished, so kick a send now instead of waiting for the next tick.
                // A cancelled campaign lands on 'draft' and is skipped.
                $continuation = 'send';
            }
        } finally {
            self::releaseLock($lockName);
        }

        if ($continuation == 'chain') {
            self::fireCampaignProcessingChain($campaignId);
            return true;
        }

        if ($continuation == 'send') {
            self::fireSendNowRequest();
        }

        return false;
    }

    /**
     * Whether a campaign's materialized rows are claimable by a sender yet.
     * Future-dated campaigns are materialized up to ~6 minutes ahead.
     *
     * @param \FluentCrm\App\Models\Campaign $campaign
     * @return bool
     */
    private static function isCampaignDue($campaign)
    {
        if (!$campaign->scheduled_at) {
            return true;
        }

        return strtotime($campaign->scheduled_at) <= strtotime(current_time('mysql'));
    }

    /**
     * Fire a non-blocking AJAX request that starts an email sending cycle now.
     * Safe to call speculatively; the receiver takes the lock in isSystemOk().
     */
    public static function fireSendNowRequest()
    {
        $url = add_query_arg([
            'action' => 'fluentcrm-post-campaigns-send-now',
            'time'   => time()
        ], admin_url('admin-ajax.php'));

        Handler::fireNonBlockingRequest($url, [
            'campaign_id' => null,
            'retry'       => 1
        ]);
    }

    /**
     * Fire a background AJAX request to continue processing a specific campaign.
     *
     * @param int $campaignId
     */
    private static function fireCampaignProcessingChain($campaignId)
    {
        $url = add_query_arg([
            'action'      => 'fluentcrm-post-campaigns-emails-processing',
            'campaign_id' => $campaignId,
            'time'        => time()
        ], admin_url('admin-ajax.php'));

        \FluentCrm\App\Services\Libs\Mailer\Handler::fireNonBlockingRequest($url, [
            'retry' => 1
        ]);
    }

    public static function maybeCleanupCsvFiles()
    {
        $dir = FileSystem::getDir();

        // loop through files in directory
        foreach (glob($dir . '/fluentcrm-*.csv') as $filename) {
            // check if file was created before last 30 minutes
            if (time() - filectime($filename) >= 1800) {
                wp_delete_file($filename); // delete file
            }
        }
    }

    public static function processMultiThreadEmails()
    {
        (new MultiThreadHandler())->handle();
        return true;
    }

    /**
     * Atomically claim a scheduler-level lock via Helper::acquireDbLock().
     * A crashed runner's lock expires after $ttl.
     *
     * @param string $name Lock identifier appended to the option key.
     * @param int    $ttl  Seconds before a held lock is considered abandoned.
     * @return bool True if the lock was acquired by this process.
     */
    private static function acquireLock($name, $ttl)
    {
        return Helper::acquireDbLock('_fluentcrm_lock_' . $name, $ttl);
    }

    /**
     * Release a scheduler-level lock previously acquired by acquireLock().
     */
    private static function releaseLock($name)
    {
        Helper::releaseDbLock('_fluentcrm_lock_' . $name);
    }
}
