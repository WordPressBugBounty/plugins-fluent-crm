<?php

namespace FluentCrm\App\Services;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\SubscriberMeta;
use FluentCrm\Framework\Support\Arr;

class ContactsQuery
{
    private $args = [];

    private $model = null;

    /**
     * Every real fc_subscribers column, the allowlist for `sort_by`.
     */
    const SORTABLE_COLUMNS = [
        'id', 'user_id', 'hash', 'contact_owner', 'company_id', 'prefix',
        'first_name', 'last_name', 'email', 'timezone', 'address_line_1',
        'address_line_2', 'postal_code', 'city', 'state', 'country', 'ip',
        'latitude', 'longitude', 'total_points', 'life_time_value', 'phone',
        'status', 'contact_type', 'source', 'avatar', 'date_of_birth',
        'created_at', 'last_activity', 'updated_at',
    ];

    /**
     * ContactsQuery args for the contact list's request params.
     * The list, its counts, prev/next and select-all bulk actions all build their query here.
     *
     * @param array $params The list's params: filter_type, search,
     *   search_custom_fields, sort_by, sort_type, tags, lists, statuses, company_ids,
     *   advanced_filters (JSON or array), has_commerce, custom_fields.
     * @return array
     */
    public static function argsFromRequest($params)
    {
        $params = is_array($params) ? $params : [];

        $flag = function ($key) use ($params) {
            return in_array(Arr::get($params, $key), [true, 1, '1', 'true', 'yes'], true);
        };
        // Absent or empty means "no filter"; a single scalar id is one id.
        $ids = function ($key) use ($params) {
            $value = Arr::get($params, $key);
            if (!$value) {
                return [];
            }
            return is_array($value) ? $value : [$value];
        };
        $scalar = function ($key, $default = '') use ($params) {
            $value = Arr::get($params, $key, $default);
            return is_scalar($value) ? (string)$value : $default;
        };

        $sortBy = sanitize_sql_orderby($scalar('sort_by', 'id'));

        $args = [
            'filter_type'          => $scalar('filter_type') === 'advanced' ? 'advanced' : 'simple',
            'search'               => trim(sanitize_text_field($scalar('search'))),
            'search_custom_fields' => $flag('search_custom_fields'),
            'sort_by'              => $sortBy ?: 'id',
            // The builder throws on anything but asc/desc.
            'sort_type'            => strtoupper($scalar('sort_type', 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
            'has_commerce'         => $flag('has_commerce'),
            'custom_fields'        => $flag('custom_fields'),
            'company_ids'          => array_values(array_filter(array_map('intval', array_filter($ids('company_ids'), 'is_scalar')))),
        ];

        if ($args['filter_type'] === 'advanced') {
            $args['filters_groups_raw'] = Helper::parseArrayOrJson(Arr::get($params, 'advanced_filters'));
        } else {
            $args['tags'] = $ids('tags');
            $args['lists'] = $ids('lists');
            $args['statuses'] = $ids('statuses');
        }

        return $args;
    }

    /**
     * A ContactsQuery for the contact list's request params; see argsFromRequest().
     *
     * @param array $params The list's params.
     * @param array $overrides Args that are not filters, e.g. `with`, `sort_by`, `limit`.
     * @return static
     */
    public static function fromRequest($params, $overrides = [])
    {
        return new static(array_merge(static::argsFromRequest($params), $overrides));
    }

    public function __construct($args = [])
    {
        $this->args = wp_parse_args($args, [
            'with'               => ['tags', 'lists'],
            'filter_type'        => 'simple',
            'filters_groups'     => [],
            'filters_groups_raw' => [],
            'search'             => '',
            'sort_by'            => 'id',
            'sort_type'          => 'DESC',
            'tags'               => [],
            'lists'              => [],
            'statuses'           => [],
            'has_commerce'       => false,
            'custom_fields'      => false,
            // Also match search text against custom-field values; separate from `custom_fields`.
            'search_custom_fields' => false,
            'limit'              => false,
            'offset'             => false,
            'contact_status'     => '',
            'contact_type'       => '',
            'company_ids'        => [],
            'contact_ids'        => [],
            'emails'             => []
        ]);

        $this->setupQuery();
    }

    private function setupQuery()
    {
        if ($this->args['filters_groups_raw']) {
            $this->formatAdvancedFilters();
        }

        $subscribersQuery = Subscriber::with($this->args['with']);
        $searchPlan = ContactSearch::parse($this->args['search']);
        if ($searchPlan['mode'] !== 'empty') {
            ContactSearch::apply($subscribersQuery, $searchPlan, [
                'include_custom_fields' => (bool)$this->args['search_custom_fields'],
            ]);
        }

        $shortBy = $this->args['sort_by'];

        // `relevance` without a search falls back to id order.
        if ($shortBy === 'relevance') {
            if ($searchPlan['mode'] !== 'empty') {
                ContactSearch::orderByRelevance($subscribersQuery, $searchPlan);
                $shortBy = '';
            } else {
                $shortBy = 'id';
                $this->args['sort_type'] = 'DESC';
            }
        }

        if ($shortBy) {
            // orderBy() throws on unknown or malformed column names.
            if (in_array($shortBy, self::SORTABLE_COLUMNS, true)) {
                $subscribersQuery->orderBy($shortBy, $this->args['sort_type']);
                // Tie-break on id so offset pages are stable.
                if ($shortBy !== 'id') {
                    $subscribersQuery->orderBy('id', $this->args['sort_type']);
                }
            }
        }

        if ($this->args['filter_type'] == 'advanced') {
            $filtersGroups = $this->args['filters_groups'];

            $subscribersQuery->where(function ($subscribersQueryGroup) use ($filtersGroups) {
                foreach ($filtersGroups as $groupIndex => $group) {
                    $method = 'orWhere';
                    if ($groupIndex == 0) {
                        $method = 'where';
                    }

                    $subscribersQueryGroup->{$method}(function ($q) use ($group) {
                        // Fail closed: a group with no applicable constraints matches nothing, not everyone.
                        if (!$group) {
                            $q->whereRaw('1 = 0');
                            return;
                        }

                        foreach ($group as $providerName => $items) {
                            if (!has_action('fluentcrm_contacts_filter_' . $providerName)) {
                                $q->whereRaw('1 = 0');
                                continue;
                            }
                            do_action_ref_array('fluentcrm_contacts_filter_' . $providerName, [&$q, $items]);
                        }
                    });
                }
            });
        } else {
            if ($tags = $this->args['tags']) {
                $subscribersQuery->filterByTags($tags);
            }

            if ($lists = $this->args['lists']) {
                $subscribersQuery->filterByLists($lists);
            }

            if ($statuses = $this->args['statuses']) {
                $statuses = array_values(array_filter(array_map([Sanitize::class, 'contactStatus'], (array) $statuses)));

                if ($statuses) {
                    $subscribersQuery->filterByStatues($statuses);
                } else {
                    // No known status in the filter: match nothing, since these args drive bulk deletes.
                    $subscribersQuery->whereRaw('1 = 0');
                }
            }
        }

        if ($this->args['has_commerce']) {
            /**
             * Filter the commerce provider for quering contacts in FluentCRM.
             *
             * This filter allows you to modify the commerce provider used in the Contact Query.
             *
             * @since 2.5.1
             *
             * @param string The commerce provider.
             */
            $commerceProvider = apply_filters('fluentcrm_commerce_provider', '');
            if ($commerceProvider) {
                $subscribersQuery->with(['commerce_by_provider' => function ($query) use ($commerceProvider) {
                    $query->where('provider', $commerceProvider);
                }]);
            }
        }

        if ($this->args['contact_status']) {
            $subscribersQuery->where('status', $this->args['contact_status']);
        }

        // Applies to both simple and advanced filters.
        if ($this->args['contact_type']) {
            $subscribersQuery->where('contact_type', $this->args['contact_type']);
        }

        if ($this->args['company_ids']) {
            $subscribersQuery->filterByCompanies($this->args['company_ids']);
        }

        if ($this->args['contact_ids'] && is_array($this->args['contact_ids']) && !empty($this->args['contact_ids'])) {
            $subscribersQuery->whereIn('id', $this->args['contact_ids']);
        }

        // Narrows either filter branch; matching follows the column collation.
        if ($this->args['emails'] && is_array($this->args['emails'])) {
            $subscribersQuery->whereIn('email', $this->args['emails']);
        }

        $this->model = $subscribersQuery;
    }

    public function get()
    {
        $subscriberModel = $this->model;

        if ($limit = $this->args['limit']) {
            $subscriberModel = $subscriberModel->limit($limit);
        }

        if ($offset = $this->args['offset']) {
            $subscriberModel = $subscriberModel->offset($offset);
        }

        return $this->returnSubscribers($subscriberModel->get());
    }

    public function paginate()
    {
        return $this->returnSubscribers($this->model->paginate());
    }

    public function getModel()
    {
        return $this->model;
    }

    private function returnSubscribers($subscribers)
    {
        if ($this->args['custom_fields']) {
            // One meta query for the whole page.
            $customFields = fluentcrm_get_custom_contact_fields();

            $keys = [];
            if ($customFields && is_array($customFields)) {
                $keys = array_map(function ($item) {
                    return $item['slug'];
                }, $customFields);
            }

            $subscriberIds = [];
            foreach ($subscribers as $subscriber) {
                $subscriberIds[] = $subscriber->id;
            }

            $valuesBySubscriber = [];
            if ($keys && $subscriberIds) {
                $metaRows = SubscriberMeta::where('object_type', 'custom_field')
                    ->whereIn('subscriber_id', $subscriberIds)
                    ->whereIn('key', $keys)
                    ->get();

                foreach ($metaRows as $metaRow) {
                    $valuesBySubscriber[$metaRow->subscriber_id][$metaRow->key] = apply_filters('fluent_crm/modify_custom_field_value', $metaRow->value);
                }
            }

            foreach ($subscribers as $subscriber) {
                $subscriber->custom_fields = isset($valuesBySubscriber[$subscriber->id]) ? $valuesBySubscriber[$subscriber->id] : [];
            }
        }

        return $subscribers;
    }

    private function formatAdvancedFilters()
    {
        $filters = $this->args['filters_groups_raw'];

        $groups = [];

        foreach ($filters as $filterGroup) {
            $group = [];
            $hasRows = false;
            foreach ((array)$filterGroup as $filterItem) {
                $hasRows = true;
                $source = Arr::get((array)$filterItem, 'source');
                if (!is_array($source) || count($source) != 2 || empty($source[0]) || empty($source[1]) || empty($filterItem['operator'])) {
                    continue;
                }
                $provider = $filterItem['source'][0];

                if (!isset($group[$provider])) {
                    $group[$provider] = [];
                }

                $property = $filterItem['source'][1];

                if ($property == 'purchased_groups') {
                    $property = 'purchased_items';
                }

                $group[$provider][] = [
                    'property'    => $property,
                    'operator'    => $filterItem['operator'],
                    'value'       => Arr::get($filterItem, 'value', ''),
                    'extra_value' => Arr::get($filterItem, 'extra_value')
                ];
            }

            // An empty UI group is dropped; a group whose rows all failed to parse is kept so it fails closed.
            if ($group || $hasRows) {
                $groups[] = $group;
            }
        }

        $this->args['filters_groups'] = $groups;
    }

}
