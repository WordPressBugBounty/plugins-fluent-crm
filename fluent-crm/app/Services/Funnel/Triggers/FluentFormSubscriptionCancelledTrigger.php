<?php

namespace FluentCrm\App\Services\Funnel\Triggers;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class FluentFormSubscriptionCancelledTrigger extends BaseTrigger
{
    public function __construct()
    {
        if (!defined('FLUENTFORM')) {
            return;
        }


        $this->actionArgNum = 3;
        $this->triggerName = 'fluentform/subscription_payment_canceled';
        $this->priority = 25;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('Fluent Forms', 'fluent-crm'),
            'label'       => __('Subscription Canceled (Fluent Forms)', 'fluent-crm'),
            'description' => __('This automation will start when a subscription is canceled via Fluent Forms.', 'fluent-crm'),
            'icon'        => 'fc-icon-fluentforms',
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed'
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Subscription Canceled Automation', 'fluent-crm'),
            'sub_title' => __('This automation will start when a subscription is canceled via Fluent Forms.', 'fluent-crm'),
            'fields'    => [
                'subscription_status' => [
                    'type'        => 'select',
                    'is_multiple' => false,
                    'options' => [
                        [
                            'id'    => 'subscribed',
                            'title' => __('Subscribed', 'fluent-crm')
                        ],
                        [
                            'id'    => 'pending',
                            'title' => __('Pending', 'fluent-crm')
                        ]
                    ],
                    'label'       => __('Subscription Status', 'fluent-crm'),
                    'placeholder' => __('Select status', 'fluent-crm')
                ],
                'subscription_status_info' => [
                    'type'       => 'html',
                    'info'       => sprintf('<b>%s</b>', __('An automated double opt-in email will be sent if the contact is new or not already subscribed.', 'fluent-crm')),
                    'dependency' => [
                        'depends_on' => 'subscription_status',
                        'operator'   => '=',
                        'value'      => 'pending'
                    ]
                ]
            ]
        ];
    }

    protected function getForms($funnel)
    {
        return fluentCrmDb()->table('fluentform_forms')
            ->select('id', 'title')
            ->orderBy('id', 'DESC')
            ->get();
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'form_ids' => []
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'form_ids'     => [
                'type'        => 'multi-select',
                'label'   => __('Target Forms', 'fluent-crm'),
                'options' => $this->getForms($funnel),
                'inline_help' => __('Leave blank to run for any form', 'fluent-crm')
            ],
            'run_multiple'       => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Allow this event to restart the automation for the same contact', 'fluent-crm'),
                'inline_help' => __('If enabled, it will restart the automation for a contact who is already in it. Otherwise, the contact will be skipped.', 'fluent-crm')
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        [$subscription, $submission, $vendorData] = $originalArgs;

        $userId = $submission->user_id;

        $subscriberData = $userId ? FunnelHelper::prepareUserData($userId) : [];

        // Payment forms do not need a login, so most subscribers are guests: use the submission's email.
        if (empty($subscriberData['email'])) {
            $email = self::emailFromSubmission($submission, $subscription->form_id ?? ($submission->form_id ?? 0));
            if (!$email) {
                return;
            }
            $subscriberData = ['email' => $email];
        }

        $willProcess = $this->isProcessable($funnel, $subscription, $subscriberData);

        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);
        if (!$willProcess) {
            return;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $subscription->id
        ]);
    }

    /**
     * The payer's email in a Fluent Forms submission: the form's configured payment email field
     * (Payment Settings, "Customer Email"), else the "email" field, else the first valid email.
     *
     * @param object $submission fluentform_submissions row
     * @param int    $formId
     * @return string
     */
    private static function emailFromSubmission($submission, $formId = 0)
    {
        $response = is_array($submission->response) ? $submission->response : json_decode((string)$submission->response, true);
        if (!is_array($response)) {
            return '';
        }

        // A form can also ask for other addresses (a friend, a guardian); the payment one is the payer.
        $paymentHelper = '\FluentForm\App\Modules\Payments\PaymentHelper';
        if ($formId && class_exists($paymentHelper) && method_exists($paymentHelper, 'getFormSettings')) {
            $field = Arr::get((array)$paymentHelper::getFormSettings((int)$formId, 'admin'), 'receipt_email');
            $email = $field ? Arr::get($response, $field) : '';
            if (is_string($email) && is_email($email)) {
                return $email;
            }
        }

        if (!empty($response['email']) && is_string($response['email']) && is_email($response['email'])) {
            return $response['email'];
        }

        foreach ($response as $value) {
            if (is_string($value) && is_email($value)) {
                return $value;
            }
        }

        return '';
    }

    private function isProcessable($funnel, $subscription, $subscriberData)
    {
        $conditions = $funnel->conditions;
        // check the Form Ids
        if ($conditions['form_ids']) {
            if (!in_array($subscription->form_id, $conditions['form_ids'])) {
                return false;
            }
        }

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);

        // check run_only_one
        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            $multipleRun = Arr::get($conditions, 'run_multiple') == 'yes';
            if ($multipleRun) {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            } else {
                return false;
            }
        }

        return true;
    }
}