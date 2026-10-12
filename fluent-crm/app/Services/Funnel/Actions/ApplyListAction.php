<?php

namespace FluentCrm\App\Services\Funnel\Actions;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

class ApplyListAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'add_contact_to_list';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category' => __('CRM', 'fluent-crm'),
            'title'       => __('Apply List', 'fluent-crm'),
            'description' => __('Add contact to the selected lists', 'fluent-crm'),
            'icon' => 'fc-icon-apply_list',//fluentCrmMix('images/funnel_icons/apply_list.svg'),
            'settings'    => [
                'lists' => []
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Add the contact to lists', 'fluent-crm'),
            'sub_title' => __('Select the lists to add the contact to', 'fluent-crm'),
            'fields'    => [
                'lists' => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'lists',
                    'is_multiple' => true,
                    'label'       => __('Select Lists', 'fluent-crm'),
                    'placeholder' => __('Select List', 'fluent-crm'),
                    'creatable' => true
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        if (empty($sequence->settings['lists']) || !is_array($sequence->settings['lists'])) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $lists = $sequence->settings['lists'];

        $renewedSubscriber = Subscriber::where('id', $subscriber->id)->first();
        if (!$renewedSubscriber) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }
        $renewedSubscriber->attachLists($lists);
    }
}
