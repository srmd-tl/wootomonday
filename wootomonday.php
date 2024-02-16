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
add_action('woocommerce_order_status_failed', 'create_monday_task_on_new_order');

//After WooCommerce Subscriptions Creates Renewal Order
//add_filter( 'wcs_renewal_order_created', 'pre_call_create_monday_task_on_new_order' ,30,2);

add_action('woocommerce_subscription_renewal_payment_complete', 'pre_call_create_monday_task_on_new_order', 10, 2);


add_action('manage_shop_order_posts_custom_column', 'wootomonday_force_push', 25, 2);
// for HPOS-based orders
add_action('manage_woocommerce_page_wc-orders_custom_column', 'wootomonday_force_push', 25, 2);
function wootomonday_force_push($column_name, $order_or_order_id)
{
    $order_or_order_id = $order_or_order_id->id;
    if ('action' === $column_name) {
        echo "<a href='/wp-admin/admin.php?page=wc-orders&wootomonday_type=force&order_id=$order_or_order_id'>Push To Monday</a>";
    }
}

// legacy – for CPT-based orders
add_filter('manage_edit-shop_order_columns', 'wootomonday_action_column');
// for HPOS-based orders
add_filter('manage_woocommerce_page_wc-orders_columns', 'wootomonday_action_column');

function wootomonday_action_column($columns)
{

    // let's add our column before "Total"
    $columns = array_slice($columns, 0, 8, true)
        + array('action' => 'Action')
        + array_slice($columns, 8, NULL, true);

    return $columns;
}
// Hook to 'admin_init' to handle the processing when the link is clicked
add_action('admin_init', 'handle_wootomonday_force_push');

function handle_wootomonday_force_push()
{
    if (isset($_GET['wootomonday_type']) && $_GET['wootomonday_type'] == 'force') {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;

        if ($order_id > 0) {
            $order = wc_get_order($order_id);

            if ($order && is_a($order, 'WC_Order')) {
                if (wcs_order_contains_renewal($order)) {
                    $related_subscriptions = wcs_get_subscriptions_for_renewal_order($order);
                    if ($related_subscriptions) {
                        $related_subscriptions = current($related_subscriptions);
                    }
                    error_log("related subs");
                    error_log(print_r($related_subscriptions, 1));
                    pre_call_create_monday_task_on_new_order($related_subscriptions, $order);
                } else {
                    create_monday_task_on_new_order($order_id);
                }

                // Redirect back to the orders list or any other page as needed
                wp_safe_redirect(admin_url('edit.php?post_type=shop_order'));
                exit;
            }
        }
    }
}
