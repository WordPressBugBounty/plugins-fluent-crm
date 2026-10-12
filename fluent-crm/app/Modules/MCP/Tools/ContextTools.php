<?php

namespace FluentCrm\App\Modules\MCP\Tools;

use FluentCrm\App\Models\CustomContactField;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Modules\MCP\AbilitiesRegistrar;
use FluentCrm\App\Modules\MCP\Helpers\MCPHelper;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\PermissionManager;
use FluentCrm\App\Services\Stats;

/**
 * `get-crm-context`: compact, permission-scoped discovery surface.
 * Sections are selected through `include` and built only for users holding the matching capability.
 */
class ContextTools
{
    const DEFAULT_SECTIONS = ['overview'];

    const AVAILABLE_SECTIONS = [
        'overview',
        'stats',
        'segments',
        'enums',
        'sender',
        'custom_fields',
        'smart_codes',
        'automation_catalog',
        'safety',
        'rate_hints',
        'capabilities',
        'guidelines',
    ];

    public static function getContext($params = [])
    {
        $params = (array) $params;
        $sections = self::normalizeSections($params);
        if (is_wp_error($sections)) {
            return $sections;
        }

        // Deliberately uncached so new tags and lists show up immediately.
        $userId      = get_current_user_id();
        $permissions = self::effectivePermissions(PermissionManager::currentUserPermissions(false));
        $access      = self::buildAccessMap();

        return self::buildContext($userId, $permissions, $access, $sections, $params);
    }

    /**
     * Normalize the `include` section selection.
     * Omitted => DEFAULT_SECTIONS, [] => identity/site only, `all` => every section.
     */
    private static function normalizeSections($params)
    {
        if (!array_key_exists('include', $params)) {
            return self::DEFAULT_SECTIONS;
        }

        if (!is_array($params['include'])) {
            return MCPHelper::error('invalid_param', 'include must be an array of context section names.');
        }

        $requested = array_values(array_unique(array_map('sanitize_key', $params['include'])));
        if (in_array('all', $requested, true)) {
            return self::AVAILABLE_SECTIONS;
        }

        $unknown = array_values(array_diff($requested, self::AVAILABLE_SECTIONS));
        if ($unknown) {
            return MCPHelper::error('invalid_param', 'include contains unknown context sections.', [
                'unknown' => $unknown,
                'allowed' => self::AVAILABLE_SECTIONS,
            ]);
        }

        // Caller's order, de-duplicated.
        return $requested;
    }

    private static function buildAccessMap()
    {
        $contacts = PermissionManager::currentUserCan('fcrm_read_contacts');
        $emails   = PermissionManager::currentUserCan('fcrm_read_emails');
        $funnels  = PermissionManager::currentUserCan('fcrm_read_funnels');

        return [
            'dashboard' => PermissionManager::currentUserCan('fcrm_view_dashboard'),
            'contacts'  => $contacts,
            'emails'    => $emails,
            'funnels'   => $funnels,
            'settings'  => PermissionManager::currentUserCan('fcrm_manage_settings'),
        ];
    }

