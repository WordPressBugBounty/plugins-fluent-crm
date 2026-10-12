<?php


namespace FluentCrm\App\Services\Libs\Mailer;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

abstract class BaseHandler
{
    protected $runnerTitle = '';

    protected $sentCount = 0;

    protected $maximumProcessingTime = 50;

    protected $calledFrom = 'cron';

    protected $startingTimeStamp = null;

    protected $optionKey = 'fluentcrm_is_sending_emails';

    protected $isMultiThread = false;

    protected $sendingChunkNumber = 0;

    protected $lastLockRefreshAt = 0;

    /**
     * The fc_campaign_emails id currently inside Mailer::send(), or null when no CRM send is in flight.
     */
    protected $currentSendingEmailId = null;

    protected $currentSendingEmailAddress = null;

    abstract protected function isTimeUp();

    protected function sendEmails($campaignEmails)
    {
        global $wpdb;
        do_action('fluent_crm/sending_emails_starting', $campaignEmails);

        if (defined('FLUENTMAIL')) {
            add_filter('fluentmail_will_log_email', 'fluentcrm_maybe_disable_fsmtp_log', 10, 2);
        }

        $failedIds = [];

        $this->sendingChunkNumber++;

        $sendableStatuses = ['subscribed', 'transactional'];
        $table = $wpdb->prefix . 'fc_campaign_emails';

        // Prime managed hashes so the batch resolves them in one query.
        $batchContactIds = [];
        foreach ($campaignEmails as $email) {
            if ($email->subscriber_id) {
                $batchContactIds[] = $email->subscriber_id;
            }
        }
        Helper::primeManagedHashes($batchContactIds);

        $reachedCount = 0;

        try {
            foreach ($campaignEmails as $email) {
                // Claimed rows the loop never reaches go back to 'pending'.
                if ($this->isTimeUp()) {
                    $this->releaseUnreachedClaims($campaignEmails, $reachedCount);
                    break;
                }

                $reachedCount++;

                if ($email->subscriber && !in_array($email->subscriber->status, $sendableStatuses, true)) {
                    $email->status = 'cancelled';
                    // Funnel and sequence rows store a parsed body; clear it on cancel.
                    $email->email_body = '';
                    $email->save();
                    continue;
                }

                // Duplicate guard runs before the expensive data() render.
                if (Helper::wasProcessedByKeyId('mail_' . $email->id . '_' . $email->email_address)) {
                    continue;
                }

                $emailData = $email->data();

                // Throttle before mark-sent so a crash mid-wait leaves the row recoverable in 'processing'.
                $this->maybeRefreshLock();
                GlobalRateLimiter::throttle($emailData);

                // Mark sent before sending: a crash may lose one email but never duplicates it.
                // The updated_at claim token makes the UPDATE miss if another sender re-claimed the row.
                // Use the raw attribute: $wpdb binds the model's DateTime cast as an empty string.
                $claimToken = Arr::get($email->getAttributes(), 'updated_at');
                $claimed = $wpdb->update($table, [
                    'status'       => 'sent',
                    'scheduled_at' => current_time('mysql'),
                    'email_body'   => '',
                    'is_parsed'    => 1,
                ], ['id' => $email->id, 'status' => 'processing', 'updated_at' => $claimToken]);

                if ($claimed === false) {
                    Helper::debugLog('DB Error at ' . $this->runnerTitle, $wpdb->last_error, 'error');
                    return new \WP_Error('db_error', $wpdb->last_error ?: 'mark-sent update failed');
                }

                if ($claimed === 0) {
                    // Another sender re-claimed this row.
                    continue;
                }

                $this->sentCount++;

                // Already throttled above, so Mailer skips its own rate-limit reservation.
                $this->currentSendingEmailId = $email->id;
                $this->currentSendingEmailAddress = $email->email_address;
                try {
                    $response = Mailer::send($emailData, $email->subscriber, $email, true);
                } finally {
                    $this->currentSendingEmailId = null;
                    $this->currentSendingEmailAddress = null;
                }

                // wp_mail() usually returns false on failure rather than WP_Error.
                if (is_wp_error($response) || $response === false) {
                    $failedIds[] = $email->id;
                }
            }
        } finally {
            // Unused primed ids must not leak into later lookups in this process.
            Helper::clearPendingManagedHashes();
        }

        $this->updateEmailsStatus($failedIds, 'failed');

        if (defined('FLUENTMAIL')) {
            remove_filter('fluentmail_will_log_email', 'fluentcrm_maybe_disable_fsmtp_log', 10);
        }

        do_action('fluentcrm_sending_emails_done', $campaignEmails);

        return true;
    }

