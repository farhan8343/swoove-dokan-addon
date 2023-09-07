<?php

new SwooveBO();

class SwooveBO
{
	public $deliveryURL = 'https://test.swooveapi.com/delivery/create-delivery?app_key=';

	public function __construct()
	{
		add_filter('manage_edit-shop_order_columns', array($this, 'swoove_order_column'), 11);
		add_action('manage_shop_order_posts_custom_column', array($this, 'swoove_status'), 10, 2);
		add_filter('woocommerce_admin_order_actions', array($this, 'add_swoove_order_action_button'), 100, 2);
		add_action('woocommerce_admin_order_actions_end', array($this, 'swoove_dispatch'));
		add_action('admin_head', array($this, 'add_custom_order_status_actions_button_css'));
		add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_swoove_info'));
		add_action('woocommerce_process_shop_order_meta', array($this, 'update_order_meta'));
		add_action('admin_notices', array($this, 'swoove_admin_notices'));
		
	}

	
	public function swoove_order_column($columns)
	{
		if ($_GET['post_status'] != 'trash') {
			$columns['s_order-status'] = __('Swoove Order Status', 'swoove');
		}
		return $columns;
	}

	public function swoove_status($column)
	{
		global $the_order;
		if ($column == 's_order-status')
			echo "<mark class='order-status'><span>" . get_post_meta($the_order->id, 'swoove_delivery_status', true) . "</span></mark>";
	}

	function add_custom_order_status_actions_button_css()
	{
		echo '<style>.wc-action-button-swoove_delivery::after { font-family: woocommerce !important; content: "\e01a" !important; }</style>';
	}

	function add_swoove_order_action_button($actions, $order)
	{
		$swoove_delivery_status = get_post_meta($order->id, 'swoove_delivery_status', true);
		if ($order->has_status(['processing'])) { // && $swoove_delivery_status == 'NOT REQUESTED') {
			$actions['swoove_delivery'] = [
				'url' => wp_nonce_url(admin_url('edit.php?post_type=shop_order&sv_action=delivery&thisOrder=' . $order->id), 'woocommerce-swoove-delivery'),
				'name' => __('Request Delivery', 'woocommerce'),
				'action' => 'swoove_delivery',
			];
		}
		return $actions;
	}

	function swoove_dispatch($order)
	{
		$failed = '0';
		if ($_GET['sv_action'] == 'delivery' && $order->id == $_GET['thisOrder']) {
			$swoove_key = get_option('swoove_key');
			$instructions = get_post_meta($order->id, 'swoove_customer_instructions', true);
			$estimateId = get_post_meta($order->id, 'swoove_estimate_id', true);

			//Customer Data
			$customerLat = get_post_meta($order->id, 'swoove_customer_lat', true);
			$customerLng = get_post_meta($order->id, 'swoove_customer_lng', true);
			$orderObj = wc_get_order($order->id);
			$customerData = $this->getCustomerInfo($orderObj);

			//Store Data
			$contact_person = get_post_meta($order->id, 'contact_person', true);
			$contact_mobile = get_post_meta($order->id, 'contact_mobile', true);
			$contact_email = get_post_meta($order->id, 'contact_mobile', true);
			$store_location_type = get_post_meta($order->id, 'store_location_type', true);
			$store_location_value = get_post_meta($order->id, 'store_location_value', true);
			$store_address = get_post_meta($order->id, 'store_address', true);

			$items = [];
			$order_items = $order->get_items();
			foreach ($order_items as $order_item) {
				$arr = [
					'itemName' => $order_item['name'],
					'itemQuantity' => $order_item['quantity'],
					'itemCost' => $order_item['line_total'],
					'itemWeight' => null,
				];
				$items[] = $arr;
			}

			$options = [
				'method' => 'POST',
				'body' => json_encode([
					"pickup" => [
						"type" => $store_location_type,
						"value" => $store_location_type != 'LATLNG' ? $store_location_value : '',
						"contact" => [
							"name" => $contact_person,
							"mobile" => $contact_mobile,
							"email" => $contact_email
						],
						"country_code" => "GH",
						"lat" => $store_location_type == 'LATLNG' ? trim(substr($store_location_value, 0, strpos($store_location_value, ','))) : null,
						"lng" => $store_location_type == 'LATLNG' ? trim(substr($store_location_value, strpos($store_location_value, ',') + 1)) : null,
						"location" => $store_address,
					],
					"dropoff" => [
						"type" => "LATLNG",
						"value" => "",
						"contact" => [
							"name" => $customerData['full_name'],
							"mobile" => $customerData['mobile'],
						],
						"country_code" => "GH",
						"lat" => $customerLat,
						"lng" => $customerLng,
						"location" => $customerData['address'],
					],
					"items" => $items,
					"instructions" => $instructions,
					"reference" => "$order->id",
					"estimate_id" => $estimateId
				]),
			];

			$est_response = wp_remote_post($this->deliveryURL . $swoove_key, $options);
			$j_res = json_decode(wp_remote_retrieve_body($est_response));
			if ($j_res->success) {
				$delivery_code = $j_res->responses->delivery_code;
				$secret_code = $j_res->responses->secret_code;
				$delivery_status = $j_res->responses->status;
				update_post_meta($order->id, 'swoove_id', $delivery_code);
				update_post_meta($order->id, 'swoove_secret', $secret_code);
				update_post_meta($order->id, 'swoove_delivery_status', $delivery_status);
			} else {
				$failed = $j_res->message;
			}
			$redirect = admin_url('edit.php?post_type=shop_order&swoove_requested=' . $order->id . '&failed=' . $failed);
			wp_safe_redirect($redirect);
			exit;
		}
	}

