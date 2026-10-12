<?php

namespace FluentCrm\App\Services;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;

class CampaignProcessor
{
    protected $campaignId = false;

    protected $initialStatus = 'scheduling';

    /**
     * Per-acquire owner token so refresh and release only touch a lock this run still owns.
     */
    private $lockToken = '';

    /**
     * Whether this run already cleared the queue-insert failure counter.
     */
    private $insertFailuresCleared = false;

    /**
     * Failed queue inserts in a row before the campaign goes back to draft.
     */
    const MAX_INSERT_FAILURES = 3;

    /**
     * Failures closer together than this count once, so the campaign screen's 3-second
     * polling cannot use up all attempts during one brief database problem.
     */
    const INSERT_FAILURE_SPACING = 300;

    public function __construct($campaignId)
    {
        $this->campaignId = $campaignId;

        // Rows are created as 'scheduling' and become 'scheduled' only after all rows exist.
    }

    /*
     * Process emails in chunks (default 30) for up to 30 seconds per cycle.
     * @param int $perChunk
     * @param int $runTime
     * @return Campaign|false
     */
    public function processEmails($perChunk = 0, $runTime = 30)
    {
        if ($runTime > 30) {
            $runTime = fluentCrmMaxRunTime() - 5;
        }

        $startTime = microtime(true);
        $campaign = Campaign::withoutGlobalScope('type')->find($this->campaignId);

        if (!$campaign) {
            return false;
        }

        if ($campaign->status != 'processing') {
            return $campaign;
        }

        if (fluentCrmIsMemoryExceeded()) {
            $this->recordMemoryAbort();
            return false;
        }

        fluentcrm_delete_campaign_meta($this->campaignId, '_memory_abort');

        if (!$perChunk || $perChunk <= 0) {
            /**
             * Filter the number of subscribers processed per request while processing Campaign Emails.
             *
             * This filter allows you to modify the number of subscribers that are processed
             * in each request when processing campaigns.
             *
             * @since 2.7.0
             *
             * @param int The number of subscribers to process per request. Default is 30.
             */
            $perChunk = (int)apply_filters('fluent_crm/process_subscribers_per_request', 30);
        }

        if (!$this->acquireProcessingLock()) {
            // False (not $campaign) so the caller does not fire another AJAX chain.
            return false;
        }

        // The lock must cover everything through the finalize.
        try {
            return $this->materializeUnderLock($campaign, $perChunk, $runTime, $startTime);
        } finally {
            $this->releaseProcessingLock();
        }
    }

    /**
     * Leave a trace when queue creation stops on the memory guard.
     *
     * Without it the campaign sat in processing with no queued emails and nothing in any log, and
     * every tick hit the same limit. The _memory_abort campaign meta holds the usage and limit seen;
     * the next run that gets past the guard deletes it.
     */
    private function recordMemoryAbort()
    {
        $usage = memory_get_usage(true);
        $limit = fluentCrmGetMemoryLimit();

        fluentcrm_update_campaign_meta($this->campaignId, '_memory_abort', [
            'memory_usage' => $usage,
            'memory_limit' => $limit,
            'recorded_at'  => current_time('mysql')
        ]);

        Helper::debugLog(
            'Campaign queue creation stopped on the memory guard',
            sprintf('Campaign #%d: %s of %s in use.', $this->campaignId, size_format($usage), size_format($limit)),
            'error'
        );
    }

