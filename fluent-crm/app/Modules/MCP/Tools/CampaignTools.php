<?php

namespace FluentCrm\App\Modules\MCP\Tools;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Models\Subject;
use FluentCrm\App\Modules\MCP\Helpers\MCPHelper;

/**
 * Campaign-centric MCP tools.
 */
class CampaignTools
{
    // -----------------------------------------------------------------
    // Read: list-campaigns
    // -----------------------------------------------------------------

    public static function listCampaigns($params)
    {
        $params = (array) $params;

        $pagination = MCPHelper::paginationFromInput($params);

        $search = sanitize_text_field((string) ($params['search'] ?? ''));
        $statuses = (array) ($params['statuses'] ?? []);
        $statuses = array_values(array_filter(array_map('sanitize_key', $statuses)));

        // All fc_campaigns columns. orderBy() throws on an invalid column name.
        $allowedSortBy = [
            'id', 'parent_id', 'type', 'title', 'available_urls', 'slug',
            'status', 'template_id', 'email_subject', 'email_pre_header',
            'email_body', 'recipients_count', 'delay', 'utm_status',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
            'utm_content', 'design_template', 'scheduled_at', 'settings',
            'created_by', 'created_at', 'updated_at',
        ];
        $sortBy = sanitize_key((string) ($params['sort_by'] ?? 'created_at'));
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'created_at';
        }
        $sortType = strtoupper(sanitize_text_field((string) ($params['sort_type'] ?? 'DESC')));
        $sortType = in_array($sortType, ['ASC', 'DESC'], true) ? $sortType : 'DESC';
        // Stats are opt-in: they add several aggregate queries per campaign.
        $includeStats = array_key_exists('include_stats', $params)
            ? (bool) $params['include_stats']
            : false;
        // One-off sends are type='custom_email_campaign', hidden by the model's global scope.
        $includeOneOffs = !empty($params['include_one_offs']);

        if ($includeOneOffs) {
            $query = Campaign::withoutGlobalScope('type')
                ->whereIn('type', ['campaign', 'custom_email_campaign'])
                ->orderBy($sortBy, $sortType);
        } else {
            $query = Campaign::query()->orderBy($sortBy, $sortType);
        }

        if ($search !== '') {
            global $wpdb;
            $query->where('title', 'LIKE', '%' . $wpdb->esc_like($search) . '%');
        }

        if (!empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        $paginated = $query->paginate();

        $items = [];
        foreach ($paginated->items() as $campaign) {
            $items[] = MCPHelper::formatCampaignSummary($campaign, $includeStats);
        }

        return [
            'items'    => $items,
            'total'    => (int) $paginated->total(),
            'page'     => (int) $paginated->currentPage(),
            'per_page' => (int) $paginated->perPage(),
            'pages'    => (int) $paginated->lastPage(),
        ];
    }

    // -----------------------------------------------------------------
    // Read: get-campaign
    // -----------------------------------------------------------------

    public static function getCampaign($params)
    {
        $params = (array) $params;
        $campaignId = (int) ($params['campaign_id'] ?? 0);

        if (!$campaignId) {
            return MCPHelper::error('invalid_param', 'campaign_id is required');
        }

        // Bypass the type scope so one-off sends are reachable too.
        $campaign = Campaign::withoutGlobalScope('type')->find($campaignId);
        if (!$campaign) {
            return MCPHelper::error('not_found', 'Campaign not found', ['campaign_id' => $campaignId]);
        }

        $bodyFormat = $params['body_format'] ?? 'both';
        if (!in_array($bodyFormat, ['text', 'html', 'both'], true)) {
            $bodyFormat = 'both';
        }

        if ($campaign->type === 'custom_email_campaign') {
            return self::formatOneOffEmail($campaign, $bodyFormat);
        }

        $defaultIncludes = ['stats'];
        // Omitted => defaults, [] => none, explicit => those.
        if (array_key_exists('include', $params) && is_array($params['include'])) {
            $include = array_values(array_intersect($params['include'], ['stats', 'subjects', 'link_report', 'recipients_estimate']));
        } else {
            $include = $defaultIncludes;
        }

        $body = (string) $campaign->email_body;
        $bodyShape = MCPHelper::dualBodyShape($body, $bodyFormat);

        $settings = is_array($campaign->settings) ? $campaign->settings : (array) $campaign->settings;

        $recipients = self::recipientsForOutput($settings, 'subscribers');
        $recipientsExcluded = self::recipientsForOutput($settings, 'excludedSubscribers');
        $recipients = [
            'lists'         => $recipients['lists'],
            'tags'          => $recipients['tags'],
            'exclude_lists' => $recipientsExcluded['lists'],
            'exclude_tags'  => $recipientsExcluded['tags'],
        ];

        $audienceType = $settings['sending_filter'] ?? 'list_tag';
        if ($audienceType !== 'list_tag') {
            $recipients['note'] = sprintf(
                "Not used: this campaign's audience is '%s', set in the FluentCRM admin. Setting recipients through upsert-campaign switches it to these tags and lists.",
                $audienceType
            );
        }

        $sentStatuses = ['archived', 'working', 'paused'];
        $sentAt = in_array($campaign->status, $sentStatuses, true)
            ? MCPHelper::toIso8601($campaign->updated_at)
            : null;

        $data = array_merge([
            'id'                => (int) $campaign->id,
            'title'             => $campaign->title,
            'email_subject'     => $campaign->email_subject,
            'email_pre_header'  => $campaign->email_pre_header,
            'status'            => $campaign->status,
            'design_template'   => $campaign->design_template,
        ], $bodyShape, [
            'settings'          => self::settingsForOutput($settings),
            'audience_type'     => $audienceType,
            'recipients'        => $recipients,
            'scheduled_at'      => MCPHelper::toIso8601($campaign->scheduled_at),
            'scheduled_at_resolved' => MCPHelper::formatScheduledAtDual($campaign->scheduled_at),
            'sent_at'           => $sentAt,
            'created_at'        => MCPHelper::toIso8601($campaign->created_at),
        ]);

        if (in_array('stats', $include, true)) {
            $data['stats'] = MCPHelper::campaignStatsCompact($campaign);
            $data['stats']['revenue'] = self::campaignRevenue($campaign);
        }

        if (in_array('subjects', $include, true)) {
            $data['subjects'] = self::campaignSubjects($campaign);
        }

        if (in_array('link_report', $include, true)) {
            $data['link_report'] = self::campaignLinkReport($campaign);
        }

        if (in_array('recipients_estimate', $include, true)) {
            $data['recipients_estimate'] = self::campaignRecipientsEstimate($campaign);
        }

        return $data;
    }

