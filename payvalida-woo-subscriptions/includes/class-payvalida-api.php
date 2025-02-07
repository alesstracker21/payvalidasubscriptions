<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

class Payvalida_API {

    /**
     * Helper: Get the current environment from the admin setting.
     *
     * @return string 'production' or 'sandbox'
     */
    private static function get_environment() {
        // Get the environment from settings; default to 'sandbox'
        return get_option('payvalida_environment', 'sandbox');
    }

    /**
     * Helper: Get the base URL based on the current environment.
     *
     * @return string
     */
    private static function get_base_url() {
        $environment = self::get_environment();
        return ($environment === 'production') ? PAYVALIDA_PROD_URL : PAYVALIDA_SANDBOX_URL;
    }

    /**
     * Creates a Payvalida plan.
     *
     * @param string $interval
     * @param string $interval_count
     * @param string $amount
     * @param string $description
     *
     * @return string|WP_Error   Plan ID on success, WP_Error on failure.
     */
    public static function createPlan( $interval, $interval_count, $amount, $description ) {
        $base_url = self::get_base_url();
        $endpoint = '/v4/subscriptions/plans';
        $url      = $base_url . $endpoint;

        // Calculate the checksum.
        $merchant    = PAYVALIDA_MERCHANT;
        $fixed_hash  = PAYVALIDA_FIXED_HASH;
        $checksum_str= $merchant . $amount . $interval . $interval_count . $fixed_hash;
        $checksum    = hash('sha512', $checksum_str);

        // Build the payload.
        $payload = [
            'merchant'       => $merchant,
            'interval'       => $interval,
            'timestamp'      => time(),
            'interval_count' => (string) $interval_count,
            'amount'         => (string) $amount,
            'description'    => sanitize_text_field($description),
            'checksum'       => $checksum
        ];

        $args = [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode($payload),
            'method'  => 'POST',
            'timeout' => 45
        ];

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error($response) ) {
            return $response;
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ( isset($data['CODE']) && $data['CODE'] === '0000' && isset($data['DATA']['id']) ) {
            return $data['DATA']['id'];
        } else {
            $desc = isset($data['DESC']) ? $data['DESC'] : __('Unknown error', 'payvalida-woo');
            return new WP_Error('payvalida_error', $desc);
        }
    }

