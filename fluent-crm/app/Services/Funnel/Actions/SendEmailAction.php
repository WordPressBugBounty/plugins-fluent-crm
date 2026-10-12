<?php

namespace FluentCrm\App\Services\Funnel\Actions;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Models\FunnelCampaign;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;

class SendEmailAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'send_custom_email';
        $this->priority = 10;
        parent::__construct();
        add_filter('fluentcrm_funnel_sequence_saving_' . $this->actionName, array($this, 'savingAction'), 10, 2);
        add_filter('fluentcrm_funnel_sequence_deleting_' . $this->actionName, array($this, 'deleteAction'), 10, 2);
        add_filter('fluentcrm_funnel_sequence_filtered_' . $this->actionName, array($this, 'gettingAction'), 10, 2);
    }

    public function getBlock()
    {
        return [
            'category'    => __('Email', 'fluent-crm'),
            'title'       => __('Send Custom Email', 'fluent-crm'),
            'description' => __('Send a custom email to the contact or a custom email address', 'fluent-crm'),
            'icon'        => 'fc-icon-send_campaign',//fluentCrmMix('images/funnel_icons/custom_email.svg'),
            'settings'    => [
                'reference_campaign' => '',
                'send_email_to_type' => 'contact',
                'send_email_custom'  => '',
                'campaign'           => FunnelCampaign::getMock(),
                'is_scheduled'       => 'no',
                'scheduled_at'       => '',
                'skip_if_overdue'    => 'no',
                'mailer_settings'    => [
                    'from_name'      => '',
                    'from_email'     => '',
                    'reply_to_name'  => '',
                    'reply_to_email' => '',
                    'is_custom'      => 'no'
                ]
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Send Custom Email', 'fluent-crm'),
            'sub_title' => __('Enter the subject and body of the email you want to send.', 'fluent-crm'),
            'fields'    => [
                'send_email_to_type' => [
                    'type'          => 'radio',
                    'wrapper_class' => 'fc_half_field',
                    'label'         => __('Send Email to', 'fluent-crm'),
                    'options'       => [
                        [
                            'id'    => 'contact',
                            'title' => __('Send to the contact', 'fluent-crm')
                        ],
                        [
                            'id'    => 'custom',
                            'title' => __('Send to Custom Email Address', 'fluent-crm')
                        ]
                    ]
                ],
                'send_email_custom'  => [
                    'wrapper_class' => 'fcrm_send_custom_email_form_wrap',
                    'type'          => 'input-text',
                    'label'         => __('Send To Email Addresses (If Custom)', 'fluent-crm'),
                    'placeholder'   => __('Custom Email Addresses', 'fluent-crm'),
                    'inline_help'   => __('Separate multiple email addresses with commas', 'fluent-crm'),
                    'dependency'    => [
                        'depends_on' => 'send_email_to_type',
                        'operator'   => '=',
                        'value'      => 'custom'
                    ]
                ],
                'campaign'           => [
                    'label'         => '',
                    'wrapper_class' => 'fc_email_writer',
                    'type'          => 'email_campaign_composer'
                ],
                'is_scheduled'       => [
                    'type'          => 'yes_no_check',
                    'check_label'   => __('Schedule this email to a specific date', 'fluent-crm'),
                    'wrapper_class' => 'fc_no_pad_l',
                ],
                'skip_if_overdue'    => [
                    'type'          => 'yes_no_check',
                    'wrapper_class' => 'fcrm_child_field',
                    'check_label'   => __('Skip sending the email if the date has passed', 'fluent-crm'),
                    'dependency'    => [
                        'depends_on' => 'is_scheduled',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'scheduled_at'       => [
                    'label'         => __('Schedule Date and Time', 'fluent-crm'),
                    'type'          => 'date_time',
                    'wrapper_class' => 'fcrm_child_field fcrm_schedule_date_time fc_no_marg_b',
                    'placeholder'   => __('Select date and time', 'fluent-crm'),
                    'inline_help'   => __('If the scheduled date has already passed when this step runs, the email will be sent immediately', 'fluent-crm'),
                    'dependency'    => [
                        'depends_on' => 'is_scheduled',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'mailer_settings'    => [
                    'type'        => 'custom_sender_config',
                    'check_label' => __('Set Custom From Name and Email', 'fluent-crm')
                ]
            ]
        ];
    }

    public function savingAction($sequence, $funnel)
    {
        $funnelCampaign = Arr::get($sequence, 'settings.campaign', []);
        $funnelCampaignId = Arr::get($funnelCampaign, 'id');

        $isStripped = Arr::get($sequence, 'is_stripped');
        if ($isStripped && $funnelCampaignId) {
            $refCampaign = FunnelCampaign::find($funnelCampaignId);
            if (!$refCampaign) {
                return $sequence;
            }
            $data = Arr::only($refCampaign->toArray(), array_keys(FunnelCampaign::getMock()));
        } else {
            $data = Arr::only($funnelCampaign, array_keys(FunnelCampaign::getMock()));
        }

        $sequenceId = Arr::get($sequence, 'id');
        $data['settings']['mailer_settings'] = Arr::get($sequence, 'settings.mailer_settings', []);

        if ($funnelCampaignId && $funnel->id == Arr::get($data, 'parent_id')) {
            $data['settings'] = \maybe_serialize($data['settings']);
            $data['type'] = 'funnel_email_campaign';
            $data['title'] = $funnel->title . ' (' . $funnel->id . ' - ' . $sequenceId . ')';
            FunnelCampaign::where('id', $funnelCampaignId)->update($data);
        } else {
            $data['parent_id'] = $funnel->id;
            $data['type'] = 'funnel_email_campaign';
            $data['title'] = $funnel->title . ' (' . $funnel->id . ' - ' . $sequenceId . ')';
            $campaign = FunnelCampaign::create($data);
            $funnelCampaignId = $campaign->id;

            if ($data['design_template'] == 'visual_builder' && !empty($funnelCampaign['_visual_builder_design'])) {
                fluentcrm_update_campaign_meta($funnelCampaignId, '_visual_builder_design', $funnelCampaign['_visual_builder_design']);
            }
        }

        $sequence['settings']['reference_campaign'] = $funnelCampaignId;
        $sequence['settings']['campaign'] = [];
        return $sequence;
    }

    public function gettingAction($sequence, $funnel)
    {
        $refCampaignData = FunnelCampaign::getMock();
        if ($refCampaignId = Arr::get($sequence, 'settings.reference_campaign')) {
            $refCampaign = FunnelCampaign::find($refCampaignId);
            if ($refCampaign) {
                $refCampaignData = Arr::only($refCampaign->toArray(), array_keys(FunnelCampaign::getMock()));

                if ($refCampaignData['design_template'] == 'visual_builder') {
                    $refCampaignData['_visual_builder_design'] = fluentcrm_get_campaign_meta($refCampaignData['id'], '_visual_builder_design', true);
                } else {
                    $refCampaignData['__fcrm_block_type'] = 'email_body_in_funnel';
                }
                $sequence['settings']['campaign'] = $refCampaignData;
            }
        }

        if (empty($sequence['settings']['mailer_settings'])) {
            $sequence['settings']['mailer_settings'] = [
                'from_name'      => '',
                'from_email'     => '',
                'reply_to_name'  => '',
                'reply_to_email' => '',
                'is_custom'      => 'no'
            ];
        }

        return $sequence;
    }

    public function deleteAction($sequence, $funnel)
    {
        $refCampaign = Arr::get($sequence->settings, 'reference_campaign');
        if ($refCampaign) {
            FunnelCampaign::where('id', $refCampaign)->delete();
            CampaignEmail::where('campaign_id', $refCampaign)->delete();
            CampaignUrlMetric::where('campaign_id', $refCampaign)->delete();
            fluentcrm_delete_campaign_meta($refCampaign, '');
        }
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $settings = $sequence->settings;
        $refCampaign = Arr::get($settings, 'reference_campaign');

        if (!$refCampaign) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        if ($funnelMetric) {
            // Run at most once per funnel subscriber per request.
            if (did_action('fluent_crm/did_run_funnel_email_action_' . $funnelMetric->id)) {
                FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
                return;
            }

            do_action('fluent_crm/did_run_funnel_email_action_' . $funnelMetric->id);
        }

        $campaign = FunnelCampaign::find($refCampaign);
        if (!$campaign) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $scheduledAt = current_time('mysql');
        if (Arr::get($settings, 'is_scheduled') == 'yes') {
            $providedDate = Arr::get($settings, 'scheduled_at');

            if ($providedDate && Arr::get($settings, 'skip_if_overdue') == 'yes') {
                if (strtotime($scheduledAt) > strtotime($providedDate)) {
                    FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
                    return;
                }
            }

            if ($providedDate && strtotime($providedDate) > strtotime($scheduledAt)) {
                $scheduledAt = $providedDate;
            }
        }

        $args = [
            'status'       => 'scheduled',
            'scheduled_at' => $scheduledAt,
            'email_type'   => 'funnel_email_campaign',
            /* translators: %s: title of the automation email */
            'note'         => sprintf(__('Email sent from automation: %s', 'fluent-crm'), $campaign->title)
        ];

        if (Arr::get($settings, 'send_email_to_type') == 'contact') {
            $campaign->processAndSubscribe($subscriber, [
                'funnel_subscriber_id' => $funnelSubscriberId
            ], $args);

            do_action('fluentcrm_process_contact_jobs', $subscriber);
        } else if ($customAddresses = Arr::get($settings, 'send_email_custom')) {
            // Event smartcodes (order, booking, space) resolve through the automation run, as in the contact branch.
            $subscriber->funnel_subscriber_id = $funnelSubscriberId;

            /**
             * Filters and parses custom email addresses for a campaign email text.
             *
             * This code applies the 'fluent_crm/parse_campaign_email_text' filter to process
             * the custom email addresses for a given subscriber.
             *
             * @param string $customAddresses The custom email addresses string to be parsed.
             * @param object $subscriber The subscriber object containing subscriber details.
             *
             * @return string The filtered and parsed custom email addresses string.
             */

            $customAddresses = apply_filters('fluent_crm/parse_campaign_email_text', $customAddresses, $subscriber);
            $customAddresses = array_filter(array_map('sanitize_email', explode(',', $customAddresses)));
            $campaign->sendToCustomAddresses($customAddresses, $args, $subscriber);
        }
    }
}