    /**
     * Insert rows in chunks, flip them to scheduled and finalize the campaign, under the processing lock.
     *
     * @return Campaign|false
     */
    private function materializeUnderLock($campaign, $perChunk, $runTime, $startTime)
    {
        $subscribersModel = $this->getSubscribersChunk($campaign, $perChunk);
        if (!$subscribersModel) {
            $this->revertUnresolvableCampaign($campaign);
            return false;
        }

        $result = $this->subscribe($campaign, $subscribersModel);
        if ($result === null) {
            $this->handleInsertFailure($campaign);
            return false;
        }

        $willRun = !!$result;

        while ($willRun && ((microtime(true) - $startTime) < $runTime) && !fluentCrmIsMemoryExceeded()) {
            usleep(10000); // 10 milliseconds sleep
            $campaign = Campaign::withoutGlobalScope('type')->find($this->campaignId);

            // Un-scheduled or deleted mid-run: stop and sweep this run's 'scheduling' rows.
            if (!$campaign || $campaign->status != 'processing') {
                $this->sweepSchedulingRows();
                return $campaign;
            }

            $willRun = !!$result;

            if ($willRun) {
                if (!$this->refreshProcessingLock()) {
                    // A successor claimed the lock after this run stalled past the TTL.
                    return false;
                }
                $subscribersModel = $this->getSubscribersChunk($campaign, $perChunk);
                if (!$subscribersModel) {
                    $this->revertUnresolvableCampaign($campaign);
                    return false;
                }

                $result = $this->subscribe($campaign, $subscribersModel);
                if ($result === null) {
                    $this->handleInsertFailure($campaign);
                    return false;
                }
            }
        }

        // unSchedule() writes 'draft' before deleting rows, so leftover 'scheduling' rows are residue.
        $campaign = Campaign::withoutGlobalScope('type')->find($this->campaignId);

        if (!$campaign || $campaign->status != 'processing') {
            $this->sweepSchedulingRows();
            return $campaign;
        }

        if (!$result) { // All rows materialized — release them for sending.
            global $wpdb;

            // Dedup while rows are still 'scheduling' and invisible to the senders.
            $campaign->deleteDuplicateEmails();

            // Status check lives inside the UPDATE so a concurrent un-schedule cannot slip in.
            // Subquery instead of UPDATE...JOIN for the WP SQLite plugin. Must run before the finalize.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}fc_campaign_emails
                SET status = 'scheduled'
                WHERE campaign_id = %d AND status = 'scheduling'
                AND (SELECT status FROM {$wpdb->prefix}fc_campaigns WHERE id = %d) = 'processing'",
                $this->campaignId,
                $this->campaignId
            ));

            // Matches 0 rows if a cancel landed after the re-fetch.
            $finalized = Campaign::withoutGlobalScope('type')
                ->where('id', $this->campaignId)
                ->where('status', 'processing')
                ->update(['status' => 'scheduled']);

