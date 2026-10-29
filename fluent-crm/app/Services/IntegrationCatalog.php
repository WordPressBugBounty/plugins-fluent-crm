<?php

namespace FluentCrm\App\Services;

/**
 * The full list of third-party integrations shown on Settings → Integrations.
 *
 * The list lives in core so it renders whether or not FluentCampaign Pro is
 * active. Pro (and any addon) still contributes the configurable part through
 * `fluentcrm_deep_integration_providers`; those entries are merged on top of
 * the catalog by key, so catalog keys must match the provider keys
 * (`woo`, `edd`, `learndash`, ...).
 */
class IntegrationCatalog
{
    /**
     * Categories the integrations screen filters by. Anything else a
     * provider sends is filed under `others`.
     */
    const CATEGORIES = ['commerce', 'lms', 'membership', 'page_builder', 'fluent', 'others'];

    /**
     * Build the integrations list for the settings screen.
     *
     * @param bool $withFields Passed through to the deep integration providers filter.
     * @return array Keyed by integration slug.
     */
    public static function getIntegrations($withFields = false)
    {
        $isProActive = defined('FLUENTCAMPAIGN');

        /**
         * Filter the static integrations catalog on Settings → Integrations.
         *
         * Each item: title, description, logo, type (array of tab categories),
         * tier ('free'|'pro') and plugin_active (bool).
         *
         * @param array $catalog Keyed by integration slug.
         * @since 3.2.7
         */
        $catalog = apply_filters('fluent_crm/integrations_catalog', self::getCatalog());

        /**
         * Determine the deep integration providers for FluentCRM.
         *
         * This filter allows modification of the deep integration providers used in FluentCRM such as Woocommerce, Easy Digital Downloads, etc.
         *
         * @param array An array of deep integration providers.
         * @param bool $withFields Whether to include fields in the integration providers.
         * @since 2.5.1
         *
         */
        $providers = apply_filters('fluentcrm_deep_integration_providers', [], $withFields);

        $integrations = [];

        foreach ($catalog as $key => $item) {
            $provider = isset($providers[$key]) && is_array($providers[$key]) ? $providers[$key] : [];
            unset($providers[$key]);

            // Provider data (sub_title, settings, sync copy) wins, but the catalog owns the tab placement.
            $integrations[$key] = self::withStatus(array_merge($item, $provider, [
                'type' => $item['type']
            ]), $isProActive);
        }

        // Providers registered by addons that the catalog does not know about yet.
        foreach ($providers as $key => $provider) {
            if (!is_array($provider) || empty($provider['title'])) {
                continue;
            }

            $provider['type'] = self::normalizeTypes(isset($provider['type']) ? $provider['type'] : 'others');
            if (!isset($provider['plugin_active'])) {
                $provider['plugin_active'] = !empty($provider['is_enabled']);
            }

            $integrations[$key] = self::withStatus($provider, $isProActive);
        }

        return $integrations;
    }

    /**
     * Add the status flags the UI renders from.
     *
     * pro_locked:      Pro-tier integration while Pro is not active; listed but not actionable.
     * is_configurable: has a settings screen (provider supplied `settings`) and the plugin is active.
     */
    private static function withStatus($item, $isProActive)
    {
        $item['tier'] = isset($item['tier']) ? $item['tier'] : 'free';
        $item['is_pro'] = $item['tier'] === 'pro';
        $item['pro_locked'] = $item['is_pro'] && !$isProActive;
        $item['plugin_active'] = !empty($item['plugin_active']);
        $item['is_configurable'] = !$item['pro_locked'] && $item['plugin_active'] && !empty($item['settings']);
        $item['logo'] = self::normalizeLogo(isset($item['logo']) ? $item['logo'] : '');

        return $item;
    }

    /**
     * `logo` accepts an image URL or inline SVG markup.
     *
     * Inline SVG is turned into a base64 data URI (same approach as the admin
     * menu icon in AdminMenu) so the UI keeps rendering it through <img src>,
     * never v-html: an SVG loaded as an image cannot run scripts or fetch
     * external resources, so markup from third-party providers stays inert.
     */
    private static function normalizeLogo($logo)
    {
        if (!is_string($logo)) {
            return '';
        }

        $logo = trim($logo);

        if (stripos($logo, '<svg') === 0 || stripos($logo, '<?xml') === 0) {
            return 'data:image/svg+xml;base64,' . base64_encode($logo);
        }

        return $logo;
    }