    private static function buildContext($userId, $permissions, $access, $sections, $params = [])
    {
        $user = get_user_by('ID', $userId);

        $isAdmin = $user && user_can($user, 'manage_options');

        $you = [
            'wp_user_id'  => (int) $userId,
            'name'        => $user ? $user->display_name : null,
            'email'       => $user ? $user->user_email : null,
            'is_admin'    => (bool) $isAdmin,
            'permissions' => $permissions,
            'tool_groups' => array_keys(array_filter([
                'dashboard'   => $access['dashboard'],
                'contacts'    => $access['contacts'],
                'campaigns'   => $access['emails'],
                'automations' => $access['funnels'],
            ])),
        ];

        $proActive = defined('FLUENTCAMPAIGN');

        $site = [
            'site_url'              => site_url(),
            'fluent_crm_version'    => defined('FLUENTCRM_PLUGIN_VERSION') ? FLUENTCRM_PLUGIN_VERSION : null,
            'fluent_campaign_active' => $proActive,
            'timezone'               => fluentCrmGetTimezoneString(),
            // Dates sent without an offset are read in this zone.
            'timezone_name'          => MCPHelper::siteTimezoneName(),
            'utc_offset'             => MCPHelper::siteUtcOffset(),
            'current_time'           => fluentCrmTimestamp(),
        ];

        $sectionCatalog = [];
        foreach (self::AVAILABLE_SECTIONS as $section) {
            if (self::canAccessSection($section, $access)) {
                $sectionCatalog[$section] = ['available' => true];
            } else {
                $sectionCatalog[$section] = self::unavailableSection($section);
            }
        }

        $context = [
            'you'              => $you,
            'site'             => $site,
            'context_sections' => [
                'default'      => self::DEFAULT_SECTIONS,
                'requested'    => $sections,
                'included'     => [],
                'request_with' => 'include',
                'catalog'      => $sectionCatalog,
            ],
        ];

        foreach ($sections as $section) {
            if (!self::canAccessSection($section, $access)) {
                continue;
            }

            self::appendSection($context, $section, $access, $params);
            $context['context_sections']['included'][] = $section;
        }

        return $context;
    }

    /**
     * Filter permissions through currentUserCan(), as the ability permission callbacks do.
     */
    private static function effectivePermissions($permissions)
    {
        $effective = [];
        foreach ((array) $permissions as $permission) {
            if (PermissionManager::currentUserCan($permission)) {
                $effective[] = $permission;
            }
        }

        return array_values(array_unique($effective));
    }

    /**
     * Append one authorized section under its top-level response keys.
     */
    private static function appendSection(&$context, $section, $access, $params = [])
    {
        switch ($section) {
            case 'overview':
                $context['overview'] = self::buildOverview($access);
                break;
            case 'stats':
                $context['stats'] = self::buildStats();
                break;
            case 'segments':
                $withCounts = !empty($params['include_segment_counts']);
                $context['tags'] = self::tagsForContext($withCounts);
                $context['lists'] = self::listsForContext($withCounts);
                break;
            case 'enums':
                $context['enums'] = self::buildEnums($access);
                break;
            case 'sender':
                $context['default_sender'] = self::buildDefaultSender();
                break;
            case 'custom_fields':
                $context['custom_fields_schema'] = ['contact' => self::buildCustomFieldSchema()];
                break;
            case 'smart_codes':
                $context['smart_codes'] = self::buildSmartCodes();
                break;
            case 'automation_catalog':
                $context['available_triggers'] = self::formatRefList(
                    apply_filters('fluentcrm_funnel_triggers', []),
                    'trigger_name'
                );
                $context['available_actions'] = self::formatRefList(
                    apply_filters('fluentcrm_funnel_blocks', [], null),
                    'action_name'
                );
                break;
            case 'safety':
                $context['safety_levels'] = self::buildSafetyLevels();
                break;
            case 'rate_hints':
                $context['rate_hints'] = self::buildRateHints();
                break;
            case 'capabilities':
                $context['mcp_capabilities'] = self::buildCapabilities();
                break;
            case 'guidelines':
                $context['guidelines'] = self::buildGuidelines($access);
                break;
        }
    }

    private static function canAccessSection($section, $access)
    {
        switch ($section) {
            case 'stats':
                return $access['dashboard'];
            case 'segments':
            case 'custom_fields':
                return $access['contacts'];
            case 'sender':
                return $access['emails'] || $access['settings'];
            case 'smart_codes':
                return $access['emails'];
            case 'automation_catalog':
                return $access['funnels'];
            case 'enums':
                return $access['contacts'] || $access['emails'] || $access['funnels'];
            default:
                return true;
        }
    }

