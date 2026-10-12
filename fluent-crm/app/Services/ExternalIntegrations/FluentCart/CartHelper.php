<?php

namespace FluentCrm\App\Services\ExternalIntegrations\FluentCart;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Customer;

class CartHelper
{
    public static function getFluentCartProducts($items, $search, $ids = [])
    {
        $search = (string)$search;
        $ids = is_array($ids) ? $ids : [];

        try {
            $productQuery = Product::query()->published();
            if ($search) {
                $productQuery->where('post_title', 'like', '%' . $search . '%');
            }

            $queried = $productQuery
                ->orderBy('post_title')
                ->limit(50)
                ->get(['ID', 'post_title']);

            $options = [];
            $pushedIds = [];
            foreach ($queried as $product) {
                $options[] = [
                    'id'    => $product->ID,
                    'title' => $product->ID . '# ' . $product->post_title,
                ];
                $pushedIds[] = $product->ID;
            }

            if ($ids) {
                $remaining = array_diff($ids, $pushedIds);
                if ($remaining) {
                    $extraProducts = Product::query()->published()->whereIn('ID', $remaining)->get(['ID', 'post_title']);
                    foreach ($extraProducts as $product) {
                        $options[] = [
                            'id'    => $product->ID,
                            'title' => $product->ID . '# ' . $product->post_title,
                        ];
                    }
                }
            }

            return $options;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Build the coupon option list for the funnel "Create Coupon" template selector.
     *
     * Searches title and code; selected IDs are always included.
     *
     * @param array  $items  Unused; kept for the fluentcrm_ajax_options_* filter signature.
     * @param string $search Free-text search term.
     * @param array  $ids    Currently selected coupon IDs to keep pinned in the result.
     * @return array List of ['id' => int, 'title' => string] option rows.
     */
    public static function getFluentCartCoupons($items, $search, $ids)
    {
        $search = sanitize_text_field($search);
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : [];

        try {
            $couponQuery = Coupon::query();

            if ($search) {
                global $wpdb;
                $likeSearch = '%' . $wpdb->esc_like($search) . '%';

                $couponQuery->where(function ($query) use ($likeSearch) {
                    $query->where('title', 'like', $likeSearch)
                        ->orWhere('code', 'like', $likeSearch);
                });
            }

            $queried = $couponQuery
                ->orderBy('id', 'DESC')
                ->limit(50)
                ->get(['id', 'title', 'code']);

            $options = [];
            $pushedIds = [];

            foreach ($queried as $coupon) {
                $options[] = [
                    'id'    => $coupon->id,
                    'title' => $coupon->code ? $coupon->title . ' - ' . $coupon->code : $coupon->title,
                ];
                $pushedIds[] = (int) $coupon->id;
            }

            // Keep previously selected coupons visible even if they are outside the search window.
            if ($ids) {
                $remaining = array_diff($ids, $pushedIds);
                if ($remaining) {
                    $extraCoupons = Coupon::query()
                        ->whereIn('id', $remaining)
                        ->get(['id', 'title', 'code']);

                    foreach ($extraCoupons as $coupon) {
                        $options[] = [
                            'id'    => $coupon->id,
                            'title' => $coupon->code ? $coupon->title . ' - ' . $coupon->code : $coupon->title,
                        ];
                    }
                }
            }

            return $options;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Build a flat product-variation option list for coupon product restrictions.
     *
     * Returns variation ids, which FluentCart coupon conditions match against cart items.
     * Selected ids are always included.
     *
     * @param array  $items  Unused; kept for the fluentcrm_ajax_options_* filter signature.
     * @param string $search Free-text search (matched against variation title and product title).
     * @param array  $ids    Currently selected variation ids to keep pinned in the result.
     * @return array List of ['id' => int variationId, 'title' => string] option rows.
     */
    public static function getFluentCartProductVariations($items, $search, $ids)
    {
        $search = sanitize_text_field($search);
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : [];

        try {
            $variationQuery = ProductVariation::query()
                ->with(['product' => function ($q) {
                    $q->select('ID', 'post_title');
                }]);

            if ($search) {
                global $wpdb;
                $likeSearch = '%' . $wpdb->esc_like($search) . '%';

                $variationQuery->where(function ($q) use ($likeSearch) {
                    $q->where('variation_title', 'like', $likeSearch)
                        ->orWhereHas('product', function ($pq) use ($likeSearch) {
                            $pq->where('post_title', 'like', $likeSearch);
                        });
                });
            }

            $variations = $variationQuery
                ->orderBy('id', 'DESC')
                ->limit(50)
                ->get(['id', 'post_id', 'variation_title']);

            $options = [];
            $pushedIds = [];

            foreach ($variations as $variation) {
                $options[] = [
                    'id'    => $variation->id,
                    'title' => self::formatVariationLabel($variation),
                ];
                $pushedIds[] = (int) $variation->id;
            }

            if ($ids) {
                $remaining = array_diff($ids, $pushedIds);
                if ($remaining) {
                    $extraVariations = ProductVariation::query()
                        ->with(['product' => function ($q) {
                            $q->select('ID', 'post_title');
                        }])
                        ->whereIn('id', $remaining)
                        ->get(['id', 'post_id', 'variation_title']);

                    foreach ($extraVariations as $variation) {
                        $options[] = [
                            'id'    => $variation->id,
                            'title' => self::formatVariationLabel($variation),
                        ];
                    }
                }
            }

            return $options;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Human-readable label for a variation option: "Product Title - Variation Title".
     * Falls back to whichever title is available and avoids duplicating identical titles.
     */
    private static function formatVariationLabel($variation)
    {
        $productTitle = $variation->product ? $variation->product->post_title : '';
        $variationTitle = (string) $variation->variation_title;

        if ($productTitle && $variationTitle && $productTitle !== $variationTitle) {
            return $productTitle . ' - ' . $variationTitle;
        }

        return $productTitle ?: $variationTitle;
    }

    /**
     * Build the product-category option list for coupon category restriction selectors.
     *
     * Caps at 50 results; selected IDs are always included.
     *
     * @param array  $items  Unused; kept for the fluentcrm_ajax_options_* filter signature.
     * @param string $search Free-text search term matched against term name.
     * @param array  $ids    Currently selected term IDs to keep pinned in the result.
     * @return array List of ['id' => int, 'title' => string] option rows.
     */
    public static function getFluentCartProductCategories($items, $search, $ids)
    {
        $search = sanitize_text_field($search);
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : [];

        try {
            $args = [
                'taxonomy'   => 'product-categories',
                'hide_empty' => false,
                'number'     => 50,
            ];

            if ($search) {
                $args['search'] = $search;
            }

            $terms = get_terms($args);

            if (is_wp_error($terms)) {
                return [];
            }

            $options = [];
            $pushedIds = [];

            foreach ($terms as $term) {
                $options[] = [
                    'id'    => (int) $term->term_id,
                    'title' => $term->name,
                ];
                $pushedIds[] = (int) $term->term_id;
            }

            // Keep previously selected categories visible even if outside the search window.
            if ($ids) {
                $remaining = array_diff($ids, $pushedIds);
                if ($remaining) {
                    $extraTerms = get_terms([
                        'taxonomy'   => 'product-categories',
                        'hide_empty' => false,
                        'include'    => $remaining,
                    ]);

                    if (!is_wp_error($extraTerms)) {
                        foreach ($extraTerms as $term) {
                            $options[] = [
                                'id'    => (int) $term->term_id,
                                'title' => $term->name,
                            ];
                        }
                    }
                }
            }

            return $options;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Category term ids of the given products.
     *
     * Term ids, as the "Target Product Categories" selector saves them. This returned
     * term_taxonomy_ids, which differ from term ids on most older sites, so the condition never
     * matched there.
     *
     * @param int[] $ids product (post) ids
     * @return int[]
     */
    public static function getProductCategoriesByIds($ids)
    {
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (!$ids) {
            return [];
        }

        $terms = wp_get_object_terms($ids, 'product-categories', ['fields' => 'ids', 'orderby' => 'none']);
        if (is_wp_error($terms)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $terms)));
    }

    public static function getFluentCartSubscriptionProducts($items, $search, $ids)
    {
        $search = (string)$search;
        $ids = is_array($ids) ? $ids : [];

        try {
            $variationQuery = ProductVariation::query()
                ->where('payment_type', 'subscription')
                ->where('item_status', 'active');

            if ($search) {
                $variationQuery->where('variation_title', 'like', '%' . $search . '%');
            }

            $productIds = $variationQuery->pluck('post_id')->unique()->slice(0, 50)->values();

            $pushedIds = $productIds->toArray();
            if ($ids) {
                $appendIds = array_diff($ids, $pushedIds);
                if ($appendIds) {
                    $productIds = $productIds->merge($appendIds);
                }
            }

            if ($productIds->isEmpty()) {
                return [];
            }

            $products = Product::query()
                ->published()
                ->whereIn('ID', $productIds->toArray())
                ->orderBy('post_title')
                ->get(['ID', 'post_title']);

            $formatted = [];
            foreach ($products as $product) {
                $formatted[] = [
                    'id'    => $product->ID,
                    'title' => $product->ID . '# ' . $product->post_title,
                ];
            }
            return $formatted;
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function prepareSubscriberData($customer)
    {
        if(!is_object($customer)) {
            $customer = (object) $customer;
        }
        return [
            'email' => $customer->email,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'full_name' => $customer->first_name . ' ' . $customer->last_name,
            'user_id' => $customer->user_id,
            'postal_code' => $customer->postcode,
            'country' => $customer->country,
            'state' => $customer->state,
            'city' => $customer->city,
            'phone' => $customer->phone,
        ];
    }

    public static function getCustomersByProductIds($productIds, $offset = 0, $limit = 100)
    {
        $customers = [];
        try {

            $customers = Customer::query()->whereHas('success_order_items', function ($q) use ($productIds) {
                $q->whereIn('post_id', $productIds);
            })->offset($offset)->limit($limit)->get();

        } catch (\Exception $e) {
        }

        return $customers;
    }

    public static function getPurchasedProductsByCustomerId($customerId)
    {
        $productIds = [];
        try {
            // Paid orders only, the rule that picked the customer (success_order_items).
            $orderIds = fluentCrmDb()->table('fct_orders')
                ->where('customer_id', $customerId)
                ->whereIn('payment_status', \FluentCart\App\Helpers\Status::getOrderPaymentSuccessStatuses())
                ->pluck('id');

            $productIds = fluentCrmDb()->table('fct_order_items')
                ->whereIn('order_id', $orderIds)
                ->pluck('post_id');
        } catch (\Exception $e) {
        }

        return $productIds;
    }
}
