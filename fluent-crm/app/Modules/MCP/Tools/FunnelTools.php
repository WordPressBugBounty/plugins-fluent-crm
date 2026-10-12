<?php

namespace FluentCrm\App\Modules\MCP\Tools;

use FluentCrm\App\Models\Funnel;
use FluentCrm\App\Models\FunnelSequence;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Modules\MCP\Helpers\MCPHelper;
use FluentCrm\App\Services\Funnel\FunnelHelper;

/**
 * Automation (funnel) MCP tools.
 */
class FunnelTools
{
    // -----------------------------------------------------------------
    // Read: list-automations
    // -----------------------------------------------------------------

    public static function listAutomations($params)
    {
        $params = (array) $params;

        MCPHelper::paginationFromInput($params);

        $search   = sanitize_text_field((string) ($params['search'] ?? ''));
        $statuses = (array) ($params['statuses'] ?? []);
        $statuses = array_values(array_intersect(
            array_map('sanitize_key', $statuses),
            ['draft', 'published']
        ));
        // All fc_funnels columns. orderBy() throws on an invalid column name.
        $allowedSortBy = [
            'id', 'type', 'title', 'trigger_name', 'status', 'conditions',
            'settings', 'created_by', 'created_at', 'updated_at',
        ];
        $sortBy = sanitize_key((string) ($params['sort_by'] ?? 'id'));
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'id';
        }
        $sortType = strtoupper(sanitize_text_field((string) ($params['sort_type'] ?? 'DESC')));
        $sortType = in_array($sortType, ['ASC', 'DESC'], true) ? $sortType : 'DESC';

        // Search and status, shared by the list and the uses_tags / uses_lists scan.
        $scope = function ($q) use ($search, $statuses) {
            if ($search !== '') {
                global $wpdb;
                $like = '%' . $wpdb->esc_like($search) . '%';
                $q->where(function ($q) use ($like) {
                    $q->where('title', 'LIKE', $like)
                      ->orWhere('trigger_name', 'LIKE', $like);
                });
            }
            if (!empty($statuses)) {
                $q->whereIn('status', $statuses);
            }
        };

        $query = Funnel::query()->where($scope)->orderBy($sortBy, $sortType);

        // uses_tags / uses_lists: only automations whose trigger, goals, actions or conditions
        // name one of these tags or lists (what a merge or delete would leave dangling).
        $usage = null;
        $idsByKind = [];
        foreach (['tag' => 'uses_tags', 'list' => 'uses_lists'] as $kind => $param) {
            if (empty($params[$param])) {
                continue;
            }
            $resolved = MCPHelper::resolveSegmentRefs((array) $params[$param], $kind, false);
            if ($resolved['unknown']) {
                return MCPHelper::error('invalid_param', $param . ' contains references that match no ' . $kind . '.', [
                    'unknown'   => $resolved['unknown'],
                    'next_step' => 'Check the names with list-' . $kind . 's.',
                ]);
            }
            $idsByKind[$kind] = $resolved['ids'];
        }
        if ($idsByKind) {
            $usage = self::automationsUsingSegments($idsByKind, ($search !== '' || $statuses) ? $scope : null);

            $query->whereIn('id', $usage ? array_keys($usage) : [0]);
        }

        $paginated = $query->paginate();

        $items = [];
        $triggerLabels = self::triggerLabelMap();
        $funnels = $paginated->items();
        $funnelIds = array_map(function ($funnel) {
            return (int) $funnel->id;
        }, $funnels);

        // One grouped count for the page. In progress means active + waiting.
        $subscriberCounts = [];
        if ($funnelIds) {
            $countRows = FunnelSubscriber::whereIn('funnel_id', $funnelIds)
                ->whereIn('status', ['active', 'waiting', 'completed'])
                ->select(['funnel_id', 'status'])
                ->selectRaw('COUNT(id) as total')
                ->groupBy('funnel_id')
                ->groupBy('status')
                ->get();

            foreach ($countRows as $row) {
                $funnelId = (int) $row->funnel_id;
                if (!isset($subscriberCounts[$funnelId])) {
                    $subscriberCounts[$funnelId] = [
                        'in_progress' => 0,
                        'completed'   => 0,
                    ];
                }

                if (in_array($row->status, ['active', 'waiting'], true)) {
                    $subscriberCounts[$funnelId]['in_progress'] += (int) $row->total;
                } elseif ($row->status === 'completed') {
                    $subscriberCounts[$funnelId]['completed'] = (int) $row->total;
                }
            }
        }

