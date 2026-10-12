<?php

namespace FluentCrm\App\Http\Controllers;

use FluentCrm\App\Hooks\Handlers\ActivationHandler;
use FluentCrm\App\Models\ActivityLog;
use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\SystemLog;
use FluentCrm\App\Services\AutoSubscribe;
use FluentCrm\App\Services\DbPerformanceService;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Libs\Mailer\DoubleOptinRenderer;
use FluentCrm\App\Services\RoleBasedTagging;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Http\Request\Request;

/**
 *  SettingsController - REST API Handler Class
 *
 *  REST API Handler
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */
class SettingsController extends Controller
{
    /**
     * Maximum campaigns returned to the Campaign Archive selector; a caller may ask for fewer.
     */
    const MAX_SELECTOR_CAMPAIGNS = 200;

    public function get(Request $request)
    {
        $existingSettings = get_option(FLUENTCRM . '-global-settings');

        $keys = $request->get('settings_keys', []);

        $returnSettings = [];
        foreach ($keys as $key) {
            if ($key == 'email_settings') {
                $returnSettings[$key] = Helper::getGlobalEmailSettings();
            } else if (isset($existingSettings[$key])) {

                if ($key == 'business_settings') {
                    $existingSettings[$key] = fluentcrmGetGlobalSettings('business_settings');
                }

                $returnSettings[$key] = $existingSettings[$key];
            }
        }

        return $returnSettings;
    }

    public function save(Request $request)
    {
        $settings = (array)$request->get('settings', []);

        $existingSettings = get_option('fluentcrm-global-settings');

        if (!$existingSettings) {
            $existingSettings = [];
        }

        foreach ($settings as $settingsKey => $setting) {
            $existingSettings[$settingsKey] = $setting;

            if ($settingsKey == 'email_settings') {
                $emailFooter = Arr::get($setting, 'email_footer');
                if (!Helper::hasComplianceText($emailFooter)) {
                    return $this->sendError([
                        'message' => __('##crm.manage_subscription_url## or ##crm.unsubscribe_url## is required for compliance. Please include an unsubscribe or manage subscription link.', 'fluent-crm')
                    ]);
                }
            }
        }

        update_option( 'fluentcrm-global-settings', $existingSettings );

        return $this->sendSuccess([
            'message' => __('Settings Updated', 'fluent-crm')
        ]);
    }

