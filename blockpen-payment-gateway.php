<?php

/**
 * Plugin Name: BLOCKPEN PAYMENT GATEWAY
 * Plugin URI:  https://blockpen.tech/plugins/wp/payment
 * Description: Blockpen plugin for Wordpress to enable seamless as PayPal, semi-centralized online payments with cryptocurrencies
 * Version:     0.0.1
 * Author:      Blockpen
 * Author URI:  https://blockpen.tech/
 */

function pluginprefix_setup_post_type() {
    // register the "book" custom post type
    register_post_type( 'book', ['public' => 'true'] );
}
add_action( 'init', 'pluginprefix_setup_post_type' );
 
function pluginprefix_install() {
    // trigger our function that registers the custom post type
    pluginprefix_setup_post_type();
 
    // clear the permalinks after the post type has been registered
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'pluginprefix_install' );


function pluginprefix_deactivation() {
    // unregister the post type, so the rules are no longer in memory
    unregister_post_type( 'book' );
    // clear the permalinks to remove our post type's rules from the database
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'pluginprefix_deactivation' );

function my_theme_scripts_function() {
    wp_enqueue_script( 'blockpen', 'http://localhost:8080/v1/lib/blockpen.js', false);
}
add_action('wp_enqueue_scripts','my_theme_scripts_function');

// <?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

add_action( 'plugins_loaded', 'blockpen_gateway_load', 0 );

