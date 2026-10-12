<?php

namespace FluentCrm\App\Hooks\Handlers;

use FluentCrm\App\Services\BlockParserHelper;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Models\Company;
use FluentCrm\App\Models\CompanyNote;
use FluentCrm\App\Models\EventTracker;
use FluentCrm\App\Models\FunnelMetric;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\SubscriberMeta;
use FluentCrm\App\Models\SubscriberNote;
use FluentCrm\App\Models\SubscriberPivot;
use FluentCrm\App\Services\BlockParser;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Models\Meta;


/**
 *  Cleanup Class
 *
 * Used to handle cleanup related assets for subscribers, campaigns and automations.
 *
 * @package FluentCrm\App\Hooks
 *
 * @version 1.0.0
 */
class Cleanup
{
    /**
     * Cleanup related data of a subscriber.
     *
     * @param array $subscriberIds
     */
    public function deleteSubscribersAssets($subscriberIds)
    {
        CampaignEmail::whereIn('subscriber_id', $subscriberIds)->delete();
        CampaignUrlMetric::whereIn('subscriber_id', $subscriberIds)->delete();
        SubscriberMeta::whereIn('subscriber_id', $subscriberIds)->delete();
        SubscriberNote::whereIn('subscriber_id', $subscriberIds)->delete();
        SubscriberPivot::whereIn('subscriber_id', $subscriberIds)->delete();
        FunnelMetric::whereIn('subscriber_id', $subscriberIds)->delete();
        FunnelSubscriber::whereIn('subscriber_id', $subscriberIds)->delete();

        if (defined('FLUENTCAMPAIGN_DIR_FILE')) {
            \FluentCampaign\App\Models\SequenceTracker::whereIn('subscriber_id', $subscriberIds)->delete();
        }

        // The tracked events table exists only once the experimental feature is enabled.
        if (Helper::isExperimentalEnabled('event_tracking')) {
            EventTracker::whereIn('subscriber_id', $subscriberIds)->delete();
        }

        if (Helper::isExperimentalEnabled('company_module')) {
            Company::whereIn('owner_id', $subscriberIds)
                ->update([
                    'owner_id' => NULL
                ]);
        }

    }

    /**
     * Cleanup related data of a campaign.
     *
     * @param int $campaignId
     */
    public function deleteCampaignAssets($campaignId)
    {
        // Idempotent backstop for callers that skip Campaign::deleteCampaignData().
        CampaignEmail::where('campaign_id', $campaignId)->delete();
        CampaignUrlMetric::where('campaign_id', $campaignId)->delete();
    }

    /**
     * Cleanup related data of a list.
     *
     * @param int $listId
     */
    public function deleteListAssets($listId)
    {
        SubscriberPivot::where('object_type', 'FluentCrm\App\Models\Lists')->where('object_id', $listId)->delete();
    }

    /**
     * Cleanup related data of a tag.
     *
     * @param int $listId
     */
    public function deleteTagAssets($listId)
    {
        SubscriberPivot::where('object_type', 'FluentCrm\App\Models\Tag')->where('object_id', $listId)->delete();
    }