            if ($finalized) {
                fluentcrm_update_campaign_meta($this->campaignId, '_last_recipient_id', 0);
                $campaign->status = 'scheduled';
                // Keep clean so a later save() cannot overwrite a concurrent 'draft'.
                $campaign->syncOriginalAttribute('status');
                $campaign->maybeDeleteDuplicates();
            } else {
                // Cancelled between the re-fetch and the finalize.
                $this->sweepSchedulingRows();
                $campaign = Campaign::withoutGlobalScope('type')->find($this->campaignId);
            }
        }

        return $campaign;
    }

    /**
     * Move a campaign whose recipients cannot be resolved back to draft.
     * A stuck `processing` campaign occupies a scheduler discovery slot.
     */
    private function revertUnresolvableCampaign($campaign)
    {
        $this->revertToDraft(
            $campaign,
            'Recipient selection could not be resolved (segment provider missing or deleted). The campaign was moved back to draft.',
            'recipients_unresolvable'
        );
    }

    /**
     * Move a processing campaign back to draft, drop its half-built queue and record why.
     *
     * Rows are still 'scheduling' at this point, so nothing was sent. The recipient cursor is
     * reset here, not left to the next schedule: a recurring edition is re-approved through a
     * path that does not reset it, and would then skip every contact already processed. The
     * recipient count is recounted because the queue build zeroed it, and Schedule refuses a
     * campaign with no recipients. The recount runs first and may fail on the same database
     * problem that caused the revert; the count is then left alone rather than leaving a draft
     * with no recorded reason. Status and count change together, only while still processing.
     */
    private function revertToDraft($campaign, $message, $reason)
    {
        if ($campaign->status != 'processing') {
            return;
        }

        $recipientsCount = null;
        try {
            $recipientsCount = (int)$campaign->getSubscriberIdsCountBySegmentSettings($campaign->settings);
        } catch (\Exception $exception) {
            Helper::debugLog('Campaign recipient recount failed', sprintf('Campaign #%d: %s', $campaign->id, $exception->getMessage()), 'error');
        }

        $update = ['status' => 'draft'];
        if ($recipientsCount !== null) {
            $update['recipients_count'] = $recipientsCount;
        }

        $reverted = Campaign::withoutGlobalScope('type')
            ->where('id', $campaign->id)
            ->where('status', 'processing')
            ->update($update);

        if (!$reverted) {
            return;
        }

        foreach ($update as $attribute => $value) {
            $campaign->{$attribute} = $value;
            $campaign->syncOriginalAttribute($attribute);
        }

        fluentcrm_update_campaign_meta($campaign->id, '_processing_error', $message);

        $this->sweepSchedulingRows();

        fluentcrm_update_campaign_meta($campaign->id, '_last_recipient_id', 0);
        fluentcrm_update_campaign_meta($campaign->id, '_recipient_processed', 0);

        do_action('fluent_crm/campaign_processing_failed', $campaign, $reason);
    }

    /**
     * Forget earlier queue-build failures, for a campaign that is scheduled again.
     *
     * @param int $campaignId
     */
    public static function clearQueueFailures($campaignId)
    {
        foreach (['_queue_insert_failures', '_queue_insert_failed_at', '_processing_error', '_memory_abort'] as $key) {
            fluentcrm_delete_campaign_meta($campaignId, $key);
        }
    }

    /**
     * A chunk had sendable contacts but no queue rows were created, or the insert threw.
     *
     * A database error used to escape as an exception, leaving the campaign in processing to be
     * retried, and failing, on every tick; an empty insert result finished the campaign after a
     * partial send. Either way the campaign now stays in processing with its cursor unmoved and
     * the next tick retries the chunk. After MAX_INSERT_FAILURES failed runs in a row it goes back
     * to draft, because a campaign stuck at the front of the queue blocks every scheduled
     * campaign behind it.
     */
    private function handleInsertFailure($campaign)
    {
        global $wpdb;

        // Read before any other query resets it.
        $dbError = $wpdb->last_error ?: 'no database error reported';
        $failures = absint(fluentcrm_get_campaign_meta($this->campaignId, '_queue_insert_failures', true));
        $lastFailureAt = absint(fluentcrm_get_campaign_meta($this->campaignId, '_queue_insert_failed_at', true));

        if (!$failures || !$lastFailureAt || (time() - $lastFailureAt) >= self::INSERT_FAILURE_SPACING) {
            $failures++;
            fluentcrm_update_campaign_meta($this->campaignId, '_queue_insert_failed_at', time());

            Helper::debugLog(
                'Campaign queue insert failed',
                sprintf('Campaign #%d, attempt %d of %d: %s', $this->campaignId, $failures, self::MAX_INSERT_FAILURES, $dbError),
                'error'
            );
        }

        if ($failures >= self::MAX_INSERT_FAILURES) {
            fluentcrm_delete_campaign_meta($this->campaignId, '_queue_insert_failures');
            fluentcrm_delete_campaign_meta($this->campaignId, '_queue_insert_failed_at');
            $this->revertToDraft(
                $campaign,
                sprintf('The email queue could not be created after %d attempts (%s). The campaign was moved back to draft.', $failures, $dbError),
                'queue_insert_failed'
            );
            return;
        }

        fluentcrm_update_campaign_meta($this->campaignId, '_queue_insert_failures', $failures);
        fluentcrm_update_campaign_meta(
            $this->campaignId,
            '_processing_error',
            sprintf('The email queue could not be created (%s). Attempt %d of %d; it will be retried.', $dbError, $failures, self::MAX_INSERT_FAILURES)
        );
    }

    /**
     * Delete this campaign's leftover 'scheduling' rows after a cancel or revert.
     * Senders never claim 'scheduling' rows.
     */
    private function sweepSchedulingRows()
    {
        CampaignEmail::where('campaign_id', $this->campaignId)
            ->where('status', 'scheduling')
            ->delete();
    }

    /**
     * Get the next stable subscriber chunk for campaign email materialization.
     */
    private function getSubscribersChunk($campaign, $perChunk)
    {
        $subscribersModel = $campaign->getSubscribersModel($campaign->settings);
        if (!$subscribersModel) {
            return false;
        }

        $lastRecipientId = absint(fluentcrm_get_campaign_meta($campaign->id, '_last_recipient_id', true));

        return $subscribersModel
            ->where('fc_subscribers.id', '>', $lastRecipientId)
            ->reorder('fc_subscribers.id', 'ASC')
            ->limit($perChunk);
    }

    /**
     * Materialize a subscriber chunk and advance the cursor after rows are created.
     *
     * @return array|true|null Created email ids; true when nobody in the chunk was sendable;
     *                         [] when there are no more recipients; null when the insert failed.
     */
    private function subscribe($campaign, $subscribersModel)
    {
        $subscribers = $subscribersModel->get();
        if ($subscribers->isEmpty()) {
            return [];
        }

        try {
            $result = $campaign->subscribe($subscribers, [
                'status'       => $this->initialStatus,
                'scheduled_at' => $campaign->getEmailScheduleAt(),
            ], true);
        } catch (\Exception $exception) {
            // The query builder throws on a database error. Uncaught, it left the campaign in
            // processing and every tick retried it, blocking the campaigns behind it.
            return null;
        }

        if (!$result) {
            $sendable = $subscribers->filter(function ($subscriber) {
                return in_array($subscriber->status, Campaign::sendableStatuses(), true);
            });
            if (!$sendable->isEmpty()) {
                return null; // The insert failed; keep the cursor so the chunk is retried.
            }
        } else if (!$this->insertFailuresCleared) {
            $this->insertFailuresCleared = true;
            fluentcrm_delete_campaign_meta($this->campaignId, '_queue_insert_failures');
            fluentcrm_delete_campaign_meta($this->campaignId, '_queue_insert_failed_at');
            fluentcrm_delete_campaign_meta($this->campaignId, '_processing_error');
        }

        $this->advanceRecipientCursor($campaign->id, (int)$subscribers->max('id'));

        // A chunk where nobody was sendable still continues to the next chunk.
        return $result ?: true;
    }

    /**
     * Advance the shared `_last_recipient_id` cursor, forward only.
     * A stalled worker must not roll the cursor back behind its successor.
     */
    private function advanceRecipientCursor($campaignId, $lastId)
    {
        global $wpdb;

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_meta SET `value` = %s
            WHERE `object_id` = %d AND `object_type` = %s AND `key` = '_last_recipient_id' AND (`value` + 0) < %d",
            (string)$lastId, $campaignId, 'FluentCrm\App\Models\Campaign', $lastId
        ));

        if (!$affected && !fluentcrm_get_campaign_meta($campaignId, '_last_recipient_id')) {
            // First chunk: the meta row does not exist yet.
            fluentcrm_update_campaign_meta($campaignId, '_last_recipient_id', $lastId);
        }
    }

    /**
     * Per-campaign materialization lock key in wp_options.
     */
    private function processingLockKey()
    {
        return '_fluentcrm_lock_campaign_processing_' . (int)$this->campaignId;
    }

    /**
     * Atomically acquire the processing lock for this campaign.
     * Lives in wp_options, not fc_meta, because only option_name has a UNIQUE key.
     *
     * @return bool True if lock acquired, false if another process holds it.
     */
    private function acquireProcessingLock()
    {
        // 90s TTL, refreshed once per chunk.
        $this->lockToken = md5(uniqid((string)wp_rand(), true));

        return Helper::acquireDbLock($this->processingLockKey(), 90, $this->lockToken);
    }

    /**
     * Refresh the lock timestamp to prevent stale-lock detection.
     *
     * @return bool False when this run no longer owns the lock.
     */
    private function refreshProcessingLock()
    {
        return Helper::refreshDbLock($this->processingLockKey(), $this->lockToken);
    }

    /**
     * Release the processing lock by deleting its row, only if this run still owns it.
     */
    private function releaseProcessingLock()
    {
        Helper::deleteDbLock($this->processingLockKey(), $this->lockToken);
    }

    public function getSchedulingMethod()
    {
        return $this->initialStatus;
    }
}
