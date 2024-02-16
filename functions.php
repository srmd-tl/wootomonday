<?php
define('PLUGIN_PATH', plugin_dir_path(__FILE__) . 'debug.log');

function status_change_cb($id, $status_transition_from, $status_transition_to, $that)
{
    if ($that->get_meta('_subscription_renewal')) {
        $subscriptionId = $that->get_meta('_subscription_renewal');
        $subscription = wc_get_order($subscriptionId);
        $parentOrder = wc_get_order($subscription->get_data()['parent_id']);
        $entryId = get_woo_order_entry_id($parentOrder);
    } else {
        $entryId = get_woo_order_entry_id($that);
    }
    //Search monday entry id from DB
    $mondayItemId = get_monday_record_id($entryId, $id);
    //Search from monday.com using GraphQL
    if (!$mondayItemId) {
        $mondayItemId = get_monday_record_id_from_graphql($id)->data->items_page_by_column_values->items[0]->id ?? null;
    }
    $orderData = getOrderDetails($entryId, $id);
    if (!$mondayItemId) {
        return;
    }
    return  update_item_in_monday($mondayItemId, $orderData);
}
//Get Monday.com record id by graphql ,if not saved in DB
function get_monday_record_id_from_graphql($orderId)
{

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.monday.com/v2/",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => "{\n\t\"query\": \"query { items_page_by_column_values (limit: 1 board_id: 1158941214, columns: [{column_id: \\\"name\\\", column_values: [\\\"" . $orderId . "\\\"]}]) { cursor items { id name column_values{text} }}}\"\n}",
        CURLOPT_HTTPHEADER => [
            "API-Version: 2023-10",
            "Authorization: eyJhbGciOiJIUzI1NiJ9.eyJ0aWQiOjMwMzk4ODU0MCwiYWFpIjoxMSwidWlkIjo1MTExNjAwMiwiaWFkIjoiMjAyMy0xMi0yMFQxMToyODoyNS43OTdaIiwicGVyIjoibWU6d3JpdGUiLCJhY3RpZCI6Nzg4ODgyOSwicmduIjoidXNlMSJ9.pC8ks0oxDJDykIGkb7s5-y6KyIKfcArV9PR2kytwTZ8",
            "Content-Type: application/json",
            "User-Agent: insomnia/8.1.0"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
        return null;
    } else {
        return json_decode($response);
    }
}
function getOrderDetails($entryId, $orderId)
{
    $entries = GFAPI::get_entry($entryId);
    error_log("Order Details");
    error_log($entryId);
    error_log(print_r($entries, 1), 3, PLUGIN_PATH);
    $order = wc_get_order($orderId);
    $product = getOrderItem($order);
    $registeredName = $entries['15'] ?? 'N/A';
    $color = $entries['82'] ?? null;
    $sex = $entries['83'] ?? null;
    $sire = $entries['84'] ?? null;
    $dam = $entries['87'] ?? null;
    $year = $entries['181'] ?? null;
    $lifeStage = $entries['13'] ?? null;
    $eligibleIncentives = $entries['154'];
    $description = $entries['188'] ?? $entries['169'] ?? $entries['162'] ?? $entries['150'] ?? null;
    $listedPrice = $entries['134'] ?? null;
    $priceListingOption = $entries['135'] ?? null;
    $formId = $entries['form_id'] ?? null;

    foreach ($order->get_items() as $item_id => $item) {
        // Get the product name
        $product_name = $item['name'];
    }

    $description = str_replace(array("\r", "\n", "\t", "\\"), array('\r', '\n', '\t', '\\'), $description);
    return array(
        'order_number' => $order->get_order_number(),
        'customer_first_name' => $order->get_billing_first_name(),
        'customer_last_name' => $order->get_billing_last_name(),
        'customer_email' => $order->get_billing_email(),
        'sire' => $sire,
        'dam' => $dam,
        'color' => $color,
        'sex' => $sex,
        'register_name' => $registeredName,
        //        'description' => $description,
        'description' => '',
        'product_name' => count($order->get_items()) > 1 ? sprintf('%s,%s', $product['name'], $product_name) : $product['name'],
        'life_stage' => $lifeStage,
        'eligible_incentives' =>  '',
        'type' => $product->is_type('subscription') ? 'subscription' : 'single',
        'entry_id' => $entryId,
        'status' => $order->get_status(),
        'created_at' => $order->get_date_created()->date('Y-m-d'),
        'year' => $year,
        'listed_price' => $listedPrice,
        'price_listing_option' => $priceListingOption,
        'form_id' => $formId

    );
}
function update_item_in_monday_cb($form, $entry_id, $original_entry)
{
    $orderId = get_order_num($entry_id);
    echo $orderId;
    echo $entry_id;
    $mondayItemId = get_monday_record_id($entry_id, $orderId);
    //Search from monday.com using GraphQL
    if (!$mondayItemId) {
        $mondayItemId = get_monday_record_id_from_graphql($orderId)->data->items_page_by_column_values->items[0]->id ?? null;
    }
    $orderData = getOrderDetails($entry_id, $orderId);
    return update_item_in_monday($mondayItemId, $orderData);
}
function pre_call_create_monday_task_on_new_order($subscription, $last_order)
{
    // Get the order associated with the subscription
    $order = wc_get_order($subscription->get_parent_id());

    $parentId = @getParentId($order->get_id());
    $productName = current($order->get_items())->get_name();
    $mondayItemId = create_monday_task_on_new_order($parentId, false, ['type' => 'renewal', 'product_name' => $productName, 'order_id' => $last_order->get_id(), 'parent_id' => $parentId, 'group_id' => 'new_group94739', 'created_at' => $last_order->get_date_created()->date('Y-m-d'), 'status' => $last_order->get_status(), 'in_db' => false]);

    $parentOrder = wc_get_order($parentId);
    $entryId = get_woo_order_entry_id($parentOrder);
    save_monday_record_id($entryId,  $order->get_id(), $mondayItemId);
    return $subscription;

    // if ($productName == 'Monthly Promotion') {
    //     create_micro_monday_task_on_new_order([
    //         'product_name' => $productName, 'order_id' => $order->get_id(), 'parent_id' => $parentId, 'created_at' => $order->get_date_created()->date('Y-m-d'), 'status' => $order->get_status(), 'customer_first_name' => $order->get_billing_first_name(),
    //         'customer_last_name' => $order->get_billing_last_name(),
    //         'customer_email' => $order->get_billing_email(),
    //     ]);
    // } else {
    //     create_monday_task_on_new_order($parentId, ['type' => 'subscription', 'product_name' => $productName, 'order_id' => $order->get_id(), 'parent_id' => $parentId]);
    // }
    // error_log(print_r($order->get_items(), 1));
}
//Create new item on monday with lesser info.
function create_micro_monday_task_on_new_order($orderDetail)
{
    $orderId = $orderDetail['order_id'];
    $checkoutId = $orderDetail['parent_id'];
    $productName = $orderDetail['product_name'];
    $type = 'renewal';
    $createdAt = $orderDetail['created_at'];
    $status = $orderDetail['status'];
    $customerFirstName = $orderDetail['customer_first_name'];
    $customerLastName = $orderDetail['customer_last_name'];
    $customerEmail = $orderDetail['customer_email'];
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.monday.com/v2/",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => "{\n  \"query\": \"mutation {create_item (board_id: 1158941214, group_id: \\\"new_group94739\\\", item_name: \\\"" . $orderId . "\\\", column_values: \\\"{\\\\\\\"text8\\\\\\\":\\\\\\\"" . $checkoutId . "\\\\\\\",\\\\\\\"status_1\\\\\\\":\\\\\\\"" . $type . "\\\\\\\",\\\\\\\"date4\\\\\\\":\\\\\\\"" . $createdAt . "\\\\\\\",\\\\\\\"status\\\\\\\":\\\\\\\"" . $status . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerFirstName . "\\\\\\\",\\\\\\\"text1\\\\\\\":\\\\\\\"" . $customerLastName . "\\\\\\\",\\\\\\\"text0\\\\\\\":\\\\\\\"" . $productName . "\\\\\\\",\\\\\\\"email\\\\\\\":{\\\\\\\"email\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\"}}\\\") {id}}\"\n}\n",
        CURLOPT_HTTPHEADER => [
            "API-Version: 2023-10",
            "Authorization: eyJhbGciOiJIUzI1NiJ9.eyJ0aWQiOjMwMzk4ODU0MCwiYWFpIjoxMSwidWlkIjo1MTExNjAwMiwiaWFkIjoiMjAyMy0xMi0yMFQxMToyODoyNS43OTdaIiwicGVyIjoibWU6d3JpdGUiLCJhY3RpZCI6Nzg4ODgyOSwicmduIjoidXNlMSJ9.pC8ks0oxDJDykIGkb7s5-y6KyIKfcArV9PR2kytwTZ8",
            "Content-Type: application/json",
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
        echo "cURL Error #:" . $err;
    } else {
        return json_decode($response);
    }
}
function create_monday_task_on_new_order($order_id, $inDb = true, $optional = [])
{
    // Retrieve order details
    $order = wc_get_order($order_id);
    //    error_log(print_r($order, 1), 3, PLUGIN_PATH);
    $entryId = get_woo_order_entry_id($order);
    $orderData = getOrderDetails($entryId, $order_id);
    error_log("ORder Details", 3, PLUGIN_PATH);
    error_log(print_r($orderData, 1), 3, PLUGIN_PATH);
    // Call a function to create a task in Monday.com using Monday API
    try {
        $mondayResponse = create_task_in_monday($orderData, $optional);
        if ($mondayResponse->error_code) {
            error_log('Error in modnay entry', 3, PLUGIN_PATH);
            error_log(print_r($mondayResponse, 1), 3, PLUGIN_PATH);
            return null;
        }
    } catch (\Exception $e) {
        error_log(print_r($e->getMessage(), 1), 3, PLUGIN_PATH);
    }
    if ($inDb) {
        save_monday_record_id($entryId, $order_id, $mondayResponse->data->create_item->id);
    }
    return $mondayResponse->data->create_item->id;
}
function update_monday_task_on_order_status_change($order_id, $old_status, $new_status, $order)
{
    // Check if the order status has changed to a specific status you want to handle
    if ($new_status === 'completed') {
        // Extract necessary order information
        $order_data = array(
            'order_number' => $order->get_order_number(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            // Add more order information as needed
        );

        // Call a function to update the task in Monday.com using Monday API
        update_task_in_monday($order_data);
    }
}

//Use GraphQL to create a new item in monday.com
function create_task_in_monday($orderData, $optional = [])
{
    $orderId = $optional['order_id'] ?? $orderData['order_number'];
    $checkoutId = $optional['parent_id'] ?? $orderData['order_number'];
    $customerFirstName = $orderData['customer_first_name'];
    $customerLastName = $orderData['customer_last_name'];
    $customerEmail = $orderData['customer_email'];
    $sire = $orderData['sire'];
    $dam = $orderData['dam'];
    $color = $orderData['color'];
    $sex = $orderData['sex'];
    $regName = $orderData['register_name'];
    $description = $orderData['description'];
    $productName = $optional['product_name'] ?? $orderData['product_name'];
    $eligibleIncentives = $orderData['eligible_incentives'];
    $lifeStage = $orderData['life_stage'];
    $createdAt = $optional['created_at'] ?? $orderData['created_at'];
    $status = $optional['status'] ?? $orderData['status'];
    $year = $orderData['year'];
    $type = $optional['type'] ?? 'checkout';
    $groupId = $optional['group_id'] ?? 'topics';
    $listedPrice = $orderData['listed_price'] ?? null;
    $priceListingOption = $orderData['price_listing_option'] ?? null;
    $formId = $orderData['form_id'] ?? null;
    error_log('form Id' . $formId, 3, PLUGIN_PATH);
    if ($formId != '3' && 'checkout' == $type) {
        $query = "{\n  \"query\": \"mutation {create_item (board_id: 1158941214, group_id: \\\"" . $groupId . "\\\", item_name: \\\"" . $orderId . "\\\", column_values: \\\"{\\\\\\\"dup__of_reg_name7\\\\\\\":\\\\\\\"" . $lifeStage . "\\\\\\\",\\\\\\\"long_text00\\\\\\\":\\\\\\\"" . $description . "\\\\\\\",\\\\\\\"long_text09\\\\\\\":\\\\\\\"" . $priceListingOption . "\\\\\\\",\\\\\\\"dup__of_dam\\\\\\\":\\\\\\\"" . $listedPrice . "\\\\\\\",\\\\\\\"status_1\\\\\\\":\\\\\\\"" . $type . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerFirstName . "\\\\\\\",\\\\\\\"text1\\\\\\\":\\\\\\\"" . $customerLastName . "\\\\\\\",\\\\\\\"date4\\\\\\\":\\\\\\\"" . $createdAt . "\\\\\\\",\\\\\\\"status\\\\\\\":\\\\\\\"" . $status . "\\\\\\\",\\\\\\\"text0\\\\\\\":\\\\\\\"" . $productName . "\\\\\\\",\\\\\\\"long_text8\\\\\\\":\\\\\\\"" . $regName . "\\\\\\\",\\\\\\\"dup__of_reg_name3\\\\\\\":\\\\\\\"" . $year . "\\\\\\\",\\\\\\\"dup__of_birth_year\\\\\\\":\\\\\\\"" . $color . "\\\\\\\",\\\\\\\"dup__of_color\\\\\\\":\\\\\\\"" . $sex . "\\\\\\\",\\\\\\\"dup__of_reg_name\\\\\\\":\\\\\\\"" . $sire . "\\\\\\\",\\\\\\\"dup__of_sire\\\\\\\":\\\\\\\"" . $dam . "\\\\\\\",\\\\\\\"long_text\\\\\\\":\\\\\\\"" . $eligibleIncentives . "\\\\\\\",\\\\\\\"email\\\\\\\":{\\\\\\\"email\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\"}}\\\") {id}}\"\n}\n";
    } else if ($formId == '3') {
        $query = "{\n  \"query\": \"mutation {create_item (board_id: 1158941214, group_id: \\\"" . $groupId . "\\\", item_name: \\\"" . $orderId . "\\\", column_values: \\\"{\\\\\\\"dup__of_reg_name7\\\\\\\":\\\\\\\"" . $lifeStage . "\\\\\\\",\\\\\\\"long_text00\\\\\\\":\\\\\\\"" . $description . "\\\\\\\",\\\\\\\"long_text09\\\\\\\":\\\\\\\"" . $priceListingOption . "\\\\\\\",\\\\\\\"dup__of_dam\\\\\\\":\\\\\\\"" . $listedPrice . "\\\\\\\",\\\\\\\"status_1\\\\\\\":\\\\\\\"" . $type . "\\\\\\\",\\\\\\\"date4\\\\\\\":\\\\\\\"" . $createdAt . "\\\\\\\",\\\\\\\"status\\\\\\\":\\\\\\\"" . $status . "\\\\\\\",\\\\\\\"text0\\\\\\\":\\\\\\\"" . $productName . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerFirstName . "\\\\\\\",\\\\\\\"text1\\\\\\\":\\\\\\\"" . $customerLastName . "\\\\\\\",\\\\\\\"email\\\\\\\":{\\\\\\\"email\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\"}}\\\") {id}}\"\n}\n";
    } else {
        $query = "{\n  \"query\": \"mutation {create_item (board_id: 1158941214, group_id: \\\"" . $groupId . "\\\", item_name: \\\"" . $orderId . "\\\", column_values: \\\"{\\\\\\\"text8\\\\\\\":\\\\\\\"" . $checkoutId . "\\\\\\\",\\\\\\\"dup__of_reg_name7\\\\\\\":\\\\\\\"" . $lifeStage . "\\\\\\\",\\\\\\\"long_text00\\\\\\\":\\\\\\\"" . $description . "\\\\\\\",\\\\\\\"status_1\\\\\\\":\\\\\\\"" . $type . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerFirstName . "\\\\\\\",\\\\\\\"text1\\\\\\\":\\\\\\\"" . $customerLastName . "\\\\\\\",\\\\\\\"date4\\\\\\\":\\\\\\\"" . $createdAt . "\\\\\\\",\\\\\\\"status\\\\\\\":\\\\\\\"" . $status . "\\\\\\\",\\\\\\\"text0\\\\\\\":\\\\\\\"" . $productName . "\\\\\\\",\\\\\\\"long_text8\\\\\\\":\\\\\\\"" . $regName . "\\\\\\\",\\\\\\\"dup__of_reg_name3\\\\\\\":\\\\\\\"" . $year . "\\\\\\\",\\\\\\\"dup__of_birth_year\\\\\\\":\\\\\\\"" . $color . "\\\\\\\",\\\\\\\"dup__of_color\\\\\\\":\\\\\\\"" . $sex . "\\\\\\\",\\\\\\\"dup__of_reg_name\\\\\\\":\\\\\\\"" . $sire . "\\\\\\\",\\\\\\\"dup__of_sire\\\\\\\":\\\\\\\"" . $dam . "\\\\\\\",\\\\\\\"long_text\\\\\\\":\\\\\\\"" . $eligibleIncentives . "\\\\\\\",\\\\\\\"email\\\\\\\":{\\\\\\\"email\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\"}}\\\") {id}}\"\n}\n";
    }
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.monday.com/v2/",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $query,
        CURLOPT_HTTPHEADER => [
            "API-Version: 2023-10",
            "Authorization: eyJhbGciOiJIUzI1NiJ9.eyJ0aWQiOjMwMzk4ODU0MCwiYWFpIjoxMSwidWlkIjo1MTExNjAwMiwiaWFkIjoiMjAyMy0xMi0yMFQxMToyODoyNS43OTdaIiwicGVyIjoibWU6d3JpdGUiLCJhY3RpZCI6Nzg4ODgyOSwicmduIjoidXNlMSJ9.pC8ks0oxDJDykIGkb7s5-y6KyIKfcArV9PR2kytwTZ8",
            "Content-Type: application/json",
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);


    if ($err) {
        error_log("Error in Monday.com API call", 3, PLUGIN_PATH);
        error_log(print_r($err, 1), 3, PLUGIN_PATH);
        return null;
    } else {
        error_log("Success in Monday.com API call", 3, PLUGIN_PATH);
        error_log(print_r($response, 1), 3, PLUGIN_PATH);
        error_log(print_r(json_decode($response), 1), 3, PLUGIN_PATH);
        return json_decode($response);
    }
}
//Update an item in monday.com using GraphQL
function update_item_in_monday($itemId, $orderData)
{
    $orderId = $orderData['order_number'];
    $customerFirstName = $orderData['customer_first_name'];
    $customerLastName = $orderData['customer_last_name'];
    $customerEmail = $orderData['customer_email'];
    $sire = $orderData['sire'];
    $dam = $orderData['dam'];
    $color = $orderData['color'];
    $sex = $orderData['sex'];
    $regName = $orderData['register_name'];
    $description = $orderData['description'];
    $productName = $orderData['product_name'];
    $eligibleIncentives = $orderData['eligible_incentives'];
    $lifeStage = $orderData['life_stage'];
    $status = $orderData['status'];
    $year = $orderData['year'];
    $createdAt = $orderData['created_at'];
    $listedPrice = $orderData['listed_price'] ?? null;
    $priceListingOption = $orderData['price_listing_option'] ?? null;
    $formId = $orderData['form_id'] ?? null;
    error_log('form Id' . $formId, 3, PLUGIN_PATH);

    if ((int)$formId == 3) {
        error_log('form Id', 3, PLUGIN_PATH);
        $query = "{\n  \"query\": \"mutation {change_multiple_column_values (item_id:" . $itemId . ",board_id: 1158941214, column_values: \\\"{\\\\\\\"dup__of_reg_name7\\\\\\\":\\\\\\\"" . $lifeStage . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerFirstName . "\\\\\\\",\\\\\\\"text1\\\\\\\":\\\\\\\"" . $customerLastName . "\\\\\\\",\\\\\\\"date4\\\\\\\":\\\\\\\"" . $createdAt . "\\\\\\\",\\\\\\\"status\\\\\\\":\\\\\\\"" . $status . "\\\\\\\",\\\\\\\"text0\\\\\\\":\\\\\\\"" . $productName . "\\\\\\\",\\\\\\\"email\\\\\\\":{\\\\\\\"email\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\"}}\\\") {id}}\"\n}\n";
    } else {
        $query = "{\n  \"query\": \"mutation {change_multiple_column_values (item_id:" . $itemId . ",board_id: 1158941214, column_values: \\\"{\\\\\\\"dup__of_reg_name7\\\\\\\":\\\\\\\"" . $lifeStage . "\\\\\\\",\\\\\\\"long_text00\\\\\\\":\\\\\\\"" . $description . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerFirstName . "\\\\\\\",\\\\\\\"text1\\\\\\\":\\\\\\\"" . $customerLastName . "\\\\\\\",\\\\\\\"date4\\\\\\\":\\\\\\\"" . $createdAt . "\\\\\\\",\\\\\\\"status\\\\\\\":\\\\\\\"" . $status . "\\\\\\\",\\\\\\\"text0\\\\\\\":\\\\\\\"" . $productName . "\\\\\\\",\\\\\\\"long_text8\\\\\\\":\\\\\\\"" . $regName . "\\\\\\\",\\\\\\\"dup__of_reg_name3\\\\\\\":\\\\\\\"" . $year . "\\\\\\\",\\\\\\\"dup__of_birth_year\\\\\\\":\\\\\\\"" . $color . "\\\\\\\",\\\\\\\"dup__of_color\\\\\\\":\\\\\\\"" . $sex . "\\\\\\\",\\\\\\\"dup__of_reg_name\\\\\\\":\\\\\\\"" . $sire . "\\\\\\\",\\\\\\\"dup__of_sire\\\\\\\":\\\\\\\"" . $dam . "\\\\\\\",\\\\\\\"long_text\\\\\\\":\\\\\\\"" . $eligibleIncentives . "\\\\\\\",\\\\\\\"email\\\\\\\":{\\\\\\\"email\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\",\\\\\\\"text\\\\\\\":\\\\\\\"" . $customerEmail . "\\\\\\\"}}\\\") {id}}\"\n}\n";
    }


    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.monday.com/v2/",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $query,
        CURLOPT_HTTPHEADER => [
            "API-Version: 2023-10",
            "Authorization: eyJhbGciOiJIUzI1NiJ9.eyJ0aWQiOjMwMzk4ODU0MCwiYWFpIjoxMSwidWlkIjo1MTExNjAwMiwiaWFkIjoiMjAyMy0xMi0yMFQxMToyODoyNS43OTdaIiwicGVyIjoibWU6d3JpdGUiLCJhY3RpZCI6Nzg4ODgyOSwicmduIjoidXNlMSJ9.pC8ks0oxDJDykIGkb7s5-y6KyIKfcArV9PR2kytwTZ8",
            "Content-Type: application/json",
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
        error_log("Error in Monday.com API call", 3, PLUGIN_PATH);
        error_log(print_r($err, 1), 3, PLUGIN_PATH);
        return "cURL Error #:" . $err;
    } else {
        error_log("Success in Monday.com API call", 3, PLUGIN_PATH);
        error_log(print_r($response, 1), 3, PLUGIN_PATH);
        error_log(print_r(json_decode($response), 1), 3, PLUGIN_PATH);
        return json_decode($response, 1);
    }
}

function getOrderItem($order)
{
    if (count($order->get_items()) > 0) {
        return current($order->get_items());
    }
}
// Function to save Monday.com record ID in the database
function save_monday_record_id($entry_id, $order_id, $monday_record_id)
{
    echo $entry_id;
    echo $order_id;
    echo $monday_record_id;
    global $wpdb;

    $table_name = $wpdb->prefix . 'monday_records';

    $wpdb->insert(
        $table_name,
        array(
            'entry_id' => $entry_id,
            'order_id' => $order_id,
            'monday_record_id' => $monday_record_id
        ),
        array(
            '%s',
            '%s',
            '%s'
        )
    );
}
// Function to retrieve Monday.com record ID from the database
function get_monday_record_id($entryId, $orderId)
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'monday_records';

    $result = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT monday_record_id FROM $table_name WHERE order_id = %s AND entry_id = %s",
            $orderId,
            $entryId
        )
    );

    return $result;
}
//get order number againt an entry id from gf_entry_meta table
function get_order_num($entryId)
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'gf_entry_meta';
    $meta_key = 'woocommerce_order_number';

    $result = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value 
        FROM $table_name 
        WHERE entry_id = %d 
        AND meta_key = %s",
            $entryId,
            $meta_key
        )
    );
    return $result;
}