function blockpen_gateway_load() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        // oops!
        return;
    }

    /**
     * Add the gateway to WooCommerce.
     */
    add_filter( 'woocommerce_payment_gateways', 'wcblockpen_add_gateway' );

    function wcblockpen_add_gateway( $methods ) {
        if (!in_array('WC_Gateway_blockpen', $methods)) {
            $methods[] = 'WC_Gateway_blockpen';
        }
        return $methods;
    }


    class WC_Gateway_blockpen extends WC_Payment_Gateway {

        var $ipn_url;

        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct() {
            global $woocommerce;

            $this->id           = 'blockpen';
            $this->icon         = $this->get_icon();
            $this->has_fields   = false;
            $this->method_title = __( 'Blockpen', 'woocommerce' );
            $this->ipn_url      = add_query_arg( 'wc-api', 'WC_Gateway_blockpen', home_url( '/' ) );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title       = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );

            // Logs
            $this->log = new WC_Logger();

            // Actions
            add_action( 'woocommerce_receipt_blockpen', array( $this, 'receipt_page' ) );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function admin_options() {
            ?>
            <h3><?php _e( '', 'woocommerce' ); ?></h3>
            <p><?php _e( 'Completes checkout via blockpen.tech', 'woocommerce' ); ?></p>

            <table class="form-table">
            <?php
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            ?>
            </table><!--/.form-table-->;

            <?php
        }

        /**
         * @return string html to insert images
         */
        public function get_icon() {
            $image_path = plugins_url().'/assets/';
            $icon_html  = '';
            $methods = ['eth', 'stellar', 'token'];

            // Load icon for each available payment method.
            for ($m = 0; $m < sizeof($methods); $m++ ) {
                $path = $image_path . '/' . $methods[$m] . '.png';
                $url        = WC_HTTPS::force_https_url( plugins_url( '/assets/' . $methods[$m] . '.png', __FILE__ ) );
                $icon_html .= '<img width="26" src="' . esc_attr( $url ) . '"/>';
            }

            return apply_filters( 'woocommerce_blockpen_icon', $icon_html, $this->id );
        }

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'woocommerce' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable blockpen.tech', 'woocommerce' ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'    => __( 'Title', 'woocommerce' ),
                    'type'     => 'text',
                    'default'  => __( 'Ethereum, Stellar and Tokens with Blockpen', 'woocommerce' ),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title'   => __( 'Description', 'woocommerce' ),
                    'type'    => 'textarea',
                    'default' => __( 'Pay with Ethereum, Stellar and Any tokens with Blockpen', 'woocommerce' )
                ),
                'webhook_secret' => array(
                    'title'       => __( 'Webhook Shared Secret', 'blockpen' ),
                    'type'        => 'text',
                    'description' =>
    
                    // translators: Instructions for setting up 'webhook shared secrets' on settings page.
                    __( 'Using webhooks allows blockpen Commerce to send payment confirmation messages to the website. To fill this out:', 'blockpen' )
    
                    . '<br /><br />' .
    
                    // translators: Step 1 of the instructions for 'webhook shared secrets' on settings page.
                    __( '1. In your blockpen Commerce settings page, scroll to the \'Webhook subscriptions\' section', 'blockpen' )
    
                    . '<br />' .
    
                    // translators: Step 2 of the instructions for 'webhook shared secrets' on settings page. Includes webhook URL.
                    sprintf( __( '2. Click \'Add an endpoint\' and paste the following URL: %s', 'blockpen' ), add_query_arg( 'wc-api', 'WC_Gateway_blockpen', home_url( '/', 'https' ) ) )
    
                    . '<br />' .
    
                    // translators: Step 3 of the instructions for 'webhook shared secrets' on settings page.
                    __( '3. Make sure to select "Send me all events", to receive all payment updates.', 'blockpen' )
    
                    . '<br />' .
    
                    // translators: Step 4 of the instructions for 'webhook shared secrets' on settings page.
                    __( '4. Click "Show shared secret" and paste into the box above.', 'blockpen' ),
    
                ),
            );
        }

        /**
         * Get blockpen.tech Args
         *
         * @access public
         * @param mixed $order
         * @return array
         */
        function get_blockpen_args( $order ) {
            global $woocommerce;

            $order_id = $order->id;

            if ( in_array( $order->billing_country, array( 'US','CA' ) ) ) {
                $order->billing_phone = str_replace( array( '( ', '-', ' ', ' )', '.' ), '', $order->billing_phone );
            }

            // blockpen.tech Args
            $blockpen_args = array(
                'merchant'    => $this->merchant_id,
                'allow_extra' => 0,
                // Get the currency from the order, not the active currency
                // NOTE: for backward compatibility with WC 2.6 and earlier,
                // $order->get_order_currency() should be used instead
                'currency'    => $order->get_currency(),
                'reset'       => 1,
                'success_url' => $this->get_return_url( $order ),
                'cancel_url'  => esc_url_raw($order->get_cancel_order_url_raw()),

                // Order key + ID
                'invoice' => $this->invoice_prefix . $order->get_order_number(),
                'custom'  => serialize( array( $order->id, $order->order_key ) ),

                // IPN
                'ipn_url' => $this->ipn_url,

                // Billing Address info
                'first_name' => $order->billing_first_name,
                'last_name'  => $order->billing_last_name,
                'email'      => $order->billing_email,
            );

            if ($this->send_shipping == 'yes') {
                $blockpen_args = array_merge($blockpen_args, array(
                    'want_shipping' => 1,
                    'address1'      => $order->billing_address_1,
                    'address2'      => $order->billing_address_2,
                    'city'          => $order->billing_city,
                    'state'         => $order->billing_state,
                    'zip'           => $order->billing_postcode,
                    'country'       => $order->billing_country,
                    'phone'         => $order->billing_phone,
                ));
            } else {
                $blockpen_args['want_shipping'] = 0;
            }

            if ($this->simple_total) {
                $blockpen_args['item_name'] = sprintf( __( 'Order %s' , 'woocommerce'), $order->get_order_number() );
                $blockpen_args['quantity']  = 1;
                $blockpen_args['amountf']   = number_format( $order->get_total(), 8, '.', '' );
                $blockpen_args['taxf']      = 0.00;
                $blockpen_args['shippingf'] = 0.00;
            } else if ( wc_tax_enabled() && wc_prices_include_tax() ) {
                $blockpen_args['item_name'] = sprintf( __( 'Order %s' , 'woocommerce'), $order->get_order_number() );
                $blockpen_args['quantity']  = 1;
                $blockpen_args['amountf']   = number_format( $order->get_total() - $order->get_total_shipping() - $order->get_shipping_tax(), 8, '.', '' );
                $blockpen_args['shippingf'] = number_format( $order->get_total_shipping() + $order->get_shipping_tax() , 8, '.', '' );
                $blockpen_args['taxf']      = 0.00;
            } else {
                $blockpen_args['item_name'] = sprintf( __( 'Order %s' , 'woocommerce'), $order->get_order_number() );
                $blockpen_args['quantity']  = 1;
                $blockpen_args['amountf']   = number_format( $order->get_total() - $order->get_total_shipping() - $order->get_total_tax(), 8, '.', '' );
                $blockpen_args['shippingf'] = number_format( $order->get_total_shipping(), 8, '.', '' );
                $blockpen_args['taxf']      = $order->get_total_tax();
            }

            $blockpen_args = apply_filters( 'woocommerce_blockpen_args', $blockpen_args );

            return $blockpen_args;
        }


        /**
         * Generate the blockpen button link
         *
         * @access public
         * @param mixed $order_id
         * @return string
         */
        function generate_blockpen_url($order) {
            global $woocommerce;

            if ( $order->status != 'completed' && get_post_meta( $order->id, 'blockpen payment complete', true ) != 'Yes' ) {
                $order->update_status('pending', 'Customer is being redirected to blockpen...');
            }

            $blockpen_adr = "http://localhost:8080/woocommerce/pay?";
                        
            $blockpen_args = $this->get_blockpen_args( $order );
            $blockpen_args["total"] = $order->total;
            $blockpen_adr .= http_build_query( $blockpen_args, '', '&' );

            return $blockpen_adr;
        }

        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

            return array(
                'result' => 'success',
                'redirect' => $this->generate_blockpen_url($order),
            );
        }


        /**
         * Output for the order received page.
         *
         * @access public
         * @return void
         */
        function receipt_page( $order ) {
            echo '<p>'.__( 'Thank you for your order, please click the button below to pay with blockpen.tech.', 'woocommerce' ).'</p>';

            echo $this->generate_blockpen_form( $order );
        }

        /**
         * Check blockpen.tech IPN validity
         **/
        function check_ipn_request_is_valid() {
            global $woocommerce;

            $order = false;
            $error_msg = "Unknown error";
            $auth_ok = false;

            if (isset($_POST['ipn_mode']) && $_POST['ipn_mode'] == 'hmac') {
                if (isset($_SERVER['HTTP_HMAC']) && !empty($_SERVER['HTTP_HMAC'])) {
                    $request = file_get_contents('php://input');
                    if ($request !== FALSE && !empty($request)) {
                        if (isset($_POST['merchant']) && $_POST['merchant'] == trim($this->merchant_id)) {
                            $hmac = hash_hmac("sha512", $request, trim($this->ipn_secret));
                            if ($hmac == $_SERVER['HTTP_HMAC']) {
                                $auth_ok = true;
                            } else {
                                $error_msg = 'HMAC signature does not match';
                            }
                        } else {
                            $error_msg = 'No or incorrect Merchant ID passed';
                        }
                    } else {
                        $error_msg = 'Error reading POST data';
                    }
                } else {
                    $error_msg = 'No HMAC signature sent.';
                }
            } else {
                $error_msg = "Unknown IPN verification method.";
            }

            if ($auth_ok) {
                if (!empty($_POST['invoice']) && !empty($_POST['custom'])) {
                    $order = $this->get_blockpen_order( $_POST );
                }

                if ($order !== FALSE) {
                    if ($_POST['ipn_type'] == "button" || $_POST['ipn_type'] == "simple") {
                        if ($_POST['merchant'] == $this->merchant_id) {
                            // Get the currency from the order, not the active currency
                            // NOTE: for backward compatibility with WC 2.6 and earlier,
                            // $order->get_order_currency() should be used instead
                            if ($_POST['currency1'] == $order->get_currency()) {
                                if ($_POST['amount1'] >= $order->get_total()) {
                                    print "IPN check OK\n";
                                    return true;
                                } else {
                                    $error_msg = "Amount received is less than the total!";
                                }
                            } else {
                                $error_msg = "Original currency doesn't match!";
                            }
                        } else {
                            $error_msg = "Merchant ID doesn't match!";
                        }
                    } else {
                        $error_msg = "ipn_type != button or simple";
                    }
                } else {
                    $error_msg = "Could not find order info for order: ".$_POST['invoice'];
                }
            }

            $report = "Error Message: ".$error_msg."\n\n";

            $report .= "POST Fields\n\n";
            foreach ($_POST as $key => $value) {
                $report .= $key.'='.$value."\n";
            }

            if ($order) {
                $order->update_status('on-hold', sprintf( __( 'blockpen.tech IPN Error: %s', 'woocommerce' ), $error_msg ) );
            }
            if (!empty($this->debug_email)) { mail($this->debug_email, "blockpen.tech Invalid IPN", $report); }
            mail(get_option( 'admin_email' ), sprintf( __( 'blockpen.tech Invalid IPN', 'woocommerce' ), $error_msg ), $report );
            die('Error: '.$error_msg);
            return false;
        }

        /**
         * Successful Payment!
         *
         * @access public
         * @param array $posted
         * @return void
         */
        function successful_request( $posted ) {
            global $woocommerce;

            $posted = stripslashes_deep( $posted );

            // Custom holds post ID
            if (!empty($_POST['invoice']) && !empty($_POST['custom'])) {
                $order = $this->get_blockpen_order( $posted );

                $this->log->add( 'blockpen', 'Order #'.$order->id.' payment status: ' . $posted['status_text'] );
                $order->add_order_note('blockpen.tech Payment Status: '.$posted['status_text']);

                if ( $order->status != 'completed' && get_post_meta( $order->id, 'blockpen payment complete', true ) != 'Yes' ) {
                        // no need to update status if it's already done
                    if ( ! empty( $posted['txn_id'] ) )
                        update_post_meta( $order->id, 'Transaction ID', $posted['txn_id'] );
                    if ( ! empty( $posted['first_name'] ) )
                        update_post_meta( $order->id, 'Payer first name', $posted['first_name'] );
                    if ( ! empty( $posted['last_name'] ) )
                        update_post_meta( $order->id, 'Payer last name', $posted['last_name'] );
                    if ( ! empty( $posted['email'] ) )
                        update_post_meta( $order->id, 'Payer email', $posted['email'] );

                    if ($posted['status'] >= 100 || $posted['status'] == 2 || ($this->allow_zero_confirm && $posted['status'] >= 0 && $posted['received_confirms'] > 0 && $posted['received_amount'] >= $posted['amount2'])) {
                        print "Marking complete\n";
                        update_post_meta( $order->id, 'blockpen payment complete', 'Yes' );
                        $order->payment_complete();
                    } else if ($posted['status'] < 0) {
                        print "Marking cancelled\n";
                        $order->update_status('cancelled', 'blockpen.tech Payment cancelled/timed out: '.$posted['status_text']);
                        mail( get_option( 'admin_email' ), sprintf( __( 'Payment for order %s cancelled/timed out', 'woocommerce' ), $order->get_order_number() ), $posted['status_text'] );
                    } else {
                        print "Marking pending\n";
                        $order->update_status('pending', 'blockpen.tech Payment pending: '.$posted['status_text']);
                    }
                }
                die("IPN OK");
            }
        }

        /**
         * get_blockpen_order function.
         *
         * @access public
         * @param mixed $posted
         * @return void
         */
        function get_blockpen_order( $posted ) {
            $custom = maybe_unserialize( stripslashes_deep($posted['custom']) );

            // Backwards comp for IPN requests
            if ( is_numeric( $custom ) ) {
                $order_id = (int) $custom;
                $order_key = $posted['invoice'];
            } elseif( is_string( $custom ) ) {
                $order_id = (int) str_replace( $this->invoice_prefix, '', $custom );
                $order_key = $custom;
            } else {
                list( $order_id, $order_key ) = $custom;
            }

            $order = new WC_Order( $order_id );

            if ( ! isset( $order->id ) ) {
                // We have an invalid $order_id, probably because invoice_prefix has changed
                $order_id       = woocommerce_get_order_id_by_order_key( $order_key );
                $order          = new WC_Order( $order_id );
            }

            // Validate key
            if ( $order->order_key !== $order_key ) {
                return FALSE;
            }

            return $order;
        }

    }

    class WC_blockpen extends WC_Gateway_blockpen {
        public function __construct() {
            _deprecated_function( 'WC_blockpen', '1.4', 'WC_Gateway_blockpen' );
            parent::__construct();
        }
    }
}