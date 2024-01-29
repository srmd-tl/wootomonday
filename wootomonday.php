<?php

/**
 * Plugin Name: WooCommerce Monday.com Integration
 * Description: Automatically creates tasks in Monday.com upon new WooCommerce orders or order updates.
 * Version: 1.0
 * Author: Sarmad Sohail
 * Author URI: https://github.com/srmd-tl
 */
require_once(__DIR__ . '/functions.php');

//Hook to trigger when order status is updated
add_action('woocommerce_order_status_changed', 'status_change_cb', 10, 4);
// Hook into WooCommerce when a new order is created
add_action('gform_after_update_entry', 'update_item_in_monday_cb', 10, 3);
add_action('woocommerce_thankyou', 'create_monday_task_on_new_order');
//After WooCommerce Subscriptions Creates Renewal Order
// add_filter( 'wcs_renewal_order_created', 'pre_call_create_monday_task_on_new_order' ,40 ,2);

add_action('woocommerce_subscription_payment_complete', 'pre_call_create_monday_task_on_new_order', 10, 2);