    protected function processBatchEmails()
    {
        if ($this->isTimeUp()) {
            return 'time_up';
        }

        $emails = $this->getNextBatchEmails();

        if (!$emails || $emails->isEmpty()) {
            return 'empty';
        }

        $this->refreshLock();
        $result = $this->sendEmails($emails);

        if (is_wp_error($result)) {
            return $result;
        }

        usleep(10000); // 0.01 seconds sleep

        return $this->processBatchEmails();
    }

    abstract protected function getNextBatchEmails();

    /**
     * Lock and return up to $limit due queue ids for one status, optionally in an id % modulo partition.
     * One status per call keeps the locking read on a single index range; an IN() filesorts and locks the whole backlog.
     * Callers claim 'pending' before 'scheduled' everywhere, inside their open transaction.
     *
     * @param string $status    Single queue status: 'pending' or 'scheduled'.
     * @param string $until     Due cutoff (MySQL datetime, WP timezone).
     * @param int    $limit     Maximum ids to lock.
     * @param string $order     'ASC' (oldest first) or 'DESC' (newest first).
     * @param int    $modulo    Id-partition size; 1 = no partition clause.
     * @param int    $remainder Partition slot: rows where id % modulo = remainder.
     * @return array Locked ids.
     */
    protected function lockClaimableIds($status, $until, $limit, $order = 'ASC', $modulo = 1, $remainder = 0)
    {
        global $wpdb;

        if ($limit <= 0) {
            return [];
        }

        $table = $wpdb->prefix . 'fc_campaign_emails';
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        $sql = "SELECT id FROM {$table} WHERE status = %s AND scheduled_at <= %s";
        $params = [$status, $until];

        if ($modulo > 1) {
            $sql .= " AND (id %% %d) = %d";
            $params[] = $modulo;
            $params[] = $remainder;
        }

        $sql .= " ORDER BY scheduled_at {$order} LIMIT %d FOR UPDATE";
        $params[] = $limit;

        return wp_list_pluck($wpdb->get_results($wpdb->prepare($sql, $params)), 'id');
    }

    protected function logSentCount()
    {
        if ($this->sentCount) {
            Helper::debugLog(sprintf($this->runnerTitle . ': Sent %d', $this->sentCount), sprintf('%d seconds via %s', time() - $this->startingTimeStamp, $this->calledFrom));
        }
    }

    /**
     * Whether the process has used 70% of the memory limit.
     *
     * @return bool
     */
    protected function memoryExceeded()
    {
        $memory_limit = fluentCrmGetMemoryLimit() * 0.70;
        $current_memory = memory_get_usage(true);

        $memory_exceeded = $current_memory >= $memory_limit;

        return apply_filters('fluentcrm_memory_exceeded', $memory_exceeded, $this);
    }

