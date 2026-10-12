<?php

namespace FluentCrm\App\Http\Controllers;

use FluentCrm\App\Hooks\Handlers\Scheduler;
use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Template;
use FluentCrm\App\Services\BlockParser;
use FluentCrm\App\Services\BlockParserHelper;
use FluentCrm\App\Services\Libs\Parser\ShortcodeParser;
use FluentCrm\App\Services\CampaignProcessor;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Libs\Mailer\Handler;
use FluentCrm\App\Services\Libs\Mailer\Mailer;
use FluentCrm\App\Services\Sanitize;
use FluentCrm\Framework\Http\Request\Request;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Support\Str;

/**
 *  CampaignController - REST API Handler Class
 *
 *  REST API Handler
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */
class CampaignController extends Controller
{
    public function campaigns(Request $request)
    {
        $search = sanitize_text_field($request->get('searchBy', ''));
        $status = $request->get('statuses', []);
        $status = is_array($status) ? array_map('sanitize_key', $status) : [];

        $order = strtoupper($request->get('sort_type', ''));
        $order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';

        $orderBy = Helper::sanitizeOrderBy($request->get('sort_by'), 'created_at');
        // Drop caller-supplied keys: a with[<html>]=x key would otherwise reach the relation name.
        $with = array_values(array_map('sanitize_key', (array) $request->get('with', [])));

        $labels = $request->get('labels', []);
        $labels = is_array($labels) ? array_map('intval', $labels) : [];
        $labels = array_filter($labels);

        if (empty($orderBy)) {
            $orderBy = 'created_at';
        }

        $campaignQuery = Campaign::when($status, function ($query) use ($status) {
            return $query->whereIn('status', $status);
        })->when($search, function ($query) use ($search) {
            global $wpdb;
            return $query->where('title', 'LIKE', '%' . $wpdb->esc_like($search) . '%');
        })
            ->orderBy($orderBy, ($order == 'ASC') ? 'ASC' : 'DESC');

        if (!empty($labels)) {
            $campaignQuery->whereHas('labelsTerm', function ($query) use ($labels) {
                $query->whereIn('term_id', $labels);
            });
        }

        $campaigns = $campaignQuery->paginate();
        if (in_array('stats', $with)) {
            $campaignIds = $campaigns->pluck('id')->toArray();

            if ($campaignIds) {
                $emailStats = fluentCrmDb()->table('fc_campaign_emails')
                    ->select('campaign_id')
                    ->selectRaw('COUNT(*) as total')
                    ->selectRaw("SUM(CASE WHEN status IN ('sent', 'bounced') THEN 1 ELSE 0 END) as sent")
                    ->selectRaw("SUM(CASE WHEN is_open = 1 THEN 1 ELSE 0 END) as views")
                    ->selectRaw("SUM(CASE WHEN click_counter IS NOT NULL THEN 1 ELSE 0 END) as clicks")
                    ->whereIn('campaign_id', $campaignIds)
                    ->groupBy('campaign_id')
                    ->get()
                    ->keyBy('campaign_id');

                $unsubCounts = fluentCrmDb()->table('fc_campaign_url_metrics')
                    ->select('campaign_id')
                    ->selectRaw('COUNT(DISTINCT subscriber_id) as total')
                    ->where('type', 'unsubscribe')
                    ->whereIn('campaign_id', $campaignIds)
                    ->groupBy('campaign_id')
                    ->get()
                    ->keyBy('campaign_id');

                $metaItems = fluentCrmDb()->table('fc_meta')
                    ->whereIn('object_id', $campaignIds)
                    ->where('object_type', 'FluentCrm\App\Models\Campaign')
                    ->whereIn('key', ['_next_config_step', '_campaign_revenue', '_ano_open_count', '_ano_url_clicks'])
                    ->get();

                $metaMap = [];
                foreach ($metaItems as $meta) {
                    $metaMap[$meta->object_id][$meta->key] = $meta->value;
                }

                $labelRelations = fluentCrmDb()->table('fc_term_relations')
                    ->whereIn('object_id', $campaignIds)
                    ->where('object_type', 'FluentCrm\App\Models\Campaign')
                    ->get();

                $labelIdsByCampaign = [];
                $allLabelIds = [];
                foreach ($labelRelations as $rel) {
                    $labelIdsByCampaign[$rel->object_id][] = $rel->term_id;
                    $allLabelIds[] = $rel->term_id;
                }

                $allLabels = [];
                if ($allLabelIds) {
                    $allLabels = fluentCrmDb()->table('fc_terms')
                        ->whereIn('id', array_unique($allLabelIds))
                        ->where('taxonomy_name', 'global_label')
                        ->get()
                        ->keyBy('id');
                }

                foreach ($campaigns as $campaign) {
                    $stat = $emailStats[$campaign->id] ?? null;
                    $unsub = $unsubCounts[$campaign->id] ?? null;
                    $campMeta = $metaMap[$campaign->id] ?? [];

                    if ($campaign->getOpenTrackingStatus(false) === 'anonymous') {
                        $views = (int) ($campMeta['_ano_open_count'] ?? 0);
                    } else {
                        $views = $stat ? (int) $stat->views : 0;
                    }

                    if ($campaign->getClickTrackingStatus(false) === 'anonymous') {
                        $clickData = $campMeta['_ano_url_clicks'] ?? null;
                        $clicks = 0;
                        if ($clickData) {
                            $clickItems = maybe_unserialize($clickData);
                            if (is_array($clickItems)) {
                                $clicks = array_sum($clickItems);
                            }
                        }
                    } else {
                        $clicks = $stat ? (int) $stat->clicks : 0;
                    }

                    $stats = array_merge([
                        'total'         => $stat ? (int) $stat->total : 0,
                        'sent'          => $stat ? (int) $stat->sent : 0,
                        'views'         => $views,
                        'clicks'        => $clicks,
                        'unsubscribers' => $unsub ? (int) $unsub->total : 0,
                    ], $campaign->anonymousStatFlags());

                    $revenueRaw = $campMeta['_campaign_revenue'] ?? null;
                    if ($revenueRaw) {
                        $data = (array) maybe_unserialize($revenueRaw);
                        $orderCount = isset($data['orderIds']) && is_array($data['orderIds']) ? count($data['orderIds']) : 0;
                        foreach ($data as $currency => $cents) {
                            if ($cents && $currency !== 'orderIds') {
                                $stats['revenue'] = [
                                    /* translators: %s: currency code, e.g. USD */
                                    'label'    => sprintf(__('Revenue (%s)', 'fluent-crm'), $currency),
                                    'total'    => number_format($cents / 100, 2),
                                    'currency' => $currency,
                                    'orders'   => $orderCount
                                ];
                            }
                        }
                    }

                    $campaign->stats = $stats;
                    $campaign->next_step = $campMeta['_next_config_step'] ?? false;

                    $campaignLabelIds = $labelIdsByCampaign[$campaign->id] ?? [];
                    $campaign->labels = array_values(array_filter(array_map(function ($labelId) use ($allLabels) {
                        $label = $allLabels[$labelId] ?? null;
                        if (!$label) {
                            return null;
                        }
                        $settings = maybe_unserialize($label->settings);
                        return [
                            'id'    => $label->id,
                            'slug'  => $label->slug,
                            'title' => $label->title,
                            'color' => is_array($settings) ? ($settings['color'] ?? '') : ''
                        ];
                    }, $campaignLabelIds)));
                }
            }
        }

        return [
            'campaigns' => $campaigns
        ];
    }

