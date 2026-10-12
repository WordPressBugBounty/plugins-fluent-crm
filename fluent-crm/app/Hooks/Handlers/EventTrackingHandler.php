<?php

namespace FluentCrm\App\Hooks\Handlers;

use FluentCrm\App\Models\EventTracker;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

class EventTrackingHandler
{
    public function register()
    {
        add_filter('fluentcrm_ajax_options_event_tracking_keys', [$this, 'getEventTrackingKeyOptions'], 10, 1);
        add_action('fluentcrm_contacts_filter_event_tracking', [$this, 'applyEventTrackingFilter'], 10, 2);
        add_action('fluent_crm/track_event_activity', [$this, 'trackEventActivity'], 10, 2);

        add_filter('fluent_crm/subscriber_info_widgets', [$this, 'addSubscriberInfoWidgets'], 10, 2);
        add_filter('fluent_crm/subscriber_info_widget_event_tracking', [$this, 'addSubscriberInfoWidgets'], 10, 2);

        add_filter('fluentcrm_advanced_filter_options', [$this, 'addEventTrackingFilterOptions'], 10, 1);

        add_filter('fluent_crm/event_tracking_condition_groups', [$this, 'addEventTrackingConditionOptions'], 10, 1);

        add_filter('fluentcrm_automation_condition_groups', function ($groups) {
            if (!Helper::isExperimentalEnabled('event_tracking')) {
                return $groups;
            }

            $groups['event_tracking'] = [
                'label'    => __('Event Tracking', 'fluent-crm'),
                'value'    => 'event_tracking',
                'children' => $this->getConditionItems()
            ];

            return $groups;
        });
    }

    public function getEventTrackingKeyOptions($options = [])
    {
        $items = EventTracker::select(['event_key'])
            ->groupBy('event_key')
            ->orderBy('event_key', 'ASC')
            ->get();

        $formattedItems = [];

        foreach ($items as $item) {
            $formattedItems[] = [
                'id'    => $item->event_key,
                'title' => $item->event_key
            ];
        }

        return $formattedItems;
    }

