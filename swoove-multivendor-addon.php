<?php
 
/**
 * Plugin Name: Swoove Multivendor Addon
 * Plugin URI: https://thetechproviders.com
 * Description: Custom Shipping Method for WooCommerce
 * Version: 1.0.0
 * Author: Farhan Ahmed
 * Author URI: http://www.thetechproviders.com
 * License: GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Domain Path: /lang
 * Text Domain: swooveshipping
 */
 
if ( ! defined( 'WPINC' ) ) {
 
    die;
 
}
if($_GET['debug'] == 'yes'){
    session_start();
    //echo '<pre>'; print_r($_SESSION); echo '</pre>';
    //echo '<pre>';print_r(get_post_meta(22667,'farhan'));echo '</pre>'; 
   // update_post_meta(22667,'farhan','fasdfasdf');
} 

 
/*
 * Check if WooCommerce is active
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    
    include('swoove-delivery.php');

    function swoove_shipping_method() {
        if ( ! class_exists( 'SwooveShippingMethod' ) ) {
            class SwooveShippingMethod extends WC_Shipping_Method {
                /**
                 * Constructor for your shipping class
                 *
                 * @access public
                 * @return void
                 */

                public $cancelURL = 'https://live.swooveapi.com/delivery/cancel-delivery';
                public $deliveryURL = 'https://live.swooveapi.com/delivery/create-delivery?app_key=';
                public $baseURL = 'https://live.swooveapi.com/estimates/create-estimate?platform=swoove_multivendor_addon&app_key=';
                public function __construct() {
                //    echo 'testing response'; $this->print(get_post_meta(22667,'farhan',true)); die();
                    $this->id                 = 'swooveshipping'; 
                    $this->method_title       = __( 'Swoove Shipping', 'swooveshipping' );  
                    $this->method_description = __( 'Custom Shipping Method for Swoove', 'swooveshipping' ); 
 
                    // Availability & Countries
                    $this->availability = 'including';
                    $this->countries = array('GH');
 
                    $this->init();
 
                    $this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
                    $this->title = isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Swoove Shipping', 'swooveshipping' );
                    $this->testmode = isset( $this->settings['testmode'] ) ? $this->settings['testmode'] : 'no';
                    $this->testapi = isset( $this->settings['testapi'] ) ? $this->settings['testapi'] : '';
                    $this->liveapi = isset( $this->settings['liveapi'] ) ? $this->settings['liveapi'] : '';

                }
				
				public function cancel_the_order($order_id){
					$order = wc_get_order($order_id);
					$seller = dokan_get_seller_id_by_order( $order_id );
					$store_info  = dokan_get_store_info($seller); 
					
					$swoove_key = $this->testmode == 'yes' ? $this->testapi : $this->liveapi;
					if ($this->testmode == 'yes') { 
                        $url = 'https://test.swooveapi.com/delivery/cancel-delivery?app_key=';
                    }else{
                        $url = $this->cancelURL;
                    }
					$delivery_code = get_post_meta($order_id, 'swoove_id', true);
					
					$url = $url . $swoove_key;
					$options = json_encode([
						'delivery_code' => $delivery_code,
						'phone_number' => $store_info['phone']
					]);
					
					$curl = curl_init();
					curl_setopt_array($curl, array(
					  CURLOPT_URL => $url,
					  CURLOPT_RETURNTRANSFER => true,
					  CURLOPT_ENCODING => '',
					  CURLOPT_MAXREDIRS => 10,
					  CURLOPT_TIMEOUT => 0,
					  CURLOPT_FOLLOWLOCATION => true,
					  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
					  CURLOPT_CUSTOMREQUEST => 'PUT',
					  CURLOPT_POSTFIELDS =>$options,
					  CURLOPT_HTTPHEADER => array(
						'Content-Type: text/plain'
					  ),
					));

					$response = curl_exec($curl);
                    
					curl_close($curl);
				 	$j_res = json_decode($response); 
                    update_post_meta($order_id,'order_cancel_requested',true); echo 'ga';
					wp_send_json_success(array('message' => 'Request sent, vendor will contact you soon.'));
						
				}
 
                /**
                 * Init your settings
                 *
                 * @access public
                 * @return void
                 */
                function init() {
                    // Load the settings API
                    $this->init_form_fields(); 
                    $this->init_settings(); 
 
                    // Save settings in admin if you have any defined
                    add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
                    add_action( 'dokan_store_profile_saved', array($this , 'save_swoove_store_digital_address'), 15 );
                    add_action('woocommerce_api_'.strtolower(get_class($this)), array($this, 'webhook'));
                    add_action('woocommerce_view_order', array($this, 'customer_order'), 20);
                    //add_action('woocommerce_thankyou',array($this,'swoove_create_delivery'),9);
                }


                // Customer View Order details.
                public function customer_order($order_id)
                {
                    echo esc_html("<h2 class='woocommerce-column__title' style='margin-bottom: 0'>Delivery</h2>");

                    $sd_code = get_post_meta($order_id, 'swoove_id', true);
                    $status = get_post_meta($order_id, 'swoove_delivery_status', true);
                    $track = get_post_meta($order_id, 'swoove_tracking_link', true);
                    $address = wc_get_order($order_id)->get_billing_address_1();

                    echo esc_html('<p style="margin-bottom: 0"><b>' . __('Delivery Code', 'swoove') . ': </b>' . (empty($sd_code) ? 'Not Created' : $sd_code) . '</p>');
                    echo esc_html('<p style="margin-bottom: 0"><b>' . __('Status', 'swoove') . ': </b>' . $status . '</p>');
                    echo esc_html('<p><b>' . __('DropOff', 'swoove') . ': </b>' . $address . '</p>');
                    if (!empty($track))
                        echo esc_html('<a href="' . $track . '"  target="_blank" class="button">
                                    Track Delivery
                                    <span class="dashicons dashicons-external" style="font-size: 17px;margin-top: 4px;"></span></a>');
                }
 
                // Webhook to save the response from the Swoove
                public function webhook() 
                { 
                    $data = file_get_contents('php://input');
                    $j_res = json_decode($data, true);
                    update_post_meta(22667,'farhan',$j_res);
                    if ($j_res['success']) {
                        $responses = $j_res['responses'];
                        $delivery_status = $responses['status'];
                        $orderId = $responses['reference'];
                        $tracking_link = isset($responses['tracking_link']) ? $responses['tracking_link'] : '';
                        update_post_meta($orderId, 'swoove_delivery_status', sanitize_text_field($delivery_status));
                        update_post_meta($orderId, 'swoove_tracking_link', sanitize_text_field($tracking_link));
                    }
                    exit();
                }


                public function swoove_create_delivery($order_id) 
                {  //echo '<pre>'; print_r(WC()->session->get( 'chosen_shipping_methods')); echo '</pre>';
                    if( $this->enabled != 'yes'){
                        return;
                    }                 
                    $order = wc_get_order($order_id); 
                    if(get_post_meta($order->id, 'swoove_id', true) != ''){
                        return;
                    }
                    $swoove_key = $this->testmode == 'yes' ? $this->testapi : $this->liveapi;
                    
                    if ($this->testmode == 'yes') { 
                        $url = 'https://test.swooveapi.com/delivery/create-delivery?app_key=';
                    }else{
                        $url = $this->deliveryURL;
                    }

                    //$custy = $order->get_address('billing'); echo'gaga';

                    $customer_digital_address = get_post_meta($order->id,'_billing_digital_address', true); 
                    $response = $this->convert_digital_address($customer_digital_address);
                    if(empty($response->data->Table)){
                        return;
                    }

                    $customerLat = $response->data->Table[0]->CenterLatitude;
                    $customerLng = $response->data->Table[0]->CenterLongitude;
                    $billing_details = $order->get_address('billing'); 

                    $customer_name = $billing_details['first_name']. " " . $billing_details['last_name'];
                    $customer_address = $billing_details['address_1'] ;
                    if(!empty($billing_details['address_2'])) $customer_address .= "," . $billing_details['address_2'] ;
                    if(!empty($billing_details['city'])) $customer_address .= "," . $billing_details['city'];
                    $customer_country = WC()->countries->countries[$billing_details['country']];
                    if(!empty($customer_country)) $customer_address .= "," . $customer_country;


                    $seller = dokan_get_seller_id_by_order( $order_id ); 
                    
                    if($seller == 0){
                        $sub_orders = dokan_get_suborder_ids_by($order_id);
                        $counter = 0;
                        foreach($sub_orders as $sub_order) {
                            $seller = dokan_get_seller_id_by_order( $sub_order->ID ); 
                            $order = wc_get_order($sub_order->ID);

                            $store_info  = dokan_get_store_info($seller); 
                            $user_info = get_userdata($seller); //echo $sub_order->ID;
                            
                            $estimate_id = WC()->session->get( 'chosen_shipping_methods')[$counter];
                            $counter++;



                            //die();
                            if(empty($store_info['digital_address_latitude']) || empty($store_info['digital_address_longitude'])){
                                return;
                            }
                            
                            $storelat = $store_info['digital_address_latitude'];
                            $storelan = $store_info['digital_address_longitude'];
                        
                            $storeaddress = $store_info['address']['street_1'] ;
                            if(!empty($store_info['address']['street_2'])) $storeaddress .= "," . $store_info['address']['street_2'] ;
                            if(!empty($store_info['address']['city'])) $storeaddress .= "," . $store_info['address']['city'];
                            $storecountry = WC()->countries->countries[$store_info['address']['country']];
                            if(!empty($storecountry)) $storeaddress .= "," . $storecountry;


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
                                        "type" => 'LATLNG',
                                        "value" => '',
                                        "contact" => [
                                            "name" => $store_info['store_name'],
                                            "mobile" => $store_info['phone'],
                                            "email" => $user_info->user_email
                                        ],
                                        "country_code" => "GH",
                                        "lat" => $storelat,
                                        "lng" => $storelan,
                                        "location" => $storeaddress,
                                    ],
                                    "dropoff" => [
                                        "type" => "LATLNG",
                                        "value" => "",
                                        "contact" => [
                                            "name" => $customer_name,
                                            "mobile" => $billing_details['phone'],
                                            "email" => $billing_details['email'],
                                        ],
                                        "country_code" => "GH",
                                        "lat" => $customerLat,
                                        "lng" => $customerLng,
                                        "location" => $customer_address,
                                    ],
                                    "items" => $items,
                                    "instructions" => '',
                                    "reference" => "$sub_order->ID",
                                    "estimate_id" => $estimate_id
                                ]),
                            ];
    						update_post_meta($order->id,'swoove_request_sent',$options);
                            update_post_meta($order->id, 'swoove_delivery_status', 'not-created');
                            // $est_response = wp_remote_post($url . $swoove_key, $options);
                            // $j_res = json_decode(wp_remote_retrieve_body($est_response)); 
                            // // echo '<pre>'; print_r($options); echo '</pre>';
                            // // echo '<pre>'; print_r($j_res); echo '</pre>';
                            // // echo $url;
                            // // echo $swoove_key;
                            // // $this->print( $j_res ); die();
                            // if ($j_res->success) {
                            //     $delivery_code = $j_res->responses->delivery_code;
                            //     $secret_code = $j_res->responses->secret_code;
                            //     $delivery_status = $j_res->responses->status;
                            //     update_post_meta($order->id, 'swoove_estimate_id', $estimate_id);
                            //     update_post_meta($order->id, 'swoove_id', $delivery_code);
                            //     update_post_meta($order->id, 'swoove_secret', $secret_code);
                            //     update_post_meta($order->id, 'swoove_delivery_status', $delivery_status);
                            // } else {
                            //     $failed = $j_res->message;
                            // }



                        }
                    }else{

                        $store_info  = dokan_get_store_info($seller); 
                        $user_info = get_userdata($seller);
                        if(empty($store_info['digital_address_latitude']) || empty($store_info['digital_address_longitude'])){
                            return;
                        }
                        $shipping = current($order->get_shipping_methods());
                        // echo '<pre>'; print_r($shipping); echo '</pre>';
                        if(!empty($shipping) && $shipping->get_method_id() != 'swooveshipping' ) {
                            return;
                        }


                        $storelat = $store_info['digital_address_latitude'];
                        $storelan = $store_info['digital_address_longitude'];
                    
                        $storeaddress = $store_info['address']['street_1'] ;
                        if(!empty($store_info['address']['street_2'])) $storeaddress .= "," . $store_info['address']['street_2'] ;
                        if(!empty($store_info['address']['city'])) $storeaddress .= "," . $store_info['address']['city'];
                        $storecountry = WC()->countries->countries[$store_info['address']['country']];
                        if(!empty($storecountry)) $storeaddress .= "," . $storecountry;
                   
                         $estimate_id = WC()->session->get( 'chosen_shipping_methods')[0];
                         //$this->print(WC()->session->get( 'chosen_shipping_methods')); die();
                        

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
                                    "type" => 'LATLNG',
                                    "value" => '',
                                    "contact" => [
                                        "name" => $store_info['store_name'],
                                        "mobile" => $store_info['phone'],
                                        "email" => $user_info->user_email
                                    ],
                                    "country_code" => "GH",
                                    "lat" => $storelat,
                                    "lng" => $storelan,
                                    "location" => $storeaddress,
                                ],
                                "dropoff" => [
                                    "type" => "LATLNG",
                                    "value" => "",
                                    "contact" => [
                                        "name" => $customer_name,
                                        "mobile" => $billing_details['phone'],
                                        "email" => $billing_details['email'],
                                    ],
                                    "country_code" => "GH",
                                    "lat" => $customerLat,
                                    "lng" => $customerLng,
                                    "location" => $customer_address,
                                ],
                                "items" => $items,
                                "instructions" => '',
                                "reference" => "$order->id",
                                "estimate_id" => $estimate_id
                            ]),
                        ];
						update_post_meta($order->id,'swoove_request_sent',$options);
                        update_post_meta($order->id, 'swoove_delivery_status', 'not-created');
                    //     $est_response = wp_remote_post($url . $swoove_key, $options);
                    //     $j_res = json_decode(wp_remote_retrieve_body($est_response)); 
                        
                    //    // $this->print( $j_res );echo 'fa'; die();
                    //     // echo $url;
                    //     // echo $swoove_key;
                    //     // $this->print( $j_res ); die();
                    //     if ($j_res->success) {
                    //         $delivery_code = $j_res->responses->delivery_code;
                    //         $secret_code = $j_res->responses->secret_code;
                    //         $delivery_status = $j_res->responses->status;
                    //         update_post_meta($order->id, 'swoove_estimate_id', $estimate_id);
                    //         update_post_meta($order->id, 'swoove_id', $delivery_code);
                    //         update_post_meta($order->id, 'swoove_secret', $secret_code);
                    //         update_post_meta($order->id, 'swoove_delivery_status', $delivery_status);
                    //     } else {
                    //         $failed = $j_res->message;
                    //     } //echo '<pre>'; print_r($j_res); echo '</pre>';
                        $redirect = admin_url('edit.php?post_type=shop_order&swoove_requested=' . $order->id . '&failed=' . $failed);
                    //  wp_safe_redirect($redirect);
                    }
                    return;
                    
                }

				public function recreate_shipping_delivery($order){
					$swoove_key = $this->testmode == 'yes' ? $this->testapi : $this->liveapi;
                    
                    if ($this->testmode == 'yes') { 
                        $url = 'https://test.swooveapi.com/delivery/create-delivery?app_key=';
                    }else{
                        $url = $this->deliveryURL;
                    }

					$options = get_post_meta($order->id,'swoove_request_sent',true);
					$est_response = wp_remote_post($url . $swoove_key, $options);
					
                    $j_res = json_decode(wp_remote_retrieve_body($est_response)); 
					$this->print($options);
					$this->print($j_res);
                    
                    die();
                    // echo '<pre>'; print_r($options); echo '</pre>';
					// echo '<pre>'; print_r($j_res); echo '</pre>';
					// echo $url;
					// echo $swoove_key;
					// $this->print( $j_res ); die();
					if ($j_res->success) {
						$delivery_code = $j_res->responses->delivery_code;
						$secret_code = $j_res->responses->secret_code;
						$delivery_status = $j_res->responses->status;
						//update_post_meta($order->id, 'swoove_estimate_id', $estimate_id);
						update_post_meta($order->id, 'swoove_id', $delivery_code);
						update_post_meta($order->id, 'swoove_secret', $secret_code);
						update_post_meta($order->id, 'swoove_delivery_status', $delivery_status);
                        wp_send_json_success(array('message' => 'Shipping Created'));
					} else {
                        wp_send_json_error(array('Something is went wrong please contact side admin to resolve this issue or try again in few minutes.'));
						$failed = $j_res->message;
					}
				}

                /**
                 * Save your store settings
                 *
                 * @access public
                 * @return void
                 */
                public function save_swoove_store_digital_address( $store_id ) {
                    $dokan_settings = dokan_get_store_info($store_id);
                    if ( isset( $_POST['digital_address'] ) ) {
                        $dokan_settings['digital_address'] = $_POST['digital_address'];
                        $response = $this->convert_digital_address($_POST['digital_address']);
                        if(empty($response->data->Table)){
                            return;
                        }
                        $dokan_settings['digital_address_latitude'] = $response->data->Table[0]->CenterLatitude;
                        $dokan_settings['digital_address_longitude'] = $response->data->Table[0]->CenterLongitude;
                    }
                    update_user_meta( $store_id, 'dokan_profile_settings', $dokan_settings );
                }




                /**
                 * Define settings field for this shipping
                 * @return void 
                 */
                function init_form_fields() { 
 
                    $this->form_fields = array(
 
                     'enabled' => array(
                          'title' => __( 'Enable', 'swooveshipping' ),
                          'type' => 'checkbox',
                          'description' => __( 'Enable this shipping.', 'swooveshipping' ),
                          'default' => 'yes'
                          ),
                     
                     'title' => array(
                        'title' => __( 'Title', 'swooveshipping' ),
                          'type' => 'text',
                          'description' => __( 'Title to be display on site', 'swooveshipping' ),
                          'default' => __( 'Swoove Shipping', 'swooveshipping' )
                          ),
                     'testmode' => array(
                        'title' => __( 'Test Mode', 'swooveshipping' ),
                        'type' => 'checkbox',
                        'description' => __( 'Enable Test Mode.', 'swooveshipping' ),
                        'default' => 'no'
                        ),
                    'testapi' => array(
                        'title' => __( 'Test API Key', 'swooveshipping' ),
                            'type' => 'text',
                            'description' => __( 'Enter test api credentionals. Will only work when test mode is activated', 'swooveshipping' ),
                            'default' => __( '', 'swooveshipping' )
                            ),
                    'liveapi' => array(
                        'title' => __( 'Live API Key', 'swooveshipping' ),
                            'type' => 'text',
                            'description' => __( 'Enter live api key. Will not used when test mode activated.', 'swooveshipping' ),
                            'default' => __( '', 'swooveshipping' )
                            )
                    
 
                     );
 
                }
 
                /**
                 * This function is used to calculate the shipping cost. Within this function we can check for weights, dimensions and other parameters.
                 *
                 * @access public
                 * @param mixed $package
                 * @return void
                 */
                public function print($object){
                    echo '<pre>'; print_r($object); echo '</pre>'; 
                }

                // Function to convert digital address into latitude and longitude
                public function convert_digital_address($address){
                    $curl = curl_init();

                    curl_setopt_array($curl, array(
                    CURLOPT_URL => "https://ghanapostgps.sperixlabs.org/get-location",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => "POST",
                    CURLOPT_POSTFIELDS => "address=".$address,
                    CURLOPT_HTTPHEADER => array(
                        "Content-Type: application/x-www-form-urlencoded"
                    ),
                    ));

                    $response = curl_exec($curl);

                    curl_close($curl);
                    //$this->print($response);
                    $response = json_decode($response);
                    return $response;
                }


                public function calculate_shipping( $package ) { session_start(); //echo '<pre>'; print_r($_POST); echo "</pre>";
                   // if($_GET['debug'] == 'yes'){
                       // echo '<pre>'; print_r(WC()->session->get( 'chosen_shipping_methods')); echo '</pre>';
                       // update_post_meta(22667,'farhan','fasdfasdf');
                    //} 
                    
                    if( $this->enabled != 'yes'){
                        return;
                    }
                    $custy = WC()->cart->get_customer(); 
                    $digital_address = WC()->session->get('billing_digital_address');

                    if(empty($digital_address['billing'])){
                        return;
                    }
                    
                    $digital_address = $digital_address['billing'];
                    
                    

                    $response = $this->convert_digital_address($digital_address);
                    if(empty($response->data->Table)){
                        return;
                    }
                   
                    $customerLat = $response->data->Table[0]->CenterLatitude;
                    $customerLng = $response->data->Table[0]->CenterLongitude;
                    
                    if(empty($package['seller_id'])){
                       return;
                    }
                    $store_info  = dokan_get_store_info( $package['seller_id'] ); 
                    $user_info = get_userdata( $package['seller_id'] );
                    if(empty($store_info['digital_address_latitude']) || empty($store_info['digital_address_longitude'])){
                        return;
                    }
                    
                    $storelat = $store_info['digital_address_latitude'];
                    $storelan = $store_info['digital_address_longitude'];
                   
                    
                    $storeaddress = $store_info['address']['street_1'] ;
                    if(!empty($store_info['address']['street_2'])) $storeaddress .= "," . $store_info['address']['street_2'] ;
                    if(!empty($store_info['address']['city'])) $storeaddress .= "," . $store_info['address']['city'];
                    $storecountry = WC()->countries->countries[$store_info['address']['country']];
                    if(!empty($storecountry)) $storeaddress .= "," . $storecountry;
                   // echo $storelat . '   ' . $storelan . '   ' . $storeaddress;
                  
                    
                   
                    $swoove_key = $this->testmode == 'yes' ? $this->testapi : $this->liveapi;
            
                    if ($this->testmode == 'yes') $this->baseURL = 'https://test.swooveapi.com/estimates/create-estimate?platform=swoove_multivendor_addon&app_key=';

                    // $customerLat = '5.6221056';
                    // $customerLng = '-0.1778348';
                    $customerName = empty($custy->get_display_name()) ? sanitize_text_field($_COOKIE['swoove_customer_name']) : $custy->get_display_name();
                    $customerMobile = empty($custy->get_shipping_phone()) ? $custy->get_billing_phone() : $custy->get_shipping_phone();
                    if (empty($customerMobile)) $customerMobile = sanitize_text_field($_COOKIE['swoove_customer_mobile']);
                    $customerEmail = $custy->get_billing_email(); 
                   
                  // echo $customerName .'<br>'. $swoove_key . '<br>' . $customerLat . '<br>' . $customerLng . '<br>' . $customerEmail . '<br>' . $customerMobile . '<br>' . $storelat . '<br>' . $storelan . '<br>' . $user_info->user_email . '<br>' . $store_info["phone"]; die();
                   
                   if (empty($swoove_key) || empty($customerLat) || empty($customerLng) || empty($customerMobile) || empty($customerEmail) || empty($storelat) || empty($storelan) || empty($user_info->user_email) || empty($store_info['phone'])) {
                         return;
                    }  
                          
                    $itemLines = $package['contents'];
                    $items = [];
                    foreach ($itemLines as $itemLine => $values) {
                        $arr = [
                            'itemName' => $values['data']->get_name(),
                            'itemQuantity' => $values['quantity'],
                            'itemCost' => $values['line_total'],
                        ];
                        $items[] = $arr;
                    }
                    $options = [
                        'method' => 'POST',
                        'body' => json_encode([
                            "pickup" => [
                                "type" => "LATLNG",
                                "value" => "",
                                "contact" => [
                                    "name" => $store_info['store_name'],
                                    "mobile" => $store_info['phone'],
                                    "email" => $user_info->user_email
                                ],
                                "country_code" => "GH",
                                "lat" => $storelat,
                                "lng" => $storelan,
                                "location" => $storeaddress,
                            ],
                            "dropoff" => [
                                "type" => "LATLNG",
                                "value" => "",
                                "contact" => [
                                    "name" => $customerName,
                                    "mobile" => $customerMobile,
                                    "email" => $customerEmail,
                                ],
                                "country_code" => "GH",
                                "lat" => $customerLat,
                                "lng" => $customerLng,
                                "location" => $custy->get_shipping_address_1() . ' ' . $custy->get_shipping_address_2(),
                            ],
                            "items" => $items,
                        ]),
                    ];
                        //$this->print($options);die();
                      //  echo json_encode($options['body']); die();
                        $raw_rates = str_replace('\\', '', sanitize_text_field($_COOKIE['rates']));
                        //$stored_rates = json_decode($raw_rates, true); //echo '<pre>'; print_r($stored_rates); echo '</pre>'; echo $package['seller_id']; 
                        $stored_rates = $_SESSION['rates'];
                    if (empty($stored_rates[$package['seller_id']])) { 
                        $est_response = wp_remote_post($this->baseURL . $swoove_key, $options);  //echo '<pre>'; print_r($est_response); echo '</pre>'; die();
                       
                        if (!is_wp_error($est_response)) {
                            $j_res = json_decode(wp_remote_retrieve_body($est_response)); //$this->print($j_res ); die();
                            if ($j_res->success) {
                                $estimates = $j_res->responses->estimates;
                               
                                //prevent further calls and save response
                               // wc_setcookie('marker_shifted', false);
                                if(empty($stored_rates)){
                                    $rates = array();                               
                                }else{
                                    $rates = $stored_rates;                             
                                }
                                $rates[$package['seller_id']] = array();
                                foreach ($estimates as $estimate) {
                                    $rate = [
                                        'id' => $estimate->estimate_id,
                                        'label' => 'Swoove (' . $estimate->agency_details->name . ')',
                                        'cost' => $estimate->full_price,
                                        'calc_tax' => 'per_item',
                                        'meta_data' => array('instance_id' => $estimate->estimate_id),
                                    ];
                                    $rates[$package['seller_id']][] = $rate;
                                    $this->add_rate($rate);
                                }
                                $_SESSION['rates'] = $rates;
                                //wc_setcookie('rates', json_encode($rates)); //$this->print(json_encode($rates)); die();
                            } else {
                                // if (!wc_has_notice($j_res->message, 'error'))
                                //     wc_add_notice($j_res->message, 'error');

                                // $this->add_rate([
                                //     'id' => 7,
                                //     'label' => 'Fill all necessary billing/shipping details to display estimates',
                                //     'cost' => 0,
                                //     'calc_tax' => 'per_item'
                                // ]);
                            }
                        }
                    } else {
                        if (!empty($_SESSION['rates']) && !is_cart()) {
                            $raw_rates = str_replace('\\', '', sanitize_text_field($_COOKIE['rates']));
                            //$stored_rates = json_decode($raw_rates, true);
                            $stored_rates = $_SESSION['rates'];
                            if(!empty($stored_rates[$package['seller_id']])){
                                foreach ($stored_rates[$package['seller_id']] as $rate) {
                                    $this->add_rate($rate);
                                }
                            }
                           
                            //$this->print($_COOKIE['rates']);die();
                        }
                    }
                   
                }
            }
        }
		if($_GET['debug']){
			$Swoove_Shipping_Method = new SwooveShippingMethod();
			$Swoove_Shipping_Method->cancel_the_order(22862);

		}
		
		
		
		
    }
 



    add_action( 'woocommerce_shipping_init', 'swoove_shipping_method' );
    add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
        $methods[] = 'SwooveShippingMethod';
        return $methods;
    });
 
    
    add_action( 'woocommerce_review_order_before_cart_contents', 'swoove_validate_order' , 10 );
    add_action( 'woocommerce_after_checkout_validation', 'swoove_validate_order' , 10 );

    function swoove_validate_order( $posted )   {
        $packages = WC()->shipping->get_packages();
        $chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
        if( is_array( $chosen_methods ) && in_array( 'swoove', $chosen_methods ) ) { 
            foreach ( $packages as $i => $package ) {
                if ( $chosen_methods[ $i ] != "swoove" ) {                          
                    continue;                      
                }
 
                $Swoove_Shipping_Method = new SwooveShippingMethod();
                foreach ( $package['contents'] as $item_id => $values ) 
                { 
                    //$_product = $values['data']; 
                    //$weight = $weight + $_product->get_weight() * $values['quantity']; 
                }
 
                //$weight = wc_get_weight( $weight, 'kg' );   
                // if( $weight > $weightLimit ) {
                //         $message = sprintf( __( 'Sorry, %d kg exceeds the maximum weight of %d kg for %s', 'swooveshipping' ), $weight, $weightLimit, $Swoove_Shipping_Method->title );
                //         $messageType = "error";
                //         if( ! wc_has_notice( $message, $messageType ) ) {
                //             wc_add_notice( $message, $messageType );
                //         }
                // }
            }       
        } 
    }




    add_action( 'wp_footer', 'checkout_send_digital_address_via_ajax_js',99 );
    function checkout_send_digital_address_via_ajax_js() {
        ?>
        <!-- <script>
        jQuery(document.body).on('updated_checkout', function() {
        //alert('updated_checkout');});
        </script> -->
        <?php
        if ( is_cart() || is_checkout() ) :
        ?><script type="text/javascript"> 
        jQuery( function($){
            
            // Function that send the Ajax request
            function sendAjaxRequest( value, fieldset = 'billing' ) {
                $.ajax({
                    type: 'POST',
                    url: '<?= admin_url( 'admin-ajax.php' ) ?>',
                    data: {
                        'action': 'digital_address',
                        'digital_address': value,
                        'fieldset' : fieldset
                    },
                    success: function (result) { 
                        $(document.body).trigger('update_checkout'); // Update checkout processes
                        console.log( result ); // For testing (output data sent)
                    }
                });
            }

            // Billing fias code change & input events
            $(document.body).on( 'change', 'input[name=billing_digital_address]', function() { 
                sendAjaxRequest( $(this).val() );
            });

            // Shipping fias code change & input events
            // $(document.body).on( 'change input', 'input[name=shipping_digital_address]', function() {
            //     sendAjaxRequest( $(this).val(), 'shipping' );
            // });
        });
        <?php if(is_cart()) :?>
              if(jQuery("select#calc_shipping_country").val() == 'GH'){
                jQuery("#calc_shipping_digital_address_field").show();
                jQuery("#calc_shipping_email_field").show();
                jQuery("#calc_shipping_phone_field").show();
              }  
              jQuery("body").on("change","select#calc_shipping_country",function(){
                if(jQuery(this).val() == "GH"){
                    jQuery("#calc_shipping_digital_address_field").show();
                    jQuery("#calc_shipping_email_field").show();
                    jQuery("#calc_shipping_phone_field").show();
                }else{
                    jQuery("#calc_shipping_digital_address_field").hide();
                    jQuery("#calc_shipping_email_field").hide();
                    jQuery("#calc_shipping_phone_field").hide();
                }
              });
        <?php endif; ?>
        </script>
        <?php
        endif;
    }




    // The Wordpress Ajax PHP receiver (set data to a WC_Session variable)
    add_action( 'wp_ajax_digital_address', 'set_digital_address_to_wc_session' );
    add_action( 'wp_ajax_nopriv_digital_address', 'set_digital_address_to_wc_session' );
    function set_digital_address_to_wc_session() { session_start();
        $field_key = 'digital_address';
        if ( isset($_POST[$field_key]) && isset($_POST['fieldset']) ){
            $values = (array) WC()->session->get($field_key);
            if( ! empty($values) ) {
                $values = array(
                    'billing' => WC()->customer->get_meta('billing_'.$field_key)
                );
            }

            $fieldset  = $_POST['fieldset'];
            $digital_address = $_POST[$field_key];

            $values[$fieldset] = $digital_address;
            WC()->session->set('billing_'.$field_key, wc_clean($values));
            wc_setcookie('rates', ' ');
            unset($_SESSION['rates']);
            echo json_encode(array($fieldset.'_'.$field_key => $digital_address));
            exit(); 
        }
    }
 


    /*
    *
    *   Validation of Digital Address Field at checkout 
    *
    */
    add_action('woocommerce_after_checkout_validation', 'swoove_after_checkout_validation');
    function swoove_after_checkout_validation( $posted ) {
        if($posted['billing_country'] == 'GH' && empty($posted['billing_digital_address'])){
            wc_add_notice( __( "Digital Address is a required for Ghana customers.", 'woocommerce' ), 'error' );
        }
    }
   



    /*
    *
    *Extra field on the seller settings and show the value on the store banner -Dokan
    *Add extra field in seller settings
    */
    add_filter( 'dokan_settings_form_bottom', function( $current_user, $profile_info ){
        $digital_address= isset( $profile_info['digital_address'] ) ? $profile_info['digital_address'] : ''; ?>
        <div class="gregcustom dokan-form-group">
            <label class="dokan-w3 dokan-control-label" for="setting_address">
                <?php _e( 'Digital Address (required for ghana vendors)', 'dokan' ); ?>
            </label>
            <div class="dokan-w5">
                <input type="text" class="dokan-form-control input-md valid" name="digital_address" id="reg_digital_address" value="<?php echo $digital_address; ?>" />
                <span id="dokan_digital_address-error" style="display:none;text-align:left;" class="error">This field is required</span>
                <style>span#dokan_digital_address-error {
                    display: block;
                }</style>
            </div>
        </div>
        <?php
    }, 9, 2);



    /*
    *
    *   Javascript Code for Dokan Setting page. 
    *
    */
    add_action('wp_footer',function(){
        ?>
        <script>
            jQuery('[name="dokan_update_store_settings"]').click(function(e){
                if(jQuery('[name="digital_address"]').val() == '' && jQuery('[name="dokan_address[country]"]').val() == "GH"){
                    e.preventDefault();
                    jQuery("#dokan_digital_address-error").show();
                    jQuery('html, body').animate({ scrollTop: jQuery('#dokan_digital_address-error').offset().top - 200}, 'slow');
                }
                
            });
        </script>
        <?php
    });


    add_action('woocommerce_checkout_update_order_review', 'checkout_update_refresh_shipping_methods', 9, 1);
    function checkout_update_refresh_shipping_methods( $post_data ) {
        $packages = WC()->cart->get_shipping_packages();
        foreach ($packages as $package_key => $package ) {
             WC()->session->set( 'shipping_for_package_' . $package_key, false ); // Or true
        }
    }


    /**
     * Filter the cart template path to use cart.php in this plugin instead of the one in WooCommerce.
     *
     * @param string $template      Default template file path.
     * @param string $template_name Template file slug.
     * @param string $template_path Template file name.
     *
     * @return string The new Template file path.
     */
    add_filter( 'woocommerce_locate_template',function( $template, $template_name, $template_path ) {
        if ( 'shipping-calculator.php' === basename( $template ) ) {
            $template = trailingslashit( plugin_dir_path( __FILE__ ) ) . 'woocommerce/cart/shipping-calculator.php';
        }
        return $template;
    },10, 3 );



    /*
    *
    *   Creating Delivery when a new order placed.  
    *
    */
    add_action( 'woocommerce_thankyou', function ( $order_id ){ 
        $Swoove_Shipping_Method = new SwooveShippingMethod();
        $Swoove_Shipping_Method->swoove_create_delivery($order_id);
    });

	



    /*
    *
    *   For the Cart page woocommerce calculated shipping function  
    *
    */
    add_action( 'woocommerce_calculated_shipping', function(){
        $address = array();
        $address['phone'] = isset( $_POST['billing_phone'] ) ? wc_clean( wp_unslash( $_POST['billing_phone'] ) ) : ''; // WPCS: input var ok, CSRF ok, sanitization ok.
        $address['email']     = isset( $_POST['billing_email'] ) ? wc_clean( wp_unslash( $_POST['billing_email'] ) ) : ''; // WPCS: input var ok, CSRF ok, sanitization ok.
        WC()->customer->set_billing_email($address['email'] );
        WC()->customer->set_billing_phone($address['phone'] );
        WC()->customer->set_calculated_shipping( false );
		WC()->customer->save();
    });




    /*
    *
    *   Callaback Function for Swoove Orders 
    *
    */
    add_action( 'rest_api_init', function () {
        register_rest_route( 'swooveshippingmethod/', 'callback', array(
          'methods' => 'POST',
          'callback' => 'swoove_save_callback',
        ) );
    });
      
    function swoove_save_callback(){
        $data = file_get_contents('php://input');  
        $j_res = json_decode($data, true); //echo '<pre>'; print_r($data); echo '</pre>'; die();
        update_post_meta(22667,'farhan',$data);
        if ($j_res['success']) {
            $responses = $j_res['responses'];
            $delivery_status = $responses['status'];
            $orderId = $responses['reference'];
            $tracking_link = isset($responses['tracking_link']) ? $responses['tracking_link'] : '';
            update_post_meta($orderId, 'swoove_delivery_status', sanitize_text_field($delivery_status));
            update_post_meta($orderId, 'swoove_tracking_link', sanitize_text_field($tracking_link));
        }
        wp_send_json_success(array('success' => 'message'));
        //die(); 
    }

    /*
    *
    *   Adding tracking info box at vendor order details page
    *
    */
    add_action( 'dokan_order_detail_after_order_items', function($order){ //echo '<pre>'; print_r(get_post_meta($order->id)); echo '</pre>'; die();
        if(get_post_meta($order->id, 'swoove_delivery_status', true) == ''){
            return;
        }
        if(get_post_meta($order->id, 'swoove_delivery_status', true) == 'not-created'){
            echo '<style>input#dokan-add-tracking-number{display:none !important;}</style> ';
            echo '<div class="dokan-panel dokan-panel-default">
                    <div class="dokan-panel-heading"><strong>Swoove Shipping</strong></div>
                    <div class="dokan-panel-body">
                        <button data-orderID="'.$order->id.'" style="display: block; margin: auto;" class="confirm-swoove-delivery">Confirm Swoove Delivery</button>
                    </div>
                </div>'; 
            ?>
            <script>
                jQuery(document).ready(function(){
                    jQuery('.confirm-swoove-delivery').click(function(){
                        if(confirm("This action is not reversible please confirm are you ready to deliver?")){
                            jQuery.blockUI({message: ''});
                            jQuery.ajax({
                                type : 'post',
                                url : "<?php echo admin_url( 'admin-ajax.php' ); ?>",
                                data : {
                                    action : 'mark_ready_for_delivery_swoove',
                                    orderid : jQuery(this).data().orderid
                                },
                                success: function(res){
                                    alert(res.data.message);
                                    jQuery.unblockUI();
                                    //window.location.href = window.location.href; 
                                }
                                    

                            });
                        }
                        else{
                            return false;
                        }
                    });
                
                });
            </script> 
        <?php
        }else{
            echo '<style>input#dokan-add-tracking-number{display:none !important;}</style> ';
            echo '<div class="dokan-panel dokan-panel-default">
                    <div class="dokan-panel-heading"><strong>Swoove Shipping</strong></div>
                    <div class="dokan-panel-body" style="text-align:center;">
                        Delivery Status : '.get_post_meta($order->get_id(), 'swoove_delivery_status', true).'<br>
                        Tracking link : '. get_post_meta($order->get_id(), 'swoove_tracking_link', true).'
                    </div>'; 
                    if(get_post_meta($order_id,'order_cancel_requested',true) == true){
                        echo '<div data-orderid='.$order_id.' class="recreate-swoove-shipping">Regerate Shipping Request</div>';
                    }
            echo '</div>';
        }

    });


    add_action("wp_ajax_mark_ready_for_delivery_swoove", "mark_ready_for_delivery_swoove");
    add_action("wp_ajax_nopriv_mark_ready_for_delivery_swoove", "mark_ready_for_delivery_swoove");
    
    function mark_ready_for_delivery_swoove() {
        if(isset($_POST['orderid']) && !empty($_POST['orderid'])){ 
            $order_id = $_POST['orderid'];
            $order = wc_get_order($order_id);
            $Swoove_Shipping_Method = new SwooveShippingMethod();
            $Swoove_Shipping_Method->recreate_shipping_delivery($order);    
        }
        exit();
    }

}

