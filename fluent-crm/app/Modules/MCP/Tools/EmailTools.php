<?php

namespace FluentCrm\App\Modules\MCP\Tools;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CustomEmailCampaign;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Modules\MCP\Helpers\MCPHelper;
use FluentCrm\App\Modules\MCP\Tools\ContextTools;
use FluentCrm\App\Services\BlockParser;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Libs\Mailer\Mailer;
use FluentCrm\App\Services\Libs\Parser\ShortcodeParser;
use FluentCrm\App\Services\PermissionManager;
use FluentCrm\App\Services\Sanitize;
use FluentCrm\Framework\Support\Arr;

/**
 * One-off email, test send and preview tools. Mirrors the contact-profile
 * "Send Custom Email" options except visual_builder and template_id.
 */
class EmailTools
{
    public static function sendEmailToContact($params)
    {
        $params = (array) $params;

        $resolved = MCPHelper::resolveContact($params);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $contact = $resolved;

        $subject = trim((string) ($params['subject'] ?? ''));
        $body    = (string) ($params['body'] ?? '');

        if ($subject === '' || $body === '') {
            return MCPHelper::error('invalid_param', 'subject and body are required');
        }

        // Same status gate as the controller. is_transactional does not bypass it.
        $allowedStatuses = ['subscribed', 'transactional'];
        if (!in_array($contact->status, $allowedStatuses, true)) {
            return MCPHelper::error('invalid_param', sprintf(
                "The contact's status is '%1\$s'. To send to this contact, the contact's own status must be one of: %2\$s. The is_transactional parameter controls the message type, not the contact gate.",
                $contact->status,
                implode(', ', $allowedStatuses)
            ), [
                'current_status'   => $contact->status,
                'allowed_statuses' => $allowedStatuses,
            ]);
        }

        $designTemplate = sanitize_key((string) ($params['design_template'] ?? 'classic'));
        if ($designTemplate === '') {
            $designTemplate = 'classic';
        }
        // Enforced server-side too: direct REST callers bypass the schema enum.
        $allowed = array_keys(ContextTools::allowedDesignTemplates());
        if (!in_array($designTemplate, $allowed, true)) {
            return MCPHelper::error('invalid_param', 'design_template not allowed via MCP', [
                'design_template' => $designTemplate,
                'allowed'         => $allowed,
            ]);
        }

        // Hard-error on invalid utm/settings before any write.
        $utmError = MCPHelper::validateUtm($params);
        if (is_wp_error($utmError)) {
            return $utmError;
        }
        $settingsCheck = MCPHelper::sanitizeSettingsShape($params['settings'] ?? null);
        if (is_wp_error($settingsCheck)) {
            return $settingsCheck;
        }

        $composed = self::composeSettings($params, $designTemplate);
        $settings = $composed['settings'];
        $isTransactional = $composed['applied']['is_transactional'];
        $disableFooter   = $composed['applied']['disable_footer'];

        $title = isset($params['title']) && $params['title'] !== ''
            ? sanitize_text_field((string) $params['title'])
            : Helper::oneOffEmailTitle($contact->email, 'MCP');

        $campaignData = [
            'title'            => $title,
            'email_subject'    => $subject,
            'email_pre_header' => sanitize_text_field((string) ($params['pre_header'] ?? $params['preheader'] ?? '')),
            'email_body'       => $body,
            'design_template'  => $designTemplate,
            'settings'         => $settings,
            // Matches SubscriberController::sendCustomEmail. Delivery state lives on the fc_campaign_emails row.
            'status'           => 'published',
        ];

        if (!empty($params['utm']) && is_array($params['utm'])) {
            $utm = $params['utm'];
            $campaignData['utm_status'] = !empty($utm['status']) ? 1 : 0;
            foreach (MCPHelper::utmAllowedKeys() as $key) {
                if ($key === 'status') {
                    continue;
                }
                if (isset($utm[$key])) {
                    $campaignData['utm_' . $key] = sanitize_text_field((string) $utm[$key]);
                }
            }
        }

        $campaignData = Sanitize::campaign($campaignData);

        // A client that times out and retries must not email the contact twice. The request
        // fingerprint rides in the one-off campaign's own settings, so the check needs no
        // extra storage (no transients piling up in wp_options without an object cache).
        $requestHash = self::sendRequestHash($contact, $params);
        $previous    = self::findRecentIdenticalSend($contact, $requestHash);
        if ($previous) {
            return [
                'ok'           => true,
                'campaign_id'  => (int) $previous->id,
                'message'      => 'An identical email to this contact was already queued; nothing was sent again.',
                'deduplicated' => true,
                'contact'      => [
                    'id'    => (int) $contact->id,
                    'email' => $contact->email,
                ],
                'applied'      => $composed['applied'] + [
                    'design_template' => $designTemplate,
                ],
            ];
        }
        $campaignData['settings']['mcp_request_hash'] = $requestHash;

        add_action('wp_mail_failed', function ($wpError) {
            if (method_exists(Helper::class, 'debugLog')) {
                Helper::debugLog('MCP send-email-to-contact failure', $wpError->get_error_message(), 'error');
            }
        }, 10, 1);

        $campaign = CustomEmailCampaign::create($campaignData);

        $campaign->subscribe([(int) $contact->id], [
            'status'       => 'scheduled',
            'scheduled_at' => current_time('mysql'),
        ]);

        do_action('fluentcrm_process_contact_jobs', $contact);

        return [
            'ok'          => true,
            'campaign_id' => (int) $campaign->id,
            'message'     => 'Email queued for delivery',
            'contact'     => [
                'id'    => (int) $contact->id,
                'email' => $contact->email,
            ],
            'applied'     => $composed['applied'] + [
                'design_template' => $designTemplate,
            ],
        ];
    }