    public function create(Request $request)
    {
        $title = $request->get('title');
        if ($title !== null && $title !== '') {
            // Title is unique among type='campaign' rows only; fc_campaigns is shared with other types.
            $data = $this->validate($request->only('title'), [
                'title' => 'required|unique:fc_campaigns,title,NULL,id,type,campaign',
            ]);
            $data['title'] = sanitize_text_field($data['title']);
        } else {
            $defaultTitle = sprintf(
                /* translators: %s: current date and time (MySQL format) used as the default campaign title suffix */
                __('Untitled Campaign %s', 'fluent-crm'),
                current_time('mysql')
            );
            $data['title'] = $this->ensureUniqueDefaultTitle($defaultTitle);
        }

        if ($designTemplate = sanitize_text_field($request->get('design_template', ''))) {
            $data['design_template'] = $designTemplate;
        }

        $campaign = Campaign::create($data)->load([
            'template', 'subjects'
        ]);

        do_action('fluent_crm/campaign_created', $campaign);

        return $this->sendSuccess(
            $campaign
        );
    }

    /**
     * Make an automatically generated campaign title unique within campaign rows.
     */
    protected function ensureUniqueDefaultTitle($baseTitle)
    {
        $title = sanitize_text_field($baseTitle);
        if (!Campaign::where('title', $title)->exists()) {
            return $title;
        }
        $count = 2;
        while (Campaign::where('title', $title . ' ' . $count)->exists()) {
            $count++;
        }
        return $title . ' ' . $count;
    }

    public function campaign(Request $request, $id)
    {
        if ($request->exists('viewCampaign')) {
            $campaign = Campaign::findOrFail($id);
            $emails = $campaign->emails()->with('subscriber')->paginate();
            return $this->sendSuccess(['campaign' => $campaign, 'emails' => $emails]);
        }

        // Drop caller-supplied keys: a with[<html>]=x key would otherwise reach the relation name.
        $with = array_values(array_map('sanitize_key', (array) $request->get('with', [])));
        if ($with) {
            $campaign = Campaign::with($with)->find($id);
        } else {
            $campaign = Campaign::findOrFail($id);
        }

        /**
         * Determine the email campaign data in FluentCRM.
         *
         * This filter allows modification of the email campaign data before it is used.
         *
         * @param array $campaign The email campaign data.
         * @since 2.6.51
         *
         */
        $campaign = apply_filters('fluent_crm/campaign_data', $campaign);

        $templates = Template::emailTemplates()
            ->select(['ID', 'post_title'])
            ->orderBy('ID', 'desc')
            ->get();

        $campaign->server_time = current_time('mysql');

        return $this->sendSuccess(compact('campaign', 'templates'));
    }

    public function campaignEmails(Request $request, $campaignId)
    {
        $filterType = $request->get('filter_type');
        $search = $request->getSafe('search', 'sanitize_text_field');

        $campaign = Campaign::withoutGlobalScope('type')->findOrFail($campaignId);

        $emailsQuery = CampaignEmail::with(['subscriber'])->where('campaign_id', $campaign->id);

        if ($search) {
            $emailsQuery->whereHas('subscriber', function ($q) use ($search) {
                $q->searchBy($search);
            });
        }

        $filterType = in_array($filterType, ['click', 'view', 'unopened', 'failed']) ? $filterType : '';

        if ($filterType == 'click') {
            $emailsQuery = $emailsQuery->whereNotNull('click_counter')
                ->orderBy('click_counter', 'DESC');
        } else if ($filterType == 'view') {
            $emailsQuery = $emailsQuery->where('is_open', '>', 0)
                ->orderBy('is_open', 'DESC');
        } else if ($filterType == 'unopened') {
            $emailsQuery = $emailsQuery->where('is_open', '==', 0)
                ->orderBy('is_open', 'DESC');
        } else if ($filterType == 'failed') {
            $emailsQuery = $emailsQuery->where('status', 'failed')
                ->orderBy('id', 'DESC');
        }

        $emails = $emailsQuery->paginate();

        $data = [
            'emails'        => $emails,
            'failed_counts' => CampaignEmail::where('campaign_id', $campaign->id)->where('status', 'failed')->count()
        ];

        if ($request->get('with_campaign')) {
            $campaign->open_tracking_status = $campaign->getOpenTrackingStatus();
            $campaign->click_tracking_status = $campaign->getClickTrackingStatus();
            $data['campaign'] = $campaign;
        }

        return $data;
    }

    public function updateSingleCampaignSimulate(Request $request)
    {
        $id = intval($request->get('campaign_id'));
        return $this->update($request, $id);
    }

    public function update(Request $request, $id)
    {
        // Unique among other type='campaign' rows.
        $data = $this->validate($this->request->except(['action']), [
            "title" => "required|unique:fc_campaigns,title,{$id},id,type,campaign",
        ]);

        $updateData = Arr::only($data, [
            'title',
            'slug',
            'template_id',
            'email_subject',
            'email_pre_header',
            'email_body',
            'utm_status',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'scheduled_at',
            'design_template'
        ]);

        if (!empty($data['settings'])) {
            $updateData['settings'] = $data['settings'];

            if (!empty($updateData['design_template']) && is_array($updateData['settings'])) {
                $updateData['settings']['template_config']['design_template'] = $updateData['design_template'];
            } else if (is_array($data['settings']) && !empty($data['settings']['template_config']['design_template'])) {
                $updateData['design_template'] = $data['settings']['template_config']['design_template'];
            }
        }

        $updateData = Sanitize::campaign($updateData);

        $campaign = Campaign::findOrFail($id);

        // Drafts only, as in the Web UI: the editor opens drafts and nothing else. A scheduled,
        // sending or sent campaign holds queued and sent emails this would discard or desync.
        if ($campaign->status !== 'draft') {
            return $this->sendError([
                'message' => __('Only draft campaigns can be edited. Unschedule a scheduled campaign first.', 'fluent-crm')
            ], 422);
        }

        $campaignSubjects = [];

        if (isset($data['update_subjects'])) {
            $campaignSubjects = Arr::get($data, 'subjects', []);

            // Validate A/B subjects before saving anything.
            $validSubjects = array_filter((array)$campaignSubjects, function ($subject) {
                return !empty($subject['key']) && trim((string)Arr::get($subject, 'value', ''));
            });

            if (!empty($campaignSubjects) && count($validSubjects) < 2) {
                return $this->sendError([
                    'message' => __('Please provide at least two subject lines for an A/B test.', 'fluent-crm')
                ], 422);
            }
        }

        $campaign->fill($updateData)->save();

        if (isset($data['update_subjects'])) {
            $campaign->syncSubjects($campaignSubjects);
            $campaign = Campaign::with(['subjects'])->find($id);
        } else {
            $campaign = Campaign::findOrFail($id);
        }

        $nextStep = Arr::get($data, 'next_step');

        if ($nextStep) {

            if ($nextStep == 1) {
                do_action('fluent_crm/update_campaign_compose', $data, $campaign);
            } else if ($nextStep == 2) {
                $footerDisabled = Arr::get(Helper::getFooterConfig($campaign), 'disable_footer') === 'yes';
                if (($footerDisabled || $campaign->design_template === 'visual_builder') && !Helper::hasComplianceText($campaign->email_body)) {
                    return $this->sendError([
                        'compliance_failed' => true,
                        'message'           => '##crm.manage_subscription_url## or ##crm.unsubscribe_url## or {{crm_global_email_footer}} string is required for compliance. Please include unsubscription or manage subscription link. <br />Please go to the previous screen and add the unsubscribe link'
                    ]);
                }
                do_action('fluent_crm/update_campaign_subjects', $data, $campaign);
            }

            fluentcrm_update_campaign_meta($id, '_next_config_step', $nextStep);
        }

        do_action('fluent_crm/campaign_data_updated', $campaign, $data);

        return $this->sendSuccess([
            'campaign' => $campaign
        ]);
    }

    public function updateStep(Request $request, $id)
    {
        $step = intval($request->get('next_step'));
        fluentcrm_update_campaign_meta($id, '_next_config_step', $step);
        return [
            'message' => __('Step saved', 'fluent-crm')
        ];
    }