    /**
     * Hand claimed-but-unreached rows back to 'pending' on a time-up break, guarded by the claim token.
     *
     * @param \FluentCrm\Framework\Support\Collection|array $campaignEmails The full claimed batch.
     * @param int $reachedCount How many rows the send loop actually entered.
     */
    protected function releaseUnreachedClaims($campaignEmails, $reachedCount)
    {
        global $wpdb;

        $idsByToken = [];
        $index = 0;
        foreach ($campaignEmails as $email) {
            if ($index++ < $reachedCount) {
                continue;
            }
            // Raw attribute, not the DateTime cast.
            $token = Arr::get($email->getAttributes(), 'updated_at');
            if (!$token) {
                Helper::debugLog('releaseUnreachedClaims', 'missing claim token for row ' . $email->id, 'extended');
                continue;
            }
            $idsByToken[(string)$token][] = (int)$email->id;
        }

        if (!$idsByToken) {
            return;
        }

        $table = $wpdb->prefix . 'fc_campaign_emails';

        foreach ($idsByToken as $token => $ids) {
            $idsPlaceholder = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'pending' WHERE id IN ($idsPlaceholder) AND status = 'processing' AND updated_at = %s",
                array_merge($ids, [$token])
            ));
        }
    }

    protected function updateEmailsStatus($ids, $status)
    {
        if (!$ids) {
            return false;
        }

        global $wpdb;
        $whereIn = implode(',', array_fill(0, count($ids), '%d'));
        $query = "UPDATE {$wpdb->prefix}fc_campaign_emails SET status = %s WHERE id IN ($whereIn)";
        $wpdb->query($wpdb->prepare($query, array_merge([$status], $ids)));

        return true;
    }

    protected function handleFailedLog()
    {
        add_action('wp_mail_failed', function ($error) {
            // Only handle failures from this handler's in-flight send.
            if (!$this->currentSendingEmailId) {
                return;
            }

            // Recipients are a veto only: a named recipient list without our address means another plugin's mail failed.
            if ($this->currentSendingEmailAddress && is_wp_error($error)) {
                $errorData = (array)$error->get_error_data();

                if (!empty($errorData['to'])) {
                    $sawRecipient = false;
                    $isOurs = false;

                    foreach ((array)$errorData['to'] as $recipient) {
                        if (!is_string($recipient) || $recipient === '') {
                            continue;
                        }
                        $sawRecipient = true;
                        // Substring match handles "Name <a@b.c>" and comma-joined recipients.
                        if (stripos($recipient, $this->currentSendingEmailAddress) !== false) {
                            $isOurs = true;
                            break;
                        }
                    }

                    if ($sawRecipient && !$isOurs) {
                        return;
                    }
                }
            }

            // Bumps updated_at; safe while 'failed' rows are never claimable.
            CampaignEmail::where('id', $this->currentSendingEmailId)
                ->update([
                    'status' => 'failed',
                    'note'   => $error->get_error_message()
                ]);
        });
    }


    /**
     * Atomically acquire the processing lock.
     *
     * @return bool True if the lock was acquired, false if another process holds it.
     */
    protected function acquireLock()
    {
        // DB lock only: some object-cache drop-ins (LiteSpeed) implement wp_cache_add() non-atomically.
        $lockTimeout = $this->maximumProcessingTime + 30;

        $acquired = Helper::acquireDbLock($this->optionKey, $lockTimeout);

        if ($acquired) {
            /**
             * A bulk sending session is starting: one lock-winning sender run,
             * up to ~50s spanning many claimed batches. Transport plugins can
             * hold a reusable connection open for this window — FluentSMTP
             * uses it for SMTP keep-alive and HTTP connection reuse — and must
             * clean up on the matching session_ended action.
             *
             * @param string $optionKey The sender lock key (identifies the cron,
             *                          multi-thread, or CLI sender).
             */
            do_action('fluent_crm/email_sender_session_started', $this->optionKey);
        }

        return $acquired;
    }

    /**
     * Refresh the lock timestamp (heartbeat) to prevent stuck-lock detection.
     */
    protected function refreshLock()
    {
        Helper::refreshDbLock($this->optionKey);
        $this->lastLockRefreshAt = microtime(true);
    }

    /**
     * Heartbeat the lock only when it is close to its TTL; otherwise an in-memory check.
     */
    protected function maybeRefreshLock()
    {
        // TTL/4 gap + 15s max rate-limit wait + one send stays under the lock TTL.
        $interval = max(8, (int)(($this->maximumProcessingTime + 30) / 4));

        if ((microtime(true) - $this->lastLockRefreshAt) >= $interval) {
            $this->refreshLock();
        }
    }

    /**
     * Release the processing lock so another process can acquire it.
     */
    protected function releaseLock()
    {
        Helper::releaseDbLock($this->optionKey);

        /**
         * The bulk sending session ended — transport plugins should close any
         * connection they held open for it. May fire without a matching
         * session_started (releaseLock is safe to call unheld) and can fire
         * more than once per run: listeners must be idempotent.
         *
         * @param string $optionKey The sender lock key.
         */
        do_action('fluent_crm/email_sender_session_ended', $this->optionKey);
    }
}