    private static function unavailableSection($section)
    {
        $requirements = [
            'stats'              => ['fcrm_view_dashboard'],
            'segments'           => ['fcrm_read_contacts'],
            'custom_fields'      => ['fcrm_read_contacts'],
            'smart_codes'        => ['fcrm_read_emails'],
            'automation_catalog' => ['fcrm_read_funnels'],
            'enums'              => ['fcrm_read_contacts', 'fcrm_read_emails', 'fcrm_read_funnels'],
        ];

        $marker = [
            'available' => false,
            'reason'    => 'missing_capability',
        ];

        if ($section === 'sender') {
            $marker['required_any'] = ['fcrm_read_emails', 'fcrm_manage_settings'];
            return $marker;
        }

        $required = $requirements[$section] ?? [];
        if (count($required) === 1) {
            $marker['required'] = $required[0];
        } elseif ($required) {
            $marker['required_any'] = $required;
        }

        return $marker;
    }

    /**
     * Default discovery: capability-scoped counts, essential enums and feature flags.
     */
    private static function buildOverview($access)
    {
        $counts = [];
        if ($access['dashboard'] || $access['contacts']) {
            $stats = (new Stats())->getCounts();
            $todayStart = (new \DateTime('today', new \DateTimeZone(fluentCrmGetTimezoneString())))->format('Y-m-d H:i:s');
            $counts['contacts_total'] = Subscriber::count();
            $counts['contacts_subscribed'] = (int) ($stats['total_subscribers']['count'] ?? Subscriber::where('status', 'subscribed')->count());
            $counts['contacts_new_today'] = Subscriber::where('created_at', '>=', $todayStart)->count();
        }

        if ($access['dashboard'] || $access['emails']) {
            $sevenDaysAgo = gmdate('Y-m-d H:i:s', current_time('timestamp') - (7 * DAY_IN_SECONDS));
            $counts['campaigns_sent_last_7d'] = \FluentCrm\App\Models\Campaign::where('status', 'archived')
                ->where('updated_at', '>=', $sevenDaysAgo)
                ->count();
        }

        if ($access['dashboard'] || $access['funnels']) {
            $counts['automations_active'] = \FluentCrm\App\Models\Funnel::where('status', 'published')->count();
            $counts['automations_total'] = \FluentCrm\App\Models\Funnel::count();
        }

        $enums = [];
        if ($access['contacts']) {
            $enums['contact_statuses'] = array_values(fluentcrm_subscriber_statuses());
            $enums['contact_types'] = array_keys(fluentcrm_contact_types());
        }
        if ($access['emails']) {
            $enums['campaign_statuses'] = self::campaignStatuses();
            $enums['one_off_statuses'] = self::oneOffStatuses();
        }

        return [
            'counts'   => $counts,
            'enums'    => $enums,
            'features' => [
                'fluent_campaign_active' => defined('FLUENTCAMPAIGN'),
                'companies_enabled'       => Helper::isCompanyEnabled(),
                'event_tracking_enabled'  => Helper::isExperimentalEnabled('event_tracking'),
            ],
        ];
    }

    /**
     * Marketing-campaign lifecycle statuses (type='campaign').
     *
     * @return array<int, string>
     */
    public static function campaignStatuses()
    {
        return ['draft', 'scheduled', 'pending-scheduled', 'processing', 'working', 'paused', 'archived'];
    }

    /**
     * Statuses carried by one-off sends (type='custom_email_campaign').
     * The status never advances; delivery state is get-campaign's `one_off_status`.
     *
     * @return array<int, string>
     */
    public static function oneOffStatuses()
    {
        return ['published'];
    }

