<?php

namespace FluentCrm\App\Services;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;

class CampaignProcessor
{
    protected $campaignId = false;

    protected $initialStatus = 'scheduling';

    public function __construct($campaignId)
    {
        $this->campaignId = $campaignId;
        /*
         * We want to send emails real time if memory limit is more than 500MB and current usage is less than 60%
         * otherwise, they are scheduled for background processing
         */
        if (fluentCrmGetMemoryLimit() > 510000000 && !fluentCrmIsMemoryExceeded(60)) {
            $this->initialStatus = 'scheduled';
        }
    }

    /*
     * By Default, this function will process emails in chunks of 30 (customizable) and run for max 30 seconds per processing cycle
     * Sleep for 200ms between each cycle
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
            return false;
        }

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

        $subscribersModel = $campaign->getSubscribersModel($campaign->settings);

        if (!$subscribersModel) {
            return false;
        }

        /*
         * Prevent Multiple Jobs here
         */
        $lastProcess = (int)fluentcrm_get_campaign_meta($campaign->id, '_processing_emails', true);
        if ($lastProcess && (time() - $lastProcess) < 55) {
            return $campaign;
        }

        fluentcrm_update_campaign_meta($campaign->id, '_processing_emails', time());
        $subscribersModel = $subscribersModel->limit($perChunk)->offset($campaign->recipients_count);

        $result = $this->subscribe($campaign, $subscribersModel);

        $willRun = !!$result;

        while ($willRun && ((microtime(true) - $startTime) < $runTime) && !fluentCrmIsMemoryExceeded()) {
            usleep(200000); // 200 miliseconds sleep
            $campaign = Campaign::withoutGlobalScope('type')->find($campaign->id);
            $willRun = !!$result;

            if ($willRun) {
                fluentcrm_update_campaign_meta($campaign->id, '_processing_emails', time());
                $subscribersModel = $subscribersModel->limit($perChunk)->offset($campaign->recipients_count);
                $result = $this->subscribe($campaign, $subscribersModel);
            }
        }

        fluentcrm_update_campaign_meta($campaign->id, '_processing_emails', 0);

        if (!$result) { // All Done. Let's make it scheduled
            $campaign = Campaign::withoutGlobalScope('type')->find($this->campaignId);

            if ($campaign->status == 'processing') {
                $campaign->status = 'scheduled';
                $campaign->save();
            }

            CampaignEmail::where('campaign_id', $campaign->id)
                ->where('status', 'scheduling')
                ->update([
                    'status' => 'scheduled'
                ]);

            $campaign->maybeDeleteDuplicates();
        }

        return $campaign;
    }

    private function subscribe($campaign, $subscribersModel)
    {
        $subscribers = $subscribersModel->get();
        if ($subscribers->isEmpty()) {
            return [];
        }

        return $campaign->subscribe($subscribers, [
            'status'       => $this->initialStatus,
            'scheduled_at' => $campaign->getEmailScheduleAt()
        ], true);
    }

    public function getSchedulingMethod()
    {
        return $this->initialStatus;
    }
}
