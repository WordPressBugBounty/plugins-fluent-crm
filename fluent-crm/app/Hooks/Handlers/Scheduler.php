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
            if (!as_has_scheduled_action('fluentcrm_scheduled_every_minute_tasks', [], 'fluent-crm')) {
                Helper::debugLog('Migrating Every Minute CRON to Action Scheduler for FluentCRM');
                as_schedule_recurring_action(time(), 60, 'fluentcrm_scheduled_every_minute_tasks', [], 'fluent-crm');
                return;
            }

            // We already have the action scheduled, but that can be blocked by some other plugin
            $lastScheduler = fluentCrmGetOptionCache('_fcrm_last_scheduler');
            if (($lastScheduler && (time() - $lastScheduler) > 70)) {
                Helper::debugLog('Action scheduler is not working', 'Scheduler::register -> ' . (time() - $lastScheduler));
                self::process();
                return;
            }

            do_action('fluent_crm_process_automation');

            // Looks like action scheduler is working just fine. Maybe we can check for some regular tasks
            // We will run the five minutes tasks for around 60% times
            if (wp_rand(1, 100) > 40) {
                self::processFiveMinutes();
            }

        });

        // This is required to instantly send emails for regular email handler
        add_action('wp_ajax_nopriv_fluentcrm-post-campaigns-send-now', function () {
            if (!get_option('fluentcrm_is_sending_emails')) {
                $nextCron = as_next_scheduled_action('fluentcrm_scheduled_every_minute_tasks');
                $willRun = !$nextCron || $nextCron == 1 || ($nextCron - time()) >= 3 || ($nextCron - time()) < -70;

                if (!$willRun) {
                    $lastCalled = (int)fluentcrm_get_option('fluentcrm_is_sending_emails_last_called');
                    if ($lastCalled && (time() - $lastCalled) < 52) {
                        $willRun = true;
                    }
                }

                if ($willRun) {
                    Helper::debugLog('AJAX: post-campaigns-send-now', 'Timing: ' . ($nextCron - time()), 'extended');
                    $mailer = new \FluentCrm\App\Services\Libs\Mailer\Handler();
                    $mailer->handle();
                }
            }

            nocache_headers();
            wp_send_json_success([
                'message'   => 'success',
                'timestamp' => time()
            ]);
        });

        // For Multi Threaded Emails Internal Ajax
        add_action('wp_ajax_nopriv_fluentcrm-post-multi-thread-send-now', function () {

            if (!get_option('fluentcrm_is_sending_multi_emails')) {
                $nextCron = as_next_scheduled_action('fluent_crm_send_multi_thread_emails');
                $willRun = !$nextCron || $nextCron == 1 || ($nextCron - time()) >= 3 || ($nextCron - time()) < -70;

                if (!$willRun) {
                    $lastCalled = (int)fluentcrm_get_option('fluentcrm_is_sending_multi_emails_last_called');
                    if ($lastCalled && (time() - $lastCalled) < 52) {
                        $willRun = true;
                    }
                }

                if ($willRun) {
                    if (Helper::isExperimentalEnabled('multi_threading_emails')) {
                        (new MultiThreadHandler())->handle();
                    }
                }
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

        /*
         *  Clean up schedule that means removing from database-  tasks by action scheduler
         * Clean up before last 7 days logs generated by action scheduler
         * this action will be triggered daily and will remove all the logs generated before 7 days
         */
        add_action('fluent_crm_ascheduler_runs_daily', function () {
            Cleanup::maybeRemoveOldScheuledActionLogs();
        });

    }

    public static function process()
    {

        wp_raise_memory_limit('admin');
        $lastScheduler = fluentCrmGetOptionCache('_fcrm_last_scheduler');

        if (($lastScheduler && (time() - $lastScheduler) < 30) || did_action('fluentcrm_process_scheduled_tasks_init')) {
            return false; // it's too fast. We don't want to run this again within 30 seconds
        }

        fluentCrmSetOptionCache('_fcrm_last_scheduler', time(), 50);
        do_action('fluentcrm_process_scheduled_tasks_init');

        // Send Pending Emails
        (new Handler)->handle();
        return true;
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
        self::markArchiveCampaigns();
        self::maybeCleanupCsvFiles();
        do_action('fluent_crm_process_automation');
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

        fluentCrmDb()->table('fc_campaign_emails')
            ->where('status', 'sent')
            ->where('email_body', '!=', '')
            ->update([
                'email_body' => ''
            ]);
    }

    /**
     * Discover and process pending campaigns.
     *
     * Called by cron/Action Scheduler. Handles housekeeping (stale email reset),
     * finds campaigns ready to process, and kicks off processing. For continuous
     * processing, use processCampaignById() via the AJAX handler.
     *
     * @return bool
     */
    public static function processFiveMinutes()
    {
        $lastRun = fluentCrmGetOptionCache('_fcrm_last_five_minutes_run', 30);

        if ($lastRun && (time() - $lastRun) < 60) {
            return false;
        }

        fluentCrmSetOptionCache('_fcrm_last_five_minutes_run', time(), 60);

        CampaignEmail::where('status', 'processing')
            ->where('updated_at', '<', gmdate('Y-m-d H:i:s', (current_time('timestamp') - 100)))
            ->update([
                'status' => 'pending'
            ]);

        $cutOutTime = gmdate('Y-m-d H:i:s', current_time('timestamp') + 360);

        $campaigns = Campaign::whereIn('status', ['pending-scheduled', 'processing'])
            ->withoutGlobalScope('type')
            ->whereIn('type', fluentCrmAutoProcessCampaignTypes())
            ->orderBy('scheduled_at', 'ASC')
            ->where('scheduled_at', '<=', $cutOutTime)
            ->limit(2)
            ->get();

        if ($campaigns->isEmpty()) {
            do_action('fluent_crm_process_automation');
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
    }

    /**
     * Process a specific campaign by ID.
     *
     * Can be called directly from the AJAX handler for continuous chaining
     * without re-discovering campaigns or running housekeeping.
     *
     * @param int $campaignId
     * @return bool True if more processing is needed, false if done.
     */
    public static function processCampaignById($campaignId)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $campaign = Campaign::withoutGlobalScope('type')->find($campaignId);
        if (!$campaign) {
            return false;
        }

        $campaignProcessingChunk = (int)apply_filters('fluent_crm/five_minute_campaign_processing_chunk', 20, $campaign);
        if ($campaignProcessingChunk < 1) {
            $campaignProcessingChunk = 1;
        }

        $runTime = fluentCrmMaxRunTime() - 5;
        $campaign = (new CampaignProcessor($campaignId))->processEmails($campaignProcessingChunk, $runTime);

        if (fluentCrmIsMemoryExceeded()) {
            return false;
        }

        if ($campaign && $campaign->status == 'processing') {
            self::fireCampaignProcessingChain($campaignId);
            return true;
        }

        return false;
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
}
