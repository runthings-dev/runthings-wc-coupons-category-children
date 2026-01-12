<?php

namespace Runthings\WCCouponsCategoryChildren;

/**
 * Copies meta from coupon templates to generated coupons
 */
class AutomateWooMetaCopier
{
    private const META_KEYS = [
        'runthings_wc_allowed_categories_with_children',
        'runthings_wc_excluded_categories_with_children',
    ];

    public function __construct()
    {
        add_action('automatewoo/coupon_generator/generate_from_template_coupon', [$this, 'copy_meta'], 10, 2);
    }

    public function copy_meta($coupon, $template_coupon)
    {
        foreach (self::META_KEYS as $key) {
            $value = $template_coupon->get_meta($key);
            if ($value !== '' && $value !== null) {
                $coupon->update_meta_data($key, $value);
            }
        }
    }
}

