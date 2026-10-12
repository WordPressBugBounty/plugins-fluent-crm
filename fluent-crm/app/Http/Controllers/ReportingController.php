<?php

namespace FluentCrm\App\Http\Controllers;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\Funnel;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Hooks\Handlers\Scheduler;
use FluentCrm\App\Services\Reporting;
use FluentCrm\Framework\Http\Request\Request;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\Framework\Support\Arr;

/**
 *  ReportingController - REST API Handler Class
 *
 *  REST API Handler
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */
class ReportingController extends Controller
{
    public function getContactGrowth(Request $request, Reporting $reporting)
    {
        list($from, $to) = $request->get('date_range') ?: ['', ''];
        $tagId = intval($request->get('tag_id', 0));
        $listId = intval($request->get('list_id', 0));
        $compareType = sanitize_text_field($request->get('compare_type', ''));
        $compareRange = $request->get('compare_range', []);

        $currentStats = $reporting->getSubscribersGrowth($from, $to, $tagId, $listId);

        // created_at is stored in site time, so defaults use the site-local clock.
        $currentFrom = $from ?: gmdate('Y-m-d', current_time('timestamp') - 30 * DAY_IN_SECONDS);
        $currentTo = $to ?: gmdate('Y-m-d', current_time('timestamp') + DAY_IN_SECONDS);

        $dataSets = [
            [
                'label'           => __('Current Range', 'fluent-crm'),
                'data'            => $currentStats,
                'range'           => [$currentFrom, $currentTo],
                'backgroundColor' => '#335CFF',
                'borderColor'     => '#335CFF',
                'fill'            => true,
            ],
        ];

        if ($compareType && $compareType !== 'no_comparison') {
            $compRange = $this->resolveCompareRange($compareType, $compareRange, $currentFrom, $currentTo);
            if ($compRange) {
                $compareStats = $reporting->getSubscribersGrowth($compRange[0], $compRange[1], $tagId, $listId);
                $dataSets[] = [
                    'label'           => __('Compare Range', 'fluent-crm'),
                    'data'            => $compareStats,
                    'range'           => $compRange,
                    'backgroundColor' => '#1FC16B',
                    'borderColor'     => '#1FC16B',
                    'fill'            => true,
                ];
            }
        }

        return $this->sendSuccess([
            'data_sets'     => $dataSets,
            'current_range' => [$currentFrom, $currentTo],
        ]);
    }

    /**
     * Calculate comparison date range based on type.
     *
     * @param string $type
     * @param array $compareRange
     * @param string $from
     * @param string $to
     * @return array|false
     */
    private function resolveCompareRange($type, $compareRange, $from, $to)
    {
        $fromTs = strtotime($from);
        $toTs = strtotime($to);
        $diffDays = (int)(($toTs - $fromTs) / 86400);

        switch ($type) {
            case 'previous_period':
                return [
                    gmdate('Y-m-d', $fromTs - ($diffDays + 1) * 86400),
                    gmdate('Y-m-d', $fromTs - 86400),
                ];
            case 'previous_month':
                $newFrom = gmdate('Y-m-d', strtotime($from . ' -1 month'));
                return [$newFrom, gmdate('Y-m-d', strtotime($newFrom) + $diffDays * 86400)];
            case 'previous_quarter':
                $newFrom = gmdate('Y-m-d', strtotime($from . ' -3 months'));
                return [$newFrom, gmdate('Y-m-d', strtotime($newFrom) + $diffDays * 86400)];
            case 'previous_year':
                $newFrom = gmdate('Y-m-d', strtotime($from . ' -12 months'));
                return [$newFrom, gmdate('Y-m-d', strtotime($newFrom) + $diffDays * 86400)];
            case 'custom':
                if (is_array($compareRange) && count(array_filter($compareRange)) >= 2) {
                    return [
                        sanitize_text_field($compareRange[0]),
                        sanitize_text_field($compareRange[1]),
                    ];
                }
                return false;
            default:
                return false;
        }
    }