    public function getDoubleOptinSettings(Request $request)
    {
        $listId = $request->get('list_id', null);
        $doubleOptinSettings = null;
        if ($listId) {
            $meta = fluentcrm_get_list_meta($listId, 'double_optin_settings');
            $doubleOptinSettings = $meta ? $meta->value : null;
        }

        // Fall back to the global settings when the list has none.
        if (!$doubleOptinSettings) {
            $doubleOptinSettings = Helper::getDoubleOptinSettings();
            if (empty($doubleOptinSettings['tag_based_redirect'])) {
                $doubleOptinSettings['tag_based_redirect'] = 'no';
                $doubleOptinSettings['tag_redirects'] = [
                    [
                        'field_key'   => [],
                        'field_value' => ''
                    ]
                ];
            }
        }

        $data = [
            'settings' => $doubleOptinSettings
        ];

        if ($listId) {
            $globalDoubleOptin = fluentcrm_get_list_meta($listId, 'global_double_optin');
            $data['global_double_optin'] = $globalDoubleOptin ? $globalDoubleOptin->value : 'yes';
        }

        if (in_array('settings_fields', $request->get('with', []))) {

            $designTemplates = Helper::getEmailDesignTemplates();

            $designTemplates = Arr::only($designTemplates, ['simple', 'plain', 'classic', 'raw_classic']);

            $data['settings_fields'] = [
                'design_template'          => [
                    'type'    => 'image-radio',
                    'label'   => __('Design Template', 'fluent-crm'),
                    'help'    => __('Email design template for the double opt-in email', 'fluent-crm'),
                    'options' => $designTemplates
                ],
                'email_subject'            => [
                    'type'        => 'input-text-popper',
                    'placeholder' => __('Opt-in Email Subject', 'fluent-crm'),
                    'label'       => __('Email Subject', 'fluent-crm'),
                    'help'        => __('Your double opt-in email subject', 'fluent-crm')
                ],
                'email_pre_header'         => [
                    'type'        => 'input-text-popper',
                    'placeholder' => __('Opt-in email preview text', 'fluent-crm'),
                    'label'       => __('Preview text', 'fluent-crm'),
                    'help'        => __('Preview text for your double opt-in email', 'fluent-crm')
                ],
                'email_body'               => [
                    'type'        => 'wp-editor',
                    'placeholder' => __('Double opt-in email body', 'fluent-crm'),
                    'label'       => __('Email body', 'fluent-crm'),
                    'help'        => __('Email body for the double opt-in email', 'fluent-crm'),
                    'inline_help' => __('Use #activate_link# for a plain URL or {{crm.activate_button|Confirm Subscription}} for the default button', 'fluent-crm'),
                    // Sent to the contact's own inbox, so the picker offers access-token smartcodes too.
                    'smartcode_level' => 'credential'
                ],
                'confirmation_html_viewer' => [
                    'type'    => 'html-viewer',
                    'heading' => __('After Confirmation Actions', 'fluent-crm'),
                    'info'    => __('Choose what happens after a contact confirms the double opt-in email', 'fluent-crm')
                ],
                'after_confirmation_type'  => [
                    'type'          => 'input-radio',
                    'label'         => __('After Confirmation Type', 'fluent-crm'),
                    'help'          => __('Select what happens after a contact confirms double opt-in', 'fluent-crm'),
                    'wrapper_class' => 'fcrm_radio_card_group',
                    'options'       => [
                        [
                            'id'          => 'message',
                            'label'       => __('Show a message', 'fluent-crm'),
                            'description' => __('A simple thank-you page hosted by your site. Good default.', 'fluent-crm')
                        ],
                        [
                            'id'          => 'redirect',
                            'label'       => __('Redirect to a Page', 'fluent-crm'),
                            'description' => __('Send people to your own thank-you page, a download or an offer.', 'fluent-crm')
                        ]
                    ]
                ],
                'after_confirm_message'    => [
                    'type'        => 'wp-editor',
                    'placeholder' => __('After Confirmation Message', 'fluent-crm'),
                    'label'       => __('After Confirmation Message', 'fluent-crm'),
                    'help'        => __('This message is shown after a contact confirms their subscription', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'after_confirmation_type',
                        'operator'   => '=',
                        'value'      => 'message'
                    ]
                ],
                'after_conf_redirect_url'  => [
                    'type'        => 'input-text-popper',
                    'placeholder' => __('Redirect URL', 'fluent-crm'),
                    'label'       => __('Redirect URL', 'fluent-crm'),
                    'help'        => __('The URL to redirect to after confirmation', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'after_confirmation_type',
                        'operator'   => '=',
                        'value'      => 'redirect'
                    ]
                ],
                'tag_based_redirect'       => [
                    'type'           => 'inline-checkbox',
                    'checkbox_label' => (defined('FLUENTCAMPAIGN')) ? __('Enable tag-based double opt-in redirect', 'fluent-crm') : __('Enable tag-based double opt-in redirect (requires FluentCRM Pro)', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'disabled'       => !defined('FLUENTCAMPAIGN')
                ],
                'tag_redirects'            => [
                    'label'                 => __('Set redirect URLs based on tags. The contact is redirected to the provided URL if any selected tag matches.', 'fluent-crm'),
                    'type'                  => 'form-many-drop-down-mapper',
                    'local_label'           => __('Targeted Tags', 'fluent-crm'),
                    'local_placeholder'     => __('Select tags', 'fluent-crm'),
                    'remote_label'          => __('Redirect URL (after double opt-in confirmation)', 'fluent-crm'),
                    'field_option_selector' => [
                        'option_key'  => 'tags',
                        'is_multiple' => true
                    ],
                    'remote_field_type'     => 'input-text-popper',
                    'remote_field'          => [
                        'placeholder' => __('Redirect URL', 'fluent-crm')
                    ],
                    'help'                  => __('The contact is redirected to the URL of the first matching tag', 'fluent-crm'),
                    'dependency'            => [
                        'depends_on' => 'tag_based_redirect',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ],
                    'manage_serial'         => true
                ]
            ];
        }

        return $this->sendSuccess($data);
    }

    /**
     * Render unsaved double opt-in settings for the admin preview without sending anything.
     *
     * @param Request $request `settings` (unsaved form values), `target` (email|confirmation), optional `contact_id`.
     * @return \WP_REST_Response|array
     */
    public function previewDoubleOptin(Request $request)
    {
        if (!defined('FLUENTCRM_PREVIEWING_EMAIL')) {
            define('FLUENTCRM_PREVIEWING_EMAIL', true);
        }

        $settings = (array)wp_unslash($request->get('settings', []));
        $contactId = absint($request->get('contact_id'));
        $subscriber = $contactId ? Subscriber::find($contactId) : fluentcrm_get_current_contact();

        if (!$subscriber) {
            $subscriber = Subscriber::where('status', 'subscribed')->first();
        }

        if (!$subscriber) {
            return $this->sendError([
                'message' => __('A contact is required to render the preview.', 'fluent-crm')
            ]);
        }

        if ($request->getSafe('target', 'sanitize_text_field') !== 'confirmation') {
            $renderedEmail = DoubleOptinRenderer::render($settings, $subscriber, true);

            return [
                'preview_html'    => $renderedEmail['body'],
                'preview_subject' => $renderedEmail['subject'],
                'subjects'        => [['id' => 0, 'key' => 100, 'value' => $renderedEmail['subject']]],
                'sender'          => Helper::getPreviewSender()
            ];
        }

        $message = Arr::get($settings, 'after_confirm_message', '');

        // Same rule as saving: the public message is filtered unless the user has unfiltered_html.
        if (!current_user_can('unfiltered_html')) {
            $message = wp_kses_post($message);
        }

        $message = apply_filters('fluent_crm/parse_campaign_email_text', $message, $subscriber);
        $message = apply_filters('fluent_crm/parse_extended_crm_text', $message, $subscriber);

        // Mirror the public confirmation page: same markup and stylesheet, minus wp_head()/wp_footer().
        // Block themes set the page font through global styles, which wp_head() would normally print.
        $themeStyles = '';
        if (wp_is_block_theme() && function_exists('wp_print_font_faces') && function_exists('wp_get_global_stylesheet')) {
            ob_start();
            wp_print_font_faces();
            $themeStyles = ob_get_clean() . '<style>' . wp_get_global_stylesheet() . '</style>';
        }

        $confirmationPage = $themeStyles
            . '<link rel="stylesheet" href="' . esc_url(fluentCrmMix('public/public_pref.css')) . '">'
            . fluentCrm('view')->make('external.confirmation_content', [
                'body'     => $message,
                'business' => fluentcrmGetGlobalSettings('business_settings', [])
            ]);

        return ['confirmation_html' => $confirmationPage];
    }

    public function saveDoubleOptinSettings(Request $request)
    {
        $settings = wp_unslash($request->get('settings'));
        $listId = $request->get('list_id');
        $globalDoubleOptin = sanitize_text_field($request->get('global_double_optin'));

        $this->validate($settings, [
            'email_subject'         => 'required',
            'email_body'            => 'required',
            'after_confirm_message' => 'required'
        ], [
            'email_subject.required'         => __('Email Subject is required', 'fluent-crm'),
            'email_body.required'            => __('Email body is required', 'fluent-crm'),
            'after_confirm_message.required' => __('After Confirmation Message is required', 'fluent-crm')
        ]);

        $emailBody = $settings['email_body'];

        if (
            strpos($emailBody, '#activate_link#') === false &&
            strpos($emailBody, '{{crm.activate_button') === false
        ) {
            return $this->sendError([
                'message' => __('Email body must contain the activation link', 'fluent-crm')
            ]);
        }

        // The confirmation message is public; filter it unless the user has unfiltered_html.
        if (!current_user_can('unfiltered_html')) {
            $settings['after_confirm_message'] = wp_kses_post($settings['after_confirm_message']);
        }

        if ($listId) {
            fluentcrm_update_list_meta($listId, 'double_optin_settings', $settings);
            if ($globalDoubleOptin) {
                fluentcrm_update_list_meta($listId, 'global_double_optin', $globalDoubleOptin);
            }
        } else {
            fluentcrm_update_option('double_optin_settings', $settings);
        }

        return $this->sendSuccess([
                'message' => __('The double opt-in settings have been updated.', 'fluent-crm')
            ]
        );
    }

    public function TestRequestResolver(Request $request)
    {
        return [
            'message' => __('Valid', 'fluent-crm'),
            'params'  => $request->all()
        ];
    }

    public function resetDB(Request $request)
    {
        if (!current_user_can('manage_options')) {
            return $this->sendError([
                'message' => __('Sorry, you do not have admin permission to reset the database', 'fluent-crm')
            ]);
        }

        if (!defined('FLUENTCRM_IS_DEV_FEATURES') || !FLUENTCRM_IS_DEV_FEATURES) {
            return $this->sendError([
                'message' => __('Development mode is not enabled, so you cannot use this feature. Define "FLUENTCRM_IS_DEV_FEATURES" in your wp-config.php to enable it.', 'fluent-crm')
            ]);
        }

        $tables = [
            'fc_campaign_emails',
            'fc_campaigns',
            'fc_campaign_url_metrics',
            'fc_funnel_metrics',
            'fc_funnels',
            'fc_funnel_sequences',
            'fc_funnel_subscribers',
            'fc_lists',
            'fc_meta',
            'fc_subscriber_meta',
            'fc_subscriber_notes',
            'fc_subscriber_pivot',
            'fc_subscribers',
            'fc_tags',
            'fc_url_stores'
        ];

        if (defined('FLUENTCAMPAIGN_PLUGIN_URL')) {
            $tables[] = 'fc_sequence_tracker';
        }

        global $wpdb;
        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . $table);
        }
        (new ActivationHandler)->handle(false);

