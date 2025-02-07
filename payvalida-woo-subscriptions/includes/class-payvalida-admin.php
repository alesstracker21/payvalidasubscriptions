<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

class Payvalida_Admin {

    /**
     * Registers our settings and adds the submenu.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts' ] );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Payvalida Plans', 'payvalida-woo'),
            __('Payvalida Plans', 'payvalida-woo'),
            'manage_options',
            'payvalida-plans',
            [ $this, 'render_admin_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'payvalida_settings_group', 'payvalida_environment', [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_environment' ],
            'default'           => 'sandbox'
        ]);
        // (Other settings registration, e.g. for Merchant, Fixed Hash, etc.)
    }

    public function sanitize_environment($val) {
        return ($val === 'production') ? 'production' : 'sandbox';
    }

    public function admin_scripts($hook) {
        if ( $hook === 'woocommerce_page_payvalida-plans' ) {
            wp_add_inline_script(
                'jquery-core',
                "
                jQuery(document).ready(function($){
                    $('.payvalida-expand-toggle').on('click', function(e){
                        e.preventDefault();
                        var target = $(this).data('target');
                        $('#' + target).toggle();
                    });
                });
                "
            );
        }
    }

    /**
     * Renders the main admin page (which now loads tabs).
     */
    public function render_admin_page() {
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'log';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Payvalida Integration', 'payvalida-woo' ); ?></h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=payvalida-plans&tab=log" class="nav-tab <?php echo ( $current_tab === 'log' ) ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Log', 'payvalida-woo' ); ?></a>
                <a href="?page=payvalida-plans&tab=plans" class="nav-tab <?php echo ( $current_tab === 'plans' ) ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Plans', 'payvalida-woo' ); ?></a>
                <a href="?page=payvalida-plans&tab=subscriptions" class="nav-tab <?php echo ( $current_tab === 'subscriptions' ) ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Subscriptions', 'payvalida-woo' ); ?></a>
                <a href="?page=payvalida-plans&tab=settings" class="nav-tab <?php echo ( $current_tab === 'settings' ) ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'payvalida-woo' ); ?></a>
            </h2>
            <?php
            $file = plugin_dir_path( __FILE__ ) . '../admin-tabs/' . $current_tab . '.php';
            if ( file_exists( $file ) ) {
                include $file;
            } else {
                echo '<p>' . esc_html__( 'Invalid tab.', 'payvalida-woo' ) . '</p>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * Helper: Appends a log entry to the log file if "save logging" is enabled.
     *
     * @param string $message
     */
    public static function add_log_entry( $message ) {
        if ( get_option( 'payvalida_save_logging' ) ) {
            $log_file = defined('PAYVALIDA_LOG_FILE') ? PAYVALIDA_LOG_FILE : ( plugin_dir_path(__FILE__) . '../payvalida.log' );
            $date     = current_time( 'mysql' );
            $entry    = "[$date] $message\n";
            file_put_contents( $log_file, $entry, FILE_APPEND );
        }
    }

    /**
     * Updates/Syncs plans for all subscription products and variations.
     */
    public static function update_payvalida_plans() {
        $environment = get_option('payvalida_environment', 'sandbox');

        // Get all products and variations.
        $all_products   = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
        ]);
        $all_variations = get_posts([
            'post_type'      => 'product_variation',
            'posts_per_page' => -1,
        ]);

        foreach ( $all_products as $product ) {
            self::maybe_create_plan_for_product( $product->ID, $environment, false );
        }
        foreach ( $all_variations as $variation ) {
            self::maybe_create_plan_for_product( $variation->ID, $environment, true );
        }