    public function getEmailSentStats(Request $request, Reporting $reporting)
    {
        list($from, $to) = $request->get('date_range') ?: ['', ''];
        $campaignId = intval($request->get('campaign_id', 0));
        return $this->sendSuccess([
            'stats' => $reporting->getEmailStats($from, $to, 'sent', $campaignId)
        ]);
    }

    public function getEmailOpenStats(Request $request, Reporting $reporting)
    {
        list($from, $to) = $request->get('date_range') ?: ['', ''];
        $campaignId = intval($request->get('campaign_id', 0));
        return $this->sendSuccess([
            'stats' => $reporting->getEmailOpenStats($from, $to, $campaignId)
        ]);
    }

    public function getEmailClickStats(Request $request, Reporting $reporting)
    {
        list($from, $to) = $request->get('date_range') ?: ['', ''];
        $campaignId = intval($request->get('campaign_id', 0));
        return $this->sendSuccess([
            'stats' => $reporting->getEmailClickStats($from, $to, $campaignId)
        ]);
    }

    public function getEmailUnsubStats(Request $request, Reporting $reporting)
    {
        list($from, $to) = $request->get('date_range') ?: ['', ''];
        return $this->sendSuccess([
            'stats' => $reporting->getUnsubscribeStats($from, $to)
        ]);
    }

    public function getEmailPerformance(Request $request, Reporting $reporting)
    {
        $dateRange = Arr::get($request->all(), 'date_range', []);

        if (!empty($dateRange[0]) && !empty($dateRange[1])) {
            $from = sanitize_text_field($dateRange[0]);
            $to = sanitize_text_field($dateRange[1]);
        } else {
            $days = intval(Arr::get($request->all(), 'days'));

            if ($days > 0) {
                $from = '-' . $days . ' days';
            } elseif ($request->exists('days')) {
                // days=0 means "All Time"
                $from = '2000-01-01';
            } else {
                $from = null; // default: -30 days
            }

            $to = null;
        }

        return $this->sendSuccess([
            'stats' => $reporting->getEmailPerformance($from, $to)
        ]);
    }

    public function getEmails(Request $request)
    {
        $status = sanitize_text_field($request->get('status', ''));
        $search = sanitize_text_field($request->get('search', ''));
        $selectedTypes = array_values(array_filter(array_map('sanitize_text_field', (array)$request->get('types', []))));
        $types = CampaignEmail::expandEmailTypes($selectedTypes);

        // Without a status filter, ORDER BY scheduled_at cannot use the (status, scheduled_at) index.
        $emailsQuery = CampaignEmail::with('subscriber', 'campaign');

        if ($status) {
            $emailsQuery->where('status', $status)->orderBy('scheduled_at', 'DESC');
        } else {
            $emailsQuery->orderBy('id', 'DESC');
        }

        $emails = $emailsQuery
            ->when($search, function ($q) use ($search) {
                return $this->applyEmailSearchFilter($q, $search);
            })
            ->when($types, function ($q) use ($types) {
                return $q->whereIn('email_type', $types);
            })
            ->paginate();

        $statuses = null;
        $emailTypes = null;

        if ($request->get('page') == 1 && !$search) {
            $statuses = CampaignEmail::select('status')
                ->selectRaw('count(id) as total')
                ->when($types, function ($q) use ($types) {
                    return $q->whereIn('email_type', $types);
                })
                ->groupBy('status')
                ->get()
                ->keyBy('status')
                ->map(function ($status) {
                    return $status->total;
                });

            $typeCounts = CampaignEmail::select('email_type')
                ->selectRaw('count(id) as total')
                ->whereNotNull('email_type')
                ->when($status, function ($q) use ($status) {
                    return $q->where('status', $status);
                })
                ->groupBy('email_type')
                ->get()
                ->reduce(function ($carry, $emailType) {
                    $canonicalType = CampaignEmail::normalizeEmailType($emailType->email_type);

                    if (!isset($carry[$canonicalType])) {
                        $carry[$canonicalType] = [
                            'id'    => $canonicalType,
                            'label' => CampaignEmail::resolveEmailTypeLabel($canonicalType),
                            'count' => 0,
                        ];
                    }

                    $carry[$canonicalType]['count'] += (int)$emailType->total;

                    return $carry;
                }, []);

            $orderedTypes = [];
            foreach (array_keys(CampaignEmail::getEmailTypeLabels()) as $canonicalType) {
                if (!empty($typeCounts[$canonicalType])) {
                    $orderedTypes[] = $typeCounts[$canonicalType];
                }
            }

            $emailTypes = array_values($orderedTypes);
        }

        return [
            'emails'    => $emails,
            'statuses'  => $statuses,
            'types'     => $emailTypes
        ];
    }