    /**
     * Registers a new subscription with Payvalida.
     *
     * There are two modes:
     * - If a $customer_id is provided, then the API call will include only that (to use an already stored customer).
     * - Otherwise, the detailed customer and credit card data is required.
     *
     * @param string $plan_id           The ID of the plan to subscribe the customer to.
     * @param array  $customer          Associative array of customer details (required if $customer_id is not provided).
     *                                  Expected keys: email, user_di, type_di, first_name, last_name, cellphone.
     * @param array  $credit_card_data  Associative array of credit card details (required if $customer_id is not provided).
     *                                  Expected keys: card_number, cvv, expiration_date, franchise, id_type, id,
     *                                  holder_name, holder_last_name, email, phone, ip, header_user_agent,
     *                                  line1, line2, line3, country, city, state, post_code.
     * @param string $customer_id       Optional. Unique identifier of the customer.
     *
     * @return array|WP_Error           Subscription data on success, WP_Error on failure.
     */
    public static function registerSubscription( $plan_id, $customer = array(), $credit_card_data = array(), $customer_id = '' ) {
        $base_url = self::get_base_url();
        $endpoint = '/v4/subscriptions';
        $url      = $base_url . $endpoint;

        $merchant   = PAYVALIDA_MERCHANT;
        $fixed_hash = PAYVALIDA_FIXED_HASH;
        // Checksum is calculated using merchant, plan_id and the fixed hash.
        $checksum   = hash('sha512', $merchant . $plan_id . $fixed_hash);

        $payload = [
            'merchant' => $merchant,
            'plan_id'  => $plan_id,
            'checksum' => $checksum
        ];

        if ( ! empty( $customer_id ) ) {
            $payload['customer_id'] = sanitize_text_field( $customer_id );
        } else {
            $required_customer_fields = ['email', 'user_di', 'type_di', 'first_name', 'last_name', 'cellphone'];
            foreach ( $required_customer_fields as $field ) {
                if ( empty( $customer[ $field ] ) ) {
                    return new WP_Error( 'missing_customer_field', sprintf( __( 'Customer field %s is required.', 'payvalida-woo' ), $field ) );
                }
            }

            $payload['customer'] = [
                'email'      => sanitize_email( $customer['email'] ),
                'user_di'    => sanitize_text_field( $customer['user_di'] ),
                'type_di'    => sanitize_text_field( $customer['type_di'] ),
                'first_name' => sanitize_text_field( $customer['first_name'] ),
                'last_name'  => sanitize_text_field( $customer['last_name'] ),
                'cellphone'  => sanitize_text_field( $customer['cellphone'] )
            ];

            $required_cc_fields = [
                'card_number', 'cvv', 'expiration_date', 'franchise', 'id_type', 'id',
                'holder_name', 'holder_last_name', 'email', 'phone', 'ip', 'header_user_agent',
                'line1', 'line2', 'line3', 'country', 'city', 'state', 'post_code'
            ];
            foreach ( $required_cc_fields as $field ) {
                if ( empty( $credit_card_data[ $field ] ) ) {
                    return new WP_Error( 'missing_cc_field', sprintf( __( 'Credit card field %s is required.', 'payvalida-woo' ), $field ) );
                }
            }

            $payload['credit_card_data'] = [
                'card_number'       => sanitize_text_field( $credit_card_data['card_number'] ),
                'cvv'               => intval( $credit_card_data['cvv'] ),
                'expiration_date'   => sanitize_text_field( $credit_card_data['expiration_date'] ),
                'retries'           => isset( $credit_card_data['retries'] ) ? intval( $credit_card_data['retries'] ) : 1,
                'franchise'         => sanitize_text_field( $credit_card_data['franchise'] ),
                'id_type'           => sanitize_text_field( $credit_card_data['id_type'] ),
                'id'                => sanitize_text_field( $credit_card_data['id'] ),
                'holder_name'       => sanitize_text_field( $credit_card_data['holder_name'] ),
                'holder_last_name'  => sanitize_text_field( $credit_card_data['holder_last_name'] ),
                'email'             => sanitize_email( $credit_card_data['email'] ),
                'phone'             => sanitize_text_field( $credit_card_data['phone'] ),
                'ip'                => sanitize_text_field( $credit_card_data['ip'] ),
                'header_user_agent' => sanitize_text_field( $credit_card_data['header_user_agent'] ),
                'line1'             => sanitize_text_field( $credit_card_data['line1'] ),
                'line2'             => sanitize_text_field( $credit_card_data['line2'] ),
                'line3'             => sanitize_text_field( $credit_card_data['line3'] ),
                'country'           => sanitize_text_field( $credit_card_data['country'] ),
                'city'              => sanitize_text_field( $credit_card_data['city'] ),
                'state'             => sanitize_text_field( $credit_card_data['state'] ),
                'post_code'         => sanitize_text_field( $credit_card_data['post_code'] )
            ];
        }

        $args = [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'method'  => 'POST',
            'timeout' => 45
        ];

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['CODE'] ) && $data['CODE'] === '0000' ) {
            return $data['DATA'];
        } else {
            $desc = isset( $data['DESC'] ) ? $data['DESC'] : __( 'Unknown error', 'payvalida-woo' );
            return new WP_Error( 'payvalida_subscription_error', $desc );
        }
    }

    /**
     * Cancels an existing subscription on Payvalida.
     *
     * @param string $subscription_id  The ID of the subscription to cancel.
     *
     * @return array|WP_Error          Response data on success, WP_Error on failure.
     */
    public static function cancelSubscription( $subscription_id ) {
        $base_url = self::get_base_url();
        $endpoint = '/v4/subscriptions';
        $url      = $base_url . $endpoint;

        $merchant   = PAYVALIDA_MERCHANT;
        $fixed_hash = PAYVALIDA_FIXED_HASH;
        $checksum   = hash('sha512', $merchant . $subscription_id . $fixed_hash);

        $payload = [
            'merchant'  => $merchant,
            'id'        => $subscription_id,
            'checksum'  => $checksum,
            'timestamp' => time()
        ];

        $args = [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'method'  => 'DELETE',
            'timeout' => 45
        ];

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ( isset( $data['CODE'] ) && $data['CODE'] === '0000' ) {
            return $data;
        } else {
            $desc = isset( $data['DESC'] ) ? $data['DESC'] : __( 'Unknown error', 'payvalida-woo' );
            return new WP_Error( 'payvalida_cancel_error', $desc );
        }
    }

    /**
     * Lists subscriptions for the merchant.
     *
     * @param int|string $page       Search page number. Default: 1.
     * @param string     $sort       Order type, either 'DESC' or 'ASC'. Default: 'DESC'.
     * @param string     $request_id Search request ID. Default: '10'.
     *
     * @return array|WP_Error  List of subscriptions data on success, WP_Error on failure.
     */
    public static function listSubscriptions( $page = 1, $sort = 'DESC', $request_id = '10' ) {
        $base_url = self::get_base_url();
        $endpoint = '/subscriptions/merchants/api/list/subscriptions';
        $url      = $base_url . $endpoint;
        $merchant = PAYVALIDA_MERCHANT;
        $fixed_hash = PAYVALIDA_FIXED_HASH;
        $checksum = hash('sha512', $merchant . $request_id . $fixed_hash);

        $payload = [
            'merchant'   => $merchant,
            'request_id' => $request_id,
            'page'       => (string) $page,
            'sort'       => strtoupper($sort),
            'checksum'   => $checksum
        ];

        $args = [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'method'  => 'POST',
            'timeout' => 45,
        ];

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['CODE'] ) && $data['CODE'] === '0000' ) {
            return $data['DATA'];
        } else {
            $desc = isset( $data['DESC'] ) ? $data['DESC'] : __( 'Unknown error', 'payvalida-woo' );
            return new WP_Error( 'payvalida_list_error', $desc );
        }
    }

    /**
     * Retrieves a single subscription's details.
     *
     * @param string $subscription_id  The ID of the subscription to retrieve.
     * @param string $request_id       Search request ID. Default: '10'.
     *
     * @return array|WP_Error  Subscription data on success, WP_Error on failure.
     */
    public static function getSubscription( $subscription_id, $request_id = '10' ) {
        $base_url = self::get_base_url();
        $endpoint = '/subscriptions/merchants/api/get/subscription';
        $url      = $base_url . $endpoint;
        $merchant = PAYVALIDA_MERCHANT;
        $fixed_hash = PAYVALIDA_FIXED_HASH;
        $checksum = hash('sha512', $merchant . $subscription_id . $request_id . $fixed_hash);

        $payload = [
            'merchant'        => $merchant,
            'subscription_id' => $subscription_id,
            'request_id'      => $request_id,
            'checksum'        => $checksum,
        ];

        $args = [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'method'  => 'POST',
            'timeout' => 45,
        ];

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['CODE'] ) && $data['CODE'] === '0000' ) {
            return $data['DATA'];
        } else {
            $desc = isset( $data['DESC'] ) ? $data['DESC'] : __( 'Unknown error', 'payvalida-woo' );
            return new WP_Error( 'payvalida_get_error', $desc );
        }
    }
}
