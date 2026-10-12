<?php

namespace FluentCrm\App\Services\Libs\Mailer;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Support\Str;

/**
 * Render a double opt-in email through the same pipeline for delivery and preview.
 */
class DoubleOptinRenderer
{
    /**
     * Render subject, preheader, and body for a contact.
     *
     * @param array      $config
     * @param Subscriber $subscriber
     * @param bool       $isPreview  Use an inert confirmation URL for admin previews.
     * @return array
     */
    public static function render(array $config, Subscriber $subscriber, $isPreview = false)
    {
        // The email goes to the contact's own inbox. An administrator's preview keeps this level so the
        // confirm button renders (the hash scrub below makes it inert); anyone else previews at private,
        // since the preview lets them pick any contact and write any body.
        $level = (!$isPreview || current_user_can('manage_options')) ? 'credential' : 'private';
        $emailBody = apply_filters('fluent_crm/parse_campaign_email_text', Arr::get($config, 'email_body', ''), $subscriber, $level);
        $emailSubject = apply_filters('fluent_crm/parse_campaign_email_text', Arr::get($config, 'email_subject', ''), $subscriber);
        $emailPreHeader = apply_filters('fluent_crm/parse_campaign_email_text', Arr::get($config, 'email_pre_header', ''), $subscriber);

        $emailBody = apply_filters('fluent_crm/double_optin_email_body', $emailBody, $subscriber);
        $emailSubject = apply_filters('fluent_crm/double_optin_email_subject', $emailSubject, $subscriber);
        $emailPreHeader = apply_filters('fluent_crm/double_optin_email_pre_header', $emailPreHeader, $subscriber);
        $emailBody = str_replace('#activate_link#', self::confirmationUrl($subscriber), $emailBody);

        $designTemplate = sanitize_text_field(Arr::get($config, 'design_template', 'simple'));
        $templateData = [
            'is_double_optin' => true,
            'preHeader'       => $emailPreHeader,
            'email_body'      => $emailBody,
            'footer_text'     => '',
            'config'          => Helper::getTemplateConfig($designTemplate, false)
        ];

        $emailBody = apply_filters(
            'fluent_crm/email-design-template-' . $designTemplate,
            $emailBody,
            $templateData,
            false,
            $subscriber
        );

        if (Str::contains($emailBody, ['##crm.', '{{crm.'])) {
            $emailBody = apply_filters('fluent_crm/parse_extended_crm_text', $emailBody, $subscriber, $level);
        }

        if ($isPreview) {
            // Covers every activation form, including {{crm.activate_button}}, so a preview can never confirm the contact.
            $emailBody = str_replace(array_filter([$subscriber->getSecureHash(), $subscriber->hash]), 'preview', $emailBody);
        }

        return [
            'subject'    => $emailSubject,
            'pre_header' => $emailPreHeader,
            'body'       => $emailBody
        ];
    }

    /**
     * Build the contact's signed confirmation URL.
     */
    private static function confirmationUrl(Subscriber $subscriber)
    {
        return add_query_arg([
            FLUENTCRM_EXTERNAL_URL_PARAM => 1,
            'route'                      => 'confirmation',
            'hash'                       => $subscriber->hash,
            'secure_hash'                => $subscriber->getSecureHash()
        ], site_url('/'));
    }
}