    /**
     * Search email activity by subject, campaign title, recipient or contact email.
     *
     * @param \FluentCrm\Framework\Database\Orm\Builder $query
     * @param string $search
     * @return \FluentCrm\Framework\Database\Orm\Builder
     */
    private function applyEmailSearchFilter($query, $search)
    {
        global $wpdb;

        $escapedSearch = $wpdb->esc_like($search);
        $containsLike = '%' . $escapedSearch . '%';
        $emailLike = strpos($search, '@') !== false ? $escapedSearch . '%' : $containsLike;

        // fc_campaigns is queried directly because the Campaign model's type scope drops funnel/sequence emails.
        return $query->where(function ($subQuery) use ($containsLike, $emailLike) {
            $subQuery->where('email_subject', 'LIKE', $containsLike)
                ->orWhere('email_address', 'LIKE', $emailLike)
                ->orWhereIn('campaign_id', function ($q) use ($containsLike) {
                    $q->select('id')->from('fc_campaigns')->where('title', 'LIKE', $containsLike);
                })
                ->orWhereIn('subscriber_id', function ($q) use ($emailLike) {
                    $q->select('id')->from('fc_subscribers')->where('email', 'LIKE', $emailLike);
                });
        });
    }

    public function deleteEmails(Request $request)
    {
        $emailIds = $request->get('email_ids');
        CampaignEmail::whereIn('id', $emailIds)
            ->delete();

        return [
            'message' => __('Selected emails have been deleted', 'fluent-crm')
        ];
    }

    public function getContactsByStatus()
    {
        $statuses = fluentCrmDb()->table('fc_subscribers')
            ->select(fluentCrmDb()->raw('status, COUNT(id) as count'))
            ->groupBy('status')
            ->get();

        $defaultOrder = [
            'subscribed',
            'unsubscribed',
            'pending',
            'bounced',
            'complained',
            'spammed',
            'transactional'
        ];
        $defaultStats = array_fill_keys($defaultOrder, 0);
        $total = 0;

        foreach ($statuses as $row) {
            $count = (int)$row->count;
            $status = sanitize_text_field($row->status);
            $total += $count;

            if (array_key_exists($status, $defaultStats)) {
                $defaultStats[$status] = $count;
            }
        }

        $result = [];
        foreach ($defaultOrder as $status) {
            $result[] = [
                'status' => $status,
                'count'  => $defaultStats[$status],
            ];
        }

        return $this->sendSuccess([
            'stats' => $result,
            'total' => $total,
        ]);
    }

    public function getContactsByTags(Request $request)
    {
        $limit = intval($request->get('per_page', 20));

        $tags = Tag::select(['fc_tags.id', 'fc_tags.title'])
            ->selectRaw('COUNT(subscriber_id) as contact_count')
            ->leftJoin('fc_subscriber_pivot', function ($join) {
                $join->on('fc_tags.id', '=', 'fc_subscriber_pivot.object_id')
                    ->where('fc_subscriber_pivot.object_type', '=', 'FluentCrm\App\Models\Tag');
            })
            ->groupBy('fc_tags.id', 'fc_tags.title')
            ->orderByDesc('contact_count')
            ->paginate($limit);

        return $this->sendSuccess([
            'tags' => $tags,
        ]);
    }

    public function getContactsByLists(Request $request)
    {
        $limit = intval($request->get('per_page', 20));

        $lists = Lists::select(['fc_lists.id', 'fc_lists.title'])
            ->selectRaw('COUNT(subscriber_id) as contact_count')
            ->leftJoin('fc_subscriber_pivot', function ($join) {
                $join->on('fc_lists.id', '=', 'fc_subscriber_pivot.object_id')
                    ->where('fc_subscriber_pivot.object_type', '=', 'FluentCrm\App\Models\Lists');
            })
            ->groupBy('fc_lists.id', 'fc_lists.title')
            ->orderByDesc('contact_count')
            ->paginate($limit);

        return $this->sendSuccess([
            'lists' => $lists,
        ]);
    }

