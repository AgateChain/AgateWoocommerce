<?php

/*
Plugin Name: WooCommerce Agate Payment Gateway
Plugin URI: https://agate.services
Description: Pay With Agate Payment gateway for woocommerce
Version: 1.0.0
Author: Agate
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

add_action('plugins_loaded', 'woocommerce_paywith_agate_init', 0);


function woocommerce_paywith_agate_init(){

    if(!class_exists('WC_Payment_Gateway')) return;


    class WC_Paywith_Agate extends WC_Payment_Gateway{

        public function error_log($contents)
        {
            if (false === isset($contents) || true === empty($contents)) {
                return;
            }

            if (true === is_array($contents)) {
                $contents = var_export($contents, true);
            } else if (true === is_object($contents)) {
                $contents = json_encode($contents);
            }

            error_log($contents);
        }

        public function __construct(){
            $this->id = 'paywithagate';
            $this->method_title = 'Pay With Agate';
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this -> title       = $this -> settings['title'];
            $this -> description = $this -> settings['description'];
            $this -> api_key     = $this -> settings['api_key'];
            $this -> convert_url = "https://data.fixer.io/api/convert?access_key=5eca86a7a5b906b37084503824894e69&from=";
            $this -> base_url    = "http://gateway.agate.services/?api_key=".$this -> api_key;


            //Actions
            if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                add_action( 'woocommerce_update_options_payment_gateways_'.$this->id, array(&$this, 'process_admin_options' ) );
            } else {
                add_action( 'woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options' ) );
            }

            add_action('woocommerce_receipt_'.$this->id, array(&$this, 'receipt_page'));

            //Payment Listener/API hook
            add_action('init', array(&$this, 'paywith_agate_response'));
            //update for woocommerce >2.0
            add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'paywith_agate_response' ) );

            add_action('woocommerce_thankyou_order_received_text', array( &$this, 'payment_response'));

            // add_action('woocommerce_api_wc_paywith_agate', array($this, 'ipn_callback'));
        }

        function init_form_fields(){

            $this -> form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Pay With Agate Payment Module.',
                    'default' => 'no'),
                'title' => array(
                    'title' => 'Title',
                    'type'=> 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Pay With Agate'),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Pay securely through Agate Payment Gateway.'),
                'api_key' => array(
                    'title' => 'API Key',
                    'type' => 'text',
                    'description' => 'This API Key is the one provided by Agate. Please visit http://www.agate.services/registration-form/ if you dont have one.'),

            );
        }

        public function admin_options(){
            echo '<h3>Pay With Agate Payment Gateway</h3>';
            echo '<p>Agate is most popular payment gateway.</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this -> generate_settings_html();
            echo '</table>';

        }
        // Get iUSD equivalent to any amount
        function convert_cur_to_iUSD ( $order_id ) {
            global $woocommerce;
            $order = new WC_Order( $order_id );
            $amount = $order->total;
            $url = $this -> convert_url.get_option("woocommerce_currency")."&to=USD&amount=". $amount;

            error_log("Requesting Covert API with Params");
            error_log("Url : " . $url);

            $request = wp_remote_get( $url,
            array(
              'timeout'     => 120
            ) );
            $body = wp_remote_retrieve_body( $request );
            $data = json_decode( $body , true);

            error_log("Response from Convert API =>" . var_export($data, TRUE));
            // error_log($data["result"]);
            // Return the equivalent iUSD value acquired from fixer.io server.
            return (float) $data["result"];


        }


        function redirect_payment( $order_id, $amount_iUSD ) {
          global $woocommerce;
          error_log("Entered into Redirect Payment");
          $order = new WC_Order( $order_id );
          $amount = $order->total;

          // Using Auto-submit form to redirect user with the token
          return "<form id='form' method='post' action='". $this -> base_url . "'>".
                  "<input type='hidden' autocomplete='off' name='amount' value='".$amount."'/>".
                  "<input type='hidden' autocomplete='off' name='amount_iUSD' value='".$amount_iUSD."'/>".
                  "<input type='hidden' autocomplete='off' name='callBackUrl' value='".$this->get_return_url()."'/>".
                  "<input type='hidden' autocomplete='off' name='api_key' value='".$this->api_key."'/>".
                  "<input type='hidden' autocomplete='off' name='cur' value='".get_option("woocommerce_currency")."'/>".
                 "</form>".
                 "<script type='text/javascript'>".
                      "document.getElementById('form').submit();".
                 "</script>";

        }

        // Displaying text on the receipt page and sending requests to Agate server.
        function receipt_page( $order ) {
            echo '<p>Thank you ! Your order is now pending payment. You should be automatically redirected to Agate to make payment.</p>';
            // Convert Base to Target
            $amount_iUSD = $this->convert_cur_to_iUSD ( $order );

            echo $this->redirect_payment ( $order, $amount_iUSD );


        }

        // Process payment
        function process_payment( $order_id ) {

            $order = new WC_Order( $order_id );

            $order->update_status( 'pending', __( 'Awaiting payment', 'wcagate' ) );

            if ( version_compare( WOOCOMMERCE_VERSION, '2.1.0', '>=' ) ) {
                /* 2.1.0 */
                $checkout_payment_url = $order->get_checkout_payment_url( true );
            } else {
                /* 2.0.0 */
                $checkout_payment_url = get_permalink( get_option ( 'woocommerce_pay_page_id' ) );
            }

            return array(
                'result' 	=> 'success',
                'redirect'	=> $checkout_payment_url
            );


        }
        // Process the payment response acquired from Agate
        function payment_response( $order_id ) {
            global $woocommerce;
            // error_log("Hey".json_encode($_REQUEST));

            $order = new WC_Order($order_id);


              echo "Your Payment was expired. To pay again please go to checkout page.";
              $order->add_order_note(__('Payment was unsuccessful', 'wcagate'));
              // Cancel order
              $order->cancel_order('Payment expired.');

        }


        // End of class
}

    /* Add the Gateway to WooCommerce */

    function woocommerce_add_paywith_agate_gateway($methods) {
        $methods[] = 'WC_Paywith_Agate';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways','woocommerce_add_paywith_agate_gateway');
}

?>