	function getCustomerInfo($orderObj)
	{
		$customerId = $orderObj->get_customer_id();
		$first_name = '';
		$last_name = '';
		$address = '';
		$phone = '';

		if ($customerId != 0) {
			$first_name = get_user_meta($customerId, 'shipping_first_name', true);
			$last_name = get_user_meta($customerId, 'shipping_first_name', true);
			$address = get_user_meta($customerId, 'shipping_address_1', true);
			$phone = get_user_meta($customerId, 'shipping_phone', true);
		} else {
			$orderData = $orderObj->get_data();
			$first_name = $orderData['billing']['first_name'];
			$last_name = $orderData['billing']['last_name'];
			$address = $orderData['billing']['address_1'];
			$phone = $orderData['billing']['phone'];
		}

		if (empty($first_name) || empty($last_name)) {
			$first_name = get_user_meta($customerId, 'billing_first_name', true);
			$last_name = get_user_meta($customerId, 'billing_first_name', true);
		}
		if (empty($address)) {
			$address = get_user_meta($customerId, 'billing_address_1', true);
		}
		if (empty($phone)) {
			$phone = get_user_meta($customerId, 'billing_phone', true);
		}

		return [
			'full_name' => "$first_name $last_name",
			'address' => $address,
			'mobile' => $phone
		];
	}

	function display_swoove_info($order)
	{
		$sd_code = get_post_meta($order->id, 'swoove_id', true);
		$ss_code = get_post_meta($order->id, 'swoove_secret', true);
		$es_id = get_post_meta($order->id, 'swoove_estimate_id', true);
		$lat = get_post_meta($order->id, 'swoove_customer_lat', true);
		$lng = get_post_meta($order->id, 'swoove_customer_lng', true);
		$status = get_post_meta($order->id, 'swoove_delivery_status', true);
		$track = get_post_meta($order->id, 'swoove_tracking_link', true);
		$address = WC()->order_factory->get_order()->get_billing_address_1();

?>
		<div class="order_data_column" style="width: 100%">
			<img style="display:inline !important" src="<?php echo dirname(__DIR__) ?>/assets/img/swoove-white.png" alt="" width="80px">
			<div class="address">
				<?php 
				if(!empty($ss_code)):
				//echo dirname(__DIR__);
				echo __('<div><b>' . __('Delivery Code', 'swoove') . ': </b>' . (empty($sd_code) ? 'Not Created' : $sd_code) . '</div>');
				echo __('<div><b>' . __('Secret Code', 'swoove') . ': </b>' . (empty($ss_code) ? 'Not Created' : $ss_code) . '</div>');
				echo __('<div><b>' . __('Estimate', 'swoove') . ': </b>' . $es_id . '</div>');
				echo __('<div><b>' . __('Status', 'swoove') . ': </b>' . $status . '</div>');
				echo __('<div><b>' . __('DropOff', 'swoove') . ': </b>' . $address . '</div>');
				if (!empty($track))
					echo __('<a href="' . $track . '"  target="_blank" class="button"> Track Delivery
						<span class="dashicons dashicons-external" style="font-size: 17px;margin-top: 4px;"></span></a>');
				endif;
				?>
			</div>
			<div class="edit_address">
				<?php
				woocommerce_wp_text_input(array(
					'id' => 'cust_lat',
					'label' => 'Customer Lat:',
					'value' => $lat,
					'wrapper_class' => 'form-field-wide'
				));
				woocommerce_wp_text_input(array(
					'id' => 'cust_lng',
					'label' => 'Customer Lng:',
					'value' => $lng,
					'wrapper_class' => 'form-field-wide'
				));
				?>
			</div>
		</div>
<?php
	}

	function update_order_meta($order_id)
	{
		update_post_meta($order_id, 'swoove_customer_lat', wc_sanitize_textarea($_POST['swoove_customer_lat']));
		update_post_meta($order_id, 'swoove_customer_lng', wc_sanitize_textarea($_POST['swoove_customer_lng']));
	}

	function swoove_admin_notices()
	{
		if (isset($_GET['post_type']) && 'shop_order' == $_GET['post_type']) {
			if (isset($_GET['failed'])) {
				if ('0' == $_GET['failed'])
					echo esc_html('<div class="notice notice-success is-dismissible"><p>' . __('Delivery for Order', 'swoove') . '#' . $_GET['swoove_requested'] . __(' was requested successfully. ', 'swoove') . '</p></div>');
				else
					echo esc_html('<div class="notice error is-dismissible"><p>' . __('Failed to request delivery for Order', 'swoove') . '#' . $_GET['swoove_requested'] . $_GET['failed'] . '</p></div>');
			}
		}
	}
}