    /**
     * Render a one-off send for get-campaign. Delivery status comes from its
     * fc_campaign_emails row as `one_off_status`; the campaign status never advances.
     */
    private static function formatOneOffEmail($campaign, $bodyFormat = 'both')
    {
        $body      = (string) $campaign->email_body;
        $bodyShape = MCPHelper::dualBodyShape($body, $bodyFormat);

        $email = \FluentCrm\App\Models\CampaignEmail::withoutGlobalScope('type')
            ->where('campaign_id', $campaign->id)
            ->orderBy('id', 'DESC')
            ->first();

        $oneOffStatus = $email ? (string) $email->status : 'unknown';
        $sentAt       = $email && in_array($email->status, ['sent', 'opened', 'clicked'], true)
            ? MCPHelper::toIso8601($email->updated_at)
            : null;

        // The recipient is contact PII; this tool only requires fcrm_read_emails.
        $canReadContacts = \FluentCrm\App\Services\PermissionManager::currentUserCan('fcrm_read_contacts');

        $recipient = null;
        if ($email && $email->subscriber_id) {
            if (!$canReadContacts) {
                $recipient = ['note' => 'Recipient hidden (requires contact read permission).'];
            } else {
                $sub = \FluentCrm\App\Models\Subscriber::find($email->subscriber_id);
                if ($sub) {
                    $recipient = [
                        'id'    => (int) $sub->id,
                        'email' => $sub->email,
                        'full_name' => trim((string) $sub->full_name),
                    ];
                }
            }
        }

        $settings = is_array($campaign->settings) ? $campaign->settings : (array) $campaign->settings;

        return array_merge([
            'id'                => (int) $campaign->id,
            'kind'              => 'one_off_email',
            'title'             => MCPHelper::oneOffTitleForOutput($campaign->title),
            'email_subject'     => $campaign->email_subject,
            'email_pre_header'  => $campaign->email_pre_header,
            'one_off_status'    => $oneOffStatus,
            'design_template'   => $campaign->design_template,
        ], $bodyShape, [
            'settings'          => self::settingsForOutput($settings),
            'recipient'         => $recipient,
            'sent_at'           => $sentAt,
            'created_at'        => MCPHelper::toIso8601($campaign->created_at),
            'note'              => 'This is a one-off send (not a marketing campaign). It supports change-campaign-status action=delete only; schedule/pause/resume/duplicate are not applicable.',
        ]);
    }

    /**
     * Convert stored [{list, tag}] recipient pairs into {lists, tags} for output.
     * 'all' is a wildcard and is omitted.
     */
    private static function recipientsForOutput($settings, $key)
    {
        $items = $settings[$key] ?? [];
        if (!is_array($items)) {
            return ['lists' => [], 'tags' => []];
        }

        $listIds = [];
        $tagIds  = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $listId = $item['list'] ?? null;
            $tagId  = $item['tag'] ?? null;
            if ($listId !== null && $listId !== '' && $listId !== 'all' && is_numeric($listId)) {
                $listIds[(int) $listId] = true;
            }
            if ($tagId !== null && $tagId !== '' && $tagId !== 'all' && is_numeric($tagId)) {
                $tagIds[(int) $tagId] = true;
            }
        }

        $listsOut = [];
        if ($listIds) {
            foreach (\FluentCrm\App\Models\Lists::whereIn('id', array_keys($listIds))->get() as $list) {
                $listsOut[] = ['id' => (int) $list->id, 'title' => $list->title];
            }
        }
        $tagsOut = [];
        if ($tagIds) {
            foreach (\FluentCrm\App\Models\Tag::whereIn('id', array_keys($tagIds))->get() as $tag) {
                $tagsOut[] = ['id' => (int) $tag->id, 'title' => $tag->title];
            }
        }

