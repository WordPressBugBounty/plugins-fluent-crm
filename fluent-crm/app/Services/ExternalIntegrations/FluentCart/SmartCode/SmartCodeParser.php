<?php

namespace FluentCrm\App\Services\ExternalIntegrations\FluentCart\SmartCode;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Payments\PaymentReceipt;
use FluentCart\App\Services\URL;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCart\Api\Resource\OrderResource;
use FluentCrm\App\Models\FunnelMetric;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Actions\DynamicCouponService;

class SmartCodeParser {

    /**
     * The FluentCart order this automation run is about, or null.
     *
     * FunnelSubscriber.source_ref_id is an order id only when a fluent_cart/* trigger started
     * the run (a form entry, course or cart id otherwise), and the order must belong to the
     * recipient, so a smartcode can never print another customer's order.
     *
     * @param object $subscriber recipient, with funnel_subscriber_id
     * @return array|null OrderResource::view() result
     */
    private static function loadOwnOrder($subscriber)
    {
        $funnelSub = FunnelSubscriber::where('id', $subscriber->funnel_subscriber_id ?? 0)->first();
        if (!$funnelSub || strpos((string)$funnelSub->source_trigger_name, 'fluent_cart/') !== 0) {
            return null;
        }

        try {
            $order = OrderResource::view($funnelSub->source_ref_id);
        } catch (\Exception $exception) {
            return null;
        }

        if (empty($order) || !isset($order['order'])) {
            return null;
        }

        $customer = $order['order']['customer'] ?? null;
        if (is_object($customer) && method_exists($customer, 'toArray')) {
            $customer = $customer->toArray();
        }
        $orderEmail = is_array($customer) ? strtolower((string)($customer['email'] ?? '')) : '';
        if (!$orderEmail || $orderEmail !== strtolower((string)$subscriber->email)) {
            return null;
        }

        return $order;
    }

    public static function parseCartOrder($code, $valueKey, $defaultValue, $subscriber)
    {
        $order = self::loadOwnOrder($subscriber);
        if (!$order) {
            return $defaultValue;
        }

        return self::parseOrderProps($order['order'], $valueKey, $defaultValue);
    }


    public static function parseCartCustomer($code, $valueKey, $defaultValue, $subscriber)
    {
        
        $customer = \FluentCart\App\Models\Customer::where('email', $subscriber->email)->first();
        if ($customer) {
            return self::parseCustomerProps($customer->toArray(), $valueKey, $defaultValue);
        }
        

        // Fall back to the order payload.
        $order = self::loadOwnOrder($subscriber);
        if (!$order || !isset($order['order']['customer'])) {
            return $defaultValue;
        }

        $orderCustomer = is_array($order['order']['customer'])
            ? $order['order']['customer']
            : (method_exists($order['order']['customer'], 'toArray')
            ? $order['order']['customer']->toArray()
            : []);

        if (!$orderCustomer) {
            return $defaultValue;
        }

        return self::parseCustomerProps($orderCustomer, $valueKey, $defaultValue);
    }

    /**
     * Resolve the dynamic coupon smartcode {{cart_coupon.{funnel_id}_{sequence_id}}}.
     * Returns the stored code only once the FluentCart coupon exists, otherwise $defaultValue.
     */
    public static function parseCartCoupon($code, $valueKey, $defaultValue, $subscriber)
    {
        $codeParts = explode('_', $valueKey);
        $codeParts = array_map('trim', $codeParts);
        $codeParts = array_map('intval', $codeParts);
        // Reindex after array_filter so index 0 is always defined.
        $codeParts = array_values(array_filter($codeParts));

        if (count($codeParts) < 2) {
            return $defaultValue;
        }

        $funnelId = $codeParts[0];
        $sequenceId = $codeParts[1];

        $funnelMetric = FunnelMetric::where('funnel_id', $funnelId)
            ->where('sequence_id', $sequenceId)
            ->where('subscriber_id', $subscriber->id)
            ->orderBy('id', 'DESC')
            ->first();

        if (!$funnelMetric || !$funnelMetric->notes) {
            return $defaultValue;
        }

        if (!(new DynamicCouponService())->ensureCouponExists((string) $funnelMetric->notes, $funnelMetric, $subscriber)) {
            return $defaultValue;
        }

        return (string) $funnelMetric->notes;
    }

    public static function parseCartTransaction($code, $valueKey, $defaultValue, $subscriber)
    {
        $order = self::loadOwnOrder($subscriber);
        if (!$order) {
            return $defaultValue;
        }

        return self::parseTransactionProps($order['order']['transactions'], $valueKey, $defaultValue);
    }

    public static function parseCartReceipt($code, $valueKey, $defaultValue, $subscriber)
    {
        $order = self::loadOwnOrder($subscriber);
        if (!$order) {
            return $defaultValue;
        }

        return self::parseReceiptProps($order, $valueKey, $defaultValue);
    }