    /**
     * Cancel Future Emails.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public function handleUnsubscribe($subscriber)
    {
        // Per-statement try/catch so a deadlock on one table does not block the others.
        try {
            CampaignEmail::where('subscriber_id', $subscriber->id)
                ->whereIn('status', ['pending', 'scheduled', 'draft', 'processing', 'scheduling'])
                ->update([
                    'status' => 'cancelled'
                ]);
        } catch (\Exception $e) {
            Helper::debugLog('handleUnsubscribe', 'CampaignEmail cancel deferred: ' . $e->getMessage(), 'extended');
        }

        try {
            FunnelSubscriber::where('subscriber_id', $subscriber->id)
                ->where('status', 'active')
                ->whereDoesntHave('funnel', function ($query) {
                    $query->where('trigger_name', 'fluent_crm/subscriber_status_changed');
                })
                ->update([
                    'status' => 'cancelled'
                ]);
        } catch (\Exception $e) {
            Helper::debugLog('handleUnsubscribe', 'FunnelSubscriber cancel deferred: ' . $e->getMessage(), 'extended');
        }

        if (defined('FLUENTCAMPAIGN')) {
            try {
                \FluentCampaign\App\Models\SequenceTracker::where('subscriber_id', $subscriber->id)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'cancelled'
                    ]);
            } catch (\Exception $e) {
                Helper::debugLog('handleUnsubscribe', 'SequenceTracker cancel deferred: ' . $e->getMessage(), 'extended');
            }
        }
    }

    /**
     * Change the future emails email_address of a provided contact.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public function handleContactEmailChanged($subscriber)
    {
        CampaignEmail::where('subscriber_id', $subscriber->id)
            ->whereIn('status', ['draft', 'scheduled'])
            ->update([
                'email_address' => $subscriber->email
            ]);

        // Soft-bounce strikes belong to the old address.
        $this->resetSoftBounceCount($subscriber);
    }

    /**
     * Clear the accumulated soft-bounce strike counter.
     * Runs on email changes, double opt-in confirmations, any change to subscribed, and tracked
     * clicks, so old transient bounces do not suppress a contact who is reachable again.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public function resetSoftBounceCount($subscriber)
    {
        fluentcrm_delete_subscriber_meta($subscriber->id, '_soft_bounce_count');
    }


    /**
     * @param $userId int
     * @param $resign int|null
     * @param $deletedUser \WP_User
     * @return bool
     */
    public function handleUserDelete($userId, $resign, $deletedUser)
    {
        $settings = Helper::getComplianceSettings();
        if ($settings['delete_contact_on_user'] !== 'yes') {
            return false;
        }

        $subscriber = Subscriber::where('user_id', $userId)->first();

        if (!$subscriber && $deletedUser) {
            $subscriber = Subscriber::where('email', $deletedUser->user_email)->first();
        }

        if (!$subscriber) {
            return false;
        }

        // delete the subscriber now;
        Helper::deleteContacts([$subscriber->id]);

        return true;
    }

    public function attachCrmExporter($exporters)
    {
        $settings = Helper::getComplianceSettings();
        if ($settings['personal_data_export'] !== 'yes') {
            return $exporters;
        }

        $exporters['fluent-crm'] = [
            'exporter_friendly_name' => __('FluentCRM Data', 'fluent-crm'),
            'callback'               => [$this, 'exportPersonalDataWP'],
        ];

        return $exporters;

    }

    public function exportPersonalDataWP($user_email, $page = 1)
    {
        $subscriber = Subscriber::where('email', $user_email)->first();

        if (!$subscriber) {
            return [
                'data' => [],
                'done' => true
            ];
        }

        $customerFields = $subscriber->custom_fields();
        $mainFields = $subscriber->toArray();

        $data = [
            'group_id'    => 'fluent-crm-contact',
            'group_label' => __('FluentCRM Data', 'fluent-crm'),
            'item_id'     => 'crm-contact',
            'data'        => []
        ];

        foreach ($mainFields as $fieldKey => $fieldValue) {
            if ($fieldValue) {
                $data['data'][] = [
                    'name'  => $fieldKey,
                    'value' => $fieldValue
                ];
            }
        }

        foreach ($customerFields as $fieldKey => $customerField) {
            $data['data'][] = [
                'name'  => $fieldKey,
                'value' => $customerField
            ];
        }

        return [
            'data' => [$data],
            'done' => true,
        ];
    }

    public function handleCompanyDelete($id)
    {
        /*
         * Remove Company ID from all connected subscribers
         */
        Subscriber::where('company_id', $id)->update([
            'company_id' => NULL
        ]);

        fluentCrmDb()->table('fc_subscriber_pivot')
            ->where('object_id', $id)
            ->where('object_type', 'FluentCrm\App\Models\Company')
            ->delete();

        // Delete company notes
        CompanyNote::where('subscriber_id', $id)->delete();
    }

