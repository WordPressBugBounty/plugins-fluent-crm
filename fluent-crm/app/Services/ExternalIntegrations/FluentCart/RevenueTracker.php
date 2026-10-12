<?php

namespace FluentCrm\App\Services\ExternalIntegrations\FluentCart;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Services\Helper as FluentCrmHelper;
use FluentCrm\Framework\Support\Arr;

/**
 * Attributes FluentCart order revenue to the campaign in the `fc_cid` cookie, via `_campaign_revenue` meta.
 */
class RevenueTracker
{
    public function init()
    {
        add_action('fluent_cart/order_created', [$this, 'attributeOrder'], 10, 1);
        add_action('fluent_cart/order_paid', [$this, 'recordRevenue'], 10, 1);
    }
    
    /**
     * Stamp the campaign id on the order at creation, while the visitor's cookie is still available.
     */
    public function attributeOrder($eventData)
    {
        $cid = $this->getCampaignIdFromCookie();
        if (!$cid) {
            return;
        }

        $order = Arr::get($eventData, 'order');
        if (!$order || empty($order->id)) {
            return;
        }

        if ($order->getMeta('_fc_cid')) {
            return;
        }

        $order->updateMeta('_fc_cid', $cid);
    }

    /**
     * On payment, add the order total to the campaign's `_campaign_revenue` meta (deduped per order).
     */
    public function recordRevenue($eventData)
    {
        $order = Arr::get($eventData, 'order');
        if (!$order || empty($order->id)) {
            return;
        }

        $cid = (int) $order->getMeta('_fc_cid');
        if (!$cid) {
            return;
        }

        // FluentCart stores amounts as integer cents.
        $amountCents = (int) $order->total_amount;
        if ($amountCents <= 0) {
            return;
        }

        $currency = $order->currency ? strtoupper($order->currency) : 'USD';

        FluentCrmHelper::recordCampaignRevenue($cid, $amountCents, $order->id, $currency);
    }

    private function getCampaignIdFromCookie()
    {
        if (empty($_COOKIE['fc_cid'])) {
            return 0;
        }

        $cid = (int) $_COOKIE['fc_cid'];
        if ($cid <= 0) {
            return 0;
        }

        $exists = Campaign::withoutGlobalScopes()->where('id', $cid)->exists();

        return $exists ? $cid : 0;
    }
}