    private static function buildEnums($access)
    {
        $enums = [];

        if ($access['contacts']) {
            $enums['contact_statuses'] = array_values(fluentcrm_subscriber_statuses());
            $enums['contact_types'] = array_keys(fluentcrm_contact_types());
            $enums['note_types'] = ['note', 'call', 'email', 'meeting', 'quote'];
        }

        if ($access['emails']) {
            $enums['campaign_statuses'] = self::campaignStatuses();
            $enums['one_off_statuses'] = self::oneOffStatuses();
            $enums['design_templates'] = array_keys(self::allowedDesignTemplates());
        }

        if ($access['funnels']) {
            $enums['funnel_statuses'] = ['draft', 'published'];
            $enums['funnel_subscriber_statuses'] = ['active', 'waiting', 'completed', 'cancelled', 'skipped'];
        }

        return $enums;
    }

    /**
     * Tools grouped by risk, derived from each ability's MCP hints so the context section
     * and tools/list can never disagree. Keys are the tool names agents call
     * ("fluent-crm-get-contact"), not the ability names.
     *
     *   read_only: reads only
     *   write: changes data, deletes nothing
     *   destructive: overwrites or deletes data
     *   reaches_people: can email or notify contacts, directly or through automations
     *   confirm_first: reaches_people, or destructive and idempotent (deletes and removals);
     *                  confirm with the user before calling. Draft edits are left out.
     */
    private static function buildSafetyLevels()
    {
        $groups = ['read_only' => [], 'write' => [], 'destructive' => [], 'reaches_people' => [], 'confirm_first' => []];

        foreach (self::abilityAnnotations() as $abilityName => $hints) {
            $tool = MCPHelper::toolName($abilityName);
            if (!empty($hints['readonly'])) {
                $groups['read_only'][] = $tool;
            } elseif (!empty($hints['destructive'])) {
                $groups['destructive'][] = $tool;
            } else {
                $groups['write'][] = $tool;
            }
            if (!empty($hints['openWorldHint'])) {
                $groups['reaches_people'][] = $tool;
            }
            $deletes = !empty($hints['destructive']) && !empty($hints['idempotent']);
            if (empty($hints['readonly']) && ($deletes || !empty($hints['openWorldHint']))) {
                $groups['confirm_first'][] = $tool;
            }
        }

        return apply_filters('fluent_crm/mcp_safety_levels', $groups);
    }

    /**
     * MCP hint annotations of every ability on the FluentCRM server, Pro's included.
     *
     * @return array<string, array> ability name => annotations
     */
    private static function abilityAnnotations()
    {
        $names = apply_filters('fluent_crm/mcp_ability_names', array_keys(AbilitiesRegistrar::getDefinitions()));
        $out   = [];

        foreach (array_unique((array) $names) as $name) {
            $ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;
            if (!$ability || !method_exists($ability, 'get_meta')) {
                continue;
            }
            $meta = (array) $ability->get_meta();
            $out[$name] = (array) ($meta['annotations'] ?? []);
        }

        return $out;
    }

    /**
     * Per-tool rate and batch-size limits.
     */
    private static function buildRateHints()
    {
        $cap = (int) apply_filters('fluent_crm/mcp_bulk_cap', 5000, 'apply-tags-lists-to-contacts');
        return apply_filters('fluent_crm/mcp_rate_hints', [
            'fluent-crm-bulk-upsert-contacts'        => ['max_per_call' => 500, 'recommended_batch' => 100],
            'fluent-crm-apply-tags-lists-to-contacts'  => ['max_per_call' => $cap],
            'fluent-crm-manage-sequence-subscribers' => ['max_per_call' => $cap],
            'fluent-crm-send-email-to-contact'       => ['note' => 'Goes through the normal queue and bounce handling; the site\'s sending rate limit applies.'],
        ]);
    }

