<?php

namespace FluentCrm\App\Http\Controllers;

use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Http\Request\Request;
use FluentCrm\Framework\Support\Arr;

/**
 *  DocsController - REST API Handler Class
 *
 *  REST API Handler
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */
class DocsController extends Controller
{
    /**
     * The docs site is a static VitePress build: sitemap.xml lists every page and
     * "<slug>.json" returns that page as {slug, title, category, url, html, ...}.
     */
    private $docsHost = 'https://docs.fluentcrm.com/';

    private $indexCacheKey = 'fluentcrm_all_docs_v2';

    /**
     * Pages left out of the in-app library (the changelog alone is >200KB).
     */
    private $excludedSlugs = ['change-log'];

    public function index()
    {
        $docs = get_transient($this->indexCacheKey);

        if (!is_array($docs) || !$docs) {
            $docs = $this->fetchAllDocs();

            // Empty or failed fetches are not cached, so the next load retries.
            if ($docs) {
                set_transient($this->indexCacheKey, $docs, WEEK_IN_SECONDS);
            }
        }

        return [
            'docs' => $docs
        ];
    }

    /**
     * @param string $docId doc slug, e.g. "setting-up-campaign"
     */
    public function getDoc($docId)
    {
        $slug = sanitize_title($docId);
        $cacheKey = 'fluentcrm_doc_' . md5($slug);

        $doc = get_transient($cacheKey);

        if (!is_array($doc)) {
            $response = wp_remote_get($this->docsHost . rawurlencode($slug) . '.json', ['timeout' => 15]);
            $doc = $this->formatDoc(
                is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200
                    ? null
                    : json_decode(wp_remote_retrieve_body($response), true)
            );

            if (!$doc) {
                return [
                    'content'  => 'sorry, we could not fetch the doc at this moment. Please try again',
                    'is_error' => true
                ];
            }

            set_transient($cacheKey, $doc, WEEK_IN_SECONDS);
        }

        return $doc;
    }

