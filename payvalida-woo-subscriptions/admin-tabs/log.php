<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Handle Clear Log button:
if ( isset( $_POST['clear_log'] ) && check_admin_referer( 'clear_log_nonce' ) ) {
    file_put_contents( PAYVALIDA_LOG_FILE, '' );
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Log cleared.', 'payvalida-woo' ) . '</p></div>';
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Payvalida Log', 'payvalida-woo' ); ?></h1>
    <form method="post">
        <?php wp_nonce_field( 'clear_log_nonce' ); ?>
        <input type="hidden" name="clear_log" value="1" />
        <?php submit_button( __( 'Clear Log', 'payvalida-woo' ), 'secondary' ); ?>
    </form>
    <h2><?php esc_html_e( 'Log Output', 'payvalida-woo' ); ?></h2>
    <div style="background:#fff; border:1px solid #ccc; padding:10px; max-height:500px; overflow:auto;">
        <pre><?php echo esc_html( file_exists( PAYVALIDA_LOG_FILE ) ? file_get_contents( PAYVALIDA_LOG_FILE ) : '' ); ?></pre>
    </div>
</div>