    /**
     * Versioned MCP capabilities map. `version` is the result schema version (MCPHelper::SCHEMA_VERSION).
     */
    private static function buildCapabilities()
    {
        return apply_filters('fluent_crm/mcp_capabilities', [
            'version' => MCPHelper::SCHEMA_VERSION,
            'supports' => [
                'paginated_tag_list_registry',
                'segment_last_used_at',
                'automation_last_enrolled_at',
                'compact_permission_scoped_context',
                'context_section_selection',
                'bounded_contact_profile',
                'campaign_body_format',
                'dry_run_apply_tags_lists',
                'send_test_email',
                'test_email_sender_parity',
                'render_email_preview',
                'mcp_annotation_hints',
                'contact_cross_plugin_includes',
                'smart_codes_discovery',
                'safety_levels',
                'rate_hints',
                'manage_tags_lists',
                'delete_contact_note',
                'one_off_email_send',
                'campaign_warnings',
                'auto_suffix_title_conflict',
                'list_funnel_subscribers',
                'applied_contact_ids_return',
                'tracking_mode_aware_stats',
                'recipients_strict_validation',
                'advanced_filters_provider_validation',
                'contact_filter_schema_discovery',
            ],
        ]);
    }

    private static function buildStats()
    {
        $stats = (new Stats())->getCounts();

        $todayStart = (new \DateTime('today', new \DateTimeZone(fluentCrmGetTimezoneString())))->format('Y-m-d H:i:s');
        // Site-local: the compared columns store site time.
        $sevenDaysAgo = gmdate('Y-m-d H:i:s', current_time('timestamp') - (7 * DAY_IN_SECONDS));

        return [
            'contacts_total'         => Subscriber::count(),
            'contacts_subscribed'    => (int) ($stats['total_subscribers']['count'] ?? Subscriber::where('status', 'subscribed')->count()),
            'contacts_new_today'     => Subscriber::where('created_at', '>=', $todayStart)->count(),
            'campaigns_sent_last_7d' => \FluentCrm\App\Models\Campaign::where('status', 'archived')
                ->where('updated_at', '>=', $sevenDaysAgo)
                ->count(),
            'automations_active'     => \FluentCrm\App\Models\Funnel::where('status', 'published')->count(),
            'automations_total'      => \FluentCrm\App\Models\Funnel::count(),
        ];
    }

    /**
     * The first tags an agent needs to resolve names to ids.
     * subscribers_count is opt-in: it runs a pivot subquery per row.
     */
    private static function tagsForContext($withCounts = false, $limit = 50)
    {
        return self::segmentRegistry(Tag::query(), $withCounts, $limit);
    }

    private static function listsForContext($withCounts = false, $limit = 50)
    {
        return self::segmentRegistry(Lists::query(), $withCounts, $limit);
    }

    private static function segmentRegistry($query, $withCounts, $limit)
    {
        // By size when counted, alphabetical otherwise.
        if ($withCounts) {
            $query->withCount('subscribers')->orderByDesc('subscribers_count');
        } else {
            $query->orderBy('title', 'ASC');
        }

        $out = [];
        foreach ($query->limit($limit)->get() as $row) {
            $item = [
                'id'    => (int) $row->id,
                'title' => $row->title,
                'slug'  => $row->slug,
            ];
            if ($withCounts) {
                $item['subscribers_count'] = (int) $row->subscribers_count;
            }
            $out[] = $item;
        }
        return $out;
    }

