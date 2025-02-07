<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Handle Update/Sync action.
if ( isset( $_POST['payvalida_sync_action']) && check_admin_referer('payvalida_sync_action_nonce') ) {
    $result = Payvalida_Admin::update_payvalida_plans();
    if ( is_wp_error( $result ) ) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
    } else {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plans updated successfully.', 'payvalida-woo' ) . '</p></div>';
    }
}

// Handle Reset Local Data action.
if ( isset( $_POST['payvalida_reset_data']) && check_admin_referer('payvalida_reset_data_nonce') ) {
    $deleted = Payvalida_Admin::reset_local_data();
    echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Reset complete. Removed plan meta from %d entries.', 'payvalida-woo' ), $deleted ) . '</p></div>';
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Payvalida Plans', 'payvalida-woo' ); ?></h1>
    <form method="post" style="display:inline-block; margin-right:20px;">
        <?php wp_nonce_field( 'payvalida_sync_action_nonce' ); ?>
        <input type="hidden" name="payvalida_sync_action" value="1" />
        <?php submit_button( __( 'Update / Sync Payvalida Plans', 'payvalida-woo' ), 'primary', 'submit', false ); ?>
    </form>
    <form method="post" style="display:inline-block;">
        <?php wp_nonce_field( 'payvalida_reset_data_nonce' ); ?>
        <input type="hidden" name="payvalida_reset_data" value="1" />
        <?php submit_button( __( 'Reset Local Data', 'payvalida-woo' ), 'secondary', 'submit', false, [ 'onclick' => "return confirm('Are you sure you want to delete all local Payvalida plan data?');" ] ); ?>
    </form>
    <?php
    // Output the full local plans table.
    Payvalida_Admin::list_plans_table();
    ?>
</div>