    /**
     * How long an identical send-email-to-contact call returns the first result instead of
     * sending again.
     */
    public static function sendDedupeSeconds()
    {
        return max(60, (int) apply_filters('fluent_crm/mcp_send_dedupe_seconds', 10 * MINUTE_IN_SECONDS));
    }

    /**
     * Fingerprint of one send: the user, the resolved contact and every argument, so a change
     * to any of them (a corrected subject, another sender) is a new send.
     */
    private static function sendRequestHash($contact, array $params)
    {
        unset($params['contact_id'], $params['email']);
        ksort($params);

        return md5(wp_json_encode([get_current_user_id(), (int) $contact->id, $params]));
    }

    /**
     * The one-off campaign an identical call queued for this contact within the dedupe window,
     * if any. Starts from the contact's own email rows (indexed on subscriber_id), so it reads
     * a handful of rows however many campaigns the site has.
     *
     * @return CustomEmailCampaign|null
     */
    private static function findRecentIdenticalSend($contact, $requestHash)
    {
        $since = gmdate('Y-m-d H:i:s', current_time('timestamp') - self::sendDedupeSeconds());

        $campaignIds = CampaignEmail::where('subscriber_id', (int) $contact->id)
            ->where('created_at', '>=', $since)
            ->pluck('campaign_id')
            ->all();
        if (!$campaignIds) {
            return null;
        }

        $candidates = CustomEmailCampaign::whereIn('id', array_unique(array_map('intval', $campaignIds)))
            ->orderBy('id', 'DESC')
            ->get();
        foreach ($candidates as $candidate) {
            $settings = is_array($candidate->settings) ? $candidate->settings : [];
            if (($settings['mcp_request_hash'] ?? '') === $requestHash) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Compose the campaign settings block from tool params.
     * Shared by the live send, test send and preview so all render the same.
     *
     * @return array{settings: array, applied: array}
     */
    private static function composeSettings(array $params, $designTemplate)
    {
        $defaults = Helper::getGlobalEmailSettings();

        $isTransactional = self::yesNo($params['is_transactional'] ?? null, 'no');

        // Transactional mail disables the marketing footer unless disable_footer is passed.
        if (array_key_exists('disable_footer', $params)) {
            $disableFooter = self::yesNo($params['disable_footer'], 'no');
        } else {
            $disableFooter = $isTransactional === 'yes' ? 'yes' : 'no';
        }

        $clickTracker = self::trackerValue($params['click_tracker'] ?? null);
        $openTracker  = self::trackerValue($params['open_tracker'] ?? null);

        $fromName     = sanitize_text_field((string) ($params['from_name'] ?? $defaults['from_name']));
        $fromEmail    = sanitize_email((string) ($params['from_email'] ?? $defaults['from_email']));
        $replyToName  = sanitize_text_field((string) ($params['reply_to_name'] ?? ($defaults['reply_to_name'] ?? '')));
        $replyToEmail = sanitize_email((string) ($params['reply_to_email'] ?? ($defaults['reply_to_email'] ?? '')));

        $settings = [
            'mailer_settings'  => [
                'from_name'      => $fromName,
                'from_email'     => $fromEmail,
                'reply_to_name'  => $replyToName,
                'reply_to_email' => $replyToEmail,
                'is_custom'      => 'yes',
            ],
            'is_transactional' => $isTransactional,
            'footer_settings'  => [
                'disable_footer' => $disableFooter,
            ],
            'template_config'  => Helper::getTemplateConfig($designTemplate),
        ];
        if ($clickTracker !== null) {
            $settings['click_tracker'] = $clickTracker;
        }
        if ($openTracker !== null) {
            $settings['open_tracker'] = $openTracker;
        }

        // Only the sanitized `settings` passthrough is merged.
        if (!empty($params['settings']) && is_array($params['settings'])) {
            $extraSettings = MCPHelper::sanitizeSettingsShape($params['settings']);
            if (is_array($extraSettings)) {
                $settings = array_replace_recursive($settings, $extraSettings);
            }
        }

        return [
            'settings' => $settings,
            'applied'  => [
                'is_transactional' => $isTransactional,
                'disable_footer'   => $disableFooter,
                'from'             => self::formatAddress($fromName, $fromEmail),
                'reply_to'         => self::formatAddress($replyToName, $replyToEmail),
            ],
        ];
    }

    /**
     * Render a "Display Name <addr>" string.
     */
    private static function formatAddress($name, $email)
    {
        $email = trim((string) $email);
        $name  = trim((string) $name);
        if ($email === '') return '';
        if ($name === '')  return $email;
        return $name . ' <' . $email . '>';
    }

    /**
     * Shared render pipeline for send-test-email and render-email-preview.
     * Renders a saved campaign or inline draft; creates no records.
     * $requireRecipient errors on a missing or invalid to_email.
     *
     * @return array|\WP_Error
     */
    private static function buildRenderedEmail(array $params, $requireRecipient)
    {
        $extraSettings = MCPHelper::sanitizeSettingsShape($params['settings'] ?? null);
        if (is_wp_error($extraSettings)) {
            return $extraSettings;
        }

        // Recipient defaults to the current WP user.
        $toEmail = sanitize_email((string) ($params['to_email'] ?? ''));
        if (!$toEmail) {
            $user = wp_get_current_user();
            $toEmail = $user ? $user->user_email : '';
        }
        if ($requireRecipient && (!$toEmail || !is_email($toEmail))) {
            return MCPHelper::error('invalid_param', 'A valid to_email is required.');
        }
        $toEmail = sanitize_email($toEmail);

        $campaignId = isset($params['campaign_id']) ? (int) $params['campaign_id'] : 0;
        $subject = $body = $preHeader = '';
        $designTemplate = '';
        $settings = [];

        if ($campaignId) {
            // Bypass the type scope so any campaign type can be rendered.
            $campaign = Campaign::withoutGlobalScope('type')->find($campaignId);
            if (!$campaign) {
                return MCPHelper::error('not_found', 'Campaign not found', ['campaign_id' => $campaignId]);
            }
            $subject        = (string) $campaign->email_subject;
            $body           = (string) $campaign->email_body;
            $preHeader      = (string) $campaign->email_pre_header;
            $designTemplate = (string) $campaign->design_template;
            $settings       = is_array($campaign->settings) ? $campaign->settings : (array) maybe_unserialize($campaign->settings);
        }

        if (isset($params['subject']) && $params['subject'] !== '') {
            $subject = (string) $params['subject'];
        }
        if (isset($params['body']) && $params['body'] !== '') {
            $body = (string) $params['body'];
        }
        if (isset($params['pre_header'])) {
            $preHeader = (string) $params['pre_header'];
        }
        if (isset($params['design_template']) && $params['design_template'] !== '') {
            $designTemplate = sanitize_key((string) $params['design_template']);
        }
        if ($designTemplate === '') {
            $designTemplate = 'classic';
        }
        $allowedTemplates = array_keys(ContextTools::allowedDesignTemplates());
        if (!in_array($designTemplate, $allowedTemplates, true)) {
            return MCPHelper::error('invalid_param', 'design_template not allowed via MCP', [
                'design_template' => $designTemplate,
                'allowed'         => $allowedTemplates,
            ]);
        }

        if ($subject === '' || $body === '') {
            return MCPHelper::error('invalid_param', 'Provide either campaign_id, or subject + body.');
        }

        // Inline drafts get the full composed block; a saved campaign keeps its settings plus explicit overrides.
        if (!$campaignId) {
            $composed = self::composeSettings($params, $designTemplate);
            $settings = $composed['settings'];
        } else {
            $override = [];
            $mailerOverride = [];
            foreach (['from_name', 'from_email', 'reply_to_name', 'reply_to_email'] as $mailerKey) {
                if (isset($params[$mailerKey]) && $params[$mailerKey] !== '') {
                    $mailerOverride[$mailerKey] = strpos($mailerKey, 'email') !== false
                        ? sanitize_email((string) $params[$mailerKey])
                        : sanitize_text_field((string) $params[$mailerKey]);
                }
            }
            if ($mailerOverride) {
                $mailerOverride['is_custom'] = 'yes';
                $override['mailer_settings'] = $mailerOverride;
            }
            if (array_key_exists('is_transactional', $params)) {
                $override['is_transactional'] = self::yesNo($params['is_transactional'], 'no');
            }
            if (array_key_exists('disable_footer', $params)) {
                $override['footer_settings'] = [
                    'disable_footer' => self::yesNo($params['disable_footer'], 'no'),
                ];
            }
            // `settings` is merged last, matching composeSettings() precedence.
            if ($extraSettings) {
                $override = array_replace_recursive($override, $extraSettings);
            }
            if ($override) {
                $settings = array_replace_recursive($settings, $override);
            }
        }

        // SmartCode contact: against_contact_*, then to_email, then any subscribed contact.
        $subscriber = null;
        if (!empty($params['against_contact_id'])) {
            $subscriber = Subscriber::find((int) $params['against_contact_id']);
        }
        if (!$subscriber && !empty($params['against_contact_email'])) {
            $subscriber = Subscriber::where('email', sanitize_email($params['against_contact_email']))->first();
        }
        if (!$subscriber) {
            $subscriber = Subscriber::where('email', $toEmail)->first();
        }
        if (!$subscriber) {
            // The fallback renders an unrelated contact's PII, so it needs contact-read.
            if (!PermissionManager::currentUserCan('fcrm_read_contacts')) {
                return MCPHelper::error('forbidden', 'No contact matches to_email, and rendering against an unrelated contact requires contact read permission. Send the test to an address that exists as a contact, or ask an administrator for contact-read access.', [
                    'required' => 'fcrm_read_contacts',
                    'to_email' => $toEmail,
                ]);
            }
            $subscriber = Subscriber::where('status', 'subscribed')->first();
        }
        if (!$subscriber) {
            return MCPHelper::error('not_supported', 'No subscriber found to drive smartcode rendering. Add at least one subscribed contact.');
        }

        $rawTemplates = ['raw_html', 'raw_classic'];
        if (!in_array($designTemplate, $rawTemplates, true)) {
            $body = (new BlockParser($subscriber))->parse($body);
        }

        // Stand-in campaign object so unsaved drafts pass through Helper::getFooterConfig().
        $stub = (object) [
            'design_template' => $designTemplate,
            'settings'        => $settings ?: ['template_config' => []],
            'email_pre_header' => $preHeader,
            'email_body'      => $body,
            'email_subject'   => $subject,
        ];
        $footerConfig = method_exists(Helper::class, 'getFooterConfig') ? Helper::getFooterConfig($stub) : ['footer_content' => ''];
        $footerText   = Arr::get($footerConfig, 'footer_content', '');

        // A rendered or test email is not the contact's real email: footer links render inert, reset links as placeholders.
        if (!defined('FLUENTCRM_PREVIEWING_EMAIL')) {
            define('FLUENTCRM_PREVIEWING_EMAIL', true);
        }

        ShortcodeParser::startWithheldLog();
        $body       = apply_filters('fluent_crm/parse_campaign_email_text', $body, $subscriber);
        $footerText = apply_filters('fluent_crm/parse_campaign_email_text', $footerText, $subscriber);
        $subject    = apply_filters('fluent_crm/parse_campaign_email_text', $subject, $subscriber);
        $preHeader  = apply_filters('fluent_crm/parse_campaign_email_text', $preHeader, $subscriber);
        $withheld   = ShortcodeParser::stopWithheldLog();

        $footerConfig['footer_content'] = $footerText;

        $templateData = [
            'preHeader'     => $preHeader,
            'email_body'    => $body,
            'footer_text'   => $footerText,
            'footer_config' => $footerConfig,
            'config'        => wp_parse_args(
                Arr::get($settings, 'template_config', []),
                Helper::getTemplateConfig($designTemplate)
            ),
        ];

        $body = apply_filters(
            'fluent_crm/email-design-template-' . $designTemplate,
            $body,
            $templateData,
            $stub,
            $subscriber
        );

        $body = str_replace('{{crm_global_email_footer}}', $footerText, $body);
        $body = str_replace('{{crm_preheader_text}}', $preHeader, $body);

        $headers = Helper::getMailHeadersFromSettings(Arr::get($settings, 'mailer_settings', []));

        $mailer  = Arr::get($settings, 'mailer_settings', []);
        $applied = [
            'is_transactional' => Arr::get($settings, 'is_transactional', 'no'),
            'disable_footer'   => Arr::get($settings, 'footer_settings.disable_footer', 'no'),
            'from'             => self::formatAddress(Arr::get($mailer, 'from_name', ''), Arr::get($mailer, 'from_email', '')),
            'reply_to'         => self::formatAddress(Arr::get($mailer, 'reply_to_name', ''), Arr::get($mailer, 'reply_to_email', '')),
            'design_template'  => $designTemplate,
        ];

        $renderedAgainst = [
            'contact_id' => (int) $subscriber->id,
            'email'      => $subscriber->email,
        ];

        return [
            'subject'          => $subject,
            'pre_header'       => $preHeader,
            'html'             => $body,
            'headers'          => $headers,
            'applied'          => $applied,
            'rendered_against' => $renderedAgainst,
            'to_email'         => $toEmail,
            'subscriber'       => $subscriber,
            'withheld'         => $withheld,
        ];
    }

    /**
     * `render-email-preview`: return the final HTML for a saved campaign or inline draft. Sends nothing.
     */
    public static function renderEmailPreview($params)
    {
        $rendered = self::buildRenderedEmail((array) $params, false);
        if (is_wp_error($rendered)) {
            return $rendered;
        }

        // The rendered HTML is mostly template CSS, so the readable text is the default.
        $format = in_array($params['body_format'] ?? 'text', ['text', 'html', 'both'], true)
            ? ($params['body_format'] ?? 'text')
            : 'text';

        $body = [];
        if ($format !== 'html') {
            $body['text'] = MCPHelper::htmlToText($rendered['html']);
        }
        if ($format !== 'text') {
            $body['html'] = $rendered['html'];
        }

        return [
            'ok'               => true,
            'rendered'         => true,
            'sent'             => false,
            'subject'          => $rendered['subject'],
            'pre_header'       => $rendered['pre_header'],
        ] + $body + [
            'body_format'      => $format,
            'headers'          => $rendered['headers'],
            'applied'          => $rendered['applied'],
            'rendered_against' => $rendered['rendered_against'],
            'withheld_smartcodes' => $rendered['withheld'],
            'note'             => 'Preview only. SmartCodes resolved with the exact settings a live send would use, except access links (password reset, opt-in confirmation, contact hashes), which only resolve in the email delivered to the contact and are listed in withheld_smartcodes. Footer links written as ##crm.unsubscribe_url## and ##crm.manage_subscription_url## are filled per recipient at send time. Nothing was sent and no records were created.',
        ];
    }

    /**
     * `send-test-email`: deliver a real "TEST:" copy of a saved campaign or inline draft.
     * Creates no records.
     */
    public static function sendTestEmail($params)
    {
        $params = (array) $params;

        // Back-compat: render_only previews instead of sending.
        if (!empty($params['render_only'])) {
            // Same caps as render-email-preview: a preview exposes contact PII,
            // and fcrm_manage_emails implies neither read cap.
            foreach (['fcrm_read_emails', 'fcrm_read_contacts'] as $cap) {
                $guard = MCPHelper::permissionGuard($cap);
                if (is_wp_error($guard)) {
                    return $guard;
                }
            }

            return self::renderEmailPreview($params);
        }

        // Choosing the render contact exposes its PII, so it needs contact-read.
        if (!empty($params['against_contact_id']) || !empty($params['against_contact_email'])) {
            $guard = MCPHelper::permissionGuard('fcrm_read_contacts');
            if (is_wp_error($guard)) {
                return $guard;
            }
        }

        $rendered = self::buildRenderedEmail($params, true);
        if (is_wp_error($rendered)) {
            return $rendered;
        }

        $toEmail    = $rendered['to_email'];
        $subscriber = $rendered['subscriber'];
        $subject    = $rendered['subject'];

        $mailErrors = [];
        $mailErrorListener = function ($wpError) use (&$mailErrors) {
            $mailErrors[] = $wpError->get_error_message();
        };
        add_action('wp_mail_failed', $mailErrorListener, 10, 1);

        $data = [
            'to'      => [
                'email' => $toEmail,
                'name'  => $subscriber->full_name ?: $toEmail,
            ],
            'subject' => 'TEST: ' . $subject,
            'body'    => $rendered['html'],
            'headers' => $rendered['headers'],
        ];

        if (method_exists(Helper::class, 'maybeDisableEmojiOnEmail')) {
            Helper::maybeDisableEmojiOnEmail();
        }
        $result = Mailer::send($data, $subscriber, null, true);

        remove_action('wp_mail_failed', $mailErrorListener, 10);

        $sent = $result !== false && empty($mailErrors);

        // rendered_against identity is shown only to callers with contact-read.
        $renderedAgainst = PermissionManager::currentUserCan('fcrm_read_contacts')
            ? $rendered['rendered_against']
            : ['note' => 'Rendered against a contact. Identity hidden (requires contact read permission).'];

        return [
            'ok'                 => $sent,
            'sent'               => $sent,
            'to'                 => $toEmail,
            'rendered_against'   => $renderedAgainst,
            'subject_preview'    => 'TEST: ' . $subject,
            'applied'            => $rendered['applied'],
            'withheld_smartcodes' => $rendered['withheld'],
            'errors'             => $mailErrors,
            'note'               => 'Test sends bypass the queue, do not enroll the recipient, and do not appear in email_history.',
        ];
    }

    private static function yesNo($value, $default = 'no')
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        $str = strtolower((string) $value);
        if (in_array($str, ['yes', 'true', '1', 'on'], true)) {
            return 'yes';
        }
        if (in_array($str, ['no', 'false', '0', 'off', ''], true)) {
            return 'no';
        }
        return $default;
    }

    private static function trackerValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $str = strtolower((string) $value);
        if (in_array($str, ['yes', 'no', 'anonymous'], true)) {
            return $str;
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        return null;
    }
}