    public function applyEventTrackingFilter($query, $filters)
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            // Fail closed: with event tracking off, an event segment matches nobody.
            $query->whereRaw('1 = 0');
            return $query;
        }

        foreach ($filters as $filter) {
            if (!array_key_exists('value', $filter) || $filter['value'] === '') {
                continue;
            }

            $relation = 'trackingEvents';

            $filterProp = $filter['property'];

            if ($filterProp == 'event_tracking_key') {
                $operator = $filter['operator'];
                $values = $filter['value'];
                if ($operator == 'not_in') {
                    $query->whereDoesntHave($relation, function ($q) use ($values) {
                        $q->whereIn('event_key', $values);
                    });
                } else {
                    $query->whereHas($relation, function ($q) use ($values) {
                        $q->whereIn('event_key', $values);
                    });
                }
                continue;
            }

            if ($filterProp == 'event_tracking_title') {
                $operator = $filter['operator'];

                if ($operator == '=') {
                    $query->whereHas($relation, function ($q) use ($filter) {
                        $q->where('title', $filter['value']);
                    });
                } else if ($operator == '!=') {
                    $query->whereDoesntHave($relation, function ($q) use ($filter) {
                        $q->where('title', $filter['value']);
                    });
                } else if ($operator == 'contains') {
                    $query->whereHas($relation, function ($q) use ($filter) {
                        global $wpdb;
                        $q->where('title', 'LIKE', '%' . $wpdb->esc_like($filter['value']) . '%');
                    });
                } else if ($operator == 'not_contains') {
                    $query->whereDoesntHave($relation, function ($q) use ($filter) {
                        global $wpdb;
                        $q->where('title', 'LIKE', '%' . $wpdb->esc_like($filter['value']) . '%');
                    });
                }
                continue;
            }

            if ($filterProp == 'event_tracking_value') {

                $eventKey = Arr::get($filter, 'extra_value');
                if (!$eventKey) {
                    continue;
                }

                $operator = $filter['operator'];
                $value = $filter['value'];

                if ($operator == '=') {
                    $query->whereIn('id', $this->latestValueSubscribers($eventKey, '=', $value));
                } else if ($operator == '!=') {
                    $query->whereNotIn('id', $this->latestValueSubscribers($eventKey, '=', $value));
                } else if (in_array($operator, ['<', '>'])) {
                    $query->whereIn('id', $this->latestValueSubscribers($eventKey, $operator, (int)$value));
                } else if ($operator == 'contains') {
                    global $wpdb;
                    $query->whereIn('id', $this->latestValueSubscribers($eventKey, 'LIKE', '%' . $wpdb->esc_like($value) . '%'));
                } else if ($operator == 'not_contains') {
                    global $wpdb;
                    $query->whereNotIn('id', $this->latestValueSubscribers($eventKey, 'LIKE', '%' . $wpdb->esc_like($value) . '%'));
                }
                continue;
            }

            if ($filterProp == 'event_tracking_key_count') {

                $eventKey = Arr::get($filter, 'extra_value');
                if (!$eventKey) {
                    continue;
                }

                $operator = $filter['operator'];
                $count = (int)$filter['value'];

                if (in_array($operator, ['=', '<', '>'])) {
                    $query->whereIn('id', $this->eventCountSubscribers($eventKey, $operator, $count));
                } else if ($operator == '!=') {
                    $query->whereNotIn('id', $this->eventCountSubscribers($eventKey, '=', $count));
                }

                continue;
            }
        }

        return $query;
    }

    /**
     * Subquery of the contacts whose total occurrences of an event match the comparison.
     *
     * Tracker::track() keeps one row per (contact, event key, title), and a new row per occurrence
     * when the event is not repeatable, so the occurrence count is the sum of counter over every
     * row of the key. Contacts without the event are not in the result.
     *
     * @param string $eventKey
     * @param string $operator one of =, <, >
     * @param int $count
     * @return \Closure for whereIn('id', ...)
     */
    private function eventCountSubscribers($eventKey, $operator, $count)
    {
        return function ($q) use ($eventKey, $operator, $count) {
            $q->from('fc_event_tracking')
                ->select('subscriber_id')
                ->where('event_key', $eventKey)
                ->groupBy('subscriber_id')
                ->havingRaw('SUM(counter) ' . $operator . ' ?', [$count]);
        };
    }

    /**
     * Subquery of the contacts whose last recorded value of an event matches the comparison.
     *
     * Only the most recently updated row of the key counts (ties go to the higher id), so an older
     * occurrence with a matching value does not match.
     *
     * @param string $eventKey
     * @param string $operator one of =, <, >, LIKE
     * @param mixed $value
     * @return \Closure for whereIn('id', ...)
     */
    private function latestValueSubscribers($eventKey, $operator, $value)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_event_tracking';

        return function ($q) use ($eventKey, $operator, $value, $table) {
            $q->from('fc_event_tracking')
                ->select('subscriber_id')
                ->where('event_key', $eventKey)
                ->where('value', $operator, $value)
                ->whereRaw("NOT EXISTS (SELECT 1 FROM `{$table}` AS newer WHERE newer.subscriber_id = `{$table}`.subscriber_id AND newer.event_key = `{$table}`.event_key AND (newer.updated_at > `{$table}`.updated_at OR (newer.updated_at = `{$table}`.updated_at AND newer.id > `{$table}`.id)))");
        };
    }

    public function trackEventActivity($data, $repeatable = true)
    {
        return FluentCrmApi('event_tracker')->track($data, $repeatable);
    }

    public function addSubscriberInfoWidgets($widgets, $subscriber)
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            return $widgets;
        }

        $events = EventTracker::where('subscriber_id', $subscriber->id)
            ->orderBy('updated_at', 'DESC')
            ->paginate();

        if ($events->isEmpty()) {
            return $widgets;
        }

        $html = '<div class="fc_scrolled_lists"><ul class="fcrm_event_tracking_lists">';
        foreach ($events as $event) {
            $html .= '<li>';
            $html .= '<p class="fcrm_event_tracking_title">' . esc_html($event->title) . '</p>';
            if ($event->value) {
                $html .= '<p class="fcrm_event_tracking_value">' . wp_kses_post($event->value) . '</p>';
            }
            $html .= '<div class="fcrm_event_tracking_footer">';
            $html .= '<div class="fcrm_event_tracking_badge">' . esc_attr($event->event_key) . '<span class="fcrm_event_tracking_count">(' . esc_html($event->counter) . ')</span></div>';
            $html .= '<span class="fcrm_event_tracking_date">' . esc_html(Helper::formatDateTime($event->updated_at)) . '</span>';
            $html .= '</div>';
            $html .= '</li>';
        }
        $html .= '</ul></div>';

        $widgets['event_tracking'] = [
            'title'          => __('Event Tracking', 'fluent-crm'),
            'content'        => $html,
            'has_pagination' => $events->total() > $events->perPage(),
            'total'          => $events->total(),
            'per_page'       => $events->perPage(),
            'current_page'   => $events->currentPage()
        ];

        return $widgets;
    }

    public function addEventTrackingFilterOptions($groups)
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            return $groups;
        }

        $groups['event_tracking'] = [
            'label'    => __('Event Tracking', 'fluent-crm'),
            'value'    => 'event_tracking',
            'children' => $this->getConditionItems()
        ];

        return $groups;
    }

    public function addEventTrackingConditionOptions($items)
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            return $items;
        }

        return [
            [
                'label'    => __('Event Tracking', 'fluent-crm'),
                'value'    => 'event_tracking',
                'children' => $this->getConditionItems()
            ],
            [
                'label'    => __('Contact Segment', 'fluent-crm'),
                'value'    => 'segment',
                'children' => [
                    [
                        'label'             => __('Type', 'fluent-crm'),
                        'value'             => 'contact_type',
                        'type'              => 'selections',
                        'component'         => 'options_selector',
                        'option_key'        => 'contact_types',
                        'is_multiple'       => false,
                        'is_singular_value' => true
                    ],
                    [
                        'label'       => __('Tags', 'fluent-crm'),
                        'value'       => 'tags',
                        'type'        => 'selections',
                        'component'   => 'options_selector',
                        'option_key'  => 'tags',
                        'is_multiple' => true,
                    ],
                    [
                        'label'       => __('Lists', 'fluent-crm'),
                        'value'       => 'lists',
                        'type'        => 'selections',
                        'component'   => 'options_selector',
                        'option_key'  => 'lists',
                        'is_multiple' => true,
                    ]
                ],
            ]
        ];
    }

    private function getConditionItems()
    {
        return [
            [
                'label'              => __('Event Key', 'fluent-crm'),
                'value'              => 'event_tracking_key',
                'type'               => 'selections',
                'component'          => 'ajax_selector',
                'option_key'         => 'event_tracking_keys',
                'is_multiple'        => true,
                'custom_operators'   => [
                    'in'     => 'in',
                    'not_in' => 'not in'
                ],
                'creatable'          => true,
                'experimental_cache' => true,
                'help'               => __('Match one or more tracking events for your contacts.', 'fluent-crm')
            ],
            [
                'label'            => __('Event Occurrence Count', 'fluent-crm'),
                'value'            => 'event_tracking_key_count',
                'type'             => 'composite_optioned_compare',
                'help'             => __('The value you enter is compared with the number of times the selected event has occurred', 'fluent-crm'),
                'ajax_selector'    => [
                    'label'              => __('For Event Key', 'fluent-crm'),
                    'option_key'         => 'event_tracking_keys',
                    'experimental_cache' => true,
                    'is_multiple'        => false,
                    'placeholder'        => __('Select Event Key', 'fluent-crm')
                ],
                'value_config'     => [
                    'label'       => __('Event Count', 'fluent-crm'),
                    'type'        => 'input_text',
                    'data_type'   => 'number',
                    'placeholder' => __('Event Value', 'fluent-crm')
                ],
                'custom_operators' => [
                    '='  => 'equal',
                    '!=' => 'not equal',
                    '>'  => 'greater than',
                    '<'  => 'less than'
                ],
            ],
            [
                'label'            => __('Event Value', 'fluent-crm'),
                'value'            => 'event_tracking_value',
                'type'             => 'composite_optioned_compare',
                'help'             => __('The compare value is matched against the last recorded value of the selected event key', 'fluent-crm'),
                'ajax_selector'    => [
                    'label'              => __('For Event Key', 'fluent-crm'),
                    'option_key'         => 'event_tracking_keys',
                    'experimental_cache' => true,
                    'is_multiple'        => false,
                    'placeholder'        => __('Select Event Key', 'fluent-crm')
                ],
                'value_config'     => [
                    'label'       => __('Compare Value', 'fluent-crm'),
                    'type'        => 'input_text',
                    'placeholder' => __('Event Value', 'fluent-crm'),
                    'data_type'   => 'number',
                ],
                'custom_operators' => [
                    '='            => 'equal',
                    '!='           => 'not equal',
                    'contains'     => 'includes',
                    'not_contains' => 'does not include',
                    '>'            => 'greater than',
                    '<'            => 'less than'
                ],
            ],
            [
                'label' => __('Event Title', 'fluent-crm'),
                'value' => 'event_tracking_title',
                'type'  => 'text',
                'help'  => __('Match by tracking event title', 'fluent-crm')
            ],
        ];
    }
}