        self::add_log_entry( __('Update/Sync process completed.', 'payvalida-woo') );
        return true;
    }

    /**
     * Checks if a product/variation is a subscription and creates a plan if needed.
     *
     * @param int    $post_id
     * @param string $environment
     * @param bool   $is_variation
     */
    private static function maybe_create_plan_for_product( $post_id, $environment, $is_variation = false ) {
        // Skip variable parents if not a variation.
        if ( ! $is_variation ) {
            $wc_product = wc_get_product( $post_id );
            if ( $wc_product && $wc_product->is_type('variable') ) {
                self::add_log_entry( sprintf(
                    'Skipping plan creation for variable parent product "%s" (ID %d).',
                    get_the_title($post_id),
                    $post_id
                ) );
                return;
            }
        }

        // Check subscription meta.
        $interval       = get_post_meta( $post_id, '_subscription_period', true );
        $interval_count = get_post_meta( $post_id, '_subscription_period_interval', true );
        $amount         = get_post_meta( $post_id, '_subscription_price', true );
        if ( empty($interval) || empty($interval_count) || empty($amount) ) {
            return; // Not a subscription product.
        }

        // Build description and get SKU.
        $desc = get_the_title($post_id);
        $wc_product = wc_get_product($post_id);
        $sku = $wc_product ? $wc_product->get_sku() : '';
        if ( empty($sku) ) {
            $sku = 'post_id_' . $post_id;
        }
        if ( $is_variation ) {
            $attributes  = $wc_product->get_variation_attributes();
            $attr_txt    = [];
            foreach ( $attributes as $k => $v ) {
                $k2 = str_replace('attribute_', '', $k);
                $attr_txt[] = $k2 . ': ' . $v;
            }
            $desc .= ' (' . implode(', ', $attr_txt) . ')';
        }

        // Check plan history.
        $history = get_post_meta( $post_id, '_payvalida_plan_history', true );
        if ( ! is_array($history) ) {
            $history = [];
        }
        $needs_new_plan = true;
        if ( ! empty($history) ) {
            $latest = end($history);
            if (
                $latest['interval']       === $interval &&
                $latest['interval_count'] === $interval_count &&
                $latest['amount']         === $amount &&
                isset($latest['sku'])      && $latest['sku'] === $sku
            ) {
                $needs_new_plan = false;
                self::add_log_entry( sprintf(
                    'No changes for %s (ID %d), skipping.',
                    $desc,
                    $post_id
                ) );
            }
        }

        if ( $needs_new_plan ) {
            // Create the plan via the API.
            $plan_id = Payvalida_API::createPlan($interval, $interval_count, $amount, $desc, $environment);
            if ( is_wp_error($plan_id) ) {
                self::add_log_entry( sprintf(
                    'Error creating plan for %s (ID %d): %s',
                    $desc,
                    $post_id,
                    $plan_id->get_error_message()
                ) );
            } else {
                $new_version_num = count($history) + 1;
                $history[] = [
                    'version'        => 'v' . $new_version_num,
                    'plan_id'        => $plan_id,
                    'interval'       => $interval,
                    'interval_count' => $interval_count,
                    'amount'         => $amount,
                    'description'    => $desc,
                    'environment'    => $environment,
                    'created_at'     => current_time('mysql'),
                    'sku'            => $sku,
                    'product_id'     => $post_id,
                ];
                update_post_meta( $post_id, '_payvalida_plan_history', $history );
                update_post_meta( $post_id, '_payvalida_latest_plan_id', $plan_id );

                self::add_log_entry( sprintf(
                    'Created new plan for %s (ID %d). Plan ID: %s',
                    $desc,
                    $post_id,
                    $plan_id
                ) );
            }
        }
    }

    /**
     * Resets all local plan data.
     *
     * @return int The number of meta entries removed.
     */
    public static function reset_local_data() {
        $all_posts = get_posts([
            'post_type'      => ['product', 'product_variation'],
            'posts_per_page' => -1
        ]);
        $count_deleted = 0;
        foreach ( $all_posts as $p ) {
            if ( get_post_meta($p->ID, '_payvalida_plan_history', true) ) {
                delete_post_meta($p->ID, '_payvalida_plan_history');
                $count_deleted++;
            }
            if ( get_post_meta($p->ID, '_payvalida_latest_plan_id', true) ) {
                delete_post_meta($p->ID, '_payvalida_latest_plan_id');
                $count_deleted++;
            }
        }
        self::add_log_entry( sprintf(
            __('Reset local data complete. Removed plan meta from %d entries.', 'payvalida-woo'),
            $count_deleted
        ) );
        return $count_deleted;
    }

    /**
     * Outputs the local plans table exactly as before.
     */
    public static function list_plans_table() {
        // 1) All products with plan history.
        $prod_args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => '_payvalida_plan_history',
                    'compare' => 'EXISTS',
                ],
            ],
        ];
        $products_with_plans = get_posts($prod_args);

        // 2) All variations with plan history.
        $var_args = [
            'post_type'      => 'product_variation',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => '_payvalida_plan_history',
                    'compare' => 'EXISTS',
                ],
            ],
        ];
        $variations_with_plans = get_posts($var_args);

        // Group variations by parent.
        $variations_by_parent = [];
        foreach ( $variations_with_plans as $var_post ) {
            $parent_id = wp_get_post_parent_id($var_post->ID);
            if ( ! isset($variations_by_parent[$parent_id]) ) {
                $variations_by_parent[$parent_id] = [];
            }
            $variations_by_parent[$parent_id][] = $var_post;
        }

        // Identify orphan variations (variations whose parent does not have plan history).
        $parent_ids_with_plans = wp_list_pluck($products_with_plans, 'ID');
        $orphan_variations = [];
        foreach ( $variations_by_parent as $parent_id => $vars ) {
            if ( ! in_array($parent_id, $parent_ids_with_plans, true) ) {
                $orphan_variations[$parent_id] = $vars;
            }
        }

        echo '<h2>' . __('Existing Payvalida Plans (Local)', 'payvalida-woo') . '</h2>';
        if ( empty($products_with_plans) && empty($variations_with_plans) ) {
            echo '<p>' . __('No local plan history found.', 'payvalida-woo') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . __('Product / Variation', 'payvalida-woo') . '</th><th>' . __('Latest Plan', 'payvalida-woo') . '</th><th>' . __('Actions', 'payvalida-woo') . '</th></tr></thead>';
        echo '<tbody>';

        // Display products with plans.
        foreach ( $products_with_plans as $p ) {
            $pid = $p->ID;
            $history = get_post_meta($pid, '_payvalida_plan_history', true);
            if ( ! is_array($history) || empty($history) ) {
                continue;
            }
            $latest = end($history);
            $toggleId = 'prod-hist-' . $pid;

            // Parent row.
            echo '<tr style="background:#f9f9f9;">';
            echo '<td colspan="3"><strong>' . esc_html($p->post_title) . ' (Product #' . $pid . ')</strong></td>';
            echo '</tr>';

            // Row for the parent's own plan history.
            echo '<tr>';
            echo '<td>— ' . __('Parent Plan', 'payvalida-woo') . '</td>';
            echo '<td>';
            printf(
                '%s (Plan ID: %s, Amount: %s, Interval: %s/%s)',
                esc_html($latest['version']),
                esc_html($latest['plan_id']),
                esc_html($latest['amount']),
                esc_html($latest['interval']),
                esc_html($latest['interval_count'])
            );
            echo '</td>';
            echo '<td><a href="#" class="payvalida-expand-toggle" data-target="' . esc_attr($toggleId) . '">' . __('View All Versions', 'payvalida-woo') . '</a></td>';
            echo '</tr>';

            // Hidden row with full history.
            echo '<tr id="' . esc_attr($toggleId) . '" style="display:none;">';
            echo '<td colspan="3">';
            echo '<ul>';
            foreach ( $history as $entry ) {
                printf(
                    '<li><strong>%s</strong> - Plan ID: %s | Amount: %s | Interval: %s/%s | Env: %s | Created: %s</li>',
                    esc_html($entry['version']),
                    esc_html($entry['plan_id']),
                    esc_html($entry['amount']),
                    esc_html($entry['interval']),
                    esc_html($entry['interval_count']),
                    esc_html($entry['environment']),
                    esc_html($entry['created_at'])
                );
            }
            echo '</ul>';
            echo '</td>';
            echo '</tr>';

            // Also show variations for this product (if any).
            if ( ! empty($variations_by_parent[$pid]) ) {
                foreach ( $variations_by_parent[$pid] as $var_post ) {
                    $vid   = $var_post->ID;
                    $vhist = get_post_meta($vid, '_payvalida_plan_history', true);
                    if ( ! is_array($vhist) || empty($vhist) ) {
                        continue;
                    }
                    $vlatest = end($vhist);
                    $toggleVar = 'var-hist-' . $vid;

                    // Variation attributes.
                    $var_obj = wc_get_product($vid);
                    $atts    = $var_obj->get_variation_attributes();
                    $att_txt = [];
                    foreach ( $atts as $k => $v ) {
                        $k2 = str_replace('attribute_', '', $k);
                        $att_txt[] = $k2 . ': ' . $v;
                    }
                    $atts_str = implode(', ', $att_txt);

                    echo '<tr>';
                    echo '<td>— Variation #' . $vid . ' (' . esc_html($atts_str) . ')</td>';
                    echo '<td>';
                    printf(
                        '%s (Plan ID: %s, Amount: %s, Interval: %s/%s)',
                        esc_html($vlatest['version']),
                        esc_html($vlatest['plan_id']),
                        esc_html($vlatest['amount']),
                        esc_html($vlatest['interval']),
                        esc_html($vlatest['interval_count'])
                    );
                    echo '</td>';
                    echo '<td><a href="#" class="payvalida-expand-toggle" data-target="' . esc_attr($toggleVar) . '">' . __('View All Versions', 'payvalida-woo') . '</a></td>';
                    echo '</tr>';

                    echo '<tr id="' . esc_attr($toggleVar) . '" style="display:none;">';
                    echo '<td colspan="3">';
                    echo '<ul>';
                    foreach ( $vhist as $vh ) {
                        printf(
                            '<li><strong>%s</strong> - Plan ID: %s | Amount: %s | Interval: %s/%s | Env: %s | Created: %s</li>',
                            esc_html($vh['version']),
                            esc_html($vh['plan_id']),
                            esc_html($vh['amount']),
                            esc_html($vh['interval']),
                            esc_html($vh['interval_count']),
                            esc_html($vh['environment']),
                            esc_html($vh['created_at'])
                        );
                    }
                    echo '</ul>';
                    echo '</td>';
                    echo '</tr>';
                }
            }
        }

        // Display orphan variations.
        if ( ! empty($orphan_variations) ) {
            foreach ( $orphan_variations as $parent_id => $vars ) {
                $parent_product = wc_get_product($parent_id);
                $parent_title = $parent_product ? $parent_product->get_name() : __('Unknown Product', 'payvalida-woo');
                echo '<tr style="background:#f1f1f1;">';
                echo '<td colspan="3"><strong>' . esc_html($parent_title) . ' (Product #' . $parent_id . ')</strong> ' . __('(Parent product has no plan history)', 'payvalida-woo') . '</td>';
                echo '</tr>';
                foreach ( $vars as $var_post ) {
                    $vid   = $var_post->ID;
                    $vhist = get_post_meta($vid, '_payvalida_plan_history', true);
                    if ( ! is_array($vhist) || empty($vhist) ) {
                        continue;
                    }
                    $vlatest = end($vhist);
                    $toggleVar = 'orphan-var-hist-' . $vid;

                    $var_obj = wc_get_product($vid);
                    $atts    = $var_obj->get_variation_attributes();
                    $att_txt = [];
                    foreach ( $atts as $k => $v ) {
                        $k2 = str_replace('attribute_', '', $k);
                        $att_txt[] = $k2 . ': ' . $v;
                    }
                    $atts_str = implode(', ', $att_txt);

                    echo '<tr>';
                    echo '<td>— Variation #' . $vid . ' (' . esc_html($atts_str) . ')</td>';
                    echo '<td>';
                    printf(
                        '%s (Plan ID: %s, Amount: %s, Interval: %s/%s)',
                        esc_html($vlatest['version']),
                        esc_html($vlatest['plan_id']),
                        esc_html($vlatest['amount']),
                        esc_html($vlatest['interval']),
                        esc_html($vlatest['interval_count'])
                    );
                    echo '</td>';
                    echo '<td><a href="#" class="payvalida-expand-toggle" data-target="' . esc_attr($toggleVar) . '">' . __('View All Versions', 'payvalida-woo') . '</a></td>';
                    echo '</tr>';

                    echo '<tr id="' . esc_attr($toggleVar) . '" style="display:none;">';
                    echo '<td colspan="3">';
                    echo '<ul>';
                    foreach ( $vhist as $vh ) {
                        printf(
                            '<li><strong>%s</strong> - Plan ID: %s | Amount: %s | Interval: %s/%s | Env: %s | Created: %s</li>',
                            esc_html($vh['version']),
                            esc_html($vh['plan_id']),
                            esc_html($vh['amount']),
                            esc_html($vh['interval']),
                            esc_html($vh['interval_count']),
                            esc_html($vh['environment']),
                            esc_html($vh['created_at'])
                        );
                    }
                    echo '</ul>';
                    echo '</td>';
                    echo '</tr>';
                }
            }
        }

        echo '</tbody></table>';
    }
}