    public function getAddons(Request $request)
    {
        $canAutoInstallToolkit = (bool) apply_filters('fluent_toolkit/can_auto_install', false);
        $toolkitPluginFile = 'fluent-toolkit/fluent-toolkit.php';
        $toolkitLoaded = defined('FLUENT_TOOLKIT_VERSION');
        $toolkitPluginExists = $this->isPluginInstalled($toolkitPluginFile);
        $toolkitActionText = __('Get FluentHub from GitHub', 'fluent-crm');

        if ($canAutoInstallToolkit) {
            $toolkitActionText = $toolkitPluginExists ? __('Activate FluentHub', 'fluent-crm') : __('Install FluentHub', 'fluent-crm');
        }

        $addOns = [
            'fluentform'     => [
                'title'          => __('Fluent Forms', 'fluent-crm'),
                'logo'           => fluentCrmMix('images/fluentform.png'),
                'is_installed'   => defined('FLUENTFORM'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluentform/',
                'settings_url'   => admin_url('admin.php?page=fluent_forms'),
                'action_text'    => $this->isPluginInstalled('fluent-form/fluent-form.php') ? __('Activate Fluent Forms', 'fluent-crm') : __('Install Fluent Forms', 'fluent-crm'),
                'description'    => __('Collect leads, build any type of form, accept payments, and connect to your CRM with the fastest contact form builder plugin for WordPress.', 'fluent-crm')
            ],
            'fluentsmtp'     => [
                'title'          => __('FluentSMTP', 'fluent-crm'),
                'logo'           => fluentCrmMix('images/fluent-smtp.svg'),
                'is_installed'   => defined('FLUENTMAIL'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluent-smtp/',
                'settings_url'   => admin_url('options-general.php?page=fluent-mail#/'),
                'action_text'    => $this->isPluginInstalled('fluent-smtp/fluent-smtp.php') ? __('Activate FluentSMTP', 'fluent-crm') : __('Install FluentSMTP', 'fluent-crm'),
                'description'    => __('The Ultimate SMTP and SES Plugin for WordPress. Connect with any SMTP, SendGrid, Mailgun, SES, Sendinblue, PepiPost, Google, Microsoft and more.', 'fluent-crm')
            ],
            'fluent-support' => [
                'title'          => __('Fluent Support', 'fluent-crm'),
                'logo'           => fluentCrmMix('images/fluent-support.svg'),
                'is_installed'   => defined('FLUENT_SUPPORT_VERSION'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluent-support/',
                'settings_url'   => admin_url('admin.php?page=fluent-support#/'),
                'action_text'    => $this->isPluginInstalled('fluent-support/fluent-support.php') ? __('Activate Fluent Support', 'fluent-crm') : __('Install Fluent Support', 'fluent-crm'),
                'description'    => __('WordPress Helpdesk and Customer Support Ticket Plugin. Provide awesome support and manage customer queries right from your WordPress dashboard.', 'fluent-crm')
            ],
            'fluent-cart' => [
                'title'          => __('FluentCart', 'fluent-crm'),
                'logo'           => fluentCrmMix('images/fluent-cart-dark.svg'),
                'is_installed'   => defined('FLUENTCART_VERSION'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluent-cart/',
                'settings_url'   => admin_url('admin.php?page=fluent-cart#/'),
                'action_text'    => $this->isPluginInstalled('fluent-cart/fluent-cart.php') ? __('Activate FluentCart', 'fluent-crm') : __('Install FluentCart', 'fluent-crm'),
                'description'    => __('WordPress eCommerce and Shopping Cart Plugin. Build an online store and manage products, orders, and customers right from your WordPress dashboard.', 'fluent-crm')
            ],
            'fluent-boards' => [
                'title'          => __('Fluent Boards', 'fluent-crm'),
                'logo'           => fluentCrmMix('images/fluent-boards.svg'),
                'is_installed'   => defined('FLUENT_BOARDS'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluent-boards/',
                'settings_url'   => admin_url('admin.php?page=fluent-boards#/'),
                'action_text'    => $this->isPluginInstalled('fluent-boards/fluent-boards.php') ? __('Activate Fluent Boards', 'fluent-crm') : __('Install Fluent Boards', 'fluent-crm'),
                'description'    => __('WordPress Project Management and Collaboration Plugin. Manage projects, tasks, and team collaboration right from your WordPress dashboard.', 'fluent-crm')
            ],
            'fluent-community' => [
                'title'          => __('Fluent Community', 'fluent-crm'),
                'logo'           => fluentCrmMix('images/fluent-community.svg'),
                'is_installed'   => defined('FLUENT_COMMUNITY_PLUGIN_VERSION'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluent-community/',
                'settings_url'   => admin_url('admin.php?page=fluent-community#/'),
                'action_text'    => $this->isPluginInstalled('fluent-community/fluent-community.php') ? __('Activate Fluent Community', 'fluent-crm') : __('Install Fluent Community', 'fluent-crm'),
                'description'    => __('WordPress Forum and Community Plugin. Build a thriving online community and discussion forum right from your WordPress dashboard.', 'fluent-crm')
            ],
            'fluent-booking' => [
                'title'          => __('Fluent Booking', 'fluent-crm'),
                'logo'           => fluentCrmMix('images/fluent-booking.svg'),
                'is_installed'   => defined('FLUENT_BOOKING_VERSION'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluent-booking/',
                'settings_url'   => admin_url('admin.php?page=fluent-booking#/'),
                'action_text'    => $this->isPluginInstalled('fluent-booking/fluent-booking.php') ? __('Activate Fluent Booking', 'fluent-crm') : __('Install Fluent Booking', 'fluent-crm'),
                'description'    => __('WordPress Appointment Booking Plugin. Manage appointments, bookings, and customer scheduling right from your WordPress dashboard.', 'fluent-crm')
            ],
            'fluent-toolkit' => [
                'title'          => __('FluentHub', 'fluent-crm'),
                'logo'           => fluentCrmMix('images/fluent-toolkit.svg'),
                'is_installed'   => $toolkitLoaded,
                'learn_more_url' => 'https://github.com/WPManageNinja/fluent-toolkit',
                'settings_url'   => admin_url('admin.php?page=fluent-toolkit'),
                'action_text'    => $toolkitActionText,
                'install_route'  => $canAutoInstallToolkit ? 'mcp/install-adapter' : '',
                'install_url'    => $canAutoInstallToolkit ? '' : 'https://github.com/WPManageNinja/fluent-toolkit',
                'description'    => __('FluentCRM ships AI agent tools, but they only become available once FluentHub is installed and active.', 'fluent-crm')
            ]
        ];

        $data = [
            'addons' => $addOns
        ];

        if (in_array('experimental_features', $request->get('with', []))) {
            $data['experimental_features'] = Helper::getExperimentalSettings();
        }

        return $data;
    }

    private function isPluginInstalled($plugin)
    {
        return file_exists(WP_PLUGIN_DIR . '/' . $plugin);
    }

    /**
     * Fetch every page listed in the docs sitemap, in parallel.
     *
     * @return array formatted docs, empty if the sitemap is unreachable
     */
    private function fetchAllDocs()
    {
        $sitemap = wp_remote_get($this->docsHost . 'sitemap.xml', ['timeout' => 15]);

        if (is_wp_error($sitemap) || wp_remote_retrieve_response_code($sitemap) !== 200) {
            return [];
        }

        preg_match_all('#<loc>' . preg_quote($this->docsHost, '#') . '([a-z0-9-]+)</loc>#', wp_remote_retrieve_body($sitemap), $matches);

        $requests = [];
        foreach (array_diff(array_unique($matches[1]), $this->excludedSlugs) as $slug) {
            $requests[$slug] = ['url' => $this->docsHost . $slug . '.json'];
        }

        if (!$requests) {
            return [];
        }

        $requestsClass = class_exists('\WpOrg\Requests\Requests') ? '\WpOrg\Requests\Requests' : '\Requests';
        $responses = $requestsClass::request_multiple($requests, ['timeout' => 20]);

        $docs = [];
        foreach ($responses as $response) {
            // Failed requests come back as exception objects without a body.
            if (empty($response->success) || $response->status_code !== 200) {
                continue;
            }

            $doc = $this->formatDoc(json_decode($response->body, true));
            if ($doc) {
                $docs[] = $doc;
            }
        }

        return $docs;
    }

    /**
     * Normalize one docs-site page into the shape the admin UI reads.
     *
     * @param mixed $raw decoded "<slug>.json" payload
     * @return array|null null when the payload is not a doc
     */
    private function formatDoc($raw)
    {
        if (!is_array($raw) || empty($raw['slug']) || empty($raw['title']) || !isset($raw['html'])) {
            return null;
        }

        $link = esc_url(Arr::get($raw, 'url', $this->docsHost . $raw['slug']));
        $category = sanitize_title(Arr::get($raw, 'category', 'other'));

        // In-page "#anchor" links would otherwise change the admin's hash route.
        $html = preg_replace('/href="#/', 'href="' . esc_url($link) . '#', $raw['html']);

        return [
            'id'       => sanitize_title($raw['slug']),
            'title'    => sanitize_text_field($raw['title']),
            'content'  => links_add_target(Helper::sanitizeHtml($html)),
            'link'     => $link,
            'category' => [
                'value' => $category,
                'label' => $this->categoryLabel($category)
            ]
        ];
    }

    private function categoryLabel($category)
    {
        $labels = [
            'devloper'            => __('Developer', 'fluent-crm'),
            'sms-module'          => __('SMS Module', 'fluent-crm'),
            'whatsapp-module'     => __('WhatsApp Module', 'fluent-crm'),
            'fluentcrm-essentials' => __('FluentCRM Essentials', 'fluent-crm'),
        ];

        return $labels[$category] ?? ucwords(str_replace('-', ' ', $category));
    }

}
