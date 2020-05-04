<?php


class Easy_Digital_Downloads_Epayco_Plugin
{
    /**
     * Filepath of main plugin file.
     *
     * @var string
     */
    public $file;
    /**
     * Plugin version.
     *
     * @var string
     */
    public $version;
    /**
     * Absolute plugin path.
     *
     * @var string
     */
    public $plugin_path;
    /**
     * Absolute plugin URL.
     *
     * @var string
     */
    public $plugin_url;
    /**
     * Absolute path to plugin includes dir.
     *
     * @var string
     */
    public $includes_path;
    /**
     * @var string
     */
    public $logger;
    /**
     * @var bool
     */
    private $_bootstrapped = false;

    public $is_setup = null;

    public $gateway_id = 'epayco';

    public function __construct($file, $version)
    {
        $this->file = $file;
        $this->version = $version;
        // Path.
        $this->plugin_path   = trailingslashit( plugin_dir_path( $this->file ) );
        $this->plugin_url    = trailingslashit( plugin_dir_url( $this->file ) );
        $this->includes_path = $this->plugin_path . trailingslashit( 'includes' );
    }

    public function run_epayco()
    {
        try{
            if ($this->_bootstrapped){
                throw new Exception( __( 'Easy digital downloads ePayco can only be called once'));
            }
            $this->_run();
            $this->_bootstrapped = true;
        }catch (Exception $e){
            if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
                subscription_epayco_se_notices('Easy digital downloads ePayco: ' . $e->getMessage());
            }
        }
    }

    protected function _run()
    {
        add_filter('edd_payment_gateways', array($this, 'pw_edd_register_gateway'));
        add_filter('edd_settings_gateways', array($this, 'pw_edd_add_settings'));
        add_filter('edd_currencies', array($this, 'add_currency_colombia'));
        add_action( 'edd_epayco_cc_form', '__return_false' );
        add_action( 'wp_enqueue_scripts', array( $this, 'load_scripts' ));
        add_action( 'edd_pre_process_purchase', array( $this, 'check_config' ));
        add_action( 'wp', array($this, 'process_ipn'));
        add_action( 'wp', array($this, 'response_epayco'));
        add_action( 'edd_gateway_epayco', array( $this, 'process_purchase' ) );
    }

    /**
     * Method to check if all the required settings have been filled out, allowing us to not output information without it.
     *
     * @since 2.7
     * @return bool
     */
    public function is_setup() {
        if ( null !== $this->is_setup )
            return $this->is_setup;

        $required_items = array( 'epayco_public_key', 'epayco_cust_id_client', 'epayco_p_key' );

        $current_values = array(
            'epayco_public_key' => edd_get_option( 'epayco_public_key', '' ),
            'epayco_cust_id_client' => edd_get_option( 'epayco_cust_id_client', '' ),
            'epayco_p_key' => edd_get_option( 'epayco_p_key', '' )
        );

        $this->is_setup = true;

        foreach ( $required_items as $key ) {
            if ( empty( $current_values[ $key ] ) ) {
                $this->is_setup = false;
                break;
            }
        }

        return $this->is_setup;
    }

    public function pw_edd_register_gateway($gateways)
    {
        $gateways['epayco'] = array(
            'admin_label' => 'ePayco',
            'checkout_label' => edd_get_option('epayco_description', 'ePayco')
        );
        return $gateways;
    }

    public function add_currency_colombia(array  $currencies)
    {
        $currency = array(
            'COP'  => __( 'Pesos Colombianos', 'easy-digital-downloads' ),
        );

        return array_merge($currencies, $currency);
    }

    function pw_edd_add_settings($settings)
    {

        $edd_epayco = array(
            'epayco_settings' => array(
                'id'   => 'epayco_settings',
                'name' => '<strong>' . __( 'ePayco configuraciones', 'easy-digital-downloads' ) . '</strong>',
                'type' => 'header'
            ),
            'epayco_description' => array(
                'id'   => 'epayco_description',
                'name' => __( 'Descripción', 'easy-digital-downloads' ),
                'desc' => __( '<br/>Corresponde a la descripción que verá el usuaro durante el checkout', 'easy-digital-downloads' ),
                'type' => 'text',
                'size' => 'regular'
            ),
            'epayco_public_key' => array(
                'id'   => 'epayco_public_key',
                'name' => __( 'PUBLIC_KEY', 'easy-digital-downloads' ),
                'desc' => __( '<br/>PUBLIC_KEY del comercio, la encuentras en el panel de administrador de ePayco, Integraciones - Llaves', 'easy-digital-downloads' ),
                'type' => 'text',
                'size' => 'regular'
            ),
            'epayco_cust_id_client' => array(
                 'id' => 'epayco_cust_id_client',
                'name' => __( 'P_CUST_ID_CLIENTE', 'easy-digital-downloads' ),
                'desc' => __( '<br/>P_CUST_ID_CLIENTE del comercio, la encuentras en el panel de administrador de ePayco, Integraciones - Llaves', 'easy-digital-downloads' ),
                'type' => 'text',
                'size' => 'regular'
            ),
            'epayco_p_key' => array(
                'id' => 'epayco_p_key',
                'name' => __( 'P_KEY', 'easy-digital-downloads' ),
                'desc' => __( '<br/>P_KEY del comercio, la encuentras en el panel de administrador de ePayco, Integraciones - Llaves', 'easy-digital-downloads' ),
                'type' => 'text',
                'size' => 'regular'
            )
        );

        return  array_merge($settings, $edd_epayco);
    }

    public function check_config()
    {
        $is_enabled = edd_is_gateway_active( $this->gateway_id );
        if ( ( ! $is_enabled || false === $this->is_setup() ) && 'epayco' == edd_get_chosen_gateway() ) {
            edd_set_error( 'epayco_gateway_not_configured', __( 'Hay un error con la configuración de ePayco.', 'easy-digital-downloads' ) );
        }
    }

    public function load_scripts()
    {
        if ( ! $this->is_setup() ) {
            return;
        }

        if ( ! edd_is_checkout() ) {
            return;
        }

        $test_mode = edd_is_test_mode();
        $epayco_public_key = edd_get_option( 'epayco_public_key', '' );

        wp_enqueue_script( 'edd-epayco-checkout', 'https://checkout.epayco.co/checkout.js', array( 'jquery' ), null, true );
        wp_localize_script( 'edd-epayco-checkout', 'edd_epayco',  array(
            'epaycoPublicKey' => $epayco_public_key,
            'test' =>  $test_mode,
            'confirmationPage' => $this->get_epayco_ipn_url()
        ));
    }

    private function get_epayco_ipn_url()
    {
        return esc_url_raw( add_query_arg( array( 'edd-listener' => 'epayco' ), home_url( 'index.php' ) ) );
    }

    public function process_purchase($purchase_data)
    {
        global $edd_options;

        $payment_data = array(
            'price' => $purchase_data['price'],
            'date' => $purchase_data['date'],
            'user_email' => $purchase_data['user_email'],
            'purchase_key' => $purchase_data['purchase_key'],
            'currency' => $edd_options['currency'],
            'downloads' => $purchase_data['downloads'],
            'user_info' => $purchase_data['user_info'],
            'cart_details' => $purchase_data['cart_details'],
            'status' => 'pending'
        );

        // record the pending payment
        $payment = edd_insert_payment( $payment_data );
        edd_set_payment_transaction_id( $payment, $payment );

        if ( !$payment ) {
            edd_record_gateway_error( __( 'Payment Error', 'easy-digital-downloads' ), sprintf( __( 'Payment creation failed before sending buyer to MercadoPago. Payment data: %s', 'easy-digital-downloads' ), json_encode( $payment_data ) ), $payment );
            // Problems? send back
            edd_send_back_to_checkout( '?payment-mode=' . $purchase_data[ 'post_data' ][ 'edd-gateway' ] );
        }else{
            $names_items = '';

            foreach ( $purchase_data[ 'cart_details' ] as $item ) {
                $names_items .= edd_get_cart_item_name( $item )  . ' ';
            }

            $epayco_public_key = edd_get_option( 'epayco_public_key', '' );

            $test_mode = edd_is_test_mode();
            $converted_test_mode = $test_mode ? 'true' : 'false';

            $names_items = strlen($names_items) > 20 ? substr($names_items, 0, 20) . '...' : $names_items;
            $total =  edd_format_amount(edd_get_cart_total(), false);
            $total = str_replace(',', '', $total);
            $tax_base = edd_format_amount(edd_get_cart_tax(), false);
            $tax_base = str_replace(',', '', $tax_base);

            $lang = get_locale();
            $lang = explode('_', $lang);
            $lang = $lang[0];

            ob_start();
            $form = ob_get_clean();
            ?>
            <!doctype html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport"
                      content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
                <meta http-equiv="X-UA-Compatible" content="ie=edge">
                <title>Checkout</title>
                <script
                        src="https://checkout.epayco.co/checkout.js"
                        class="epayco-button"
                        data-epayco-key="<?php echo $epayco_public_key; ?>"
                        data-epayco-amount="<?php echo $total; ?>"
                        data-epayco-invoice="<?php echo $payment; ?>"
                        data-epayco-tax="<?php echo edd_get_cart_tax_rate(); ?>"
                        data-epayco-tax-base="<?php echo $tax_base; ?>"
                        data-epayco-name="<?php echo $names_items;  ?>"
                        data-epayco-description="<?php echo $names_items; ?>"
                        data-epayco-currency="<?php echo edd_get_currency(); ?>"
                        data-epayco-country="<?php echo edd_get_shop_country(); ?>"
                        data-epayco-lang="<?php echo $lang; ?>"
                        data-epayco-test="<?php echo $converted_test_mode; ?>"
                        data-epayco-external="true"
                        data-epayco-response="<?php echo edd_get_checkout_uri(); ?>"
                        data-epayco-confirmation="<?php echo $this->get_epayco_ipn_url(); ?>"
                        data-epayco-autoclick="true"
                        data-epayco-email-billing="<?php echo $purchase_data['user_email']; ?>"
                        data-epayco-name-billing="<?php echo $purchase_data['user_info']['first_name'] . " " . $purchase_data['user_info']['last_name']; ?>">
                </script>
                <style>
                    .spinner {
                        margin: 100px auto 0;
                        width: 70px;
                        text-align: center;
                    }

                    .spinner > div {
                        width: 18px;
                        height: 18px;
                        background-color: #333;

                        border-radius: 100%;
                        display: inline-block;
                        -webkit-animation: sk-bouncedelay 1.4s infinite ease-in-out both;
                        animation: sk-bouncedelay 1.4s infinite ease-in-out both;
                    }

                    .spinner .bounce1 {
                        -webkit-animation-delay: -0.32s;
                        animation-delay: -0.32s;
                    }

                    .spinner .bounce2 {
                        -webkit-animation-delay: -0.16s;
                        animation-delay: -0.16s;
                    }

                    @-webkit-keyframes sk-bouncedelay {
                        0%, 80%, 100% { -webkit-transform: scale(0) }
                        40% { -webkit-transform: scale(1.0) }
                    }

                    @keyframes sk-bouncedelay {
                        0%, 80%, 100% {
                            -webkit-transform: scale(0);
                            transform: scale(0);
                        } 40% {
                              -webkit-transform: scale(1.0);
                              transform: scale(1.0);
                          }
                    }
                </style>
            </head>
            <body>
            <div class="spinner">
                <div class="bounce1"></div>
                <div class="bounce2"></div>
                <div class="bounce3"></div>
            </div>
            </body>
            </html>
            <?php
            echo $form;
        }
    }

    public function response_epayco()
    {
        if ( ! isset( $_GET['ref_payco'] ) ) return;

        $ref_payco = $_GET['ref_payco'];

        $url = "https://secure.epayco.co/validation/v1/reference/$ref_payco";

        $transaction_data = wp_safe_remote_get($url,
            array('headers' =>
                array(
                    'cache-control' => 'no-cache',
                    'content-type' => 'application/json')
                )
        );
        if ( is_wp_error( $transaction_data ) ) return;
        $transaction_data = wp_remote_retrieve_body( $transaction_data );
        $transaction_data = json_decode($transaction_data, true);
        $data = $transaction_data['data'];

        $epayco_cust_id_client = edd_get_option( 'epayco_cust_id_client', '' );
        $epayco_p_key = edd_get_option( 'epayco_p_key', '' );

        $x_signature = $data['x_signature'];
        $x_cod_transaction_state = $data['x_cod_transaction_state'];
        $x_id_invoice = $data['x_id_invoice'];

        $signature = hash('sha256',
            $epayco_cust_id_client.'^'
            .$epayco_p_key.'^'
            .$data['x_ref_payco'].'^'
            .$data['x_transaction_id'].'^'
            .$data['x_amount'].'^'
            .$data['x_currency_code']
        );

        if($x_signature !== $signature) return;

        $payment_id = edd_get_purchase_id_by_transaction_id($x_id_invoice);

        if ($x_cod_transaction_state == 1){
            edd_update_payment_status( $payment_id, 'publish' );

            // Empty the shopping cart
            edd_empty_cart();
            edd_send_to_success_page();
        }elseif ($x_cod_transaction_state == 3){
            edd_set_error( 'epayco_error', 'Estado de pago pendiente');
            edd_send_back_to_checkout();
        }else{
            edd_update_payment_status( $payment_id, 'failed' );
            edd_set_error( 'epayco_error', 'La transacción ha fallado, inténtelo de nuevo');
            edd_send_back_to_checkout();
        }
    }

    public function process_ipn()
    {
        if ( ! isset( $_GET['edd-listener'] ) || $_GET['edd-listener'] !== 'epayco' )
            return;

        $body    = file_get_contents( 'php://input' );
        parse_str($body, $data);

        $epayco_cust_id_client = edd_get_option( 'epayco_cust_id_client', '' );
        $epayco_p_key = edd_get_option( 'epayco_p_key', '' );

        $x_signature = $data['x_signature'];
        $x_id_invoice = $data['x_id_invoice'];
        $x_cod_transaction_state = $data['x_cod_transaction_state'];

        if ($x_cod_transaction_state == 3)
            return;

        $signature = hash('sha256',
            $epayco_cust_id_client.'^'
            .$epayco_p_key.'^'
            .$data['x_ref_payco'].'^'
            .$data['x_transaction_id'].'^'
            .$data['x_amount'].'^'
            .$data['x_currency_code']
        );

        if ($x_signature !== $signature) return;

        $payment_id = edd_get_purchase_id_by_transaction_id($x_id_invoice);

        if ($payment_id === 0) return;

        if ($x_cod_transaction_state == 1){
            edd_update_payment_status( $payment_id, 'publish' );
        }else{
            edd_update_payment_status( $payment_id, 'failed' );
        }
    }
}