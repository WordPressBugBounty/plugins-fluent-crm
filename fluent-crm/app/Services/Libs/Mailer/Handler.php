<?php

namespace FluentCrm\App\Services\Libs\Mailer;

use FluentCrm\App\Hooks\Handlers\Scheduler;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Support\Collection;

class Handler extends BaseHandler
{
    protected $runnerTitle = 'Handler::handle';

    protected $sendingPerChunk = 20;

    protected $maximumProcessingTime = 50;

    protected $optionKey = 'fluentcrm_is_sending_emails';

    public function __construct()
    {
        /**
         * The default mailer chunk size for the main email handler.
         *
         * @param int $sendingPerChunk Number of campaign emails pulled per batch. Default is 20.
         * @return int
         */
        $sendingPerChunk = (int)apply_filters('fluent_crm/mailer_handler_chunk_size', $this->sendingPerChunk);
        if ($sendingPerChunk > 0) {
            $this->sendingPerChunk = $sendingPerChunk;
        }

        /**
         * The maximum processing window (seconds) for the main email handler.
         *
         * @param int $maximumProcessingTime Max loop runtime in seconds. Default is 50.
         * @return int
         */
        $maximumProcessingTime = (int)apply_filters('fluent_crm/mailer_handler_max_processing_seconds', $this->maximumProcessingTime);
        if ($maximumProcessingTime > 0) {
            $this->maximumProcessingTime = $maximumProcessingTime;
        }
    }

    public function handle()
    {
        if (!$this->isSystemOk()) {
            return true; // Early return
        }

        Helper::debugLog('Running Scheduler -> ' . $this->calledFrom, 'Handler::handle');

        Helper::maybeDisableEmojiOnEmail();

        try {
            $this->handleFailedLog();
            $result = $this->processBatchEmails();

            if (is_wp_error($result)) {
                Helper::debugLog('Error at Mailer::handle', $result->get_error_message(), 'error');
                $this->releaseLock();
                $this->logSentCount();
                return true;
            }

            if ($result === 'time_up') {
                $this->releaseLock();
                $this->callBackGround();
                $this->logSentCount();
                return true;
            }
        } catch (\Throwable $e) {
            // Catch \Throwable so a third-party TypeError still reaches releaseLock().
            Helper::debugLog('Exception at Mailer::handle', $e->getMessage(), 'error');
        }

        $this->releaseLock();
        $this->logSentCount();

        if ($this->sentCount || random_int(0, 50) > 20) { // sometimes we want to check this
            $lastChecked = fluentCrmGetOptionCache('_fcrm_last_email_process_cleanup', 600);
            if (!$lastChecked || time() - $lastChecked > 70) {
                // Stale-row recovery goes through the scheduler helper; a direct UPDATE can deadlock with a chained sender.
                Scheduler::resetStaleProcessingEmails($this->maximumProcessingTime + 30, $this->runnerTitle);
                fluentCrmSetOptionCache('_fcrm_last_email_process_cleanup', time(), 600);
            }
        }

        if (!$this->sentCount) {
            do_action('fluentcrm_scheduled_maybe_regular_tasks');
            do_action('fluent_crm_process_automation');
        }

        return true;
    }

    private function isSystemOk()
    {
        $this->calledFrom = Arr::get($_REQUEST, 'action') == 'fluentcrm-post-campaigns-send-now' ? 'ajax' : 'cron';

        if (did_action('fluent_crm/sending_emails_starting') || apply_filters('fluent_crm/disable_email_processing', false)) {
            return false;
        }

        if ($this->memoryExceeded()) {
            Helper::debugLog('Mailer Memory Exceeded at ' . $this->runnerTitle, 'Memory Limit: ' . fluentCrmGetMemoryLimit() . '<br />Current Usage: ' . memory_get_usage(true));
            return false;
        }

        // max_execution_time (often 30s on the web) can kill the process before isTimeUp() stops it.
        if (function_exists('set_time_limit')) {
            @set_time_limit($this->maximumProcessingTime + 30);
        }

        $systemMaxProcessingTime = fluentCrmMaxRunTime();

        if ($this->maximumProcessingTime > $systemMaxProcessingTime) {
            $this->maximumProcessingTime = $systemMaxProcessingTime;
        }

        // The atomic lock is the only guard against concurrent senders; take it before any expensive work.
        if (!$this->acquireLock()) {
            return false;
        }

        // handle() calls this outside its try/catch, so release the held lock on any error.
        try {
            // callBackGround() reads this to keep the loopback continuation alive.
            fluentcrm_update_option($this->optionKey . '_last_called', time());

            $this->startingTimeStamp = time();
            $this->isMultiThread = Helper::willMultiThreadEmail();

            if ($this->isMultiThread) {
                if (!as_has_scheduled_action('fluent_crm_send_multi_thread_emails', [], 'fluent-crm')) {
                    Helper::debugLog('Scheduling multi thread emails', 'extended log');
                    as_schedule_recurring_action(time(), 60, 'fluent_crm_send_multi_thread_emails', [], 'fluent-crm', false);
                }
            }
        } catch (\Throwable $e) {
            $this->releaseLock();
            Helper::debugLog('isSystemOk post-lock failure at ' . $this->runnerTitle, $e->getMessage(), 'error');
            return false;
        }

        return true;
    }