    /**
     * Reduce any provider `type` (string or array) to the known tab categories.
     */
    private static function normalizeTypes($types)
    {
        $types = array_intersect((array)$types, self::CATEGORIES);

        return $types ? array_values($types) : ['others'];
    }

    /**
     * Logo URL for a file in resources/images (copied to assets/images on build).
     *
     * Returns an empty string when the file does not ship yet, so the UI falls
     * back to the first letter of the title instead of a broken image.
     */
    private static function logo($file)
    {
        foreach (['assets/images/', 'resources/images/'] as $dir) {
            if (file_exists(FLUENTCRM_PLUGIN_PATH . $dir . $file)) {
                return fluentCrmMix('images/' . $file);
            }
        }

        return '';
    }

    /**
     * Check whether an Easy Digital Downloads version can load the Pro integration.
     *
     * @param string $version Easy Digital Downloads version number.
     * @return bool
     */
    private static function supportsEddVersion($version)
    {
        return version_compare((string) $version, '3.0', '>=');
    }

    /**
     * The integrations implemented in FluentCRM (free), FluentCampaign Pro, and
     * the Fluent plugins that ship their FluentCRM integration themselves
     * (FluentBooking, Fluent Support, ...).
     */
    private static function getCatalog()
    {
        $theme = wp_get_theme();
        $isVoxelTheme = strtolower((string)$theme->get('Name')) === 'voxel' || strtolower((string)$theme->get('Template')) === 'voxel';

        return [
            // Free
            'fluentcart'   => [
                'title'         => __('FluentCart', 'fluent-crm'),
                'description'   => __('Sync FluentCart customers, run automations on orders and subscriptions, and segment contacts by purchase history.', 'fluent-crm'),
                'logo'          => self::logo('fluent-cart-dark.svg'),
                'type'          => ['commerce', 'fluent'],
                'tier'          => 'free',
                'plugin_active' => defined('FLUENTCART_VERSION'),
            ],
            'fluentform'   => [
                'title'         => __('Fluent Forms', 'fluent-crm'),
                'description'   => __('Add form submitters to FluentCRM and start automations when a form is submitted.', 'fluent-crm'),
                'logo'          => self::logo('fluent-forms.svg'),
                'type'          => ['fluent', 'others'],
                'tier'          => 'free',
                'plugin_active' => defined('FLUENTFORM'),
            ],

            // Free: Fluent plugins that ship their FluentCRM integration in their own codebase
            'fluentbooking' => [
                'title'         => __('FluentBooking', 'fluent-crm'),
                'description'   => __('Run automations when a booking is scheduled, rescheduled, completed or canceled.', 'fluent-crm'),
                'logo'          => self::logo('fluent-booking.svg'),
                'type'          => ['fluent'],
                'tier'          => 'free',
                'plugin_active' => defined('FLUENT_BOOKING_VERSION'),
            ],
            'fluentsupport' => [
                'title'         => __('Fluent Support', 'fluent-crm'),
                'description'   => __('See support tickets on contact profiles and create tickets from automations.', 'fluent-crm'),
                'logo'          => self::logo('fluent-support.svg'),
                'type'          => ['fluent'],
                'tier'          => 'free',
                'plugin_active' => defined('FLUENT_SUPPORT_VERSION'),
            ],
            'fluentcommunity' => [
                'title'         => __('FluentCommunity', 'fluent-crm'),
                'description'   => __('Run automations when members join spaces, enroll in courses or complete lessons.', 'fluent-crm'),
                'logo'          => self::logo('fluent-community.svg'),
                'type'          => ['fluent', 'lms', 'membership'],
                'tier'          => 'free',
                'plugin_active' => defined('FLUENT_COMMUNITY_PLUGIN_VERSION'),
            ],
            'fluentboards' => [
                'title'         => __('Fluent Boards', 'fluent-crm'),
                'description'   => __('Create tasks from automations and run automations when contacts are added to boards or tasks change stage.', 'fluent-crm'),
                'logo'          => self::logo('fluent-boards.svg'),
                'type'          => ['fluent'],
                'tier'          => 'free',
                'plugin_active' => defined('FLUENT_BOARDS'),
            ],
            'fluentaffiliate' => [
                'title'         => __('FluentAffiliate', 'fluent-crm'),
                'description'   => __('Run automations when affiliates register or are approved, referrals are created and payouts are sent.', 'fluent-crm'),
                'logo'          => '<svg width="300" height="300" viewBox="0 0 300 300" fill="none" xmlns="http://www.w3.org/2000/svg"><rect y="6.10352e-05" width="300" height="300" rx="30" fill="#2C6AE2"/><rect x="195.927" y="65.9805" width="184.615" height="46.1539" rx="23.0769" transform="rotate(105 195.927 65.9805)" fill="#FEFFFF"/><rect x="234.68" y="150.21" width="69.2307" height="46.1539" rx="23.0769" transform="rotate(105 234.68 150.21)" fill="#FEFFFF"/><rect x="127.31" y="93.2028" width="69.2307" height="46.1539" rx="23.0769" transform="rotate(105 127.31 93.2028)" fill="#FEFFFF"/></svg>',
                'type'          => ['fluent'],
                'tier'          => 'free',
                'plugin_active' => defined('FLUENT_AFFILIATE_VERSION'),
            ],
            'paymattic'    => [
                'title'         => __('Paymattic', 'fluent-crm'),
                'description'   => __('Add payment form submitters to FluentCRM with tags and lists.', 'fluent-crm'),
                'logo'          => '<svg width="300" height="300" viewBox="0 0 300 300" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M0 30C0 13.4315 13.4315 0 30 0H270C286.569 0 300 13.4315 300 30V270C300 286.569 286.569 300 270 300H30C13.4315 300 0 286.569 0 270V30Z" fill="#FF6A00"/><path d="M213.27 55.4807C211.611 51.6572 207.155 49.4175 202.808 50.8493L195.795 53.1535C195.743 53.1696 195.687 53.188 195.636 53.2041L98.5696 85.129C98.0119 85.3131 97.4637 85.5249 96.9387 85.7643C90.4387 88.9662 90.84 98.7193 98.1286 101.118L98.1333 101.122L138.774 114.487L178.385 101.484C181.308 100.524 184.323 102.672 184.323 105.71V133.192C184.323 137.027 181.833 140.432 178.145 141.643L163.164 146.559V189.137L202.372 176.245C209.282 173.968 213.944 167.587 213.944 160.401V58.7701C213.944 57.5892 213.701 56.482 213.267 55.4807H213.27Z" fill="white" fill-opacity="0.7"/><path d="M151.601 118.699L98.1402 101.12L98.1355 101.115C90.8446 98.7167 90.4456 88.9636 96.9457 85.7617C90.9402 88.4388 87.002 94.3616 87.002 100.97V210.669C87.002 217.86 91.6635 224.241 98.5742 226.513L152.035 244.092C157.513 245.897 163.173 241.871 163.173 236.172V134.543C163.173 127.357 158.512 120.971 151.601 118.699Z" fill="white"/><path opacity="0.5" d="M98.1293 85.2725C97.7063 85.4106 97.309 85.574 96.9375 85.7628C97.4633 85.5234 98.0101 85.3116 98.5709 85.1274L98.1293 85.2725Z" fill="white"/></svg>',
                'type'          => ['fluent', 'commerce'],
                'tier'          => 'free',
                'plugin_active' => defined('WPPAYFORM_VERSION'),
            ],
            'oxygen'       => [
                'title'         => __('Oxygen Builder', 'fluent-crm'),
                'description'   => __('Show or hide Oxygen content based on the visitor\'s FluentCRM tags, lists and status.', 'fluent-crm'),
                'logo'          => '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M50 87.5C52.5711 87.5 55.0818 87.2412 57.5074 86.7484C56.6808 84.5324 56.229 82.1338 56.229 79.6296C56.229 68.3794 65.3491 59.2593 76.5993 59.2593C79.8825 59.2593 82.9842 60.036 85.7308 61.4157C86.8798 57.8162 87.5 53.9805 87.5 50C87.5 29.2893 70.7107 12.5 50 12.5C29.2893 12.5 12.5 29.2893 12.5 50C12.5 70.7107 29.2893 87.5 50 87.5ZM95.221 71.3597C98.2858 64.8825 100 57.6414 100 50C100 22.3858 77.6142 0 50 0C22.3858 0 0 22.3858 0 50C0 77.6142 22.3858 100 50 100C55.737 100 61.2484 99.0338 66.3802 97.2552C69.3841 99.0005 72.875 100 76.5993 100C87.8496 100 96.9697 90.8799 96.9697 79.6296C96.9697 76.6853 96.345 73.8869 95.221 71.3597ZM86.0322 79.6875C86.0322 84.8652 81.8349 89.0625 76.6572 89.0625C71.4795 89.0625 67.2822 84.8652 67.2822 79.6875C67.2822 74.5098 71.4795 70.3125 76.6572 70.3125C81.8349 70.3125 86.0322 74.5098 86.0322 79.6875Z" fill="black"/></svg>',
                'type'          => ['page_builder'],
                'tier'          => 'free',
                'plugin_active' => defined('CT_VERSION'),
            ],
            'bricks'       => [
                'title'         => __('Bricks Builder', 'fluent-crm'),
                'description'   => __('Show or hide Bricks elements based on the visitor\'s FluentCRM tags, lists and status.', 'fluent-crm'),
                'logo'          => self::logo('bricks.svg'),
                'type'          => ['page_builder'],
                'tier'          => 'free',
                'plugin_active' => defined('BRICKS_VERSION'),
            ],

            // Pro: e-commerce
            'woo'          => [
                'title'         => __('WooCommerce', 'fluent-crm'),
                'description'   => __('Segment customers by purchases, lifetime value and purchase dates, and run automations on orders.', 'fluent-crm'),
                'logo'          => self::logo('woo.svg'),
                'type'          => ['commerce'],
                'tier'          => 'pro',
                'plugin_active' => defined('WC_PLUGIN_FILE'),
            ],
            'edd'          => [
                'title'         => __('Easy Digital Downloads', 'fluent-crm'),
                'description'   => __('Segment customers by purchases and lifetime value, and run automations on product sales.', 'fluent-crm'),
                'logo'          => self::logo('edd.svg'),
                'type'          => ['commerce'],
                'tier'          => 'pro',
                'plugin_active' => defined('EDD_VERSION') && self::supportsEddVersion(EDD_VERSION),
            ],
            'surecart'     => [
                'title'         => __('SureCart', 'fluent-crm'),
                'description'   => __('Sync SureCart customers and run automations on purchases.', 'fluent-crm'),
                'logo'          => '<svg width="16px" height="16px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 137 137" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M68.5 137C106.332 137 137 106.332 137 68.5C137 30.6685 106.332 0 68.5 0C30.6685 0 0 30.6685 0 68.5C0 106.332 30.6685 137 68.5 137ZM68.795 34.25C63.2947 34.25 55.6831 37.3955 51.7938 41.2756L41.2306 51.8141H93.8817L111.487 34.25H68.795ZM85.1174 95.7244C81.2282 99.6045 73.6165 102.75 68.1163 102.75H25.4242L43.0295 85.1859H95.6806L85.1174 95.7244ZM102.229 60.5962H32.4471L29.1508 63.8895C21.3458 70.9151 23.6606 76.4039 34.5912 76.4039H104.563L107.86 73.1106C115.589 66.1263 113.16 60.5962 102.229 60.5962Z" fill="#008156"/></svg>',
                'type'          => ['commerce'],
                'tier'          => 'pro',
                'plugin_active' => defined('SURECART_PLUGIN_FILE'),
            ],
            'voxel'        => [
                'title'         => __('Voxel', 'fluent-crm'),
                'description'   => __('Sync Voxel customers and segment them by their orders.', 'fluent-crm'),
                'logo'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 206.34 206.34"><polygon points="114.63 91.71 114.63 137.56 22.93 45.85 45.85 22.93 22.93 22.93 0 45.85 0 114.63 91.71 206.34 114.63 206.34 114.63 160.49 137.56 160.49 206.34 91.71 206.34 22.93 183.41 22.93 114.63 91.71"/><polygon points="160.49 0 68.78 0 45.85 22.93 183.41 22.93 160.49 0"/></svg>',
                'type'          => ['commerce', 'membership'],
                'tier'          => 'pro',
                'plugin_active' => $isVoxelTheme,
            ],

            // Pro: LMS
            'learndash'    => [
                'title'         => __('LearnDash', 'fluent-crm'),
                'description'   => __('Run automations on course enrollment, course completion, lesson completion and group membership.', 'fluent-crm'),
                'logo'          => self::logo('learndash.png'),
                'type'          => ['lms'],
                'tier'          => 'pro',
                'plugin_active' => defined('LEARNDASH_VERSION'),
            ],
            'lifterlms'    => [
                'title'         => __('LifterLMS', 'fluent-crm'),
                'description'   => __('Run automations on course enrollment, course completion, lesson completion and memberships.', 'fluent-crm'),
                'logo'          => self::logo('lifterlms.png'),
                'type'          => ['lms'],
                'tier'          => 'pro',
                'plugin_active' => defined('LLMS_PLUGIN_FILE'),
            ],
            'tutorlms'     => [
                'title'         => __('Tutor LMS', 'fluent-crm'),
                'description'   => __('Run automations on course enrollment, course completion and lesson completion.', 'fluent-crm'),
                'logo'          => self::logo('tutorlms.jpg'),
                'type'          => ['lms'],
                'tier'          => 'pro',
                'plugin_active' => defined('TUTOR_VERSION'),
            ],
            'learnpress'   => [
                'title'         => __('LearnPress', 'fluent-crm'),
                'description'   => __('Run automations on course enrollment and course completion.', 'fluent-crm'),
                'logo'          => self::logo('learnpress.png'),
                'type'          => ['lms'],
                'tier'          => 'pro',
                'plugin_active' => defined('LP_PLUGIN_FILE'),
            ],

            // Pro: membership, community, forms, page builders
            'memberpress'  => [
                'title'         => __('MemberPress', 'fluent-crm'),
                'description'   => __('Segment members by membership and run automations when memberships change.', 'fluent-crm'),
                'logo'          => self::logo('memberpress.png'),
                'type'          => ['membership'],
                'tier'          => 'pro',
                'plugin_active' => defined('MEPR_PLUGIN_NAME'),
            ],
            'buddypress'   => [
                'title'         => __('BuddyPress / BuddyBoss', 'fluent-crm'),
                'description'   => __('Sync community members and segment them by groups and member types.', 'fluent-crm'),
                'logo'          => self::logo('buddypress.png'),
                'type'          => ['membership'],
                'tier'          => 'pro',
                'plugin_active' => defined('BP_REQUIRED_PHP_VERSION') && function_exists('buddypress'),
            ],
            'pmpro'        => [
                'title'         => __('Paid Memberships Pro', 'fluent-crm'),
                'description'   => __('Run automations when a membership level is added, canceled or expires.', 'fluent-crm'),
                'logo'          => self::logo('pmpro.png'),
                'type'          => ['membership'],
                'tier'          => 'pro',
                'plugin_active' => defined('PMPRO_VERSION'),
            ],
            'wishlist'     => [
                'title'         => __('WishList Member', 'fluent-crm'),
                'description'   => __('Run automations when members join or leave a membership level.', 'fluent-crm'),
                'logo'          => self::logo('wishlist_member.png'),
                'type'          => ['membership'],
                'tier'          => 'pro',
                'plugin_active' => defined('WLM3_PLUGIN_VERSION'),
            ],
            'rcp'          => [
                'title'         => __('Kadence Memberships', 'fluent-crm'),
                'description'   => __('Run automations for Kadence Memberships (formerly Restrict Content Pro) when a membership is added, canceled or expires.', 'fluent-crm'),
                'logo'          => self::logo('rcp.png'),
                'type'          => ['membership'],
                'tier'          => 'pro',
                'plugin_active' => class_exists('\Restrict_Content_Pro'),
            ],
            'affiliatewp'  => [
                'title'         => __('AffiliateWP', 'fluent-crm'),
                'description'   => __('Segment affiliates by earnings and referrals, and run automations when an affiliate is approved.', 'fluent-crm'),
                'logo'          => self::logo('affiliatewp.svg'),
                'type'          => ['others'],
                'tier'          => 'pro',
                'plugin_active' => class_exists('\Affiliate_WP'),
            ],
            'elementor'    => [
                'title'         => __('Elementor Pro Forms', 'fluent-crm'),
                'description'   => __('Add Elementor Pro form submitters to FluentCRM with tags and lists.', 'fluent-crm'),
                'logo'          => '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32" fill="none"><g clip-path="url(#clip0_2296_595)"><path d="M16 0C7.16 0 0 7.16 0 16C0 24.84 7.16 32 16 32C24.84 32 32 24.84 32 16C32 7.16 24.84 0 16 0ZM11.2 24H8V8H11.2V24ZM24 24H14.4V20.8H24V24ZM24 17.6H14.4V14.4H24V17.6ZM24 11.2H14.4V8H24V11.2Z" fill="#ED01EE"/></g><defs><clipPath id="clip0_2296_595"><rect width="32" height="32" fill="white"/></clipPath></defs></svg>',
                'type'          => ['page_builder'],
                'tier'          => 'pro',
                'plugin_active' => defined('ELEMENTOR_PRO_VERSION'),
            ],
            'beaverbuilder' => [
                'title'         => __('Beaver Builder', 'fluent-crm'),
                'description'   => __('Show or hide Beaver Builder content based on the visitor\'s FluentCRM tags and lists.', 'fluent-crm'),
                'logo'          => self::logo('beaver-builder.svg'),
                'type'          => ['page_builder'],
                'tier'          => 'pro',
                'plugin_active' => defined('FL_BUILDER_VERSION'),
            ],
        ];
    }
}