    public function getContactsByCountry()
    {
        $countries = fluentCrmDb()->table('fc_subscribers')
            ->select(fluentCrmDb()->raw('UPPER(TRIM(country)) as country_code, COUNT(id) as contact_count'))
            ->whereNotNull('country')
            ->whereRaw("TRIM(country) != ''")
            ->groupBy(fluentCrmDb()->raw('UPPER(TRIM(country))'))
            ->orderByDesc('contact_count')
            ->get();

        $result = [];
        foreach ($countries as $row) {
            $result[] = [
                'country_code' => $row->country_code,
                'contact_count' => (int) $row->contact_count,
            ];
        }

        return $this->sendSuccess([
            'countries' => $result,
        ]);
    }

    public function getCampaignsList(Request $request)
    {
        $limit = max(1, min(100, intval($request->get('per_page', 15))));

        $campaigns = Campaign::where('status', 'archived')
            ->orderBy('updated_at', 'DESC')
            ->paginate($limit);

        $this->hydrateCampaignStats($campaigns);

        return $this->sendSuccess([
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * Attach report stats to one campaign page using fixed-count aggregate queries.
     *
     * @param \FluentCrm\Framework\Pagination\LengthAwarePaginator $campaigns
     * @return void
     */
    private function hydrateCampaignStats($campaigns)
    {
        $campaignIds = $campaigns->pluck('id')->toArray();

        if (!$campaignIds) {
            return;
        }

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

        $unsubscribeCounts = fluentCrmDb()->table('fc_campaign_url_metrics')
            ->select('campaign_id')
            ->selectRaw('COUNT(DISTINCT subscriber_id) as total')
            ->where('type', 'unsubscribe')
            ->whereIn('campaign_id', $campaignIds)
            ->groupBy('campaign_id')
            ->get()
            ->keyBy('campaign_id');

        $metaItems = fluentCrmDb()->table('fc_meta')
            ->whereIn('object_id', $campaignIds)
            ->where('object_type', Campaign::class)
            ->whereIn('key', ['_campaign_revenue', '_ano_open_count', '_ano_url_clicks'])
            ->get();

        $metaMap = [];
        foreach ($metaItems as $meta) {
            $metaMap[$meta->object_id][$meta->key] = $meta->value;
        }

        foreach ($campaigns as $campaign) {
            $emailStat = $emailStats[$campaign->id] ?? null;
            $unsubscribeCount = $unsubscribeCounts[$campaign->id] ?? null;
            $campaignMeta = $metaMap[$campaign->id] ?? [];

            $views = $emailStat ? (int) $emailStat->views : 0;
            if ($campaign->getOpenTrackingStatus(false) === 'anonymous') {
                $views = (int) ($campaignMeta['_ano_open_count'] ?? 0);
            }

            $clicks = $emailStat ? (int) $emailStat->clicks : 0;
            if ($campaign->getClickTrackingStatus(false) === 'anonymous') {
                $clicks = 0;
                $clickItems = maybe_unserialize($campaignMeta['_ano_url_clicks'] ?? null);
                if (is_array($clickItems)) {
                    $clicks = array_sum($clickItems);
                }
            }

            $stats = array_merge([
                'total'         => $emailStat ? (int) $emailStat->total : 0,
                'sent'          => $emailStat ? (int) $emailStat->sent : 0,
                'clicks'        => $clicks,
                'views'         => $views,
                'unsubscribers' => $unsubscribeCount ? (int) $unsubscribeCount->total : 0,
            ], $campaign->anonymousStatFlags());

            $revenue = maybe_unserialize($campaignMeta['_campaign_revenue'] ?? null);
            if ($revenue) {
                foreach ((array) $revenue as $currency => $cents) {
                    if ($cents && $currency !== 'orderIds') {
                        $stats['revenue'] = [
                            /* translators: %s: currency code, e.g. USD */
                            'label'    => sprintf(__('Revenue (%s)', 'fluent-crm'), $currency),
                            'total'    => number_format($cents / 100, 2),
                            'currency' => $currency,
                        ];
                    }
                }
            }

            $campaign->stats = $stats;
        }
    }

    public function getAutomationReports(Request $request)
    {
        $limit = intval($request->get('per_page', 15));

        $funnels = Funnel::where('status', 'published')
            ->orderBy('created_at', 'DESC')
            ->paginate($limit);

        $funnelIds = [];
        foreach ($funnels as $funnel) {
            $funnelIds[] = $funnel->id;
        }

        $pageAggregates = [];
        if ($funnelIds) {
            $aggregateRows = FunnelSubscriber::whereIn('funnel_id', $funnelIds)
                ->groupBy('funnel_id')
                ->select('funnel_id')
                ->selectRaw('COUNT(DISTINCT subscriber_id) as total_subscribers')
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count")
                ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as in_progress_count")
                ->selectRaw('MAX(last_executed_time) as last_run_at')
                ->get();

            foreach ($aggregateRows as $aggregateRow) {
                $pageAggregates[$aggregateRow->funnel_id] = $aggregateRow;
            }
        }

        foreach ($funnels as $funnel) {
            $aggregate = isset($pageAggregates[$funnel->id]) ? $pageAggregates[$funnel->id] : null;

            $funnel->total_subscribers = $aggregate ? (int)$aggregate->total_subscribers : 0;
            $funnel->completed_count = $aggregate ? (int)$aggregate->completed_count : 0;
            $funnel->in_progress_count = $aggregate ? (int)$aggregate->in_progress_count : 0;
            $funnel->last_run_at = $aggregate ? $aggregate->last_run_at : null;

            $recentEntries = FunnelSubscriber::where('funnel_id', $funnel->id)
                ->with(['subscriber' => function ($q) {
                    $q->select(['id', 'first_name', 'last_name', 'email', 'avatar']);
                }])
                ->orderByDesc('created_at')
                ->limit(3)
                ->get();

            $funnel->recent_subscribers = $recentEntries->map(function ($entry) {
                if (!$entry->subscriber) {
                    return null;
                }
                return [
                    'id'    => $entry->subscriber->id,
                    'name'  => trim($entry->subscriber->first_name . ' ' . $entry->subscriber->last_name),
                    'email' => $entry->subscriber->email,
                    'avatar' => $entry->subscriber->avatar,
                    'entered_at' => $entry->created_at,
                ];
            })->filter()->values();
        }

        // Top-5 and global totals scan all enrollment history; cached in a transient with TTL-only invalidation.
        $overviewStats = get_transient('fluent_crm_automation_overview_stats');

        if (!is_array($overviewStats) || !isset($overviewStats['top_automations'], $overviewStats['totals'])) {
            // Top 5 automations by total enrollments.
            $topAutomations = FunnelSubscriber::join('fc_funnels', function ($join) {
                    $join->on('fc_funnel_subscribers.funnel_id', '=', 'fc_funnels.id')
                        ->where('fc_funnels.status', '=', 'published')
                        ->where('fc_funnels.type', '=', 'funnels');
                })
                ->groupBy('fc_funnel_subscribers.funnel_id', 'fc_funnels.title', 'fc_funnels.trigger_name')
                ->select('fc_funnel_subscribers.funnel_id', 'fc_funnels.title', 'fc_funnels.trigger_name')
                ->selectRaw('COUNT(*) as trigger_count')
                ->orderByDesc('trigger_count')
                ->limit(5)
                ->get()
                ->map(function ($row) {
                    return [
                        'id'            => $row->funnel_id,
                        'title'         => $row->title,
                        'trigger_name'  => $row->trigger_name,
                        'trigger_count' => (int)$row->trigger_count,
                    ];
                })
                ->values()
                ->toArray();

            // Totals are global, not page sums. Raw fragments bypass the prefix grammar, so the table is prefixed by hand.
            global $wpdb;
            $funnelSubscribersTable = $wpdb->prefix . 'fc_funnel_subscribers';

            $globalAggregate = FunnelSubscriber::join('fc_funnels', function ($join) {
                    $join->on('fc_funnel_subscribers.funnel_id', '=', 'fc_funnels.id')
                        ->where('fc_funnels.status', '=', 'published')
                        ->where('fc_funnels.type', '=', 'funnels');
                })
                ->selectRaw("COUNT(DISTINCT `{$funnelSubscribersTable}`.`funnel_id`, `{$funnelSubscribersTable}`.`subscriber_id`) as subscribers")
                ->selectRaw("SUM(CASE WHEN `{$funnelSubscribersTable}`.`status` = 'completed' THEN 1 ELSE 0 END) as completed")
                ->selectRaw("SUM(CASE WHEN `{$funnelSubscribersTable}`.`status` = 'active' THEN 1 ELSE 0 END) as in_progress")
                ->first();

            $overviewStats = [
                'top_automations' => $topAutomations,
                'totals'          => [
                    'subscribers' => $globalAggregate ? (int)$globalAggregate->subscribers : 0,
                    'completed'   => $globalAggregate ? (int)$globalAggregate->completed : 0,
                    'in_progress' => $globalAggregate ? (int)$globalAggregate->in_progress : 0,
                ],
            ];

            set_transient('fluent_crm_automation_overview_stats', $overviewStats, 5 * MINUTE_IN_SECONDS);
        }

        $topAutomations = $overviewStats['top_automations'];

        $overview = [
            'total'        => Funnel::where('status', 'published')->count(),
            'subscribers'  => $overviewStats['totals']['subscribers'],
            'completed'    => $overviewStats['totals']['completed'],
            'in_progress'  => $overviewStats['totals']['in_progress'],
        ];

        return $this->sendSuccess([
            'automations'     => $funnels,
            'overview'        => $overview,
            'top_automations' => $topAutomations,
        ]);
    }

    public function getAutomationStepReport(Request $request, Reporting $reporting, $id)
    {
        $id = intval($id);
        $funnel = Funnel::findOrFail($id);

        $stats = $reporting->funnelStat($funnel->id);

        return $this->sendSuccess([
            'funnel' => $funnel,
            'stats'  => $stats,
        ]);
    }

    public function getCampaignOptions(Request $request)
    {
        global $wpdb;

        $search = sanitize_text_field($request->get('search', ''));
        $limit = intval($request->get('per_page', 50));

        $query = Campaign::select(['id', 'title'])
            ->where('status', 'archived');

        if ($search) {
            $query->where('title', 'LIKE', '%' . $wpdb->esc_like($search) . '%');
        }

        $options = $query->orderBy('updated_at', 'DESC')
            ->limit($limit)
            ->get();

        return $this->sendSuccess([
            'options' => $options,
        ]);
    }

    public function getAdvancedReportProviders()
    {
        return [
            /**
             * Determine the advanced report providers for FluentCRM.
             *
             * This filter allows you to modify the list of advanced report providers.
             *
             * @since 1.0.0
             *
             * @param array An array of advanced report providers.
             */
            'providers' => apply_filters('fluent_crm/advanced_report_providers', [])
        ];
    }

    public function getRecentTags(Request $request)
    {
        $limit = intval($request->get('per_page', 5));

        $tags = Tag::select(['fc_tags.id', 'fc_tags.title', 'fc_tags.created_at'])
            ->selectRaw('COUNT(subscriber_id) as contact_count')
            ->leftJoin('fc_subscriber_pivot', function ($join) {
                $join->on('fc_tags.id', '=', 'fc_subscriber_pivot.object_id')
                    ->where('fc_subscriber_pivot.object_type', '=', 'FluentCrm\App\Models\Tag');
            })
            ->groupBy('fc_tags.id', 'fc_tags.title', 'fc_tags.created_at')
            ->orderByDesc('fc_tags.created_at')
            ->limit($limit)
            ->get();

        return $this->sendSuccess([
            'tags' => $tags,
        ]);
    }

    public function ping()
    {
        // Browser-driven cron fallback when Action Scheduler and WP-Cron have stalled.
        Scheduler::maybeProcessFromBrowserPing();

        return [
            'message' => 'pong'
        ];
    }
}