    public function validateRecipientsSelection(Request $request)
    {
        $items = $request->get('items');
        $campaignId = absint($request->get('campaign_id'));
        $campaign = Campaign::findOrFail($campaignId);
        $subscribersIds = $campaign->getSubscribeIdsByList($items);

        if (!$subscribersIds) {
            return $this->sendError([
                'message' => __('No contacts found for your selection', 'fluent-crm'),
                'count'   => 0
            ]);
        }

        $settings = $campaign->settings;
        $settings['subscribers'] = $items;
        $campaign->settings = $settings;
        $campaign->save();

        return $this->sendSuccess([
            'count' => count($subscribersIds)
        ]);
    }

    public function draftRecipients(Request $request, $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);

        // Drafts only, as in the Web UI: the editor opens drafts and nothing else. A scheduled,
        // sending or sent campaign holds queued and sent emails this would discard or desync.
        if ($campaign->status !== 'draft') {
            return $this->sendError([
                'message' => __('Only draft campaigns can be edited. Unschedule a scheduled campaign first.', 'fluent-crm')
            ], 422);
        }


        $subscribersSettings = [
            'subscribers'         => $request->get('subscribers'),
            'excludedSubscribers' => $request->get('excludedSubscribers'),
            'sending_filter'      => $request->get('sending_filter', 'list_tag'),
            'dynamic_segment'     => $request->get('dynamic_segment'),
            'advanced_filters'    => Helper::parseArrayOrJson($request->get('advanced_filters'))
        ];

        $sanitizedSubscribersSettings['sending_filter'] = sanitize_text_field($subscribersSettings['sending_filter']);


        $count = (new Campaign())->getSubscriberIdsCountBySegmentSettings($subscribersSettings);

        if (!$count) {
            return $this->sendError([
                'message' => __('No contacts found for your selection', 'fluent-crm')
            ]);
        }

        $campaign->campaign_emails()->delete();
        $campaign->status = 'draft';
        $campaign->recipients_count = $count;
        $campaign->settings = wp_parse_args($subscribersSettings, $campaign->settings);
        $campaign->save();
        fluentcrm_update_campaign_meta($campaign->id, '_recipient_processed', 0);
        fluentcrm_update_campaign_meta($campaign->id, '_last_recipient_id', 0);

        do_action('fluent_crm/campaign_recipients_query_updated', $campaign);