    public static function parseOrderProps($order, $valueKey, $defaultValue)
    {
        if (!$order) {
            return $defaultValue;
        }

        switch ($valueKey) {
            case 'order_id':
                return $order['id'];
            case 'status':
                return $order['status'];
            case 'invoice_no':
                return $order['invoice_no'];
            case 'receipt_number':
                return $order['receipt_number'];
            case 'type':
                return $order['type'];
            case 'customer_id':
                return $order['customer_id'];
            case 'payment_method':
                return $order['payment_method'];
            case 'payment_method_title':
                return $order['payment_method_title'];
            case 'payment_status':
                return $order['payment_status'];
            case 'currency':
                return $order['currency'];
            case 'subtotal':
                return Helper::toDecimal($order['subtotal']);
            case 'shipping_total':
                return Helper::toDecimal($order['shipping_total']);
            case 'total_amount':
                return Helper::toDecimal($order['total_amount']);
            case 'note':
                return $order['note'];
            case 'completed_at':
                return $order['completed_at'];
            case 'total_refund':
                return Helper::toDecimal($order['total_refund']);
            case 'uuid':
                return $order['uuid'];
            case 'created_at':
                return $order['created_at'];
            case 'total_paid':
                return Helper::toDecimal($order['total_paid']);
            case 'shipping_status':
                return $order['shipping_status'];
            default:
                return null;
        }

    }

    public static function parseTransactionProps($transaction, $valueKey, $defaultValue)
    {
        // Currently unused; see the disabled cart_transaction hook in FluentCart::addHooks().
        usort($transaction, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        $transaction = $transaction[0];
        if (!$transaction) {
            return $defaultValue;
        }

        switch ($valueKey) {
            case 'transaction_id':
                return $transaction['id'];
            case 'order_id':
                return $transaction['order_id'];
            case 'order_type':
                return $transaction['order_type'];
            case 'vendor_charge_id':
                return $transaction['vendor_charge_id'];
            case 'payment_method':
                return $transaction['payment_method'];
            case 'payment_mode':
                return $transaction['payment_mode'];
            case 'payment_method_type':
                return $transaction['payment_method_type'];
            case 'transaction_type':
                return $transaction['transaction_type'];
            case 'subscription_id':
                return $transaction['subscription_id'];
            case 'card_last_4':
                return $transaction['card_last_4'];
            case 'card_brand':
                return $transaction['card_brand'];
            case 'status':
                return $transaction['status'];
            case 'total':
                return $transaction['total'];
            case 'rate':
                return $transaction['rate'];
            case 'meta':
                return $transaction['meta'];
            case 'uuid':
                return $transaction['uuid'];
            default:
                return null;
        }

    }

    public static function parseCustomerProps($customer, $valueKey, $defaultValue)
    {
        if (!$customer) {
            return $defaultValue;
        }
        switch ($valueKey) {
            case 'user_id':
                return $customer['user_id'];
            case 'contact_id':
                return $customer['contact_id'];
            case 'first_name':
                return $customer['first_name'];
            case 'last_name':
                return $customer['last_name'];
            case 'email':
                return $customer['email'];
            case 'status':
                return $customer['status'];
            case 'purchase_value':
                return $customer['purchase_value'];
            case 'purchase_count':
                return $customer['purchase_count'];
            case 'first_purchase_date':
                return $customer['first_purchase_date'];
            case 'last_purchase_date':
                return $customer['last_purchase_date'];
            case 'aov':
                return $customer['aov'];
            case 'notes':
                return $customer['notes'];
            case 'uuid':
                return $customer['uuid'];
            case 'country':
                return $customer['country'];
            case 'city':
                return $customer['city'];
            case 'state':
                return $customer['state'];
            case 'postcode':
                return $customer['postcode'];
            default:
                return null;
        }

    }

    public static function parseReceiptProps($order, $valueKey, $defaultValue)
    {
        if (!$order) {
            return $defaultValue;
        }

        switch ($valueKey) {
            case 'payment_summary':
                return self::PaymentReceipt($order);
            case 'order_summary':
                return null;
            default:
                return null;
        }

    }

    private static function paymentReceipt($order)
    {
        $order = Order::with('order_items')->find($order['order']['id']);
        $paymentReceipt = new PaymentReceipt($order);
        $showQuantityColumn = false;

        // Check if any item in the receipt is not a subscription.
        // If such an item exists, set $showQuantityColumn to true.
        foreach ($paymentReceipt->getItems() as $item) {
            if ($item['payment_type'] !== 'subscription') {
                $showQuantityColumn = true;
                break;
            }
        }
        $shop = Helper::shopConfig();
        $currencySign = $shop['currency_sign'];

        ob_start();
        do_action('fluent_cart/views/checkout_order_summary', compact('order', 'paymentReceipt', 'showQuantityColumn', 'currencySign'));
        return ob_get_clean();
    }
}
