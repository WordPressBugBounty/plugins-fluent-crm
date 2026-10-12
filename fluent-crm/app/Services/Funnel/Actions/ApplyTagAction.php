<?php

namespace FluentCrm\App\Services\Funnel\Actions;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

class ApplyTagAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'add_contact_to_tag';
        $this->priority = 21;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category' => __('CRM', 'fluent-crm'),
            'title'       => __('Apply Tag', 'fluent-crm'),
            'description' => __('Add this contact to the selected tags', 'fluent-crm'),
            'icon'        => 'fc-icon-apply_tag',//fluentCrmMix('images/funnel_icons/apply_tag.svg'),
            'settings'    => [
                'tags' => []
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Apply tags to the contact', 'fluent-crm'),
            'sub_title' => __('Select the tags to apply to the contact', 'fluent-crm'),
            'fields'    => [
                'tags' => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'tags',
                    'is_multiple' => true,
                    'creatable'   => true,
                    'label'       => __('Select tags', 'fluent-crm'),
                    'placeholder' => __('Select Tag', 'fluent-crm')
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        if (empty($sequence->settings['tags']) || !is_array($sequence->settings['tags'])) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $tags = $sequence->settings['tags'];
        $renewedSubscriber = Subscriber::where('id', $subscriber->id)->first();
        if (!$renewedSubscriber) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }
        $renewedSubscriber->attachTags($tags);
    }
}