    public function handleUserPasswordChanged($user)
    {
        $contact = Subscriber::where('email', $user->user_email)
            ->first();

        if (!$contact) {
            return false;
        }

        $exist = SubscriberMeta::where('subscriber_id', $contact->id)
            ->where('key', '_secure_managed_hash')
            ->first();

        if (!$exist) {
            return false;
        }

        $hash = md5(wp_generate_uuid4() . '_' . $contact->id . '_' . '_' . time() . '__' . $contact->id);
        $exist->value = $hash;
        $exist->updated_at = current_time('mysql');
        $exist->save();

        return true;
    }

    public function archiveCampaignAssets($campaign)
    {
        if ($campaign->type != 'campaign' || fluentcrm_get_campaign_meta($campaign->id, '_cached_email_body', true)) {
            return;
        }

        // We will create email body and then cache it for future use
        $rawTemplates = [
            'raw_html',
            'visual_builder',
            'raw_classic'
        ];

        if (in_array($campaign->design_template, $rawTemplates)) {
            $emailBody = $campaign->email_body;
        } else {
            // The cached body is what the public share link and the archive show to anyone.
            $emailBody = BlockParserHelper::renderForAnonymousViewer(function () use ($campaign) {
                return (new BlockParser())->parse($campaign->email_body);
            });
        }

        fluentcrm_update_campaign_meta($campaign->id, '_cached_email_body', $emailBody);
        return true;
    }

    public static function maybeRemoveOldScheuledActionLogs()
    {
        $group_slug = 'fluent-crm';
        $days_old = 7;

        global $wpdb;

        // Get the timestamp for $days_old days ago
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        // Get the group ID
        $group_id = $wpdb->get_var($wpdb->prepare(
            "SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s",
            $group_slug
        ));

        if (!$group_id) {
            return false; // Group not found
        }

        // Delete old actions and their logs in bounded batches within a time budget.
        $batchSize = 500;
        $totalDeleted = 0;

        do {
            $actionIds = $wpdb->get_col($wpdb->prepare(
                "SELECT action_id
                 FROM {$wpdb->prefix}actionscheduler_actions
                 WHERE group_id = %d
                 AND status IN ('complete', 'failed')
                 AND scheduled_date_gmt < %s
                 LIMIT %d",
                $group_id, $cutoff_date, $batchSize
            ));

            if (!$actionIds) {
                break;
            }

            // Every id is cast to an integer.
            $ids = implode(',', array_map('intval', $actionIds));

            // Remove the associated logs first, then the actions themselves.
            $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ({$ids})");
            $totalDeleted += (int) $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ({$ids})");

            $isFullBatch = count($actionIds) === $batchSize;
        } while ($isFullBatch && !fluentCrmIsTimeOut(30));

        // Clean up orphaned claims (claims whose actions no longer exist)
        $wpdb->query("
        DELETE c
        FROM {$wpdb->prefix}actionscheduler_claims c
        LEFT JOIN {$wpdb->prefix}actionscheduler_actions a ON c.claim_id = a.claim_id
        WHERE a.action_id IS NULL");

        return $totalDeleted;
    }

    public function SyncSubscriberDeleteSettings($fromKey, $value)
    {
        if ($fromKey == 'compliance_settings') {
            $option = Meta::where('key', 'user_syncing_settings')
                ->where('object_type', 'option')
                ->first();

            if ($option) {
                $settings = $option->value;

                if ($settings['delete_contact_on_user_delete'] != $value) {
                    $settings['delete_contact_on_user_delete'] = $value;
                    $option->value = $settings;
                    $option->save();
                }
            }
        } else {
            $complianceSettings = get_option('_fluentcrm_compliance_settings');
            if ($complianceSettings) {
                $complianceSettings['delete_contact_on_user'] = $value;
                update_option('_fluentcrm_compliance_settings', $complianceSettings, 'no');
            }
        }
    }
}
