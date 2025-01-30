<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

class Payvalida_API {

    /**
     * Creates a Payvalida plan
     *
     * @param string $interval
     * @param string $interval_count
     * @param string $amount
     * @param string $description
     * @param string $environment 'sandbox' or 'production'
     *
     * @return string|WP_Error   Plan ID on success, WP_Error on fail
     */
    public static function createPlan( $interval, $interval_count, $amount, $description, $environment = 'sandbox' ) {
        // Decide which URL
        $base_url = ( $environment === 'production' ) ? PAYVALIDA_PROD_URL : PAYVALIDA_SANDBOX_URL;
        $endpoint = '/v4/subscriptions/plans';
        $url      = $base_url . $endpoint;

        // Calculate checksum
        $merchant    = PAYVALIDA_MERCHANT;
        $fixed_hash  = PAYVALIDA_FIXED_HASH;
        $checksum_str= $merchant . $amount . $interval . $interval_count . $fixed_hash;
        $checksum    = hash('sha512', $checksum_str);

        // Build JSON payload
        $payload = [
            'merchant'      => $merchant,
            'interval'      => $interval,
            'timestamp'     => time(),
            'interval_count'=> (string) $interval_count,
            'amount'        => (string) $amount,
            'description'   => sanitize_text_field($description),
            // 'method'      => 'tc', // if needed
            'checksum'      => $checksum
        ];

        $args = [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
            'method'  => 'POST',
            'timeout' => 45
        ];

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error($response) ) {
            return $response; // Some network or WP error
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
}