    protected function getNextBatchEmails()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_campaign_emails';
        $currentTime = current_time('mysql');

        // Atomic claim: lock ids, then flip them to 'processing' in one transaction.
        $wpdb->query('START TRANSACTION');

        // One locking SELECT per status, 'pending' first. See lockClaimableIds().
        $ids = $this->lockClaimableIds('pending', $currentTime, $this->sendingPerChunk, 'ASC');
        if (count($ids) < $this->sendingPerChunk) {
            $ids = array_merge($ids, $this->lockClaimableIds('scheduled', $currentTime, $this->sendingPerChunk - count($ids), 'ASC'));
        }

        if ($ids) {
            $idsPlaceholder = implode(',', array_fill(0, count($ids), '%d'));
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id IN ($idsPlaceholder) AND status IN ('pending', 'scheduled')",
                array_merge([$currentTime], $ids)
            ));

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                return new Collection([]);
            }
        }

        $wpdb->query('COMMIT');

        if (!$ids) {
            return new Collection([]);
        }

        // Only return rows we actually claimed (status = processing)
        return CampaignEmail::whereIn('id', $ids)
            ->where('status', 'processing')
            ->with(['campaign', 'subscriber'])
            ->get();
    }

    public function processSubscriberEmail($subscriberId)
    {
        if (!$this->isSystemOk()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fc_campaign_emails';
        $currentTime = current_time('mysql');

        $wpdb->query('START TRANSACTION');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status IN ('pending', 'scheduled') AND scheduled_at <= %s AND scheduled_at IS NOT NULL AND subscriber_id = %d FOR UPDATE",
            $currentTime, $subscriberId
        ));

        $ids = wp_list_pluck($rows, 'id');

        if ($ids) {
            $idsPlaceholder = implode(',', array_fill(0, count($ids), '%d'));
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id IN ($idsPlaceholder) AND status IN ('pending', 'scheduled')",
                array_merge([$currentTime], $ids)
            ));

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                $this->releaseLock();
                return;
            }
        }

        $wpdb->query('COMMIT');

        if ($ids) {
            $emailCollection = CampaignEmail::whereIn('id', $ids)
                ->where('status', 'processing')
                ->with('campaign', 'subscriber')
                ->get();

            $this->sendEmails($emailCollection);
        }

        $this->releaseLock();
    }

    public function sendDoubleOptInEmail($subscriber)
    {
        if ($subscriber->status == 'subscribed' || !$subscriber->email) {
            return false; // already confirmed: nothing left to opt in to
        }

        $listIdOfSubscriber = Helper::latestListIdOfSubscriber($subscriber->id);
        $config = null;
        if ($listIdOfSubscriber) {
            $globalDoubleOptin = fluentcrm_get_list_meta($listIdOfSubscriber, 'global_double_optin');
            if ($globalDoubleOptin && $globalDoubleOptin->value == 'no') {
                $meta = fluentcrm_get_meta($listIdOfSubscriber, 'FluentCrm\App\Models\Lists', 'double_optin_settings');
                $config = $meta ? $meta->value : null;
            }
        }

        if (!$config) {
            $config = Helper::getDoubleOptinSettings();
        }

        if (!Arr::get($config, 'email_subject') || !Arr::get($config, 'email_body')) {
            return false; // is not valid
        }

        $renderedEmail = DoubleOptinRenderer::render($config, $subscriber);

        $data = [
            'to'      => [
                'email' => $subscriber->email,
                'name'  => $subscriber->full_name
            ],
            'subject' => $renderedEmail['subject'],
            'body'    => $renderedEmail['body'],
            'headers' => Helper::getMailHeader(),
            'scope'   => 'double_optin'
        ];

        Helper::maybeDisableEmojiOnEmail();
        Mailer::send($data, $subscriber, null, true); // want to send without any rate-limiting checking
        return true;
    }

    private function callBackGround()
    {
        if ($this->memoryExceeded()) {
            Helper::debugLog('Handler::callBackGround Memory Exceeded', 'Memory Limit: ' . fluentCrmGetMemoryLimit() . '<br />Current Usage: ' . memory_get_usage(true), 'info');
            return false;
        }

        $nextCron = as_next_scheduled_action('fluentcrm_scheduled_every_minute_tasks');
        $willRun = !$nextCron || $nextCron == 1 || ($nextCron - time()) >= 5 || ($nextCron - time()) < -70;

        if (!$willRun) {
            $lastCalled = (int)fluentcrm_get_option($this->optionKey . '_last_called');
            if ($lastCalled && (time() - $lastCalled) < 50) {
                $willRun = true;
            }
        }

        if ($willRun) {

            $url = add_query_arg([
                'action' => 'fluentcrm-post-campaigns-send-now',
                'time'   => time()
            ], admin_url('admin-ajax.php'));

            Helper::debugLog('Sent to Background Handler::callBackGround', $url, 'extended');

            self::fireNonBlockingRequest($url, [
                'campaign_id' => null,
                'retry'       => 1
            ]);
        } else {
            Helper::debugLog('Not Running', 'Handler::callBackGround -> ' . ($nextCron - time()), 'extended');
        }
    }

    protected function isTimeUp()
    {
        return (time() - $this->startingTimeStamp) >= $this->maximumProcessingTime;
    }

    /**
     * Fire a non-blocking POST request to continue the sender chain.
     * cURL goes first because it bypasses WP_Http SSL filters that break self-signed loopbacks.
     *
     * @param string $url
     * @param array  $body POST body data
     */
    public static function fireNonBlockingRequest($url, $body = [])
    {
        // Test seam: allows suites to observe continuation intent without HTTP.
        if (apply_filters('fluent_crm/intercept_loopback', false, $url, $body)) {
            return;
        }

        $timeout = max(1, (int)apply_filters('fluent_crm/non_blocking_request_timeout', 3, $url, $body));
        $connectTimeout = max(1, (int)apply_filters('fluent_crm/non_blocking_request_connect_timeout', 2, $url, $body));

        if (apply_filters('fluent_crm/non_blocking_request_use_wp_http', false, $url, $body)) {
            self::fireNonBlockingWpRequest($url, $body, $timeout);
            return;
        }

        if (!function_exists('curl_init')) {
            self::fireNonBlockingWpRequest($url, $body, $timeout);
            return;
        }

        $ch = curl_init($url);
        if (!$ch) {
            Helper::debugLog('FluentCRM non-blocking cURL request failed', 'Unable to initialize cURL. URL: ' . esc_url_raw($url), 'extended');
            self::fireNonBlockingWpRequest($url, $body, $timeout);
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        $errorNo = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if (!$errorNo && $response !== false) {
            return;
        }

        $errorMessage = $errorNo ? ('Error #' . $errorNo . ': ' . $error) : 'Unknown cURL failure';
        Helper::debugLog('FluentCRM non-blocking cURL request failed', $errorMessage . ' URL: ' . esc_url_raw($url), 'extended');

        self::fireNonBlockingWpRequest($url, $body, $timeout);
    }

    /**
     * Fire the sender-chain request via WordPress HTTP as a fallback transport.
     *
     * @param string $url
     * @param array  $body
     * @param int    $timeout
     */
    private static function fireNonBlockingWpRequest($url, $body, $timeout)
    {
        add_filter('https_local_ssl_verify', '__return_false');
        $response = wp_remote_post($url, [
            'sslverify' => false,
            'blocking'  => false,
            'timeout'   => $timeout,
            'body'      => $body
        ]);
        remove_filter('https_local_ssl_verify', '__return_false');

        if (is_wp_error($response)) {
            Helper::debugLog('FluentCRM non-blocking WP HTTP request failed', $response->get_error_message() . ' URL: ' . esc_url_raw($url), 'extended');
        }
    }
}