//Extract order entry id
function get_woo_order_entry_id($order)
{
    try {
        // Check if $order is an instance of the expected class or an object
        if (!is_object($order) || !method_exists($order, 'get_data')) {
            throw new Exception('Invalid order object');
        }

        $orderData = $order->get_data();

        if (!is_array($orderData) || !array_key_exists('line_items', $orderData)) {
            throw new Exception('Order data or line items not found in the expected format');
        }

        $lineItems = $orderData['line_items'];

        if (!is_array($lineItems) || empty($lineItems)) {
            throw new Exception('Line items not found or empty in the order data');
        }

        $firstLineItem = current($lineItems);

        if (!is_object($firstLineItem) || !method_exists($firstLineItem, 'get_meta_data')) {
            throw new Exception('Invalid first line item object');
        }

        $metaData = $firstLineItem->get_meta_data();

        if (!is_array($metaData) || empty($metaData)) {
            $firstLineItem = end($lineItems);

            if (!is_object($firstLineItem) || !method_exists($firstLineItem, 'get_meta_data')) {
                throw new Exception('Invalid first line item object');
            }

            $metaData = $firstLineItem->get_meta_data();

            if (!is_array($metaData) || empty($metaData)) {
                throw new Exception('Meta data not found or empty for the first line item');
            }

            $metaValue = current($metaData);

            if (!is_object($metaValue) || !method_exists($metaValue, 'get_data')) {
                throw new Exception('Invalid meta value object');
            }

            return $metaValue->get_data()['value']['_gravity_form_linked_entry_id'] ?? null;
        }

        $metaValue = current($metaData);

        if (!is_object($metaValue) || !method_exists($metaValue, 'get_data')) {
            throw new Exception('Invalid meta value object');
        }

        $linkedEntryId = $metaValue->get_data()['value']['_gravity_form_linked_entry_id'] ?? null;

        return $linkedEntryId;
    } catch (Exception $ex) {
        // Log the exception message for debugging purposes
        error_log("Exception occurred in get_order_entry_id(): " . $ex->getMessage());
        return null;
    }
}

//get renewal order parent id 
function getParentId($orderId)
{
    $subscriptions = wcs_get_subscriptions_for_order($orderId, ['order_type' => 'any']);

    foreach ($subscriptions as $subscriptionID => $subscriptionObj) {

        $parentOrderID = $subscriptionObj->order->get_id();
    }

    return $parentOrderID;
}
