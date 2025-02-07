<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Determine current page (for pagination):
$current_page = isset( $_GET['paged'] ) ? intval( $_GET['paged'] ) : 1;

// Call the API to list subscriptions:
$response = Payvalida_API::listSubscriptions( $current_page );
if ( is_wp_error( $response ) ) {
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $response->get_error_message() ) . '</p></div>';
    $subscriptions = [];
    $pagination = [];
} else {
    $subscriptions = isset( $response['subscriptions'] ) ? $response['subscriptions'] : [];
    $pagination    = isset( $response['pagination'] ) ? $response['pagination'] : [];
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Payvalida Subscriptions', 'payvalida-woo' ); ?></h1>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Name', 'payvalida-woo' ); ?></th>
                <th><?php esc_html_e( 'Product', 'payvalida-woo' ); ?></th>
                <th><?php esc_html_e( 'Status', 'payvalida-woo' ); ?></th>
                <th><?php esc_html_e( 'Start Date', 'payvalida-woo' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ( ! empty( $subscriptions ) ) {
                foreach ( $subscriptions as $sub ) {
                    $customer = isset( $sub['customer'] ) ? $sub['customer'] : [];
                    $name     = trim( ( isset( $customer['first_name'] ) ? $customer['first_name'] : '' ) . ' ' . ( isset( $customer['last_name'] ) ? $customer['last_name'] : '' ) );
                    $plan_id  = isset( $sub['plan_id'] ) ? $sub['plan_id'] : '';
                    // Look up the local product with meta _payvalida_latest_plan_id = $plan_id:
                    $query = new WP_Query( [
                        'post_type'  => [ 'product', 'product_variation' ],
                        'meta_query' => [
                            [
                                'key'   => '_payvalida_latest_plan_id',
                                'value' => $plan_id,
                                'compare' => '='
                            ]
                        ]
                    ] );
                    $product_title = __( 'Unknown', 'payvalida-woo' );
                    if ( $query->have_posts() ) {
                        $query->the_post();
                        $product_title = get_the_title();
                        wp_reset_postdata();
                    }
                    $status     = isset( $sub['status'] ) ? $sub['status'] : '';
                    $start_date = isset( $sub['start_date'] ) ? $sub['start_date'] : '';
                    echo '<tr>';
                    echo '<td>' . esc_html( $name ) . '</td>';
                    echo '<td>' . esc_html( $product_title ) . '</td>';
                    echo '<td>' . esc_html( $status ) . '</td>';
                    echo '<td>' . esc_html( $start_date ) . '</td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="4">' . esc_html__( 'No subscriptions found.', 'payvalida-woo' ) . '</td></tr>';
            }
            ?>
        </tbody>
    </table>
    <?php
    // Pagination links:
    if ( ! empty( $pagination ) ) {
        $total_pages  = isset( $pagination['total_pages'] ) ? intval( $pagination['total_pages'] ) : 1;
        $current_page = isset( $pagination['page_num'] ) ? intval( $pagination['page_num'] ) : 1;
        $base_url     = add_query_arg( [ 'tab' => 'subscriptions', 'paged' => '%#%' ] );
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links( [
            'base'      => $base_url,
            'format'    => '',
            'current'   => $current_page,
            'total'     => $total_pages,
        ] );
        echo '</div></div>';
    }
    ?>
</div>