        return ['lists' => $listsOut, 'tags' => $tagsOut];
    }

    private static function settingsForOutput($settings)
    {
        return [
            'mailer_settings'  => $settings['mailer_settings'] ?? new \stdClass(),
            'is_transactional' => $settings['is_transactional'] ?? 'no',
            'click_tracker'    => $settings['click_tracker'] ?? 'yes',
            'open_tracker'     => $settings['open_tracker'] ?? 'yes',
        ];
    }

    /**
     * Campaign revenue. Meta is shaped ['orderIds' => [...], 'usd' => 12450, 'eur' => 600].
     */
    private static function campaignRevenue($campaign)
    {
        $revenue = fluentcrm_get_campaign_meta($campaign->id, '_campaign_revenue', true);
        if (!is_array($revenue)) {
            return ['total' => 0, 'currency' => '', 'orders_count' => 0, 'by_currency' => []];
        }

        $orderCount = isset($revenue['orderIds']) && is_array($revenue['orderIds'])
            ? count($revenue['orderIds'])
            : 0;

        $byCurrency = [];
        foreach ($revenue as $key => $value) {
            if ($key === 'orderIds' || !is_numeric($value)) {
                continue;
            }
            $byCurrency[strtoupper((string) $key)] = (float) $value;
        }

        // First currency is the headline; the rest stay in by_currency.
        $primaryCurrency = '';
        $primaryTotal    = 0.0;
        if ($byCurrency) {
            $primaryCurrency = (string) array_key_first($byCurrency);
            $primaryTotal    = (float) $byCurrency[$primaryCurrency];
        }

        return [
            'total'        => $primaryTotal,
            'currency'     => $primaryCurrency,
            'orders_count' => $orderCount,
            'by_currency'  => $byCurrency,
        ];
    }

    /**
     * A/B subjects from fc_meta, keyed by object_id. The Subject scope filters object_type.
     * `value` is the subject string itself.
     */
    private static function campaignSubjects($campaign)
    {
        $subjects = Subject::where('object_id', $campaign->id)->get();
        $out = [];
        foreach ($subjects as $subject) {
            $out[] = [
                'id'      => (int) $subject->id,
                'key'     => (string) $subject->key,
                'value'   => (string) $subject->value,
            ];
        }
        return $out;
    }

    /**
     * Link click report via CampaignUrlMetric::getLinksReport().
     */
    private static function campaignLinkReport($campaign)
    {
        $metric = new CampaignUrlMetric();
        $links  = method_exists($metric, 'getLinksReport') ? $metric->getLinksReport($campaign) : [];

        $formatted = [];
        foreach ((array) $links as $link) {
            $formatted[] = [
                'url'          => $link['url'] ?? '',
                'total_clicks' => (int) ($link['total'] ?? 0),
            ];
        }

        return [
            'links'        => $formatted,
            'click_status' => method_exists($campaign, 'getClickTrackingStatus') ? $campaign->getClickTrackingStatus(false) : 'yes',
            'open_status'  => method_exists($campaign, 'getOpenTrackingStatus') ? $campaign->getOpenTrackingStatus(false) : 'yes',
        ];
    }

    /**
     * First free title of the form "Title (2)", "Title (3)" for a taken base title.
     */
    private static function nextAvailableTitle($baseTitle)
    {
        $count = 2;
        while ($count < 1000) {
            $candidate = $baseTitle . ' (' . $count . ')';
            if (!Campaign::where('title', $candidate)->exists()) {
                return $candidate;
            }
            $count++;
        }
        return $baseTitle . ' (' . time() . ')';
    }

    /**
     * Estimate recipients for a campaign using its stored segment settings.
     */
    private static function campaignRecipientsEstimate($campaign)
    {
        if (!in_array($campaign->status, ['draft', 'scheduled', 'pending-scheduled'], true)) {
            return null;
        }

        $settings = is_array($campaign->settings) ? $campaign->settings : (array) maybe_unserialize($campaign->settings);

        $start = microtime(true);
        $count = self::estimateRecipientsFromSettings($settings);
        $execMs = (int) round((microtime(true) - $start) * 1000);

        return [
            'count'             => $count !== null ? (int) $count : 0,
            'execution_time_ms' => $execMs,
        ];
    }

    // -----------------------------------------------------------------
    // Write: upsert-campaign
    // -----------------------------------------------------------------

    public static function upsertCampaign($params)
    {
        $params = (array) $params;
        $campaignId = isset($params['campaign_id']) ? (int) $params['campaign_id'] : 0;

        $isNew = !$campaignId;
        $campaign = null;

        if (!$isNew) {
            $campaign = Campaign::find($campaignId);
            if (!$campaign) {
                return MCPHelper::error('not_found', 'Campaign not found', ['campaign_id' => $campaignId]);
            }
            // Drafts only, as in the Web UI. A scheduled campaign may already be sending, and its
            // queued emails carry the subject and recipients from when the queue was built.
            $editableStatuses = ['draft'];
            if (!in_array($campaign->status, $editableStatuses, true)) {
                $isScheduled = in_array($campaign->status, ['scheduled', 'pending-scheduled'], true);
                return MCPHelper::error('not_supported', $isScheduled
                    ? 'This campaign is scheduled. Unschedule it first (change-campaign-status action=unschedule), edit it, then schedule it again.'
                    : 'Only draft campaigns can be edited.', [
                    'status'           => $campaign->status,
                    'allowed_statuses' => $editableStatuses,
                ]);
            }
        }

        $title = isset($params['title']) ? sanitize_text_field((string) $params['title']) : '';
        if ($isNew && $title === '') {
            return MCPHelper::error('invalid_param', 'title is required when creating a campaign');
        }

        // Validation runs before Campaign::create() so a bad payload leaves no orphan draft.
        $validation = self::validateUpsertInput($params);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $sanitizedSettings = MCPHelper::sanitizeSettingsShape($params['settings'] ?? null);
        if (is_wp_error($sanitizedSettings)) {
            return $sanitizedSettings;
        }
        $params['settings'] = $sanitizedSettings;

        $titleConflictWarning = null;
        if ($isNew) {
            // A title conflict auto-suffixes with a warning, or errors when if_exists='error'.
            $ifExists = isset($params['if_exists']) ? sanitize_key((string) $params['if_exists']) : 'auto_suffix';
            $originalTitle = $title;
            if ($title !== '' && Campaign::where('title', $title)->exists()) {
                if ($ifExists === 'error') {
                    return MCPHelper::error('invalid_param', 'A campaign with that title already exists', [
                        'title' => $title,
                    ]);
                }
                $existingId = (int) Campaign::where('title', $title)->value('id');
                $title = self::nextAvailableTitle($title);
                $titleConflictWarning = sprintf(
                    'A campaign with title "%1$s" already exists (id %2$d). Created this one as "%3$s" instead. Pass if_exists="error" to make conflicts a hard failure.',
                    $originalTitle,
                    $existingId,
                    $title
                );
            }
            $campaign = Campaign::create(['title' => $title]);
            do_action('fluent_crm/campaign_created', $campaign);

            // Keep the suffixed title so the passthru below does not overwrite it.
            if ($titleConflictWarning) {
                $params['title'] = $title;
            }
        }

        $updateData = [];
        $passthru = ['title', 'email_subject', 'email_pre_header', 'email_body', 'design_template'];
        foreach ($passthru as $field) {
            if (array_key_exists($field, $params) && $params[$field] !== null) {
                $updateData[$field] = $params[$field];
            }
        }

        if (isset($updateData['design_template']) && $updateData['design_template'] !== '') {
            $updateData['design_template'] = sanitize_key((string) $updateData['design_template']);
        }

        if (!empty($params['utm']) && is_array($params['utm'])) {
            $utm = $params['utm'];
            if (array_key_exists('status', $utm)) {
                $updateData['utm_status'] = !empty($utm['status']) ? 1 : 0;
            }
            foreach (MCPHelper::utmAllowedKeys() as $key) {
                if ($key === 'status') {
                    continue;
                }
                if (isset($utm[$key])) {
                    $updateData['utm_' . $key] = sanitize_text_field((string) $utm[$key]);
                }
            }
        }

        $settings = $campaign->settings ?: [];
        if (is_string($settings)) {
            $settings = (array) maybe_unserialize($settings);
        }

        if (!empty($params['settings']) && is_array($params['settings'])) {
            $settings = array_replace_recursive((array) $settings, $params['settings']);
        }

        // Tag and list recipients belong to the "Lists & Tags" audience mode, as in the Web UI's
        // Recipients step; the send path reads sending_filter first, so it is set with them.
        $hasRecipients = !empty($params['recipients']) && is_array($params['recipients']);
        $hasExclusions = !empty($params['exclude_recipients']) && is_array($params['exclude_recipients']);
        $audienceType = $settings['sending_filter'] ?? 'list_tag';
        if ($hasExclusions && !$hasRecipients && $audienceType !== 'list_tag') {
            return MCPHelper::error('invalid_param', sprintf(
                "This campaign's audience is '%s', which has no exclusion lists. Send recipients together with exclude_recipients to switch it to tags and lists, or change the audience in the FluentCRM admin.",
                $audienceType
            ), [
                'audience_type' => $audienceType,
                'use_instead'   => ['recipients', 'exclude_recipients'],
            ]);
        }

        if ($hasRecipients) {
            $settings['sending_filter'] = 'list_tag';
            $settings['subscribers'] = self::filterToCampaignSegment($params['recipients']);
        }
        if ($hasExclusions) {
            $settings['excludedSubscribers'] = self::filterToCampaignSegment($params['exclude_recipients']);
        }

        // Keep design_template and template_config in sync; an explicit design_template wins.
        if (isset($updateData['design_template']) && $updateData['design_template'] !== '') {
            if (!isset($settings['template_config']) || !is_array($settings['template_config'])) {
                $settings['template_config'] = [];
            }
            $settings['template_config']['design_template'] = $updateData['design_template'];
        } elseif (!empty($settings['template_config']['design_template'])) {
            $updateData['design_template'] = $settings['template_config']['design_template'];
        }

        $updateData['settings'] = $settings;
        $updateData = \FluentCrm\App\Services\Sanitize::campaign($updateData);

        $campaign->fill($updateData)->save();

        if (!empty($params['subjects']) && is_array($params['subjects']) && method_exists($campaign, 'syncSubjects')) {
            $prepared = [];
            foreach (array_values($params['subjects']) as $i => $s) {
                if (!is_array($s) || empty($s['value'])) {
                    continue;
                }
                $value = sanitize_text_field((string) $s['value']);
                // `key` is the A/B weight Campaign::getSubject() picks by, so it defaults to 1.
                $key   = isset($s['key']) && is_numeric($s['key']) ? (string) max(0, (int) $s['key']) : '1';
                $item  = ['key' => $key, 'value' => $value];
                if (!empty($s['id'])) {
                    $item['id'] = (int) $s['id'];
                }
                $prepared[] = $item;
            }
            if ($prepared) {
                $campaign->syncSubjects($prepared);
            }
        }

        if (!empty($params['label_ids']) && is_array($params['label_ids']) && method_exists($campaign, 'attachLabels')) {
            $campaign->attachLabels(array_map('intval', $params['label_ids']));
        }

        do_action('fluent_crm/campaign_data_updated', $campaign, $params);

        $estimated = null;
        $warnings = [];
        if (!empty($settings['subscribers']) || !empty($settings['excludedSubscribers'])) {
            $estimated = self::estimateRecipientsFromSettings($settings);
        }

        if (empty($params['email_pre_header']) && empty($campaign->email_pre_header)) {
            $warnings[] = 'email_pre_header not set: a preheader improves deliverability';
        }
        if ($titleConflictWarning) {
            $warnings[] = $titleConflictWarning;
        }

        $campaign = Campaign::find($campaign->id);

        return [
            'ok'                   => true,
            'action'               => $isNew ? 'created' : 'updated',
            'campaign'             => self::getCampaign(['campaign_id' => (int) $campaign->id, 'include' => ['stats']]),
            'estimated_recipients' => $estimated,
            'warnings'             => $warnings,
        ];
    }

    /**
     * Validate upsert-campaign input before any Campaign row is created.
     *
     * @return true|\WP_Error
     */
    private static function validateUpsertInput($params)
    {
        if (isset($params['design_template']) && $params['design_template'] !== null && $params['design_template'] !== '') {
            $designTemplate = sanitize_key((string) $params['design_template']);
            $allowed = array_keys(\FluentCrm\App\Modules\MCP\Tools\ContextTools::allowedDesignTemplates());
            if (!in_array($designTemplate, $allowed, true)) {
                return MCPHelper::error('invalid_param', 'design_template not allowed via MCP', [
                    'design_template' => $designTemplate,
                    'allowed'         => $allowed,
                ]);
            }
        }

        // A non-object recipients value would otherwise be silently ignored.
        foreach (['recipients', 'exclude_recipients'] as $rk) {
            if (array_key_exists($rk, $params) && $params[$rk] !== null && !is_array($params[$rk])) {
                return MCPHelper::invalidTypeError($rk, 'an object');
            }
        }

        if (!empty($params['recipients']) && is_array($params['recipients'])) {
            $reject = self::rejectUnsupportedRecipientKeys($params['recipients'], 'recipients');
            if (is_wp_error($reject)) {
                return $reject;
            }
        }

        if (!empty($params['exclude_recipients']) && is_array($params['exclude_recipients'])) {
            $reject = self::rejectUnsupportedRecipientKeys($params['exclude_recipients'], 'exclude_recipients');
            if (is_wp_error($reject)) {
                return $reject;
            }
        }

        // Audience-targeting keys in settings would bypass the recipient checks above.
        if (!empty($params['settings']) && is_array($params['settings'])) {
            $targeting = ['subscribers', 'excludedSubscribers', 'sending_filter', 'dynamic_segment', 'advanced_filters'];
            $smuggled  = array_values(array_intersect(array_keys($params['settings']), $targeting));
            if ($smuggled) {
                return MCPHelper::error('invalid_param', sprintf(
                    'settings may not carry audience-targeting keys: %s. Recipients are set through the `recipients` / `exclude_recipients` parameters (tags + lists only) so they can be validated; writing them through settings would skip that entirely. To target a status/engagement/contact-type segment, apply a temporary tag via apply-tags-lists-to-contacts (dry_run first), use that tag in recipients, then remove it after the send.',
                    implode(', ', $smuggled)
                ), [
                    'rejected_setting_keys' => $smuggled,
                    'use_instead'           => ['recipients', 'exclude_recipients'],
                ]);
            }
        }

        $utmError = MCPHelper::validateUtm($params);
        if (is_wp_error($utmError)) {
            return $utmError;
        }

        return true;
    }

    /**
     * Reject recipient keys other than lists and tags, which campaigns would silently drop.
     *
     * @param array  $recipients
     * @param string $paramName Either 'recipients' or 'exclude_recipients'
     * @return true|\WP_Error
     */
    private static function rejectUnsupportedRecipientKeys($recipients, $paramName)
    {
        $supported = ['lists', 'tags'];
        $unsupported = array_values(array_diff(array_keys($recipients), $supported));
        if (!empty($unsupported)) {
            return MCPHelper::error('invalid_param', sprintf(
                '%1$s only persists lists + tags on a campaign; these keys would be silently dropped: %2$s. To target a status/engagement/contact-type segment, apply a temporary tag via apply-tags-lists-to-contacts (use dry_run first), use that tag in recipients, then remove it after the send completes.',
                $paramName,
                implode(', ', $unsupported)
            ), [
                'unsupported_keys' => $unsupported,
                'supported_keys'   => $supported,
                // Recipients are materialized by cron after scheduling, so the tag stays until the send finishes.
                'workaround'       => '1) apply-tags-lists-to-contacts(filter={...}, add_tags=["temp-X"], dry_run=true); 2) re-run without dry_run; 3) upsert-campaign(recipients={tags:["temp-X"]}); 4) change-campaign-status(action=schedule), then WAIT until get-campaign reports status=archived (recipients are materialized by the cron processor after scheduling — removing the tag before that shrinks the audience mid-send); 5) apply-tags-lists-to-contacts(filter={tags:["temp-X"]}, remove_tags=["temp-X"]); 6) manage-tag(action=delete, tag_id=X)',
            ]);
        }
        return true;
    }

    /**
     * Translate the filter shape into the flat [{list, tag}] pairs settings.subscribers stores.
     * Lists and tags together become a cross product: AND within a pair, OR across pairs.
     */
    private static function filterToCampaignSegment($filter)
    {
        $tagIds = !empty($filter['tags'])
            ? MCPHelper::resolveTagIds((array) $filter['tags'])['ids']
            : [];
        $listIds = !empty($filter['lists'])
            ? MCPHelper::resolveListIds((array) $filter['lists'])['ids']
            : [];

        $items = [];

        if (!$tagIds && !$listIds) {
            return $items;
        }

        if ($tagIds && $listIds) {
            foreach ($listIds as $listId) {
                foreach ($tagIds as $tagId) {
                    $items[] = ['list' => (string) $listId, 'tag' => (string) $tagId];
                }
            }
        } elseif ($tagIds) {
            foreach ($tagIds as $tagId) {
                $items[] = ['list' => 'all', 'tag' => (string) $tagId];
            }
        } else {
            foreach ($listIds as $listId) {
                $items[] = ['list' => (string) $listId, 'tag' => 'all'];
            }
        }

        return $items;
    }

    private static function estimateRecipientsFromSettings($settings)
    {
        try {
            $count = (new Campaign())->getSubscriberIdsCountBySegmentSettings([
                'subscribers'         => $settings['subscribers'] ?? [],
                'excludedSubscribers' => $settings['excludedSubscribers'] ?? [],
                'sending_filter'      => $settings['sending_filter'] ?? 'list_tag',
                'dynamic_segment'     => $settings['dynamic_segment'] ?? null,
                'advanced_filters'    => $settings['advanced_filters'] ?? [],
            ]);
            return (int) $count;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // -----------------------------------------------------------------
    // Write: change-campaign-status
    // -----------------------------------------------------------------

    public static function changeCampaignStatus($params)
    {
        $params = (array) $params;
        $campaignId = (int) ($params['campaign_id'] ?? 0);
        $action = sanitize_key((string) ($params['action'] ?? ''));

        if (!$campaignId) {
            return MCPHelper::error('invalid_param', 'campaign_id is required');
        }
        if (!in_array($action, ['schedule', 'unschedule', 'pause', 'resume', 'duplicate', 'delete'], true)) {
            return MCPHelper::error('invalid_param', 'Invalid action');
        }

        // Bypass the type scope so one-off sends are reachable; they only allow delete.
        $campaign = Campaign::withoutGlobalScope('type')->find($campaignId);
        if (!$campaign) {
            return MCPHelper::error('not_found', 'Campaign not found', ['campaign_id' => $campaignId]);
        }

        if ($campaign->type === 'custom_email_campaign' && $action !== 'delete') {
            return MCPHelper::error('not_supported', 'One-off email sends only support action=delete. They do not have a marketing-campaign lifecycle (schedule/pause/resume/duplicate).', [
                'campaign_id'      => $campaignId,
                'campaign_type'    => 'one_off',
                'allowed_actions'  => ['delete'],
            ]);
        }

        $previousStatus = $campaign->status;

        switch ($action) {
            case 'schedule':
                return self::actionSchedule($campaign, $params, $previousStatus);
            case 'unschedule':
                return self::actionUnschedule($campaign, $previousStatus);
            case 'pause':
                return self::actionPause($campaign, $previousStatus);
            case 'resume':
                return self::actionResume($campaign, $previousStatus);
            case 'duplicate':
                return self::actionDuplicate($campaign, $params);
            case 'delete':
                return self::actionDelete($campaign);
        }

        return MCPHelper::error('invalid_param', 'Unhandled action');
    }

    private static function actionSchedule($campaign, $params, $previousStatus)
    {
        if ($campaign->status !== 'draft') {
            return MCPHelper::error('not_supported', 'Only draft campaigns can be scheduled', [
                'status' => $campaign->status,
            ]);
        }

        $sendingType = $params['sending_type'] ?? ($params['scheduled_at'] ?? null ? 'schedule' : 'instant');

        if ($sendingType === 'instant') {
            $scheduleAt = null;
        } elseif ($sendingType === 'range_schedule') {
            $range = $params['schedule_range'] ?? [];
            if (!is_array($range) || count($range) !== 2) {
                return MCPHelper::error('invalid_param', 'schedule_range must be [start, end]');
            }
            $rangeStart = MCPHelper::validateScheduledAt(sanitize_text_field($range[0]));
            if (is_wp_error($rangeStart)) {
                return $rangeStart;
            }
            $rangeEnd = MCPHelper::validateScheduledAt(sanitize_text_field($range[1]));
            if (is_wp_error($rangeEnd)) {
                return $rangeEnd;
            }
            if ($rangeEnd->getTimestamp() <= $rangeStart->getTimestamp()) {
                return MCPHelper::error('invalid_param', 'schedule_range end must be after the start time', [
                    'start' => $rangeStart->format('Y-m-d H:i:s'),
                    'end'   => $rangeEnd->format('Y-m-d H:i:s'),
                ]);
            }
            $scheduleAt = [
                $rangeStart->format('Y-m-d H:i:s'),
                $rangeEnd->format('Y-m-d H:i:s'),
            ];
        } else {
            $rawInput = sanitize_text_field((string) ($params['scheduled_at'] ?? ''));
            if (!$rawInput) {
                return MCPHelper::error('invalid_param', 'scheduled_at is required for sending_type=schedule');
            }
            $dt = MCPHelper::validateScheduledAt($rawInput);
            if (is_wp_error($dt)) {
                return $dt;
            }
            // Stored as a site-local datetime string.
            $scheduleAt = $dt->format('Y-m-d H:i:s');
        }

        // Compute recipients_count if not yet set.
        if (!$campaign->recipients_count) {
            $settings = is_array($campaign->settings) ? $campaign->settings : (array) maybe_unserialize($campaign->settings);
            if (!empty($settings['subscribers']) || !empty($settings['excludedSubscribers'])) {
                $count = (new Campaign())->getSubscriberIdsCountBySegmentSettings($settings);
                $campaign->recipients_count = (int) $count;
                $campaign->save();
            }
        }

        if (!$campaign->recipients_count) {
            return MCPHelper::error('validation_failed', 'No recipients found for this campaign');
        }

        // Same transition the controller applies.
        $settings = is_array($campaign->settings) ? $campaign->settings : (array) maybe_unserialize($campaign->settings);

        if ($scheduleAt === null) {
            $settings['sending_type'] = 'instant';
            $update = [
                'status'           => 'processing',
                'updated_at'       => fluentCrmTimestamp(),
                'scheduled_at'     => fluentCrmTimestamp(),
                'recipients_count' => 0,
                'settings'         => $settings,
            ];
        } elseif (is_array($scheduleAt)) {
            $settings['sending_type']  = 'range_schedule';
            $settings['schedule_range'] = [strtotime($scheduleAt[0]), strtotime($scheduleAt[1])];
            $update = [
                'status'           => 'pending-scheduled',
                'updated_at'       => fluentCrmTimestamp(),
                'scheduled_at'     => $scheduleAt[0],
                'recipients_count' => 0,
                'settings'         => $settings,
            ];
        } else {
            $settings['sending_type'] = 'schedule';
            $update = [
                'status'           => 'pending-scheduled',
                'updated_at'       => fluentCrmTimestamp(),
                'scheduled_at'     => $scheduleAt,
                'recipients_count' => 0,
                'settings'         => $settings,
            ];
        }

        // Seed trackers from site defaults only when the campaign has not set them.
        if (!isset($update['settings']['click_tracker']) || $update['settings']['click_tracker'] === '') {
            $update['settings']['click_tracker'] = fluentcrmTrackClicking();
        }
        if (!isset($update['settings']['open_tracker']) || $update['settings']['open_tracker'] === '') {
            $update['settings']['open_tracker'] = fluentcrmTrackEmailOpen();
        }
        $update['settings'] = maybe_serialize($update['settings']);

        $updated = Campaign::where('id', $campaign->id)->where('status', 'draft')->update($update);
        if (!$updated) {
            return MCPHelper::error('failed', 'Could not transition campaign: status changed concurrently');
        }

        // Wipe pre-processed emails only after the draft-status transition wins.
        \FluentCrm\App\Models\CampaignEmail::where('campaign_id', $campaign->id)->delete();
        fluentcrm_update_campaign_meta($campaign->id, '_recipient_processed', 0);
        fluentcrm_update_campaign_meta($campaign->id, '_last_recipient_id', 0);
        \FluentCrm\App\Services\CampaignProcessor::clearQueueFailures($campaign->id);

        $campaign = Campaign::find($campaign->id);
        fluentcrm_update_campaign_meta($campaign->id, '_campaign_sent_by', get_current_user_id());

        if ($scheduleAt) {
            do_action('fluent_crm/campaign_scheduled', $campaign, $campaign->scheduled_at);
        } else {
            do_action('fluent_crm/campaign_set_send_now', $campaign);
        }

        return [
            'ok'                       => true,
            'action'                   => 'schedule',
            'campaign'                 => self::getCampaign(['campaign_id' => (int) $campaign->id]),
            'previous_status'          => $previousStatus,
            'current_status'           => $campaign->status,
            'scheduled_at'             => MCPHelper::toIso8601($campaign->scheduled_at),
            'scheduled_at_resolved'    => MCPHelper::formatScheduledAtDual($campaign->scheduled_at),
        ];
    }

    private static function actionUnschedule($campaign, $previousStatus)
    {
        if (!in_array($campaign->status, ['scheduled', 'pending-scheduled', 'processing'], true)) {
            return MCPHelper::error('not_supported', 'Campaign is not in a schedulable state', [
                'status' => $campaign->status,
            ]);
        }

        // As REST unSchedule: a campaign already processing past its send time is being queued now.
        if ($campaign->status === 'processing' && strtotime($campaign->scheduled_at) < current_time('timestamp')) {
            return MCPHelper::error('not_supported', 'This campaign has started processing and can no longer be unscheduled. Pause it once it is sending.', [
                'status'       => $campaign->status,
                'scheduled_at' => MCPHelper::toIso8601($campaign->scheduled_at),
            ]);
        }

        // As REST unSchedule: a campaign that has started sending is paused instead, keeping what
        // was sent; unscheduling it would let the next schedule mail everyone again.
        if ($campaign->hasStartedSending()) {
            $campaign->pauseSending();

            return [
                'ok'              => true,
                'action'          => 'unschedule',
                'paused_instead'  => true,
                'note'            => 'The campaign had already started sending, so it was paused instead of unscheduled. Emails already sent are kept; change-campaign-status action=resume sends the rest.',
                'campaign'        => self::getCampaign(['campaign_id' => (int) $campaign->id]),
                'previous_status' => $previousStatus,
                'current_status'  => 'paused',
            ];
        }

        $campaign->status = 'draft';
        $campaign->scheduled_at = null;
        $campaign->save();

        // Only unsent emails are removed; sent history is never deleted here.
        \FluentCrm\App\Models\CampaignEmail::where('campaign_id', $campaign->id)
            ->whereNotIn('status', \FluentCrm\App\Models\CampaignEmail::STARTED_STATUSES)
            ->delete();
        \FluentCrm\App\Models\CampaignEmail::withoutGlobalScope('type')
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['scheduled', 'scheduling'])
            ->delete();

        return [
            'ok'              => true,
            'action'          => 'unschedule',
            'campaign'        => self::getCampaign(['campaign_id' => (int) $campaign->id]),
            'previous_status' => $previousStatus,
            'current_status'  => $campaign->status,
        ];
    }

    private static function actionPause($campaign, $previousStatus)
    {
        if ($campaign->status !== 'working') {
            return MCPHelper::error('not_supported', 'Only working campaigns can be paused', [
                'status' => $campaign->status,
            ]);
        }
        $campaign->status = 'paused';
        $campaign->save();
        \FluentCrm\App\Models\CampaignEmail::where('campaign_id', $campaign->id)
            ->whereIn('status', ['scheduled', 'pending', 'scheduling'])
            ->update(['status' => 'paused']);

        return [
            'ok'              => true,
            'action'          => 'pause',
            'campaign'        => self::getCampaign(['campaign_id' => (int) $campaign->id]),
            'previous_status' => $previousStatus,
            'current_status'  => 'paused',
        ];
    }

    private static function actionResume($campaign, $previousStatus)
    {
        if ($campaign->status !== 'paused') {
            return MCPHelper::error('not_supported', 'Only paused campaigns can be resumed', [
                'status' => $campaign->status,
            ]);
        }
        $campaign->status = 'working';
        $campaign->save();
        \FluentCrm\App\Models\CampaignEmail::where('campaign_id', $campaign->id)
            ->where('status', 'paused')
            ->update([
                'status'       => 'scheduled',
                'scheduled_at' => current_time('mysql'),
            ]);

        return [
            'ok'              => true,
            'action'          => 'resume',
            'campaign'        => self::getCampaign(['campaign_id' => (int) $campaign->id]),
            'previous_status' => $previousStatus,
            'current_status'  => 'working',
        ];
    }

    private static function actionDuplicate($campaign, $params)
    {
        $newTitle = isset($params['new_title']) && $params['new_title'] !== ''
            ? sanitize_text_field((string) $params['new_title'])
            : sprintf('[Duplicate] %s', $campaign->title);

        $newCampaign = [
            'title'            => $newTitle,
            'slug'             => $campaign->slug . '-' . time(),
            'email_body'       => $campaign->email_body,
            'status'           => 'draft',
            'template_id'      => $campaign->template_id,
            'email_subject'    => $campaign->email_subject,
            'email_pre_header' => $campaign->email_pre_header,
            'utm_status'       => $campaign->utm_status,
            'utm_source'       => $campaign->utm_source,
            'utm_medium'       => $campaign->utm_medium,
            'utm_campaign'     => $campaign->utm_campaign,
            'utm_term'         => $campaign->utm_term,
            'utm_content'      => $campaign->utm_content,
            'design_template'  => $campaign->design_template,
            'created_by'       => get_current_user_id(),
            'settings'         => $campaign->settings,
        ];

        $copy = Campaign::create($newCampaign);

        if (method_exists($campaign, 'getFormattedLabels')) {
            $labelIds = $campaign->getFormattedLabels()->pluck('id')->toArray();
            if ($labelIds && method_exists($copy, 'attachLabels')) {
                $copy->attachLabels($labelIds);
            }
        }
        if (method_exists($copy, 'duplicateSubjects')) {
            $copy->duplicateSubjects($campaign);
        }

        do_action('fluent_crm/campaign_duplicated', $copy, $campaign);

        return [
            'ok'                   => true,
            'action'               => 'duplicate',
            'campaign'             => self::getCampaign(['campaign_id' => (int) $copy->id]),
            'duplicated_from_id'   => (int) $campaign->id,
        ];
    }

    private static function actionDelete($campaign)
    {
        if (!\FluentCrm\App\Services\PermissionManager::currentUserCan('fcrm_manage_email_delete')) {
            return MCPHelper::error('forbidden', 'Deleting campaigns requires fcrm_manage_email_delete');
        }

        $campaignId = (int) $campaign->id;
        if (method_exists($campaign, 'deleteCampaignData')) {
            $campaign->deleteCampaignData();
        }
        $campaign->delete();
        do_action('fluent_crm/campaign_deleted', $campaignId);

        return [
            'ok'         => true,
            'action'     => 'delete',
            'deleted_id' => $campaignId,
        ];
    }
}