        // Last enrollment, any status: last_executed_time stays fresh after the trigger stops firing.
        $lastEnrolled = [];
        if (!empty($params['include_last_enrolled']) && $funnelIds) {
            $enrollRows = FunnelSubscriber::whereIn('funnel_id', $funnelIds)
                ->select(['funnel_id'])
                ->selectRaw('MAX(created_at) as last_enrolled_at')
                ->groupBy('funnel_id')
                ->get();

            foreach ($enrollRows as $row) {
                $lastEnrolled[(int) $row->funnel_id] = $row->last_enrolled_at;
            }
        }

        foreach ($funnels as $funnel) {
            $counts = $subscriberCounts[(int) $funnel->id] ?? [
                'in_progress' => 0,
                'completed'   => 0,
            ];
            $item = [
                'id'                            => (int) $funnel->id,
                'title'                         => $funnel->title,
                'status'                        => $funnel->status,
                'trigger_name'                  => $funnel->trigger_name,
                'trigger_label'                 => $triggerLabels[$funnel->trigger_name] ?? $funnel->trigger_name,
                'in_progress_subscribers_count' => $counts['in_progress'],
                'completed_subscribers_count'   => $counts['completed'],
                'created_at'                    => MCPHelper::toIso8601($funnel->created_at),
                'updated_at'                    => MCPHelper::toIso8601($funnel->updated_at),
            ];

            if (!empty($params['include_last_enrolled'])) {
                $item['last_enrolled_at'] = MCPHelper::toIso8601($lastEnrolled[(int) $funnel->id] ?? null);
            }

            if ($usage !== null) {
                $item['uses'] = $usage[(int) $funnel->id] ?? [];
            }

            $items[] = $item;
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
    // Read: get-automation
    // -----------------------------------------------------------------

    public static function getAutomation($params)
    {
        $params = (array) $params;
        $funnelId = (int) ($params['funnel_id'] ?? 0);

        if (!$funnelId) {
            return MCPHelper::error('invalid_param', 'funnel_id is required');
        }

        $funnel = Funnel::find($funnelId);
        if (!$funnel) {
            return MCPHelper::error('not_found', 'Automation not found', ['funnel_id' => $funnelId]);
        }

        $defaultIncludes = ['steps', 'report'];
        // Omitted => defaults, [] => metadata only, explicit list => exactly those.
        if (array_key_exists('include', $params) && is_array($params['include'])) {
            // Unknown names are refused, not dropped: "sequences" (the pre-2.0 name for steps)
            // would otherwise return an automation with no steps and no hint why.
            $unknown = array_values(array_diff($params['include'], $defaultIncludes));
            if ($unknown) {
                return MCPHelper::error('invalid_param', 'include contains unknown sections.', [
                    'unknown' => $unknown,
                    'allowed' => $defaultIncludes,
                ]);
            }
            $include = array_values(array_intersect($params['include'], $defaultIncludes));
        } else {
            $include = $defaultIncludes;
        }

        $triggerLabels = self::triggerLabelMap();

        $data = [
            'id'                            => (int) $funnel->id,
            'title'                         => $funnel->title,
            'status'                        => $funnel->status,
            'trigger_name'                  => $funnel->trigger_name,
            'trigger_label'                 => $triggerLabels[$funnel->trigger_name] ?? $funnel->trigger_name,
            'trigger_settings'              => is_array($funnel->settings) ? $funnel->settings : [],
            'conditions'                    => is_array($funnel->conditions) ? $funnel->conditions : [],
            'in_progress_subscribers_count' => self::inProgressCount((int) $funnel->id),
            'completed_subscribers_count'   => (int) FunnelSubscriber::where('funnel_id', $funnel->id)
                ->where('status', 'completed')
                ->count(),
            'created_at'                    => MCPHelper::toIso8601($funnel->created_at),
            'updated_at'                    => MCPHelper::toIso8601($funnel->updated_at),
        ];

        if (in_array('steps', $include, true)) {
            $sequences = FunnelHelper::getFunnelSequences($funnel, true);
            $includeBodies = !empty($params['include_bodies']);
            $data['steps'] = self::formatSteps($sequences, $includeBodies);
        }

        if (in_array('report', $include, true)) {
            $data['report'] = self::buildStepReport($funnel);
        }

        return $data;
    }

    /**
     * Automations that name any of the given tag or list ids, with where each one does.
     *
     * Tag and list ids sit in trigger and goal settings ({"tags": ["8"]}), action settings
     * and condition rows ({"source": ["segment", "tags"], "value": ["14"]}). A LIKE prefilter
     * keeps the scan to rows that mention a requested kind at all, and rows are read in id
     * batches so memory stays flat however many automations the site has. Only matches are kept.
     *
     * @param array<string, int[]> $idsByKind 'tag' and/or 'list' => ids; both kinds share one scan
     * @param callable|null        $scope     extra fc_funnels constraints (search, status); steps
     *                                        are only read for automations inside it
     * @return array<int, array<int, array>> funnel id => [{kind, ids, where, step_id, name}]
     */
    public static function automationsUsingSegments(array $idsByKind, $scope = null)
    {
        $wanted = [];
        foreach ($idsByKind as $kind => $ids) {
            $ids = array_values(array_unique(array_map('intval', (array) $ids)));
            if ($ids) {
                $wanted[$kind === 'list' ? 'lists' : 'tags'] = [$kind, $ids];
            }
        }
        if (!$wanted) {
            return [];
        }

        $mentions = function ($q, array $columns) use ($wanted) {
            $q->where(function ($q) use ($columns, $wanted) {
                foreach ($columns as $column) {
                    foreach (array_keys($wanted) as $plural) {
                        $q->orWhere($column, 'LIKE', '%' . $plural . '%');
                    }
                }
            });
        };

        $out = [];
        $add = function ($funnelId, $where, $stepId, $name, $settings) use ($wanted, &$out) {
            foreach ($wanted as $plural => list($kind, $ids)) {
                $found = [];
                self::collectSegmentIds($settings, $plural, $found);
                $hits = array_values(array_intersect($ids, array_unique($found)));
                if ($hits) {
                    $out[(int) $funnelId][] = [
                        'kind'    => $kind,
                        'ids'     => $hits,
                        'where'   => $where,
                        'step_id' => $stepId,
                        'name'    => $name,
                    ];
                }
            }
        };

        $funnelQuery = Funnel::select(['id', 'trigger_name', 'settings', 'conditions']);
        if ($scope) {
            $funnelQuery->where($scope);
        }
        $mentions($funnelQuery, ['settings', 'conditions']);
        $funnelQuery->chunkById(200, function ($funnels) use ($add) {
            foreach ($funnels as $funnel) {
                $add($funnel->id, 'trigger', null, $funnel->trigger_name, [$funnel->settings, $funnel->conditions]);
            }
        });

        $stepQuery = FunnelSequence::select(['id', 'funnel_id', 'type', 'action_name', 'settings']);
        if ($scope) {
            $scopedIds = Funnel::where($scope)->pluck('id')->toArray();
            if (!$scopedIds) {
                return $out;
            }
            $stepQuery->whereIn('funnel_id', $scopedIds);
        }
        $mentions($stepQuery, ['settings']);
        $stepQuery->chunkById(500, function ($steps) use ($add) {
            foreach ($steps as $step) {
                $add($step->funnel_id, $step->type === 'benchmark' ? 'goal' : 'step', (int) $step->id, $step->action_name, $step->settings);
            }
        });

        return $out;
    }

    /**
     * Collect the ids a settings tree holds for "tags" or "lists": arrays under that key, and
     * the value of condition rows whose source names it.
     */
    private static function collectSegmentIds($node, $plural, array &$found)
    {
        if (!is_array($node)) {
            return;
        }

        if (isset($node['source'], $node['value']) && is_array($node['source']) && in_array($plural, $node['source'], true)) {
            foreach ((array) $node['value'] as $value) {
                if (is_scalar($value) && is_numeric($value)) {
                    $found[] = (int) $value;
                }
            }
        }

        foreach ($node as $key => $child) {
            if ($key === $plural && is_array($child)) {
                foreach ($child as $value) {
                    if (is_scalar($value) && is_numeric($value)) {
                        $found[] = (int) $value;
                    }
                }
                continue;
            }
            self::collectSegmentIds($child, $plural, $found);
        }
    }

    /**
     * Format automation steps (fc_funnel_sequences rows) for MCP. Body fields are stripped from
     * settings unless $includeBodies.
     */
    private static function formatSteps($sequences, $includeBodies = false)
    {
        $out = [];
        foreach ((array) $sequences as $seq) {
            $row = is_object($seq) ? get_object_vars($seq) : (array) $seq;
            $settings = $row['settings'] ?? [];
            if (!$includeBodies) {
                $settings = self::stripBodyFields($settings);
            }
            $out[] = [
                'id'           => isset($row['id']) ? (int) $row['id'] : null,
                // Run order: getFunnelSequences() sorts by this column.
                'position'     => isset($row['sequence']) ? (int) $row['sequence'] : null,
                'type'         => $row['type'] ?? null,
                'action_name'  => $row['action_name'] ?? null,
                'settings'     => $settings,
                'delay'        => $row['delay'] ?? 0,
                'delay_unit'   => $row['c_delay_unit'] ?? ($row['delay_unit'] ?? null),
                'parent_id'    => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * Recursively replace body fields with a marker pointing at include_bodies.
     */
    private static function stripBodyFields($value)
    {
        $bodyKeys = ['email_body', 'body', 'body_html', 'body_text'];
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            if (is_string($k) && in_array($k, $bodyKeys, true) && is_string($v)) {
                $len = strlen($v);
                $value[$k] = $len > 0
                    ? '[truncated — re-fetch with include_bodies=true; ' . $len . ' chars]'
                    : '';
            } elseif (is_array($v)) {
                $value[$k] = self::stripBodyFields($v);
            }
        }
        return $value;
    }

    private static function buildStepReport($funnel)
    {
        $stepCounts = FunnelSubscriber::where('funnel_id', $funnel->id)
            ->select(['last_sequence_id'])
            ->selectRaw('COUNT(id) as total')
            ->groupBy('last_sequence_id')
            ->get();

        $steps = [];
        foreach ($stepCounts as $row) {
            if (!$row->last_sequence_id) {
                continue;
            }
            $steps[] = [
                'step_id'  => (int) $row->last_sequence_id,
                'total'    => (int) $row->total,
            ];
        }
        return ['steps' => $steps];
    }

    private static function inProgressCount($funnelId)
    {
        return (int) FunnelSubscriber::where('funnel_id', $funnelId)
            ->whereIn('status', ['active', 'waiting'])
            ->count();
    }

    private static function triggerLabelMap()
    {
        $triggers = apply_filters('fluentcrm_funnel_triggers', []);
        $map = [];
        if (is_array($triggers)) {
            foreach ($triggers as $key => $config) {
                $map[$key] = $config['label'] ?? $key;
            }
        }
        return $map;
    }

    // -----------------------------------------------------------------
    // Read: list-funnel-subscribers (round-4 review P3 #11)
    // -----------------------------------------------------------------

    /**
     * List the contacts in a funnel, filtered by funnel-subscriber status.
     */
    public static function listFunnelSubscribers($params)
    {
        $params = (array) $params;
        $funnelId = (int) ($params['funnel_id'] ?? 0);
        if (!$funnelId) {
            return MCPHelper::error('invalid_param', 'funnel_id is required');
        }

        $funnel = Funnel::find($funnelId);
        if (!$funnel) {
            return MCPHelper::error('not_found', 'Automation not found', ['funnel_id' => $funnelId]);
        }

        $allowedStatuses = ['active', 'waiting', 'completed', 'cancelled', 'skipped'];
        $statuses = (array) ($params['statuses'] ?? ['active']);
        $statuses = array_values(array_intersect(array_map('sanitize_key', $statuses), $allowedStatuses));
        if (!$statuses) {
            $statuses = ['active'];
        }

        MCPHelper::paginationFromInput($params);

        $rows = FunnelSubscriber::with(['subscriber' => function ($q) {
            $q->select(['id', 'email', 'first_name', 'last_name', 'status', 'contact_type']);
        }])
            ->where('funnel_id', $funnelId)
            ->whereIn('status', $statuses)
            ->orderBy('id', 'DESC')
            ->paginate();

        $items = [];
        foreach ($rows->items() as $row) {
            $sub = $row->subscriber;
            if (!$sub) {
                continue;
            }
            $items[] = [
                'funnel_subscriber_id' => (int) $row->id,
                'funnel_status'        => $row->status,
                'next_step_id'         => $row->next_sequence_id ? (int) $row->next_sequence_id : null,
                'last_executed_at'     => MCPHelper::toIso8601($row->last_executed_time),
                'next_execution_at'    => MCPHelper::toIso8601($row->next_execution_time),
                'enrolled_at'          => MCPHelper::toIso8601($row->created_at),
                'contact'              => [
                    'id'           => (int) $sub->id,
                    'email'        => $sub->email,
                    'full_name'    => trim((string) ($sub->first_name . ' ' . $sub->last_name)),
                    'status'       => $sub->status,
                    'contact_type' => $sub->contact_type,
                ],
            ];
        }

        return [
            'items'     => $items,
            'total'     => (int) $rows->total(),
            'page'      => (int) $rows->currentPage(),
            'per_page'  => (int) $rows->perPage(),
            'pages'     => (int) $rows->lastPage(),
            'funnel'    => [
                'id'    => (int) $funnel->id,
                'title' => $funnel->title,
            ],
            'filtered_statuses' => $statuses,
        ];
    }

    // -----------------------------------------------------------------
    // Write: update-contact-automation-status
    // -----------------------------------------------------------------

    public static function updateContactAutomationStatus($params)
    {
        $params = (array) $params;
        $funnelId = (int) ($params['funnel_id'] ?? 0);
        $action   = sanitize_key((string) ($params['action'] ?? ''));

        if (!$funnelId) {
            return MCPHelper::error('invalid_param', 'funnel_id is required');
        }
        if (!in_array($action, ['resume', 'cancel', 'advance_now'], true)) {
            // FluentCRM has no paused funnel-subscriber state.
            if ($action === 'pause') {
                return MCPHelper::error('not_supported', 'pause is not supported: FluentCRM has no paused state for funnel subscribers. Use cancel to stop processing (reversible from the UI), or wait for a real benchmark.', [
                    'allowed_actions' => ['resume', 'cancel', 'advance_now'],
                ]);
            }
            return MCPHelper::error('invalid_param', 'Invalid action', [
                'allowed_actions' => ['resume', 'cancel', 'advance_now'],
            ]);
        }

        $contact = MCPHelper::resolveContact($params);
        if (is_wp_error($contact)) {
            return $contact;
        }

        $funnel = Funnel::find($funnelId);
        if (!$funnel) {
            return MCPHelper::error('not_found', 'Automation not found', ['funnel_id' => $funnelId]);
        }

        $row = FunnelSubscriber::where('funnel_id', $funnelId)
            ->where('subscriber_id', $contact->id)
            ->first();
        if (!$row) {
            return MCPHelper::error('not_found', 'Contact is not enrolled in this automation');
        }

        $previousStatus = $row->status;

        if ($row->status === 'completed') {
            return MCPHelper::error('not_supported', 'Automation is already completed for this contact', [
                'status' => $row->status,
            ]);
        }

        if ($action === 'cancel') {
            $row->status = 'cancelled';
            $row->save();
        } elseif ($action === 'resume') {
            $row->status = 'active';
            if (!$row->next_execution_time) {
                $row->next_execution_time = gmdate('Y-m-d H:i:s', current_time('timestamp') + 60);
            }
            $row->save();
        } elseif ($action === 'advance_now') {
            $sequenceId = (int) ($params['advance_to_step_id'] ?? 0);
            if (!$sequenceId) {
                return MCPHelper::error('invalid_param', 'advance_to_step_id is required for advance_now', [
                    'next_step' => 'Read the step ids from get-automation include=["steps"].',
                ]);
            }
            $sequence = \FluentCrm\App\Models\FunnelSequence::where('id', $sequenceId)
                ->where('funnel_id', $funnelId)
                ->first();
            if (!$sequence) {
                return MCPHelper::error('not_found', 'advance_to_step_id is not a step of this automation.', [
                    'advance_to_step_id' => $sequenceId,
                    'next_step'          => 'Read the step ids from get-automation include=["steps"].',
                ]);
            }

            // Same service as the Web UI's "Advance to Step".
            $advanced = \FluentCrm\App\Services\Funnel\FunnelHelper::advanceSubscriberToSequence($row, $sequence, 'Skipped via MCP advance_now');
            if (is_wp_error($advanced)) {
                return MCPHelper::error('not_supported', $advanced->get_error_message(), [
                    'status' => $row->status,
                ]);
            }
        }

        $row = FunnelSubscriber::find($row->id);

        return [
            'ok'              => true,
            'action'          => $action,
            'previous_status' => $previousStatus,
            'current_status'  => $row->status,
            'funnel_subscriber' => [
                'id'                => (int) $row->id,
                'funnel_id'         => (int) $row->funnel_id,
                'subscriber_id'     => (int) $row->subscriber_id,
                'status'            => $row->status,
                'next_step_id'      => $row->next_sequence_id ? (int) $row->next_sequence_id : null,
                'next_execution_time' => MCPHelper::toIso8601($row->next_execution_time),
            ],
        ];
    }
}
