<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Payvalida Settings', 'payvalida-woo' ); ?></h1>
    <form method="post" action="options.php">
        <?php
        settings_fields( 'payvalida_settings_group' );
        do_settings_sections( 'payvalida_settings' );
        ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row"><?php esc_html_e( 'Merchant', 'payvalida-woo' ); ?></th>
                <td><input type="text" name="payvalida_merchant" value="<?php echo esc_attr( get_option( 'payvalida_merchant' ) ); ?>" class="regular-text" /></td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php esc_html_e( 'Fixed Hash', 'payvalida-woo' ); ?></th>
                <td><input type="text" name="payvalida_fixed_hash" value="<?php echo esc_attr( get_option( 'payvalida_fixed_hash' ) ); ?>" class="regular-text" /></td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php esc_html_e( 'Enable Save Logging', 'payvalida-woo' ); ?></th>
                <td>
                    <input type="checkbox" name="payvalida_save_logging" value="1" <?php checked( get_option( 'payvalida_save_logging' ), 1 ); ?> />
                    <p class="description"><?php esc_html_e( 'Warning: Logging can consume a lot of disk space.', 'payvalida-woo' ); ?></p>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row"><?php esc_html_e( 'Environment', 'payvalida-woo' ); ?></th>
                <td>
                    <select name="payvalida_environment">
                        <option value="sandbox" <?php selected( get_option( 'payvalida_environment', 'sandbox' ), 'sandbox' ); ?>><?php esc_html_e( 'Sandbox', 'payvalida-woo' ); ?></option>
                        <option value="production" <?php selected( get_option( 'payvalida_environment', 'sandbox' ), 'production' ); ?>><?php esc_html_e( 'Production', 'payvalida-woo' ); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
</div>