        if (defined('FLUENTCAMPAIGN_PLUGIN_URL')) {
            \FluentCampaign\App\Migration\Migrate::run(false);
        }

        $options = [
            '_fluentcrm_commerce_modules'
        ];

        foreach ($options as $option) {
            delete_option($option);
        }

        return [
            'message' => __('All FluentCRM database tables have been reset', 'fluent-crm'),
            'tables'  => $tables
        ];
    }

    public function getBounceConfigs()
    {
        $securityCode = fluentcrm_get_option('_fc_bounce_key');
        if (!$securityCode) {
            $securityCode = 'fcrm_' . substr(md5(wp_generate_uuid4()), 0, 14); // first 14 digit
            fluentcrm_update_option('_fc_bounce_key', $securityCode);
        }

        $restNamespace = FluentCrm()->config->get('app.rest_namespace');
        $bounceUrl = function ($provider) use ($restNamespace, $securityCode) {
            return rest_url($restNamespace . '/v2/public/bounce_handler/' . $provider . '/handle/' . $securityCode);
        };

        $bounceSettings = [
            'ses'          => [
                'label'       => __('Amazon SES', 'fluent-crm'),
                'webhook_url' => add_query_arg([
                    FLUENTCRM_EXTERNAL_URL_PARAM => 1,
                    'route'                      => 'bounce_handler',
                    'provider'                   => 'ses',
                    'verify_key'                 => $securityCode
                ], site_url('index.php')),
                'doc_url'     => 'https://fluentcrm.com/docs/bounce-handler-with-amazon-ses/',
                'input_title' => __('Amazon SES Bounce Handler URL', 'fluent-crm'),
                'input_info'  => __('Use this bounce handler URL in your Amazon SES + SNS settings', 'fluent-crm')
            ],
            'tosend'       => [
                'label'       => __('ToSend', 'fluent-crm'),
                'webhook_url' => $bounceUrl('tosend'),
                'doc_url'     => 'https://fluentcrm.com/docs/bounce-handling-with-tosend/',
                'input_title' => __('ToSend Bounce Handler Webhook URL', 'fluent-crm'),
                'input_info'  => __('Paste this URL into your ToSend webhook settings to enable bounce handling in FluentCRM. Select both the Bounce and Complaint events.', 'fluent-crm')
            ],
            'mailgun'      => [
                'label'       => __('Mailgun', 'fluent-crm'),
                'webhook_url' => $bounceUrl('mailgun'),
                'doc_url'     => 'https://fluentcrm.com/docs/bounce-handling-with-mailgun/',
                'input_title' => __('Mailgun Bounce Handler Webhook URL', 'fluent-crm'),
                'input_info'  => __('Paste this URL into your Mailgun webhook settings to enable bounce handling in FluentCRM', 'fluent-crm')
            ],
            'pepipost'     => [
                'label'       => __('PepiPost', 'fluent-crm'),
                'webhook_url' => $bounceUrl('pepipost'),
                'doc_url'     => 'https://fluentcrm.com/docs/bounce-handling-with-pepipost/',
                'input_title' => __('PepiPost Bounce Handler Webhook URL', 'fluent-crm'),
                'input_info'  => __('Paste this URL into your PepiPost webhook settings to enable bounce handling in FluentCRM', 'fluent-crm')
            ],
            'postmark'     => [
                'label'       => __('Postmark', 'fluent-crm'),
                'webhook_url' => $bounceUrl('postmark'),
                'doc_url'     => 'https://fluentcrm.com/docs/bounce-handling-with-postmark/',
                'input_title' => __('Postmark Bounce Handler Webhook URL', 'fluent-crm'),
                'input_info'  => __('Paste this URL into your Postmark webhook settings to enable bounce handling in FluentCRM', 'fluent-crm')
            ],
            'sendgrid'     => [
                'label'       => __('SendGrid', 'fluent-crm'),
                'webhook_url' => $bounceUrl('sendgrid'),
                'doc_url'     => 'https://fluentcrm.com/docs/bounce-handling-with-sendgrid/',
                'input_title' => __('SendGrid Bounce Handler Webhook URL', 'fluent-crm'),
                'input_info'  => __('Paste this URL into your SendGrid webhook settings to enable bounce handling in FluentCRM', 'fluent-crm')
            ],
            'sparkpost'    => [
                'label'       => __('SparkPost', 'fluent-crm'),
                'webhook_url' => $bounceUrl('sparkpost'),
                'doc_url'     => 'https://fluentcrm.com/docs/bounce-handling-with-sparkpost/',
                'input_title' => __('SparkPost Bounce Handler Webhook URL', 'fluent-crm'),
                'input_info'  => __('Paste this URL into your SparkPost webhook settings to enable bounce handling in FluentCRM', 'fluent-crm')
            ],
            'elasticemail' => [
                'label'       => __('Elastic Email', 'fluent-crm'),
                'webhook_url' => $bounceUrl('elasticemail'),
                'doc_url'     => 'https://fluentcrm.com/docs/bounce-handling-with-elastic-email/',
                'input_title' => __('Elastic Email Bounce Handler Webhook URL', 'fluent-crm'),
                'input_info'  => __('Paste this URL into your Elastic Email webhook settings to enable bounce handling in FluentCRM', 'fluent-crm')
            ],
            'postalserver' => [
                'label'       => __('Postal Server', 'fluent-crm'),
                'webhook_url' => $bounceUrl('postalserver'),
                'doc_url'     => 'https://fluentcrm.com/docs/bounce-handling-with-postal-server/',
                'input_title' => __('Postal Server Bounce Handler Webhook URL', 'fluent-crm'),
                'input_info'  => __('Paste this URL into your Postal Server webhook settings to enable bounce handling in FluentCRM. Select only the MessageBounced and MessageDeliveryFailed events.', 'fluent-crm')
            ],
            'smtp2go'      => [
                'label'       => __('SMTP2Go', 'fluent-crm'),
                'webhook_url' => $bounceUrl('smtp2go'),
                'doc_url'     => 'https://fluentcrm.com/docs/bounce-handling-with-smtp2go/',
                'input_title' => __('SMTP2Go Bounce Handler Webhook URL', 'fluent-crm'),
                'input_info'  => __('Paste this URL into your SMTP2Go webhook settings to enable bounce handling in FluentCRM', 'fluent-crm')
            ],
            'brevo'        => [
                'label'       => __('Brevo (formerly Sendinblue)', 'fluent-crm'),
                'webhook_url' => $bounceUrl('brevo'),
                'doc_url'     => 'https://fluentcrm.com/docs/bounce-handling-with-brevo/',
                'input_title' => __('Brevo Bounce Handler Webhook URL', 'fluent-crm'),
                'input_info'  => __('Paste this URL into your Brevo webhook settings to enable bounce handling in FluentCRM', 'fluent-crm')
            ],
        ];

        $data = [
            /**
             * Determine FluentCRM Bounce Handler settings.
             *
             * This filter allows modification of the bounce handler settings.
             *
             * @param array $bounceSettings The current bounce settings.
             * @param string $securityCode The security code for the bounce handler.
             * @since 2.5.95
             *
             */
            'bounce_settings' => apply_filters('fluent_crm/bounce_handlers', $bounceSettings, $securityCode)
        ];

        if (defined('FLUENTMAIL')) {
            $smtpSettings = get_option('fluentmail-settings', []);
            if (!$smtpSettings || !count($smtpSettings['connections'])) {
                $data['fluentsmtp_info'] = [
                    'configured' => false
                ];
            } else {
                $data['fluentsmtp_info'] = [
                    'configured'       => true,
                    'verified_senders' => array_keys($smtpSettings['mappings'])
                ];
            }
            $data['fluentsmtp_info']['config_url'] = admin_url('options-general.php?page=fluent-mail#/connections');
        } else {
            $data['fluentsmtp_info'] = false;
        }

        return $data;
    }

    public function getAutoSubscribeSettings(Request $request)
    {
        $autoSubscribeService = new AutoSubscribe();

        $roleBasedTaggingClass = new RoleBasedTagging();

        $data = [
            'registration_setting'        => $autoSubscribeService->getRegistrationSettings(),
            'comment_settings'            => $autoSubscribeService->getCommentSettings(),
            'user_syncing_settings'       => $autoSubscribeService->getUserSyncSettings(),
            'role_based_tagging_settings' => $roleBasedTaggingClass->getSettings(true),
            'date_time_settings'          => [
                'classic_date_time' => Arr::get(Helper::getExperimentalSettings(), 'classic_date_time', 'no')
            ]
        ];

        $with = $request->get('with', []);
        if (in_array('fields', $with)) {
            $data['registration_fields'] = $autoSubscribeService->getRegistrationFields();
            $data['comment_fields'] = $autoSubscribeService->getCommentFields();
            $data['user_syncing_fields'] = $autoSubscribeService->getUserSyncFields();
            $data['role_based_tagging_settings_fields'] = $roleBasedTaggingClass->getFields();
        }

        if (defined('WC_PLUGIN_FILE')) {
            $data['woo_checkout_fields'] = $autoSubscribeService->getWooCheckoutFields();
            $data['woo_checkout_settings'] = $autoSubscribeService->getWooCheckoutSettings();
        }

        if (defined('FLUENTCART_VERSION')) {
            $data['fluent_cart_checkout_fields'] = $autoSubscribeService->getFluentCartCheckoutFields();
            $data['fluent_cart_checkout_settings'] = $autoSubscribeService->getFluentCartCheckoutSettings();
        }

        return $data;
    }

    public function saveAutoSubscribeSettings(Request $request)
    {
        $registrationSettings = $request->get('registration_setting', []);
        $commentSettings = $request->get('comment_settings', []);
        $userSyncSettings = $request->get('user_syncing_settings', []);
        $dateTimeSettings = $request->get('date_time_settings', []);


        fluentcrm_update_option('user_registration_subscribe_settings', $registrationSettings);
        fluentcrm_update_option('comment_form_subscribe_settings', $commentSettings);
        fluentcrm_update_option('user_syncing_settings', $userSyncSettings);

        if (is_array($dateTimeSettings) && array_key_exists('classic_date_time', $dateTimeSettings)) {
            $experimentalSettings = Helper::getExperimentalSettings();
            $experimentalSettings['classic_date_time'] = sanitize_text_field($dateTimeSettings['classic_date_time']) === 'yes' ? 'yes' : 'no';
            update_option('_fluentcrm_experimental_settings', $experimentalSettings, 'yes');
        }

        if (defined('FLUENTCAMPAIGN_PLUGIN_VERSION')) {
            $roleBasedSettings = $request->get('role_based_tagging_settings', []);
            fluentcrm_update_option('role_based_tagging_settings', $roleBasedSettings);
        }

        if (defined('WC_PLUGIN_FILE') && defined('FLUENTCAMPAIGN_DIR_FILE')) {
            $wooCheckoutSettings = $request->get('woo_checkout_settings');
            fluentcrm_update_option('woo_checkout_form_subscribe_settings', $wooCheckoutSettings);
            fluentCrmSetCache('woo_checkout_form_subscribe_settings', $wooCheckoutSettings, 86400);
        }

        if (defined('FLUENTCART_VERSION')) {
            $fluentCartCheckoutSettings = (new AutoSubscribe())->sanitizeFluentCartCheckoutSettings(
                $request->get('fluent_cart_checkout_settings', [])
            );
            fluentcrm_update_option('fluent_cart_checkout_form_subscribe_settings', $fluentCartCheckoutSettings);
        }

        return [
            'message' => __('Settings have been updated', 'fluent-crm')
        ];
    }

    public function getCronStatus()
    {
        $events = [];

        $nextRun = Helper::getNextMinuteTaskTimeStamp();

        $events[] = (object)array(
            'hook'       => 'fluentcrm_scheduled_every_minute_tasks',
            'is_overdue' => (time() - $nextRun) > 30,
            'human_name' => __('Scheduled Email Sending Tasks', 'fluent-crm'),
            'next_run'   => human_time_diff($nextRun, time()),
            'interval'   => 60
        );

        // 'hook' is the row key in the UI and the hook its "Run Manually" button fires.
        $nextFiverMinutesRun = wp_next_scheduled('fluentcrm_scheduled_five_minute_tasks');
        $events[] = (object)array(
            'hook'       => 'fluentcrm_scheduled_five_minute_tasks',
            'is_overdue' => ($nextFiverMinutesRun - time()) < -60,
            'human_name' => __('Scheduled Email Processing', 'fluent-crm'),
            'next_run'   => human_time_diff($nextFiverMinutesRun, time()),
            'interval'   => 300
        );

        $nextHourlyRun = wp_next_scheduled('fluentcrm_scheduled_hourly_tasks');
        $events[] = (object)array(
            'hook'       => 'fluentcrm_scheduled_hourly_tasks',
            'is_overdue' => ($nextHourlyRun - time()) < -120,
            'human_name' => __('Scheduled Automation Tasks', 'fluent-crm'),
            'next_run'   => human_time_diff($nextHourlyRun, time()),
            'interval'   => 3600
        );


        return [
            'cron_events' => $events,
            'server'      => [
                'memory_limit'       => intval(fluentCrmGetMemoryLimit() / 1048576) . 'MB',
                'usage_percent'      => fluentCrmGetMemoryUsagePercentage(),
                'max_execution_time' => fluentCrmMaxRunTime() . ' seconds',
                'has_server_cron'    => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON
            ]
        ];
    }

    public function runCron(Request $request)
    {
        $hookName = $request->get('hook');
        $hookNames = [
            'fluentcrm_scheduled_every_minute_tasks' => __('Scheduled Email Sending', 'fluent-crm'),
            'fluentcrm_scheduled_hourly_tasks'       => __('Scheduled Automation Tasks', 'fluent-crm'),
            'fluentcrm_scheduled_five_minute_tasks'  => __('Scheduled Email Processing', 'fluent-crm')
        ];

        if (!isset($hookNames[$hookName])) {
            return $this->sendError([
                'message' => __('The provided hook name is not valid', 'fluent-crm')
            ]);
        }

        do_action($hookName);

        return [
            'message' => __('The selected cron event has been run.', 'fluent-crm')
        ];
    }

    /**
     * Return the health of the critical DB indexes. Cached unless ?fresh=1.
     *
     * @param Request $request
     * @return array
     */
    public function getDbIndexHealth(Request $request)
    {
        $fromDb = $request->getSafe('fresh', 'sanitize_text_field') === '1';

        return $this->sendSuccess([
            'indexes' => array_values(DbPerformanceService::getIndexHealth($fromDb))
        ]);
    }

    /**
     * Repair any missing or broken critical DB indexes, behind a transient lock.
     *
     * @param Request $request
     * @return array
     */
    public function repairDbIndexes(Request $request)
    {
        // Re-check the live DB, not the cache.
        if (!DbPerformanceService::hasBrokenIndex(true)) {
            return $this->sendSuccess([
                'message' => __('All database indexes are healthy.', 'fluent-crm'),
                'indexes' => array_values(DbPerformanceService::getIndexHealth(false))
            ]);
        }

        $lockKey = 'fc_db_index_repairing';
        if (get_transient($lockKey)) {
            // Another request is already repairing; report pending, not an error.
            return $this->sendSuccess([
                'pending' => true,
                'message' => __('A database index repair is already running.', 'fluent-crm'),
                'indexes' => array_values(DbPerformanceService::getIndexHealth(false))
            ]);
        }

        set_transient($lockKey, 1, 5 * MINUTE_IN_SECONDS);

        try {
            $result = DbPerformanceService::repairBrokenIndexes();
        } finally {
            delete_transient($lockKey);
        }

        if ($result['failed']) {
            $reasons = array_unique(array_values($result['errors']));

            return $this->sendError([
                'message' => $reasons ? implode(' ', $reasons) : __('Some database indexes could not be repaired. Please contact your host.', 'fluent-crm'),
                'failed'  => $result['failed'],
                'errors'  => $result['errors'],
                'indexes' => array_values($result['health'])
            ], 422);
        }

        return $this->sendSuccess([
            'message'  => __('The database indexes have been repaired.', 'fluent-crm'),
            'repaired' => $result['repaired'],
            'indexes'  => array_values($result['health'])
        ]);
    }

    public function getOldLogDetails(Request $request)
    {
        $data = $request->all();
        $this->validate($data, [
            'days_before'   => 'required|numeric|min:7',
            'selected_logs' => 'array|required'
        ]);

        $selectedLogs = $data['selected_logs'];
        $daysBefore = $data['days_before'];

        // created_at is site-local (current_time()), so the cutoff is site-local too.
        $refDate = gmdate('Y-m-d 00:00:01', current_time('timestamp') - $daysBefore * 86400);

        $dataCounters = [];
        if (in_array('emails', $selectedLogs)) {
            $dataCounters[] = [
                'title' => __('Email History Logs', 'fluent-crm'),
                'count' => CampaignEmail::where('created_at', '<', $refDate)
                    ->whereIn('status', CampaignEmail::SENT_STATUSES)
                    ->count()
            ];
        }

        if (in_array('email_clicks', $selectedLogs)) {
            $dataCounters[] = [
                'title' => __('Email Clicks', 'fluent-crm'),
                'count' => CampaignUrlMetric::where('type', 'click')
                    ->where('created_at', '<', $refDate)
                    ->count()
            ];
        }

        if (in_array('email_open', $selectedLogs)) {
            $dataCounters[] = [
                'title' => __('Email Opens', 'fluent-crm'),
                'count' => CampaignUrlMetric::where('type', 'open')
                    ->where('created_at', '<', $refDate)
                    ->count()
            ];
        }

        if (in_array('system_logs', $selectedLogs)) {
            $dataCounters[] = [
                'title' => __('System Logs', 'fluent-crm'),
                'count' => SystemLog::where('created_at', '<', $refDate)
                    ->count()
            ];
        }

        if (in_array('activity_logs', $selectedLogs)) {
            $dataCounters[] = [
                'title' => __('Activity Logs', 'fluent-crm'),
                'count' => ActivityLog::where('created_at', '<', $refDate)
                    ->count()
            ];
        }

        return [
            'log_counts' => $dataCounters
        ];
    }

    public function removeOldLogs(Request $request)
    {
        $data = $request->all();
        $this->validate($data, [
            'days_before'   => 'required|numeric|min:7',
            'selected_logs' => 'array|required'
        ]);

        $selectedLogs = $data['selected_logs'];
        $daysBefore = $data['days_before'];

        $perChunk = 10000;
        $hasMore = false;

        // Same site-local cutoff as the preview in getOldLogDetails().
        $refDate = gmdate('Y-m-d 00:00:01', current_time('timestamp') - $daysBefore * 86400);
        if (in_array('emails', $selectedLogs)) {

            $campaignIds = CampaignEmail::where('created_at', '<', $refDate)
                ->whereIn('status', CampaignEmail::SENT_STATUSES)
                ->groupBy('campaign_id')
                ->pluck('campaign_id');

            foreach ($campaignIds->toArray() as $campaignId) {
                fluentcrm_update_campaign_meta($campaignId, '_data_trunked', 'yes');
            }

            CampaignEmail::where('created_at', '<', $refDate)
                ->whereIn('status', CampaignEmail::SENT_STATUSES)
                ->limit($perChunk)
                ->delete();

            $hasMore = CampaignEmail::where('created_at', '<', $refDate)
                ->whereIn('status', CampaignEmail::SENT_STATUSES)
                ->exists();
        }

        $urlMetricsTypes = [];
        if (in_array('email_clicks', $selectedLogs)) {
            $urlMetricsTypes[] = 'click';
        }
        if (in_array('email_open', $selectedLogs)) {
            $urlMetricsTypes[] = 'open';
        }

        if ($urlMetricsTypes) {
            CampaignUrlMetric::whereIn('type', $urlMetricsTypes)
                ->where('created_at', '<', $refDate)
                ->limit($perChunk)
                ->delete();

            if (!$hasMore) {
                $hasMore = CampaignUrlMetric::whereIn('type', $urlMetricsTypes)
                    ->where('created_at', '<', $refDate)
                    ->exists();
            }

        }

        if (in_array('system_logs', $selectedLogs)) {
            SystemLog::where('created_at', '<', $refDate)
                ->limit($perChunk)
                ->delete();

            if (!$hasMore) {
                $hasMore = SystemLog::where('created_at', '<', $refDate)
                    ->exists();
            }

        }

        if (in_array('activity_logs', $selectedLogs)) {
            ActivityLog::where('created_at', '<', $refDate)
                ->limit($perChunk)
                ->delete();

            if (!$hasMore) {
                $hasMore = ActivityLog::where('created_at', '<', $refDate)
                    ->exists();
            }

        }

        return [
            /* translators: %d is the number of days; used to indicate how old the deleted logs were. */
            'message'  => sprintf(__('Logs older than %d days have been deleted.', 'fluent-crm'), $daysBefore),
            'has_more' => $hasMore
        ];
    }

    public function deleteRestKey(Request $request)
    {
        $data = $request->all();
        $this->validate($data, [
            'user_id' => 'required',
            'uuid'    => 'required'
        ]);

        $data['user_id'] = intval($data['user_id']);
        $data['uuid'] = sanitize_text_field($data['uuid']);

        if (!get_user_meta($data['user_id'], '_fcrm_has_role', true)) {
            return $this->sendError([
                'message' => __('Sorry, the provided user does not have FluentCRM access', 'fluent-crm')
            ]);
        }

        if (!current_user_can('manage_options')) {
            return $this->sendError([
                'message' => __('Sorry, you do not have permission to delete REST API keys', 'fluent-crm')
            ]);
        }

        $deleted = \WP_Application_Passwords::delete_application_password($data['user_id'], $data['uuid']);

        if ($deleted) {
            $applicationUsers = fluentcrm_get_option('_rest_api_users', []);

            foreach ($applicationUsers[$data['user_id']] as $index => $uuid) {
                if ($uuid == $data['uuid']) {
                    array_splice($applicationUsers[$data['user_id']], $index, 1);
                }
            }

            fluentcrm_update_option('_rest_api_users', $applicationUsers);
        }

        if (!$deleted) {
            return $this->sendError([
                'message' => __('The API key could not be deleted. Please try again.', 'fluent-crm')
            ]);
        }

        return [
            'message' => __('The API key has been deleted.', 'fluent-crm')
        ];
    }

    public function getRestKeys(Request $request)
    {
        $query = new \WP_User_Query(array(
            'meta_key'     => '_fcrm_has_role',
            'meta_value'   => 1,
            'meta_compare' => '=',
            'number'       => 200
        ));

        $managers = [];

        foreach ($query->get_results() as $user) {
            if (user_can($user, 'manage_options')) {
                continue;
            }

            $managers[] = [
                'id'        => $user->ID,
                'full_name' => $user->first_name . ' - ' . $user->last_name,
                'email'     => $user->user_email
            ];
        }

        $applicationUsers = fluentcrm_get_option('_rest_api_users', []);
        $restApps = [];

        if ($applicationUsers) {
            $userIds = array_keys($applicationUsers);
            $restUsers = get_users([
                'include' => $userIds,
                'number'  => 20
            ]);

            foreach ($restUsers as $restUser) {
                $applicationUUIDs = $applicationUsers[$restUser->ID];
                $passwords = get_user_meta($restUser->ID, '_application_passwords', true);

                $crmApps = [];

                foreach ($passwords as $password) {
                    if (in_array($password['uuid'], $applicationUUIDs)) {
                        $crmApps[] = [
                            'uuid'    => $password['uuid'],
                            'name'    => $password['name'],
                            'created' => gmdate('Y-m-d H:i:s', $password['created'])
                        ];
                    }
                }

                if (!$crmApps) {
                    continue;
                }

                $restApps[] = [
                    'id'         => $restUser->ID,
                    'first_name' => $restUser->first_name,
                    'last_name'  => $restUser->last_name,
                    'email'      => $restUser->user_email,
                    'api_keys'   => $crmApps,
                    'manage_url' => admin_url('user-edit.php?user_id=' . $restUser->ID . '#application-passwords-section')
                ];
            }
        }


        return [
            'managers'  => $managers,
            'rest_keys' => $restApps
        ];
    }

    public function createRestKey(Request $request)
    {
        $data = $request->all();
        $this->validate($data, [
            'api_name'    => 'required',
            'api_user_id' => 'required'
        ]);

        $data['api_user_id'] = intval($data['api_user_id']);
        $data['api_name'] = sanitize_text_field($data['api_name']);

        if (!get_user_meta($data['api_user_id'], '_fcrm_has_role', true)) {
            return $this->sendError([
                'message' => __('Sorry, the provided user does not have FluentCRM access', 'fluent-crm')
            ]);
        }

        if (!current_user_can('manage_options')) {
            return $this->sendError([
                'message' => __('Sorry, you do not have permission to create REST API keys', 'fluent-crm')
            ]);
        }

        $user = get_user_by('ID', $data['api_user_id']);

        if (is_wp_error($user)) {
            return $this->sendError([
                'message' => __('Sorry, the provided user does not have FluentCRM access', 'fluent-crm')
            ]);
        }

        $prepared = (object)[
            'name' => $data['api_name']
        ];

        $created = \WP_Application_Passwords::create_new_application_password($user->ID, wp_slash((array)$prepared));

        if (is_wp_error($created)) {
            return $this->sendError([
                'message' => $created->get_error_message()
            ]);
        }


        $password = $created[0];
        $item = \WP_Application_Passwords::get_user_application_password($user->ID, $created[1]['uuid']);

        $item['info'] = [
            'api_password' => \WP_Application_Passwords::chunk_password($password),
            'api_username' => $user->user_login,
        ];

        $uuid = $item['uuid'];

        $applicationUsers = fluentcrm_get_option('_rest_api_users', []);

        if (!isset($applicationUsers[$user->ID])) {
            $applicationUsers[$user->ID] = [];
        }
        $applicationUsers[$user->ID][] = $uuid;

        fluentcrm_update_option('_rest_api_users', $applicationUsers);

        return [
            'item'    => $item,
            'message' => __('The API key has been created.', 'fluent-crm')
        ];
    }

    public function getIntegrations(Request $request)
    {
        $withFields = in_array('fields', $request->get('with', []));

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
        $deepIntegrationProviders = apply_filters('fluentcrm_deep_integration_providers', [], $withFields);

        return [
            'integrations' => $deepIntegrationProviders
        ];
    }

    public function saveIntegration(Request $request)
    {
        $provider = $request->get('provider');
        $action = $request->get('action');
        $data = $request->all();

        if ($action == 'sync') {
            /**
             * Determine whether to allow deep integration sync for a specific provider.
             *
             * This filter allows you to modify the result of the deep integration sync for a given provider.
             *
             * @param mixed  The result of the integration sync. Default false. Expected to be a boolean.
             * @param array $data The data to be synced.
             * @since 2.5.1
             *
             */
            $result = apply_filters('fluentcrm_deep_integration_sync_' . $provider, false, $data);
        } else {
            /**
             * Determine the result of saving deep integration settings for a specific provider.
             *
             * The dynamic portion of the hook name, `$provider`, refers to the specific integration provider.
             *
             * @param mixed  The result of the save operation. Default false. Expected to be a boolean.
             * @param array $data The data being saved.
             * @since 2.5.1
             *
             */
            $result = apply_filters('fluentcrm_deep_integration_save_' . $provider, false, $data);
        }

        if ($result) {
            if (is_wp_error($result)) {
                return $this->sendError([
                    'message' => $result->get_error_message()
                ]);
            }
            return $result;
        }

        return $this->sendError([
            'message' => __('Sorry, this provider does not exist', 'fluent-crm')
        ]);
    }

    public function getComplianceSettings(Request $request)
    {
        return [
            'settings' => Helper::getComplianceSettings()
        ];
    }

    public function updateComplianceSettings(Request $request)
    {
        $data = Arr::only($request->all(), array_keys(Helper::getComplianceSettings()));
        $validValues = [
            'yes',
            'no',
            'anonymous'
        ];

        foreach ($data as $key => $datum) {
            if (!in_array($datum, $validValues)) {
                $data[$key] = '';
            } else {
                $data[$key] = $datum;
            }
        }

        update_option('_fluentcrm_compliance_settings', $data, 'no');

        do_action('fluent_crm/sync_subscriber_delete_setting', 'compliance_settings', $data['delete_contact_on_user']);

        return [
            'message'  => __('The settings have been updated.', 'fluent-crm'),
            'settings' => $data
        ];
    }

    public function getExperimentalSettings(Request $request)
    {
        return [
            'settings' => Helper::getExperimentalSettings()
        ];
    }

    public function updateExperimentalSettings(Request $request)
    {

        $data = Arr::only($request->all(), array_keys(Helper::getExperimentalSettings()));

        foreach ($data as $key => $datum) {
            if ($key === 'campaign_ids' && is_array($datum)) {
                $data[$key] = array_map('intval', $datum);
            } elseif ($key === 'frontend_portal_page_id') {
                $data[$key] = absint($datum);
            } elseif ($key === 'frontend_portal_slug') {
                $data[$key] = sanitize_title($datum);
            } elseif ($key === 'frontend_portal_render_type') {
                $renderType = sanitize_text_field($datum);
                $data[$key] = in_array($renderType, ['standalone', 'shortcode'], true) ? $renderType : 'standalone';
            } else {
                $data[$key] = sanitize_text_field($datum);
            }
        }

        if (Arr::get($data, 'frontend_portal') === 'yes' && empty($data['frontend_portal_slug'])) {
            $data['frontend_portal_slug'] = 'fluentcrm';
        }

        if (Arr::get($data, 'company_module') == 'yes') {
            require_once(FLUENTCRM_PLUGIN_PATH . 'database/migrations/CompaniesMigrator.php');
            \FluentCrmMigrations\CompaniesMigrator::migrate();
        }

        if (Arr::get($data, 'event_tracking') == 'yes') {
            require_once(FLUENTCRM_PLUGIN_PATH . 'database/migrations/SubscriberEventTracking.php');
            \FluentCrmMigrations\SubscriberEventTracking::migrate();
        }

        if (Arr::get($data, 'activity_log') == 'yes') {
            require_once(FLUENTCRM_PLUGIN_PATH . 'database/migrations/ActivityLogsMigrator.php');
            \FluentCrmMigrations\ActivityLogsMigrator::migrate();
        }

        // Merge over the raw stored option so a partial request keeps the flags it did not send,
        // without persisting defaults or filtered values.
        $stored = get_option('_fluentcrm_experimental_settings', []);

        if (!$stored || !is_array($stored)) {
            $stored = [];
        }

        $data = array_merge($stored, $data);

        update_option('_fluentcrm_experimental_settings', $data, 'yes');

        /**
         * Fires after the experimental module switches have been saved.
         *
         * The migrations above cover core's own modules. Modules that live in an
         * add-on cannot be reached from here, so they hook this to create their
         * tables when their switch has just been turned on.
         *
         * @since 3.1.12
         *
         * @param array $data The full settings as saved, which for a partial
         *                    request includes the stored flags it did not carry.
         */
        do_action('fluent_crm/experimental_settings_saved', $data);

        return [
            'message' => __('Settings have been updated', 'fluent-crm')
        ];
    }

    /**
     * Newest-first campaign list for the Campaign Archive selector, capped at MAX_SELECTOR_CAMPAIGNS.
     * include_ids (also capped) are appended so saved selections always resolve to a title.
     */
    public function getCampaigns(Request $request)
    {
        $columns = ['id', 'title', 'email_subject', 'status'];
        $search = sanitize_text_field($request->get('searchBy', ''));
        $includeIds = array_slice(
            array_unique(array_filter(array_map('intval', (array) $request->get('include_ids', [])))),
            0,
            self::MAX_SELECTOR_CAMPAIGNS
        );

        $limit = intval($request->get('limit', 0));
        if ($limit <= 0 || $limit > self::MAX_SELECTOR_CAMPAIGNS) {
            $limit = self::MAX_SELECTOR_CAMPAIGNS;
        }

        $campaigns = Campaign::select($columns)
            ->when($search, function ($query) use ($search) {
                global $wpdb;
                return $query->where('title', 'LIKE', '%' . $wpdb->esc_like($search) . '%');
            })
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get();

        $missingIds = array_diff($includeIds, $campaigns->pluck('id')->toArray());
        if ($missingIds) {
            $campaigns = $campaigns->merge(
                Campaign::select($columns)->whereIn('id', $missingIds)->get()
            );
        }

        return [
            'campaigns' => $campaigns
        ];
    }

}
