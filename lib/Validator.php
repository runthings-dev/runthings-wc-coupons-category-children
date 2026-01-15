<?php

namespace Runthings\WCCouponsCategoryChildren;

use Exception;
use WC_Coupon;
use WC_Discounts;
use WC_Product;

if (!defined('WPINC')) {
    die;
}

class Validator
{
    public function __construct()
    {
        add_filter('woocommerce_coupon_is_valid', [$this, 'validate_coupon_categories'], 10, 3);
        add_filter('woocommerce_coupon_is_valid_for_product', [$this, 'validate_coupon_for_product'], 10, 4);
        add_filter('woocommerce_coupon_error', [$this, 'customize_coupon_error'], 10, 3);
    }

    /**
     * Cart-level validation for fixed_cart coupons.
     * Product coupons are handled by validate_coupon_for_product + customize_coupon_error.
     */
    public function validate_coupon_categories(bool $is_valid, WC_Coupon $coupon, WC_Discounts $discounts): bool
    {
        if (!$is_valid || $coupon->is_type(wc_get_product_coupon_types())) {
            return $is_valid;
        }

        $restrictions = $this->get_category_restrictions($coupon);
        if (!$restrictions) {
            return $is_valid;
        }

        $cart_category_ids = $this->get_cart_category_ids();

        if (!empty($restrictions['expanded_allowed'])) {
            if (empty(array_intersect($cart_category_ids, $restrictions['expanded_allowed']))) {
                $this->throw_validation_error($coupon, 'allowed', $restrictions);
            }
        }

        if (!empty($restrictions['expanded_excluded'])) {
            if (!empty(array_intersect($cart_category_ids, $restrictions['expanded_excluded']))) {
                $this->throw_validation_error($coupon, 'excluded', $restrictions);
            }
        }

        return true;
    }

    /**
     * Product-level validation for percent/fixed_product coupons.
     *
     * @param array|WC_Order_Item_Product $values Cart item data or order item.
     */
    public function validate_coupon_for_product(bool $valid, WC_Product $product, WC_Coupon $coupon, $values): bool
    {
        $restrictions = $this->get_category_restrictions($coupon);
        if (!$restrictions) {
            return $valid;
        }

        $product_id = $product->get_parent_id() ?: $product->get_id();
        $product_cats = wc_get_product_cat_ids($product_id);

        return $this->categories_pass_restrictions($product_cats, $restrictions);
    }

    /**
     * Customize error message for product coupons that fail due to our category restrictions.
     * Only customizes if we can verify our restrictions actually caused the failure.
     */
    public function customize_coupon_error(string $err, int $err_code, WC_Coupon $coupon): string
    {
        if ($err_code !== \WC_Coupon::E_WC_COUPON_NOT_APPLICABLE) {
            return $err;
        }

        $restrictions = $this->get_category_restrictions($coupon);
        if (!$restrictions) {
            return $err;
        }

        // Verify our restrictions actually caused the failure by re-checking cart products
        if (!$this->did_our_restrictions_fail($restrictions)) {
            return $err;
        }

        $type = !empty($restrictions['allowed']) ? 'allowed' : 'excluded';
        return $this->get_error_message($coupon, $type, $restrictions);
    }

    /**
     * Check if our category restrictions caused ALL products to fail.
     * Returns true only if NO products pass our restrictions.
     */
    private function did_our_restrictions_fail(array $restrictions): bool
    {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['data']->get_parent_id() ?: $cart_item['data']->get_id();
            $product_cats = wc_get_product_cat_ids($product_id);

            if ($this->categories_pass_restrictions($product_cats, $restrictions)) {
                // At least one product passes - we didn't cause the total failure
                return false;
            }
        }

        // No products passed our restrictions - we caused the failure
        return true;
    }

    /**
     * Get category restrictions for a coupon, with expanded children.
     * Returns null if no restrictions configured.
     */
    private function get_category_restrictions(WC_Coupon $coupon): ?array
    {
        $allowed = get_post_meta($coupon->get_id(), Plugin::ALLOWED_CATEGORIES_META_KEY, true);
        $allowed = is_array($allowed) ? $allowed : [];

        $excluded = get_post_meta($coupon->get_id(), Plugin::EXCLUDED_CATEGORIES_META_KEY, true);
        $excluded = is_array($excluded) ? $excluded : [];

        if (empty($allowed) && empty($excluded)) {
            return null;
        }

        return [
            'allowed' => $allowed,
            'excluded' => $excluded,
            'expanded_allowed' => $this->expand_categories_with_children($allowed),
            'expanded_excluded' => $this->expand_categories_with_children($excluded),
        ];
    }

    /**
     * Check if categories pass the allowed/excluded restrictions.
     */
    private function categories_pass_restrictions(array $category_ids, array $restrictions): bool
    {
        if (!empty($restrictions['expanded_allowed'])) {
            if (empty(array_intersect($category_ids, $restrictions['expanded_allowed']))) {
                return false;
            }
        }

        if (!empty($restrictions['expanded_excluded'])) {
            if (!empty(array_intersect($category_ids, $restrictions['expanded_excluded']))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get filtered error message for validation failures.
     */
    private function get_error_message(WC_Coupon $coupon, string $type, array $restrictions): string
    {
        $error_context = [
            'coupon' => $coupon,
            'type' => $type,
            'configured_category_ids' => $type === 'allowed' ? $restrictions['allowed'] : $restrictions['excluded'],
            'expanded_category_ids' => $type === 'allowed' ? $restrictions['expanded_allowed'] : $restrictions['expanded_excluded'],
        ];

        $default_message = $type === 'allowed'
            ? __('This coupon is not valid for the product categories in your cart.', 'runthings-wc-coupons-category-children')
            : __('This coupon cannot be used with some product categories in your cart.', 'runthings-wc-coupons-category-children');

        return apply_filters('runthings_wc_coupons_category_children_error_message', $default_message, $error_context);
    }

    private function get_cart_category_ids(): array
    {
        $category_ids = [];

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $product_cats = wc_get_product_cat_ids($product_id);
            $category_ids = array_merge($category_ids, $product_cats);

            $product = wc_get_product($product_id);
            if ($product && $product->get_parent_id()) {
                $parent_cats = wc_get_product_cat_ids($product->get_parent_id());
                $category_ids = array_merge($category_ids, $parent_cats);
            }
        }

        return array_unique($category_ids);
    }

    private function expand_categories_with_children(array $category_ids): array
    {
        $expanded = [];

        foreach ($category_ids as $cat_id) {
            $expanded[] = $cat_id;
            $children = get_term_children($cat_id, 'product_cat');
            if (!is_wp_error($children)) {
                $expanded = array_merge($expanded, $children);
            }
        }

        return array_unique($expanded);
    }

    private function throw_validation_error(WC_Coupon $coupon, string $type, array $restrictions): void
    {
        $error_message = $this->get_error_message($coupon, $type, $restrictions);
        throw new Exception(esc_html($error_message), WC_Coupon::E_WC_COUPON_INVALID_FILTERED);
    }
}