    private static function formatRefList($items, $keyField)
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $key => $item) {
            $name = is_string($key) ? $key : ($item[$keyField] ?? null);
            if (!$name) {
                continue;
            }
            $out[] = [
                'key'    => $name,
                'label'  => $item['label'] ?? $item['title'] ?? $name,
                'is_pro' => !empty($item['is_pro']),
            ];
        }
        return $out;
    }

    /**
     * Design templates agents may select. visual_builder is excluded unless
     * added through `fluent_crm/mcp_allowed_design_templates`.
     */
    public static function allowedDesignTemplates()
    {
        $defaults = [
            'plain'       => 'Plain',
            'classic'     => 'Classic',
            'raw_html'    => 'Raw HTML',
            'raw_classic' => 'Raw Classic',
        ];

        if (method_exists(Helper::class, 'getEmailDesignTemplates')) {
            $all = Helper::getEmailDesignTemplates();
            if (is_array($all) && $all) {
                $excluded = ['visual_builder'];
                $filtered = array_diff_key($all, array_flip($excluded));
                if ($filtered) {
                    $defaults = $filtered;
                }
            }
        }

        /**
         * Filter the design templates surfaced to MCP agents. Useful for
         * adding custom templates a site has registered, or allow-listing
         * visual_builder if the operator really wants agents to use it.
         *
         * @since 2.10.0
         *
         * @param array $templates Map of slug => label.
         */
        return apply_filters('fluent_crm/mcp_allowed_design_templates', $defaults);
    }

    private static function buildDefaultSender()
    {
        $emailSettings = Helper::getGlobalEmailSettings();
        return [
            'from_name'      => $emailSettings['from_name'] ?? '',
            'from_email'     => $emailSettings['from_email'] ?? '',
            'reply_to_name'  => $emailSettings['reply_to_name'] ?? '',
            'reply_to_email' => $emailSettings['reply_to_email'] ?? '',
        ];
    }

    private static function buildCustomFieldSchema()
    {
        $model  = new CustomContactField();
        $global = $model->getGlobalFields();
        $fields = is_array($global) ? ($global['fields'] ?? []) : [];

        $out = [];
        foreach ((array) $fields as $field) {
            $entry = [
                'key'   => $field['slug'] ?? null,
                'label' => $field['label'] ?? null,
                'type'  => $field['type'] ?? null,
            ];
            if (!empty($field['options'])) {
                $entry['options'] = array_values((array) $field['options']);
            }
            if ($entry['key']) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Flatten Helper::getGlobalSmartCodes() into a compact shape, keeping the groups.
     */
    private static function buildSmartCodes()
    {
        if (!method_exists(Helper::class, 'getGlobalSmartCodes')) {
            return [];
        }

        $groups = Helper::getGlobalSmartCodes();
        if (!is_array($groups)) {
            return [];
        }

        $out = [];
        foreach ($groups as $group) {
            $codes = [];
            $shortcodes = $group['shortcodes'] ?? [];
            if (is_array($shortcodes)) {
                foreach ($shortcodes as $code => $label) {
                    $codes[] = ['code' => (string) $code, 'label' => (string) $label];
                }
            }
            $out[] = [
                'key'   => $group['key'] ?? null,
                'title' => $group['title'] ?? null,
                'codes' => $codes,
            ];
        }
        return $out;
    }

    private static function buildGuidelines($access)
    {
        $guidelines = ['Be concise.'];

        if ($access['contacts']) {
            $guidelines[] = 'Use add_tags/remove_tags for contact delta updates. Request include=["custom_fields"] before constructing custom_fields payloads — never invent keys.';
            $guidelines[] = "For OR groups, engagement/activity, custom-field, or relative-date conditions, call get-contact-filter-schema before using advanced_filters.";
        }

        if ($access['emails']) {
            $guidelines[] = "send-email-to-contact needs the contact's status to be subscribed or transactional; never change a status just to send. Campaigns go only to subscribed contacts. upsert-campaign saves a draft; change-campaign-status action=schedule starts delivery.";
            $guidelines[] = 'A scheduled_at without an offset is read in site.timezone_name.';
        }

        if ($access['funnels']) {
            $guidelines[] = 'Inspect an automation and subscriber state before changing contact automation status.';
        }

        $default = implode(' ', $guidelines);

        /**
         * Filter the AI guidelines text returned in get-crm-context.
         *
         * Useful for shop-specific nudges (e.g. "always tag MCP-touched contacts
         * with `mcp-edited`"). Keep terse because agents may request it often.
         *
         * @since 2.10.0
         *
         * @param string $default
         */
        return apply_filters('fluent_crm/mcp_ai_guidelines', $default);
    }

}
