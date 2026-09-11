<?php
if (!defined('ABSPATH')) exit;

if (class_exists('Modir_Shipping_Method')) {
    return;
}

/**
 * روش حمل‌ونقل سفارشی «مدیر». قوانین آن (چند نرخ همزمان) از تنظیمات این
 * افزونه (تب «حمل و نقل پیشرفته») خوانده می‌شود، نه از تنظیمات استاندارد
 * هر Zone — تا مدیریت همه‌جا یکپارچه و از داخل همین افزونه باشد.
 */
class Modir_Shipping_Method extends WC_Shipping_Method {

    public function __construct($instance_id = 0) {
        $this->id                 = 'modir_shipping';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = 'حمل و نقل مدیر';
        $this->method_description = 'روش‌های حمل و نقل سفارشی که از تب «حمل و نقل پیشرفته» افزونه مدیر تعریف می‌شوند.';
        $this->supports           = array('shipping-zones', 'instance-settings');
        $this->init();
    }

    public function init() {
        $this->enabled = 'yes';
        $this->title   = 'حمل و نقل مدیر';
    }

    public function calculate_shipping($package = array()) {
        if (!function_exists('modir_shipping_get_rules')) {
            return;
        }

        $rules = modir_shipping_get_rules();
        if (empty($rules)) {
            return;
        }

        $item_count = 0;
        $weight     = 0.0;
        foreach ($package['contents'] as $cart_item) {
            $item_count += (int) $cart_item['quantity'];
            if (!empty($cart_item['data']) && is_object($cart_item['data'])) {
                $w = (float) $cart_item['data']->get_weight();
                $weight += $w * (int) $cart_item['quantity'];
            }
        }

        $subtotal = 0.0;
        if (isset($package['contents_cost'])) {
            $subtotal = (float) $package['contents_cost'];
        }

        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            if (!empty($rule['min_items']) && $item_count < (int) $rule['min_items']) {
                continue;
            }
            if (!empty($rule['max_items']) && $item_count > (int) $rule['max_items']) {
                continue;
            }

            $cost = (float) $rule['base_price'] + ((float) $rule['per_item_price'] * $item_count);

            if (!empty($rule['weight_threshold']) && $weight > (float) $rule['weight_threshold'] && isset($rule['weight_extra_price'])) {
                $extra_weight = $weight - (float) $rule['weight_threshold'];
                $cost        += $extra_weight * (float) $rule['weight_extra_price'];
            }

            $is_free = false;
            if (!empty($rule['free_threshold']) && $subtotal >= (float) $rule['free_threshold']) {
                $cost    = 0;
                $is_free = true;
            }

            $label = $rule['label'];
            if (!empty($rule['estimated_days'])) {
                $label .= ' (' . $rule['estimated_days'] . ')';
            }
            if ($is_free) {
                $label .= ' — رایگان';
            }

            $this->add_rate(array(
                'id'       => $this->id . $this->get_instance_id() . '_' . $rule['id'],
                'label'    => $label,
                'cost'     => max(0, $cost),
                'calc_tax' => 'per_order',
            ));
        }
    }
}
