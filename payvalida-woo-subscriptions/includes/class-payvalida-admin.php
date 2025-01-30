<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

class Payvalida_Admin {

    private $log_messages = [];

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
            [ $this, 'render_plans_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'payvalida_settings_group', 'payvalida_environment', [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_environment' ],
            'default'           => 'sandbox'
        ]);

        add_settings_section(
            'payvalida_main_settings',
            __('Payvalida Settings', 'payvalida-woo'),
            null,
            'payvalida_settings'
        );

        add_settings_field(
            'payvalida_environment',
            __('Environment', 'payvalida-woo'),
            [ $this, 'environment_field_html' ],
            'payvalida_settings',
            'payvalida_main_settings'
        );
    }

    public function sanitize_environment($val) {
        return ($val === 'production') ? 'production' : 'sandbox';
    }

    public function environment_field_html() {
        $env = get_option('payvalida_environment', 'sandbox');
        ?>
        <select name="payvalida_environment">
            <option value="sandbox" <?php selected($env, 'sandbox'); ?>>Sandbox</option>
            <option value="production" <?php selected($env, 'production'); ?>>Production</option>
        </select>
        <?php
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

    public function render_plans_page() {
        // 1) Handle "Update / Sync" button
        if ( isset($_POST['payvalida_sync_action']) && check_admin_referer('payvalida_sync_action_nonce') ) {
            $this->update_payvalida_plans();
        }

        // 2) Handle "Reset Local Data" button // NEW
        if ( isset($_POST['payvalida_reset_data']) && check_admin_referer('payvalida_reset_data_nonce') ) {
            $this->reset_local_data();
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Payvalida Plans', 'payvalida-woo') . '</h1>';

        // Environment setting form
        echo '<form method="post" action="options.php">';
        settings_fields('payvalida_settings_group');
        do_settings_sections('payvalida_settings');
        submit_button();
        echo '</form>';

        echo '<hr>';

        // "Update / Sync" button
        echo '<form method="post" style="display:inline-block; margin-right:20px;">';
        wp_nonce_field('payvalida_sync_action_nonce');
        echo '<input type="hidden" name="payvalida_sync_action" value="1" />';
        submit_button(__('Update / Sync Payvalida Plans', 'payvalida-woo'), 'primary', 'submit', false);
        echo '</form>';

        // "Reset Local Data" button // NEW
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field('payvalida_reset_data_nonce');
        echo '<input type="hidden" name="payvalida_reset_data" value="1" />';
        submit_button(__('Reset Local Data', 'payvalida-woo'), 'secondary', 'submit', false, [
            'onclick' => "return confirm('Are you sure you want to delete all local Payvalida plan data?');"
        ]);
        echo '</form>';

        // Show log messages if any
        if ( ! empty($this->log_messages) ) {
            echo '<div id="payvalida-sync-log" style="margin-top:20px;">';
            echo '<h2>'.esc_html__('Sync / Reset Log', 'payvalida-woo').'</h2>';
            echo '<ul>';
            foreach ( $this->log_messages as $msg ) {
                printf('<li>%s</li>', esc_html($msg));
            }
            echo '</ul>';
            echo '</div>';
        }

        // Show existing plan data
        $this->list_plans_table();

        echo '</div>'; // end wrap
    }

    /**
     * On-demand update of Payvalida plans
     */
    private function update_payvalida_plans() {
        $environment = get_option('payvalida_environment', 'sandbox');

        // 1) Get all "subscription" products (simple or variable-subscription).
        //    Some sites use product_type=subscription or product_type=variable-subscription,
        //    but let's also allow ANY product that might have subscription meta.
        //    We'll handle the filtering in maybe_create_plan_for_product().
        $all_products = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
            // We won't do a tax_query here; we'll just check meta inside the loop if needed.
        ]);

        // 2) Get all variations (there might be variable-subscription variations, etc.)
        $all_variations = get_posts([
            'post_type'      => 'product_variation',
            'posts_per_page' => -1,
        ]);

        // Process "simple" or "variable" parent products
        foreach ( $all_products as $product ) {
            $this->maybe_create_plan_for_product( $product->ID, $environment );
        }

        // Process variations
        foreach ( $all_variations as $variation ) {
            $this->maybe_create_plan_for_product( $variation->ID, $environment, true );
        }

        $this->log_messages[] = __('Update/Sync process completed.', 'payvalida-woo');
    }

    /**
    * Decides if a product/variation is a subscription, and if so, whether a new Payvalida plan is needed.
    * But SKIPS creation for the PARENT of variable products.
    *
    * @param int    $post_id
    * @param string $environment
    * @param bool   $is_variation  (true if explicitly known this is a variation)
    */
    private function maybe_create_plan_for_product( $post_id, $environment, $is_variation = false ) {
        // 1) Possibly skip variable parent
        if ( ! $is_variation ) {
            $wc_product = wc_get_product( $post_id );
            if ( $wc_product && $wc_product->is_type('variable') ) {
                $this->log_messages[] = sprintf(
                    'Skipping plan creation for variable parent product "%s" (ID %d).',
                    get_the_title($post_id),
                    $post_id
                );
                return;
            }
        }

        // 2) Check subscription meta
        $interval       = get_post_meta( $post_id, '_subscription_period', true );
        $interval_count = get_post_meta( $post_id, '_subscription_period_interval', true );
        $amount         = get_post_meta( $post_id, '_subscription_price', true );
        if ( empty($interval) || empty($interval_count) || empty($amount) ) {
            return; // Not a subscription product
        }

        // 3) Build description + get SKU
        $desc = get_the_title($post_id);
        $wc_product = wc_get_product($post_id);
        $sku = $wc_product ? $wc_product->get_sku() : '';
        if ( empty($sku) ) {
            $sku = 'post_id_'.$post_id;
        }

        if ( $is_variation ) {
            $attributes  = $wc_product->get_variation_attributes();
            $attr_txt    = [];
            foreach ( $attributes as $k=>$v ) {
                $k2 = str_replace('attribute_', '', $k);
                $attr_txt[] = $k2 . ': ' . $v;
            }
            $desc .= ' (' . implode(', ', $attr_txt) . ')';
        }

        // 4) Check history
        $history = get_post_meta( $post_id, '_payvalida_plan_history', true );
        if ( ! is_array($history) ) {
            $history = [];
        }

        $needs_new_plan = true;
        if ( ! empty($history) ) {
            $latest = end($history);
            // NOW we compare the current subscription details AND the SKU to the last plan
            if (
                $latest['interval']       === $interval &&
                $latest['interval_count'] === $interval_count &&
                $latest['amount']         === $amount &&
                isset($latest['sku'])     && $latest['sku'] === $sku
            ) {
                $needs_new_plan = false;
                $this->log_messages[] = sprintf(
                    'No changes for %s (ID %d), skipping.',
                    $desc,
                    $post_id
                );
            }
        }

        if ( $needs_new_plan ) {
            // 5) Create the plan at Payvalida
            $plan_id = Payvalida_API::createPlan($interval, $interval_count, $amount, $desc, $environment);
            if ( is_wp_error($plan_id) ) {
                $this->log_messages[] = sprintf(
                    'Error creating plan for %s (ID %d): %s',
                    $desc,
                    $post_id,
                    $plan_id->get_error_message()
                );
            } else {
                // 6) Record new plan info, now with SKU
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
                    'sku'            => $sku,        // <--- new
                    'product_id'     => $post_id,    // <--- new (optional)
                ];
                update_post_meta( $post_id, '_payvalida_plan_history', $history );
                update_post_meta( $post_id, '_payvalida_latest_plan_id', $plan_id );

                $this->log_messages[] = sprintf(
                    'Created new plan for %s (ID %d). Plan ID: %s',
                    $desc,
                    $post_id,
                    $plan_id
                );
            }
        }
    }


    /**
     * Lists existing local plan data in a table (similar to previous code).
     */
    private function list_plans_table() {
        // 1) All products with plan history
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

        // 2) All variations with plan history
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

        // Group variations by parent
        $variations_by_parent = [];
        foreach ( $variations_with_plans as $var_post ) {
            $parent_id = wp_get_post_parent_id($var_post->ID);
            if ( ! isset($variations_by_parent[$parent_id]) ) {
                $variations_by_parent[$parent_id] = [];
            }
            $variations_by_parent[$parent_id][] = $var_post;
        }

        // Identify all parent IDs that have plans
        $parent_ids_with_plans = wp_list_pluck($products_with_plans, 'ID');

        // Identify orphan variations (parents without plan history)
        $orphan_variations = [];
        foreach ($variations_with_parent as $parent_id => $vars) {
            if ( ! in_array($parent_id, $parent_ids_with_plans, true) ) {
                $orphan_variations[$parent_id] = $vars;
            }
        }

        echo '<h2>'.__('Existing Payvalida Plans (Local)', 'payvalida-woo').'</h2>';
        if ( empty($products_with_plans) && empty($variations_with_plans) ) {
            echo '<p>'.__('No local plan history found.', 'payvalida-woo').'</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>'.__('Product / Variation','payvalida-woo').'</th><th>'.__('Latest Plan','payvalida-woo').'</th><th>'.__('Actions','payvalida-woo').'</th></tr></thead>';
        echo '<tbody>';

        // ### Display Products with Plans ###
        foreach ( $products_with_plans as $p ) {
            $pid = $p->ID;
            $history = get_post_meta($pid, '_payvalida_plan_history', true);
            if ( ! is_array($history) || empty($history) ) {
                continue;
            }
            $latest = end($history);
            $toggleId = 'prod-hist-'.$pid;

            // Parent row
            echo '<tr style="background:#f9f9f9;">';
            echo '<td colspan="3"><strong>'.esc_html($p->post_title).' (Product #'.$pid.')</strong></td>';
            echo '</tr>';

            // Row for the parent's own plan history
            echo '<tr>';
            echo '<td>— '.__('Parent Plan','payvalida-woo').'</td>';
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
            echo '<td><a href="#" class="payvalida-expand-toggle" data-target="'.esc_attr($toggleId).'">'.__('View All Versions','payvalida-woo').'</a></td>';
            echo '</tr>';

            // Hidden row w/ entire plan history
            echo '<tr id="'.esc_attr($toggleId).'" style="display:none;">';
            echo '<td colspan="3">';
            echo '<ul>';
            foreach ($history as $entry) {
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

            // Also show variations for this product (if any)
            if ( ! empty($variations_by_parent[$pid]) ) {
                foreach ($variations_by_parent[$pid] as $var_post) {
                    $vid   = $var_post->ID;
                    $vhist = get_post_meta($vid, '_payvalida_plan_history', true);
                    if ( ! is_array($vhist) || empty($vhist) ) {
                        continue;
                    }
                    $vlatest = end($vhist);
                    $toggleVar = 'var-hist-'.$vid;

                    // Variation attributes
                    $var_obj = wc_get_product($vid);
                    $atts    = $var_obj->get_variation_attributes();
                    $att_txt = [];
                    foreach ($atts as $k=>$v) {
                        $k2 = str_replace('attribute_', '', $k);
                        $att_txt[] = $k2 . ': ' . $v;
                    }
                    $atts_str = implode(', ', $att_txt);

                    // Variation row
                    echo '<tr>';
                    echo '<td>— Variation #'.$vid.' ('.esc_html($atts_str).')</td>';
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
                    echo '<td><a href="#" class="payvalida-expand-toggle" data-target="'.esc_attr($toggleVar).'">'.__('View All Versions','payvalida-woo').'</a></td>';
                    echo '</tr>';

                    // Hidden row for variation’s full history
                    echo '<tr id="'.esc_attr($toggleVar).'" style="display:none;">';
                    echo '<td colspan="3">';
                    echo '<ul>';
                    foreach ($vhist as $vh) {
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

        // ### Display Orphan Variations ###
        if ( ! empty($variations_with_plans) ) {
            // Determine orphan variations by excluding parents with plans
            $all_parent_ids = wp_list_pluck($variations_with_plans, 'ID');
            $parent_ids_with_plans = wp_list_pluck($products_with_plans, 'ID');
            $orphan_variations = [];

            foreach ( $variations_with_plans as $var_post ) {
                $parent_id = wp_get_post_parent_id($var_post->ID);
                if ( ! in_array($parent_id, $parent_ids_with_plans, true) ) {
                    if ( ! isset($orphan_variations[$parent_id]) ) {
                        $orphan_variations[$parent_id] = [];
                    }
                    $orphan_variations[$parent_id][] = $var_post;
                }
            }

            // If there are orphan variations, display them
            if ( ! empty($orphan_variations) ) {
                foreach ( $orphan_variations as $parent_id => $vars ) {
                    $parent_product = wc_get_product($parent_id);
                    if ( ! $parent_product ) {
                        $parent_title = __('Unknown Product', 'payvalida-woo');
                    } else {
                        $parent_title = $parent_product->get_name();
                    }

                    echo '<tr style="background:#f1f1f1;">';
                    echo '<td colspan="3"><strong>'.esc_html($parent_title).' (Product #'.$parent_id.')</strong> '.__('(Parent product has no plan history)', 'payvalida-woo').'</td>';
                    echo '</tr>';

                    foreach ( $vars as $var_post ) {
                        $vid   = $var_post->ID;
                        $vhist = get_post_meta($vid, '_payvalida_plan_history', true);
                        if ( ! is_array($vhist) || empty($vhist) ) {
                            continue;
                        }
                        $vlatest = end($vhist);
                        $toggleVar = 'orphan-var-hist-'.$vid;

                        // Variation attributes
                        $var_obj = wc_get_product($vid);
                        $atts    = $var_obj->get_variation_attributes();
                        $att_txt = [];
                        foreach ($atts as $k=>$v) {
                            $k2 = str_replace('attribute_', '', $k);
                            $att_txt[] = $k2 . ': ' . $v;
                        }
                        $atts_str = implode(', ', $att_txt);

                        // Variation row
                        echo '<tr>';
                        echo '<td>— Variation #'.$vid.' ('.esc_html($atts_str).')</td>';
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
                        echo '<td><a href="#" class="payvalida-expand-toggle" data-target="'.esc_attr($toggleVar).'">'.__('View All Versions','payvalida-woo').'</a></td>';
                        echo '</tr>';

                        // Hidden row for variation’s full history
                        echo '<tr id="'.esc_attr($toggleVar).'" style="display:none;">';
                        echo '<td colspan="3">';
                        echo '<ul>';
                        foreach ($vhist as $vh) {
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
        }

        echo '</tbody></table>';
    }


    /**
     * Reset all local data. Removes _payvalida_plan_history and _payvalida_latest_plan_id from ALL products and variations.
     */
    private function reset_local_data() {
        // 1) Grab all product + variations
        $all_posts = get_posts([
            'post_type' => ['product','product_variation'],
            'posts_per_page' => -1
        ]);

        $count_deleted = 0;
        foreach ($all_posts as $p) {
            // If any exist, delete them
            $meta_exists1 = get_post_meta($p->ID, '_payvalida_plan_history', true);
            $meta_exists2 = get_post_meta($p->ID, '_payvalida_latest_plan_id', true);

            if ( $meta_exists1 ) {
                delete_post_meta($p->ID, '_payvalida_plan_history');
                $count_deleted++;
            }
            if ( $meta_exists2 ) {
                delete_post_meta($p->ID, '_payvalida_latest_plan_id');
                $count_deleted++;
            }
        }

        $this->log_messages[] = sprintf(
            __('Reset local data complete. Removed plan meta from %d entries.', 'payvalida-woo'),
            $count_deleted
        );
    }
}