        return [
            'message' => __('Recipient settings have been updated', 'fluent-crm'),
            'count'   => $count
        ];
    }

    public function recipientsCount(Request $request, $campaignId)
    {
        $campaign = Campaign::withoutGlobalScope('type')->findOrFail($campaignId);
        $preProcessedStatuses = [
            'draft',
            'processing',
            'pending-scheduled'
        ];

        if (in_array($campaign->status, $preProcessedStatuses)) {
            $count = $campaign->getSubscribersModel()->count();
        } else {
            $count = $campaign->recipients_count;
        }

        return [
            'estimated_count' => $count
        ];
    }


    /*
    * TODO: Unused since v3.0.0, kept for reference. Remove in the next version.
    */
    // public function subscribe(Request $request, $campaignId)
    // {
    //     $startTime = microtime(true);
    //     $campaign = Campaign::findOrFail($campaignId);

    //     do_action('fluentcrm_campaign_status_active', $campaign);

    //     $page = (int)$this->request->get('page', 1);
    //     /**
    //      * Determine the number of subscribers to process per request in FluentCRM Email Campaign.
    //      *
    //      * This filter allows you to modify the number of subscribers that are processed
    //      * in a single request when handling email campaigns.
    //      *
    //      * @param int The number of subscribers to process per request. Default is 90.
    //      * @since 2.7.0
    //      *
    //      */
    //     $limit = (int)apply_filters('fluent_crm/process_subscribers_per_request', 90);

    //     $subscribersSettings = [
    //         'subscribers'         => $request->get('subscribers'),
    //         'excludedSubscribers' => $request->get('excludedSubscribers'),
    //         'sending_filter'      => $request->get('sending_filter', 'list_tag'),
    //         'dynamic_segment'     => $request->get('dynamic_segment'),
    //         'advanced_filters'    => Helper::parseArrayOrJson($request->get('advanced_filters'))
    //     ];

    //     $offset = 0;
    //     $runTime = 40;

    //     if ($page == 1) {
    //         $runTime = 15;
    //         $campaign->campaign_emails()->delete();
    //         $campaign->settings = wp_parse_args($subscribersSettings, $campaign->settings);
    //         $campaign->save();
    //         fluentcrm_update_campaign_meta($campaign->id, '_recipient_processed', 0);
    //     } else {
    //         $offset = (int)fluentcrm_get_campaign_meta($campaign->id, '_recipient_processed', true);
    //     }

    //     $subscribeStatus = $campaign->subscribeBySegment($subscribersSettings, $limit, $offset);
    //     fluentcrm_update_campaign_meta($campaign->id, '_recipient_processed', $campaign->recipients_count);

    //     $willRun = true;

    //     while ($willRun && ((microtime(true) - $startTime) < $runTime) && !fluentCrmIsMemoryExceeded()) {
    //         $campaign = Campaign::findOrFail($campaignId);
    //         $willRun = !!$subscribeStatus['result'];

    //         if ($willRun) {
    //             $subscribeStatus = $campaign->subscribeBySegment($subscribersSettings, $limit, $campaign->recipients_count);
    //             fluentcrm_update_campaign_meta($campaign->id, '_recipient_processed', $campaign->recipients_count);
    //         }
    //     }

    //     $hasMore = !!$subscribeStatus['result'];

    //     if (!$hasMore) {
    //         $campaign = Campaign::findOrFail($campaignId);
    //         fluentcrm_update_campaign_meta($campaign->id, '_recipient_processed', 0);
    //         if (!$campaign->recipients_count) {
    //             return $this->sendError([
    //                 'message' => __('No contacts found for your filters', 'fluent-crm')
    //             ]);
    //         }
    //         $campaign->maybeDeleteDuplicates();
    //     }

    //     if ($subscribeStatus['total_items']) {
    //         return $this->sendSuccess([
    //             'has_more'       => $hasMore,
    //             'count'          => $campaign->recipients_count,
    //             'total_items'    => $subscribeStatus['total_items'],
    //             'page_total'     => ceil($subscribeStatus['total_items'] / $limit),
    //             'next_page'      => $page + 1,
    //             'execution_time' => microtime(true) - $startTime,
    //             'memory'         => fluentCrmIsMemoryExceeded(),
    //             'memory_limit'   => fluentCrmGetMemoryLimit(),
    //             'memory_usage'   => memory_get_usage(true)
    //         ]);
    //     }

    //     if ($campaign->recipients_count) {
    //         return [
    //             'has_more' => false,
    //             'count'    => $campaign->recipients_count
    //         ];
    //     }

    //     return $this->sendError([
    //         'message' => __('No contacts found for your filters', 'fluent-crm')
    //     ]);
    // }

    public function getContactEstimation(Request $request)
    {
        $start_time = microtime(true);

        $filterType = $request->get('sending_filter', 'list_tag');

        $subscribersSettings = [
            'sending_filter' => $filterType
        ];

        if ($filterType == 'list_tag') {
            $subscribersSettings['subscribers'] = $request->get('subscribers', []);
            $subscribersSettings['excludedSubscribers'] = $request->get('excludedSubscribers', []);
        } else if ($filterType == 'dynamic_segment') {
            $subscribersSettings['dynamic_segment'] = $request->get('dynamic_segment', []);
        } else if ($filterType == 'advanced_filters') {
            $subscribersSettings['advanced_filters'] = Helper::parseArrayOrJson($request->get('advanced_filters'));
        } else {
            return [
                'count' => 0
            ];
        }

        $count = (new Campaign())->getSubscriberIdsCountBySegmentSettings($subscribersSettings);

        return [
            'count'          => $count,
            'execution_time' => microtime(true) - $start_time
        ];
    }

    public function deleteCampaignEmails(Request $request, $campaignId)
    {
        $selectionIds = array_filter($request->get('email_ids'), 'intval');

        if ($selectionIds) {
            CampaignEmail::where('campaign_id', $campaignId)
                ->whereIn('id', $selectionIds)
                ->delete();
        }

        $newCount = CampaignEmail::where('campaign_id', $campaignId)
            ->count();

        Campaign::where('id', $campaignId)->update([
            'recipients_count' => $newCount
        ]);

        return $this->sendSuccess([
            'message'          => __('Selected emails have been deleted', 'fluent-crm'),
            'recipients_count' => $newCount
        ]);
    }

    public function schedule(Request $request, $campaignId)
    {
        $scheduleAt = $request->get('scheduled_at');
        $campaign = Campaign::findOrFail($campaignId);

        if ($campaign->status != 'draft') {
            return $this->sendError([
                'message' => __('Campaign is not in draft status. Please reload the page.', 'fluent-crm')
            ], 422);
        }

        if (!$campaign->recipients_count) {
            return $this->sendError([
                'message' => __('No recipients found for this campaign. Please add recipients first.', 'fluent-crm')
            ], 422);
        }

        fluentCrmDb()->beginTransaction();

        try {
        CampaignEmail::where('campaign_id', $campaignId)->delete();
        fluentcrm_update_campaign_meta($campaign->id, '_recipient_processed', 0);
        fluentcrm_update_campaign_meta($campaign->id, '_last_recipient_id', 0);
        CampaignProcessor::clearQueueFailures($campaign->id);

        if ($scheduleAt) {
            $sendingType = $request->get('sending_type', 'schedule');
            if ($sendingType == 'range_schedule') {
                $isInvalid = true;
                if (is_array($scheduleAt) && count($scheduleAt) == 2) {
                    $scheduleStartAt = sanitize_text_field($scheduleAt[0]);

                    if ($scheduleStartAt && strtotime($scheduleStartAt) < current_time('timestamp')) {
                        $scheduleStartAt = current_time('mysql');
                    }

                    $scheduleEndAt = sanitize_text_field($scheduleAt[1]);

                    // Both bounds must parse; a zero-date start would send instantly under non-strict SQL.
                    $scheduleStartTs = $scheduleStartAt ? strtotime($scheduleStartAt) : false;
                    $scheduleEndTs = $scheduleEndAt ? strtotime($scheduleEndAt) : false;

                    if ($scheduleStartTs && $scheduleEndTs && $scheduleStartTs < $scheduleEndTs) {
                        $isInvalid = false;
                    }

                    $scheduleAt = [$scheduleStartAt, $scheduleEndAt];
                }

                if ($isInvalid) {
                    fluentCrmDb()->rollBack();
                    return $this->sendError([
                        'message' => __('Invalid schedule date range', 'fluent-crm')
                    ], 422);
                }

                $settings = $campaign->settings;
                $settings['sending_type'] = 'range_schedule';
                $settings['schedule_range'] = [strtotime($scheduleAt[0]), strtotime($scheduleAt[1])];

                $data = [
                    'status'           => 'pending-scheduled',
                    'updated_at'       => fluentCrmTimestamp(),
                    'scheduled_at'     => $scheduleAt[0],
                    'recipients_count' => 0,
                    'settings'         => $settings
                ];

            } else {
                $scheduleAt = sanitize_text_field($scheduleAt);
                $scheduleTs = $scheduleAt ? strtotime($scheduleAt) : false;
                if (!$scheduleTs) {
                    fluentCrmDb()->rollBack();
                    return $this->sendError([
                        'message' => __('Invalid schedule date', 'fluent-crm')
                    ], 422);
                }

                // A time in the past would be sent on the next cron run; five minutes of grace
                // covers time spent on the review screen. Send now has its own path.
                if ($scheduleTs < current_time('timestamp') - 5 * MINUTE_IN_SECONDS) {
                    fluentCrmDb()->rollBack();
                    return $this->sendError([
                        'message' => sprintf(
                            /* translators: %s: the current date and time on the site */
                            __('The schedule time is in the past for this site. Current site time: %s', 'fluent-crm'),
                            current_time('mysql')
                        )
                    ], 422);
                }

                $settings = $campaign->settings;
                $settings['sending_type'] = 'schedule';

                $data = [
                    'status'           => 'pending-scheduled',
                    'updated_at'       => fluentCrmTimestamp(),
                    'scheduled_at'     => $scheduleAt,
                    'recipients_count' => 0,
                    'settings'         => $settings
                ];
            }

            $message = __('Your campaign email has been scheduled', 'fluent-crm');

        } else {
            $message = __('Email sending will start soon', 'fluent-crm');
            $settings = $campaign->settings;
            $settings['sending_type'] = 'instant';
            $data = [
                'status'           => 'processing',
                'updated_at'       => fluentCrmTimestamp(),
                'scheduled_at'     => fluentCrmTimestamp(),
                'recipients_count' => 0,
                'settings'         => $settings
            ];
        }

        $data['settings']['click_tracker'] = fluentcrmTrackClicking();
        $data['settings']['open_tracker'] = fluentcrmTrackEmailOpen();

        $data['settings'] = maybe_serialize($data['settings']);

        // Only transition from 'draft', so concurrent requests cannot both schedule.
        $updated = Campaign::where('id', $campaignId)
            ->where('status', 'draft')
            ->update($data);

        if (!$updated) {
            fluentCrmDb()->rollBack();
            return $this->sendError([
                'message' => __('Campaign is no longer in draft status. Please reload the page.', 'fluent-crm')
            ], 422);
        }

            fluentCrmDb()->commit();
        } catch (\Throwable $e) {
            fluentCrmDb()->rollBack();
            return $this->sendError([
                'message' => __('Failed to schedule campaign. Please try again.', 'fluent-crm')
            ], 500);
        }

        if (!$scheduleAt) {
            $url = add_query_arg([
                'action'      => 'fluentcrm-post-campaigns-emails-processing',
                'campaign_id' => $campaignId,
                'time'        => time()
            ], admin_url('admin-ajax.php'));

            \FluentCrm\App\Services\Libs\Mailer\Handler::fireNonBlockingRequest($url, [
                'retry' => 1
            ]);
        }

        $campaign = Campaign::findOrFail($campaignId);

        fluentcrm_update_campaign_meta($campaign->id, '_campaign_sent_by', get_current_user_id());

        if ($scheduleAt) {
            do_action('fluent_crm/campaign_scheduled', $campaign, $campaign->scheduled_at);
        } else {
            do_action('fluent_crm/campaign_set_send_now', $campaign);
        }

        return $this->sendSuccess([
            'campaign'          => $campaign,
            'message'           => $message,
            'current_timestamp' => fluentCrmTimestamp()
        ]);
    }

    public function processingStat(Request $request, $campaignId)
    {
        $campaign = Campaign::withoutGlobalScope('type')
            ->with(['subjects'])
            ->whereIn('type', fluentCrmAutoProcessCampaignTypes())
            ->findOrFail($campaignId);

        if ($campaign->status == 'pending-scheduled' && (strtotime($campaign->scheduled_at) - current_time('timestamp')) < 360) {
            $campaign->status = 'processing';
            $campaign->recipients_count = 0;
            $campaign->save();
            do_action('fluent_crm/campaign_processing_start', $campaign);
        }

        if ($campaign->status != 'processing') {
            if ($campaign->status == 'scheduled' && current_time('timestamp') - strtotime($campaign->scheduled_at) > 300) {
                if (Scheduler::markArchiveCampaigns()) {
                    $campaign = Campaign::withoutGlobalScope('type')
                        ->with(['subjects'])
                        ->findOrFail($campaignId);
                }
            }

            return [
                'reload'   => true,
                'campaign' => $campaign
            ];
        }

        $processor = (new CampaignProcessor($campaign->id));
        $processingChunk = (int)apply_filters('fluent_crm/campaign_processing_stat_chunk', 30, $campaign);
        if ($processingChunk < 1) {
            $processingChunk = 1;
        }

        $processingRunTime = (int)apply_filters('fluent_crm/campaign_processing_stat_runtime_seconds', 10, $campaign);
        if ($processingRunTime < 1) {
            $processingRunTime = 1;
        }

        $processedCampaign = $processor->processEmails($processingChunk, $processingRunTime);

        $didRun = false;
        if ($processedCampaign) {
            $campaign = $processedCampaign;
            $campaign->load('subjects');
            $didRun = true;
        }

        $campaign->scheduling_range = $campaign->rangedScheduleDates();

        return [
            'campaign'          => $campaign,
            'didRun'            => $didRun,
            'scheduling_method' => $processor->getSchedulingMethod()
        ];
    }

    public function sendTestEmail()
    {
        // A test email is not the contact's real email: footer links render inert, reset links as placeholders.
        if (!defined('FLUENTCRM_PREVIEWING_EMAIL')) {
            define('FLUENTCRM_PREVIEWING_EMAIL', true);
        }

        $isTest = $this->request->get('test_campaign') == 'yes';

        add_action('wp_mail_failed', function ($wpError) {
            Helper::debugLog(
                'Test Email failed',
                $wpError->get_error_message(),
                'error'
            );
        }, 10, 1);

        if ($isTest) {
            $campaign = (object)$this->request->get('campaign');
            $emailSubject = $campaign->email_subject;

            if (empty($campaign->settings)) {
                $campaign->settings = [
                    'template_config' => []
                ];
            }

            if (!empty($campaign->subjects) && is_array($campaign->subjects)) {
                $validSubjects = array_filter($campaign->subjects, function ($subject) {
                    return trim((string)Arr::get((array)$subject, 'value', ''));
                });

                if ($validSubjects) {
                    // Quick test uses the first A/B subject.
                    $subject = reset($validSubjects);
                    $emailSubject = Arr::get((array)$subject, 'value', $emailSubject);
                }
            }

            $campaignEmail = (object)[
                'email_subject'    => $emailSubject,
                'email_pre_header' => $campaign->email_pre_header,
                'email_body'       => $campaign->email_body
            ];
        } else {
            $campaignId = $this->request->get('campaign_id');
            $campaign = Campaign::findOrFail($campaignId);
            // Never a queued row: its body was parsed for that row's contact and can hold their access links.
            $campaignEmail = (object)[
                'email_subject'    => $campaign->email_subject,
                'email_pre_header' => $campaign->email_pre_header,
                'email_body'       => $campaign->email_body
            ];
        }

        $email = trim((string)$this->request->get('email', ''));

        // Validate before sanitizing: sanitize_email() can turn a malformed list into a sendable address.
        if ($email && !is_email($email)) {
            return $this->sendError([
                'message' => __('Please provide a single valid email address to send the test email', 'fluent-crm')
            ]);
        }

        $email = sanitize_email($email);

        if (!$email) {
            $user = get_user_by('ID', get_current_user_id());
            $email = $user->user_email;
        }

        ShortcodeParser::startWithheldLog();

        $emailBody = $campaignEmail->email_body ?: $campaign->email_body;

        $subscriber = Subscriber::where('email', $email)->first();
        if (!$subscriber) {
            $subscriber = Subscriber::where('status', 'subscribed')->first();
        }

        if (!$subscriber) {
            return $this->sendError([
                'message' => __('No subscribed contact found to send the test to. Please add at least one contact with the Subscribed status.', 'fluent-crm')
            ]);
        }

        $designTemplate = $campaign->design_template;

        $rawTemplates = [
            'raw_html',
            'visual_builder',
            'raw_classic'
        ];

        if (in_array($designTemplate, $rawTemplates)) {
            $emailBody = $campaign->email_body;
        } else {
            $emailBody = (new BlockParser($subscriber))->parse($emailBody);
        }

        $emailFooterConfig = Helper::getFooterConfig($campaign);
        $emailFooter = Arr::get($emailFooterConfig, 'footer_content', '');

        $emailSubject = $campaignEmail->email_subject;

        $preHeader = (!empty($campaign->email_pre_header)) ? $campaign->email_pre_header : '';

        if ($subscriber) {
            /**
             * Determine the email campaign body text before it is sent.
             *
             * This filter allows you to modify the email body content for a campaign before it is sent to the subscriber.
             *
             * @param string $emailBody The email body content.
             * @param object $subscriber The subscriber object.
             *
             * @return string The filtered email body content.
             * @since 2.7.0
             *
             */
            $emailBody = apply_filters('fluent_crm/parse_campaign_email_text', $emailBody, $subscriber);
            /**
             * Determine the email footer text for a campaign.
             *
             * This filter allows you to modify the email footer text for a campaign before it is sent to a subscriber.
             *
             * @param string $emailFooter The email footer text.
             * @param object $subscriber The subscriber object.
             * @since 2.7.0
             *
             */
            $emailFooter = apply_filters('fluent_crm/parse_campaign_email_text', $emailFooter, $subscriber);

            $emailFooterConfig['footer_content'] = $emailFooter;

            /**
             * Determine the email campaign subject text.
             *
             * This filter allows you to modify the email subject text for a campaign.
             *
             * @param string $emailSubject The email subject text.
             * @param object $subscriber The subscriber object.
             * @since 2.7.0
             *
             */
            $emailSubject = apply_filters('fluent_crm/parse_campaign_email_text', $emailSubject, $subscriber);
            /**
             * Determine the pre-header text of the campaign email.
             *
             * This filter allows you to modify the pre-header text of the campaign email before it is sent to the subscriber.
             *
             * @param string $preHeader The pre-header text of the campaign email.
             * @param object $subscriber The subscriber object containing subscriber details.
             *
             * @return string The filtered pre-header text.
             * @since 2.7.0
             *
             */
            $preHeader = apply_filters('fluent_crm/parse_campaign_email_text', $preHeader, $subscriber);
        }

        $templateData = [
            'preHeader'     => $preHeader,
            'email_body'    => $emailBody,
            'footer_text'   => $emailFooter,
            'footer_config' => $emailFooterConfig,
            'config'        => wp_parse_args(Arr::get($campaign->settings, 'template_config', []), Helper::getTemplateConfig($campaign->design_template))
        ];

        /**
         * Determine the email body content based on the design template type.
         *
         * This filter allows modification of the email body content based on the specified design template.
         *
         * @param string $emailBody The email body content.
         * @param array $templateData The data used for the email template.
         * @param object $campaign The campaign object.
         * @param object $subscriber The subscriber object.
         * @since 2.5.1
         *
         */
        $emailBody = apply_filters(
            'fluent_crm/email-design-template-' . $campaign->design_template,
            $emailBody,
            $templateData,
            $campaign,
            $subscriber
        );


        $emailBody = str_replace('{{crm_global_email_footer}}', $emailFooter, $emailBody);
        $emailBody = str_replace('{{crm_preheader_text}}', $preHeader, $emailBody);

        $data = [
            'to'      => [
                'email' => $email,
                'name'  => $subscriber->full_name
            ],
            'subject' => 'TEST: ' . $emailSubject,
            'body'    => $emailBody,
            'headers' => Helper::getMailHeadersFromSettings(Arr::get($campaign->settings, 'mailer_settings', []))
        ];

        $withheld = ShortcodeParser::stopWithheldLog();

        Helper::maybeDisableEmojiOnEmail();
        $result = Mailer::send($data, $subscriber, null, true);

        return [
            // Codes that show their fallback because they only resolve in the contact's own email.
            'withheld_smartcodes' => $withheld,
            'message' => sprintf(
                /* translators: %1$s: recipient email address of the test email */
                __('Test email sent to %1$s. Smartcodes may not be replaced in the test email.', 'fluent-crm'),
                $email
            ),
            'result'  => $result
        ];
    }

    /**
     * Render the editor/draft email preview. Sent-email previews use previewEmail().
     *
     * @return array
     */
    public function getEmailPreviewBody()
    {
        if (!defined('FLUENTCRM_PREVIEWING_EMAIL')) {
            define('FLUENTCRM_PREVIEWING_EMAIL', true);
        }

        $campaignId = $this->request->get('campaign_id');

        if ($campaignId) {
            $campaignId = (int)$campaignId;
            $campaign = Campaign::withoutGlobalScope('type')->with(['subjects'])->findOrfail($campaignId);
        } else {
            $campaign = $this->request->get('campaign', []);
            if (isset($campaign['post_content'])) {
                $campaign['email_body'] = $campaign['post_content'];
            }

            if (isset($campaign['post_excerpt'])) {
                $campaign['email_pre_header'] = sanitize_text_field($campaign['post_excerpt']);
            }

            $campaign = (object)$campaign;
        }

        ShortcodeParser::startWithheldLog();

        $emailBody = $campaign->email_body;

        if ($this->request->get('contact_id')) {
            $subscriber = Subscriber::find($this->request->getSafe('contact_id', 'sanitize_text_field'));
        } else {
            $subscriber = fluentcrm_get_current_contact();
        }

        if (!$subscriber) {
            $subscriber = Subscriber::where('status', 'subscribed')->first();
        }

        if ($this->request->get('disable_subscriber') == 'yes') {
            $subscriber = null;
        }

        $designTemplate = $campaign->design_template;

        if (!$designTemplate) {
            $designTemplate = 'plain';
        }

        $rawTemplates = [
            'raw_html',
            'visual_builder',
            'raw_classic'
        ];

        if (in_array($designTemplate, $rawTemplates)) {
            $emailBody = wp_unslash($campaign->email_body);
        } else {
            $emailBody = (new BlockParser($subscriber))->parse($emailBody);
        }

        if (empty($campaign->settings)) {
            $campaign->settings = [];
        }

        $emailFooterConfig = Helper::getFooterConfig($campaign);
        $emailFooter = Arr::get($emailFooterConfig, 'footer_content', '');
        // Inbox preview subject: first A/B variant (a model or an array, depending on the source), else the campaign subject.
        $subjects = $campaign->subjects ?? [];
        $firstSubject = is_array($subjects) ? reset($subjects) : $subjects->first();
        $previewSubject = !empty($firstSubject['value']) ? $firstSubject['value'] : (string)($campaign->email_subject ?? '');

        if ($subscriber) {
            /**
             * Determine the campaign email body content text.
             *
             * This filter allows you to modify the email body content before it is sent to the subscriber.
             *
             * @param string $emailBody The original email body content.
             * @param object $subscriber The subscriber object containing subscriber details.
             *
             * @return string The filtered email body content.
             * @since 2.7.0
             *
             */
            $emailBody = apply_filters('fluent_crm/parse_campaign_email_text', $emailBody, $subscriber);
            /**
             * Determine the campaign email footer text.
             *
             * This filter allows you to modify the email footer content before it is sent to the subscriber.
             *
             * @param string $emailFooter The original email footer content.
             * @param object $subscriber The subscriber object containing subscriber details.
             *
             * @return string The filtered email footer content.
             * @since 2.7.0
             *
             */
            $emailFooter = apply_filters('fluent_crm/parse_campaign_email_text', $emailFooter, $subscriber);
            $previewSubject = apply_filters('fluent_crm/parse_campaign_email_text', $previewSubject, $subscriber);
        }

        $preHeader = (!empty($campaign->email_pre_header)) ? $campaign->email_pre_header : '';

        if ($preHeader && $subscriber) {
            /**
             * Determine the campaign email Pre-header text before sending.
             *
             * This filter allows you to modify the email pre-header content for a campaign before it is sent to the subscriber.
             *
             * @param string $emailBody The email body content.
             * @param object $subscriber The subscriber object.
             * @since 2.7.0
             *
             */
            $preHeader = apply_filters('fluent_crm/parse_campaign_email_text', $preHeader, $subscriber);
        }

        $emailFooterConfig['footer_content'] = $emailFooter;

        $templateData = [
            'preHeader'     => $preHeader,
            'email_body'    => $emailBody,
            'footer_text'   => $emailFooter,
            'footer_config' => $emailFooterConfig,
            'config'        => wp_parse_args(Arr::get($campaign->settings, 'template_config', []), Helper::getTemplateConfig($campaign->design_template))
        ];

        /**
         * Determine the email body content based on the design template type.
         *
         * This filter allows modification of the email body content based on the specified design template.
         *
         * @param string $emailBody The email body content.
         * @param array $templateData The data used for the email template.
         * @param object $campaign The campaign object.
         * @param object $subscriber The subscriber object.
         * @since 2.5.1
         *
         */
        $emailBody = apply_filters(
            'fluent_crm/email-design-template-' . $designTemplate,
            $emailBody,
            $templateData,
            $campaign,
            $subscriber
        );

        if (Str::contains($emailBody, ['{{crm', '##crm'])) {
            $emailBody = str_replace(['{{crm_global_email_footer}}', '{{crm_preheader_text}}'], [$emailFooter, $preHeader], $emailBody);
            if (Str::contains($emailBody, ['##crm.', '{{crm.'])) {
                /**
                 * Determine the email body content including a specific Smartcode for a subscriber.
                 *
                 * This filter allows modification of the email body content including a smartcode before it is sent to a subscriber.
                 *
                 * @param string $emailBody The email body content.
                 * @param object $subscriber The subscriber object.
                 *
                 * @return string The filtered email body content.
                 * @since 2.7.0
                 *
                 */
                $emailBody = apply_filters('fluent_crm/parse_extended_crm_text', $emailBody, $subscriber);
            }
        }

        $emailBody = str_replace(['https://fonts.googleapis.com/css2', 'https://fonts.googleapis.com/css'], 'https://fonts.bunny.net/css', $emailBody);

        return [
            'preview_html'        => $emailBody,
            'preview_subject'     => $previewSubject,
            'subjects'            => !empty($campaign->subjects) ? $campaign->subjects : [],
            'sender'              => Helper::getPreviewSender(Arr::get($campaign->settings, 'mailer_settings', [])),
            // Codes that show their fallback here because they only resolve in the contact's own email.
            'withheld_smartcodes' => ShortcodeParser::stopWithheldLog()
        ];
    }

    public function unsubscribe()
    {
        $campaignId = $this->request->get('campaign_id');

        $subscriberIds = (array)$this->request->get('subscriber_ids');

        $campaign = Campaign::findOrFail($campaignId);

        $campaign->unsubscribe($subscriberIds);

        return $this->sendSuccess(compact('campaign'));
    }

    public function delete(Request $request, $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);

        $campaign->deleteCampaignData();
        $campaign->delete();
        do_action('fluent_crm/campaign_deleted', $campaignId);

        return $this->send(['success' => true]);
    }

    public function handleBulkAction(Request $request)
    {
        $actionName = $request->getSafe('action_name', 'sanitize_text_field', '');
        // Only the ids the user selected; there is no "every campaign" mode.
        $campaignIds = array_map('intval', (array)$request->get('campaign_ids', []));
        $campaignIds = array_unique(array_filter($campaignIds));

        if (!$campaignIds) {
            return $this->sendError([
                'message' => __('Please provide campaign IDs', 'fluent-crm')
            ]);
        }

        if ($actionName == 'apply_labels') {
            $newLabelIds = (array)$request->get('labels', []);
            $newLabelIds = array_map('intval', $newLabelIds);
            $newLabelIds = array_unique(array_filter($newLabelIds));

            if (!$newLabelIds) {
                return $this->sendError([
                    'message' => __('Please provide labels', 'fluent-crm')
                ]);
            }

            $campaigns = Campaign::whereIn('id', $campaignIds)->get();

            foreach ($campaigns as $campaign) {
                $campaign->attachLabels($newLabelIds);
            }

            return $this->sendSuccess([
                'message' => __('The labels have been applied.', 'fluent-crm'),
            ]);
        }

        if ($actionName == 'delete_campaigns') {
            $campaigns = Campaign::whereIn('id', $campaignIds)->get();
            foreach ($campaigns as $campaign) {
                $campaignId = $campaign->id;
                $campaign->deleteCampaignData();
                $campaign->delete();
                do_action('fluent_crm/campaign_deleted', $campaignId);
            }

            return $this->sendSuccess([
                'message' => __('Selected campaigns have been permanently deleted', 'fluent-crm'),
            ]);
        }

        return $this->sendError([
            'message' => __('Invalid bulk action', 'fluent-crm')
        ]);
    }

    public function createTemplate()
    {
        $templateId = $this->request->get('template_id');
        $campaignId = $this->request->get('campaign_id');
        $campaign = Campaign::findOrFail($campaignId);

        $template = Template::emailTemplates()->find($templateId);
        if (!$template) {
            return $this->sendError([
                'message' => __('Template not found', 'fluent-crm')
            ], 404);
        }

        $template = Template::emailTemplates()->find($templateId);

        if (!$template) {
            return $this->sendError([
                'message' => __('Template not found', 'fluent-crm')
            ], 404);
        }

        return $this->send([
            'id' => Template::create([
                'post_type'    => fluentcrmCampaignTemplateCPTSlug(),
                'post_content' => $template->post_content
            ])->ID
        ]);
    }

    /**
     * Render a sent/scheduled email preview with metadata and click stats.
     * CampaignEmail::previewData() must handle templates the same way as getEmailPreviewBody().
     *
     * @param Request $request
     * @param int     $emailId
     * @return array
     */
    public function previewEmail(Request $request, $emailId)
    {
        if (!defined('FLUENTCRM_PREVIEWING_EMAIL')) {
            define('FLUENTCRM_PREVIEWING_EMAIL', true);
        }

        $email = CampaignEmail::findOrFail($emailId);

        ShortcodeParser::startWithheldLog();
        $emailData = $email->previewData();
        $emailData['withheld_smartcodes'] = ShortcodeParser::stopWithheldLog();
        $emailData['clicks'] = $email->getClicks();

        return $this->sendSuccess([
            'info'  => $email,
            'email' => $emailData
        ]);
    }

    public function getCampaignStatus(Request $request, $campaignId)
    {
        $campaign = Campaign::withoutGlobalScope('type')
            ->with(['subjects'])
            ->whereIn('type', fluentCrmAutoProcessCampaignTypes())
            ->findOrFail($campaignId);

        if ($campaign->status == 'processing' || $campaign->status == 'pending-scheduled') {
            return [
                'current_timestamp' => fluentCrmTimestamp(),
                'stat'              => [],
                'campaign'          => $campaign,
                'sent_count'        => 0,
                'analytics'         => (object)[],
                'subject_analytics' => (object)[]
            ];
        }

        if ($campaign->status == 'scheduled' && $campaign->scheduled_at) {
            if (strtotime($campaign->scheduled_at) < strtotime(current_time('mysql'))) {
                $campaign->status = 'working';
                $campaign->save();

                \FluentCrm\App\Hooks\Handlers\Scheduler::fireSendNowRequest();
            }
        }

        $ranged = null;

        if ($campaign->status == 'working') {
            $ranged = $campaign->rangedScheduleDates();
            if (!$ranged) {
                // Stale-email recovery belongs to Scheduler::resetStaleProcessingEmails(), not this polling route.
                // Stall detection keys off _last_called, which Handler::isSystemOk() stamps on every lock-winning run.
                $lastCalled = (int)fluentcrm_get_option('fluentcrm_is_sending_emails_last_called');
                if (!$lastCalled || (time() - $lastCalled) > 140) {
                    // Stuck for over 140s: re-fire the sender at most once per 60s.
                    $lastRefire = fluentCrmGetOptionCache('_fcrm_last_stuck_refire', 200);
                    if (!$lastRefire || (time() - $lastRefire) > 60) {
                        fluentCrmSetOptionCache('_fcrm_last_stuck_refire', time(), 200);

                        // Do not clear the sending lock here; acquireLock() takes over an expired one.
                        wp_remote_post(admin_url('admin-ajax.php'), [
                            'sslverify' => false,
                            'blocking'  => false,
                            'cookies'   => array(),
                            'body'      => [
                                'campaign_id' => $campaignId,
                                'retry'       => 1,
                                'time'        => time(),
                                'action'      => 'fluentcrm-post-campaigns-send-now'
                            ]
                        ]);
                    }
                }
            }
        }

        $analytics = [];
        $subjectsAnalytics = [];

        $sentCount = CampaignEmail::select('id')
            ->where('campaign_id', $campaignId)
            ->whereIn('status', CampaignEmail::SENT_STATUSES)
            ->count();

        if ($campaign->status == 'working') {

            $campaign->scheduling_range = $ranged;

            $processingCount = CampaignEmail::select('id')
                ->where('campaign_id', $campaignId)
                ->where('status', 'processing')
                ->count();

            if (!$processingCount && $sentCount) {
                // 'scheduling' rows are mid-materialization and count as future work.
                $futureCount = CampaignEmail::select('id')
                    ->where('campaign_id', $campaignId)
                    ->whereIn('status', ['pending', 'scheduled', 'paused', 'processing', 'draft', 'scheduling'])
                    ->count();

                if (!$futureCount) {
                    Campaign::withoutGlobalScope('type')->where('id', $campaign->id)->update([
                        'status'     => 'archived',
                        'updated_at' => current_time('mysql')
                    ]);
                    $campaign = Campaign::withoutGlobalScope('type')->with(['subjects'])->findOrFail($campaignId);

                    do_action('fluent_crm/campaign_archived', $campaign);
                }
            }
        }

        if ($campaign->status == 'archived') {
            $campaignUrlMetric = new CampaignUrlMetric();
            $analytics = $campaignUrlMetric->getCampaignAnalytics($campaign);

            if (isset($analytics['open']) && $analytics['open']['total'] > $sentCount) {
                $analytics['open']['total'] = $sentCount;
            }

            if (isset($analytics['click']) && $analytics['click']['total'] > $sentCount) {
                $analytics['click']['total'] = $sentCount;
            }

            $subjectsAnalytics = $campaignUrlMetric->getSubjectStats($campaign);

            $campaign->open_tracking_status = $campaign->getOpenTrackingStatus();
            $campaign->click_tracking_status = $campaign->getClickTrackingStatus();
        }

        $stat = CampaignEmail::select('status', fluentCrmDb()->raw('count(*) as total'))
            ->where('campaign_id', $campaignId)
            ->groupBy('status')
            ->get();

        $campaignSentBy = $this->getCampaignSentData($campaign->id);
        $campaign->sent_by = $campaignSentBy;

        return $this->sendSuccess([
            'current_timestamp' => fluentCrmTimestamp(),
            'stat'              => $stat,
            'campaign'          => $campaign,
            'sent_count'        => $sentCount,
            'analytics'         => (object)$analytics,
            'subject_analytics' => (object)$subjectsAnalytics
        ], 200);
    }

    public function getOverviewStats(Request $request, CampaignUrlMetric $campaignUrlMetric, $campaignId)
    {
        $campaign = Campaign::withoutGlobalScope('type')
            ->whereIn('type', fluentCrmAutoProcessCampaignTypes())
            ->findOrFail($campaignId);

        $sentCount = CampaignEmail::select('id')
            ->where('campaign_id', $campaignId)
            ->whereIn('status', CampaignEmail::SENT_STATUSES)
            ->count();


        $analytics = $campaignUrlMetric->getCampaignAnalytics($campaignId);

        if (isset($analytics['open']) && $analytics['open']['total'] > $sentCount) {
            $analytics['open']['total'] = $sentCount;
        }

        if (isset($analytics['click']) && $analytics['click']['total'] > $sentCount) {
            $analytics['click']['total'] = $sentCount;
        }

        $stat = CampaignEmail::select('status', fluentCrmDb()->raw('count(*) as total'))
            ->where('campaign_id', $campaignId)
            ->groupBy('status')
            ->get();

        return [
            'sent_count' => $sentCount,
            'stat'       => $stat,
            'analytics'  => $analytics
        ];
    }

    public function pauseCampaign(Request $request, $id)
    {
        $campaign = Campaign::findOrFail($id);

        if ($campaign->status != 'working') {
            return $this->sendError([
                'message' => __('You can only pause a campaign that is in the "Working" state. Please reload this page.', 'fluent-crm')
            ]);
        }

        $campaign->pauseSending();

        $campaign = Campaign::findOrFail($id);

        return [
            'message'  => __('The campaign has been paused.', 'fluent-crm'),
            'campaign' => $campaign
        ];
    }

    public function resumeCampaign(Request $request, $id)
    {
        $campaign = Campaign::findOrFail($id);

        if ($campaign->status != 'paused') {
            return $this->sendError([
                'message' => __('You can only resume a campaign that is in the "paused" state. Please reload this page.', 'fluent-crm')
            ]);
        }

        $campaign->status = 'working';
        $campaign->save();

        // Keep each row's scheduled_at so range-scheduled pacing resumes; only unscheduled rows get now.
        CampaignEmail::where('campaign_id', $campaign->id)
            ->where('status', 'paused')
            ->whereNotNull('scheduled_at')
            ->update([
                'status' => 'scheduled'
            ]);

        CampaignEmail::where('campaign_id', $campaign->id)
            ->where('status', 'paused')
            ->whereNull('scheduled_at')
            ->update([
                'status'       => 'scheduled',
                'scheduled_at' => current_time('mysql')
            ]);

        \FluentCrm\App\Hooks\Handlers\Scheduler::fireSendNowRequest();

        return [
            'message'  => __('The campaign has been resumed.', 'fluent-crm'),
            'campaign' => Campaign::findOrFail($id)
        ];
    }

    public function updateCampaignTitle(Request $request, $id)
    {
        $campaign = Campaign::findOrFail($id);
        $campaign->title = sanitize_text_field($request->get('title'));
        $campaign->save();

        if ($campaign->status == 'scheduled') {
            $newTime = $request->get('scheduled_at');
            // Reschedule only on a valid new time; a title-only payload must not null the schedule.
            if ($newTime && strtotime($newTime) && $newTime != $campaign->scheduled_at) {
                $campaign->scheduled_at = $newTime;
                $campaign->save();
                CampaignEmail::where('campaign_id', $campaign->id)
                    ->whereNotIn('status', ['sent', 'failed', 'bounced'])
                    ->update([
                        'status'       => 'scheduled',
                        'scheduled_at' => $newTime
                    ]);
            }
        }

        return [
            'message'  => __('Campaign has been updated', 'fluent-crm'),
            'campaign' => Campaign::findOrFail($id)
        ];
    }

    public function duplicateCampaign(Request $request, $id)
    {
        $oldCampaign = Campaign::findOrFail($id);

        /* translators: %s: title of the campaign being duplicated */
        $title = sprintf(__('[Duplicate] %s', 'fluent-crm'), $oldCampaign->title);

        // Campaign titles must be unique: number repeat copies (2) to (4), then use the date and time.
        $uniqueTitle = $title;
        for ($n = 2; $n <= 4 && Campaign::where('title', $uniqueTitle)->exists(); $n++) {
            $uniqueTitle = $title . ' (' . $n . ')';
        }
        if (Campaign::where('title', $uniqueTitle)->exists()) {
            $uniqueTitle = $title . ' (' . current_time('Y-m-d H:i:s') . ')';
        }

        $newCampaign = [
            'title'            => $uniqueTitle,
            'slug'             => $oldCampaign->slug . '-' . time(),
            'email_body'       => $oldCampaign->email_body,
            'status'           => 'draft',
            'template_id'      => $oldCampaign->template_id,
            'email_subject'    => $oldCampaign->email_subject,
            'email_pre_header' => $oldCampaign->email_pre_header,
            'utm_status'       => $oldCampaign->utm_status,
            'utm_source'       => $oldCampaign->utm_source,
            'utm_medium'       => $oldCampaign->utm_medium,
            'utm_campaign'     => $oldCampaign->utm_campaign,
            'utm_term'         => $oldCampaign->utm_term,
            'utm_content'      => $oldCampaign->utm_content,
            'design_template'  => $oldCampaign->design_template,
            'created_by'       => get_current_user_id(),
            'settings'         => $oldCampaign->settings
        ];
        $labelIds = $oldCampaign->getFormattedLabels()->pluck('id')->toArray();

        $campaign = Campaign::create($newCampaign);
        $campaign->attachLabels($labelIds);

        $campaign->duplicateSubjects($oldCampaign);

        do_action('fluent_crm/campaign_duplicated', $campaign, $oldCampaign);

        return [
            'campaign' => $campaign,
            'message'  => __('The campaign has been duplicated.', 'fluent-crm')
        ];

    }

    public function unSchedule(Request $request, $id)
    {
        $campaign = Campaign::withoutGlobalScope('type')
            ->whereIn('type', fluentCrmAutoProcessCampaignTypes())
            ->findOrFail($id);

        $validStatuses = [
            'scheduled',
            'pending-scheduled',
            'processing'
        ];

        if (!in_array($campaign->status, $validStatuses)) {
            return $this->sendError([
                'message' => __('You can only unschedule a campaign that is in the "scheduled" state. Please reload this page.', 'fluent-crm')
            ]);
        }

        if ($campaign->status == 'processing' && strtotime($campaign->scheduled_at) < current_time('timestamp')) {
            return $this->sendError([
                'message' => __('You can only unschedule a campaign that is in the "scheduled" state. Please reload this page.', 'fluent-crm')
            ]);
        }

        // Unscheduling would delete what was already sent and let the next schedule mail everyone
        // again, so a campaign that has started sending is paused instead.
        if ($campaign->hasStartedSending()) {
            $campaign->pauseSending();

            return [
                'message'  => __('This campaign had already started sending, so it was paused instead of unscheduled. Emails already sent are kept; resume the campaign to send the rest.', 'fluent-crm'),
                'paused'   => true,
                'campaign' => Campaign::withoutGlobalScope('type')->find($campaign->id)
            ];
        }

        $campaign->status = 'draft';
        $campaign->save();

        // Only unsent emails are removed; sent history is never deleted here.
        CampaignEmail::where('campaign_id', $campaign->id)
            ->whereNotIn('status', CampaignEmail::STARTED_STATUSES)
            ->delete();

        CampaignEmail::withoutGlobalScope('type')->where('campaign_id', $campaign->id)
            ->whereIn('status', ['scheduled', 'scheduling'])
            ->delete();

        return [
            'message' => __('The campaign has been unscheduled.', 'fluent-crm')
        ];
    }


    public function getShareUrl($id)
    {
        $campaign = Campaign::withoutGlobalScope('type')
            ->findOrFail($id);

        return [
            'sharable_url' => $campaign->getShareableUrl()
        ];
    }

    public function updateLabels(Request $request, $funnel_id)
    {
        $funnel = Campaign::findOrFail($funnel_id);
        $action = $request->getSafe('action', 'sanitize_text_field');
        $labelIds = $request->get('label_ids');

        if (!is_array($labelIds)) {
            $labelIds = [$labelIds];
        }

        $labelIds = array_map('intval', $labelIds);
        $labelIds = array_unique(array_filter($labelIds));

        if ($action == 'detach') {
            $funnel->detachLabels($labelIds);
        }

        return $this->sendSuccess([
            'message' => __('Labels have been updated', 'fluent-crm')
        ]);

    }

    private function getCampaignSentData($campaignId)
    {
        $campaignSentById = fluentcrm_get_campaign_meta($campaignId, '_campaign_sent_by', true);
        $user = get_userdata($campaignSentById);
        if ($user) {
            return $user->display_name . ' (' . $user->user_email . ')';
        }
        return false;
    }
}
