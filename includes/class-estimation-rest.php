<?php
/**
 * REST API endpoints for the estimation tool.
 *
 * @package estimation-tool-for-woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estitofo_REST {

    const NAMESPACE = 'estitofo/v1';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(self::NAMESPACE, '/search', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => array(
                'term' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            ),
            'callback' => array(__CLASS__, 'search_products'),
        ));

        register_rest_route(self::NAMESPACE, '/suggested', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => array(__CLASS__, 'suggested_products'),
        ));

        register_rest_route(self::NAMESPACE, '/categories', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => array(__CLASS__, 'list_categories'),
        ));

        register_rest_route(self::NAMESPACE, '/save', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => array(__CLASS__, 'save_estimation'),
        ));

        register_rest_route(self::NAMESPACE, '/resume/(?P<token>[A-Za-z0-9]+)', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => array(__CLASS__, 'resume'),
            'args' => array(
                'token' => array('sanitize_callback' => 'sanitize_text_field'),
            ),
        ));
    }

    public static function search_products(WP_REST_Request $r) {
        if (!function_exists('wc_get_products')) {
            return new WP_Error('wc_missing', __('WooCommerce is not active.', 'estimation-tool-for-woocommerce'), array('status' => 503));
        }
        $term = (string) $r->get_param('term');
        if (mb_strlen($term) < 2) {
            return rest_ensure_response(array());
        }
        $category = absint($r->get_param('category'));
        $products = self::run_product_search($term, $category, 12);
        return rest_ensure_response(array_values(array_map(array(__CLASS__, 'shape'), $products)));
    }

    public static function suggested_products() {
        if (!function_exists('wc_get_products')) {
            return rest_ensure_response(array());
        }
        $products = wc_get_products(array(
            'status'  => 'publish',
            'limit'   => 8,
            'orderby' => 'rand',
            'type'    => self::allowed_product_types(),
        ));
        return rest_ensure_response(array_values(array_map(array(__CLASS__, 'shape'), $products)));
    }

    /**
     * Allowed product types for search and suggestions.
     * Filterable via `estitofo_search_product_types`.
     */
    public static function allowed_product_types() {
        return apply_filters(
            'estitofo_search_product_types',
            array('simple', 'variable', 'grouped', 'external')
        );
    }

    /**
     * Run the product search with cascading fallbacks so user-added products
     * always surface, regardless of catalog-visibility taxonomy state or the
     * quirks of `wc_get_products` 's' parameter on various WC versions.
     *
     * Order of attempts (the first non-empty result wins):
     *   1. wc_get_products with 's' (matches title + SKU via WC's posts_search filter)
     *   2. wc_get_products with 'name__like' (LIKE on post_title)
     *   3. wc_get_products with 'sku' (exact SKU match)
     *   4. Direct WP_Query post_type=product with 's' (last-ditch)
     *
     * @param string $term     Search term (already length-checked by caller).
     * @param int    $category Optional category term_id; 0 for all.
     * @param int    $limit    Max results.
     * @return WC_Product[]
     */
    public static function run_product_search($term, $category = 0, $limit = 12) {
        $types = self::allowed_product_types();

        $base = array(
            'status' => 'publish',
            'limit'  => $limit,
            'type'   => $types,
        );
        if ($category > 0) {
            $slug = get_term_field('slug', $category, 'product_cat');
            if (!is_wp_error($slug) && $slug) {
                $base['category'] = array($slug);
            }
        }

        // 1. WC's broad search ('s' goes through WP_Query + WC's SKU posts_search filter).
        $products = wc_get_products(array_merge($base, array('s' => $term)));
        if (!empty($products)) {
            return $products;
        }

        // 2. Title LIKE.
        $products = wc_get_products(array_merge($base, array('name__like' => $term)));
        if (!empty($products)) {
            return $products;
        }

        // 3. Exact SKU.
        $products = wc_get_products(array_merge($base, array('sku' => $term)));
        if (!empty($products)) {
            return $products;
        }

        // 4. Direct WP_Query — catches everything WC's query layer might filter out.
        $wp_args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            's'              => $term,
            'no_found_rows'  => true,
        );
        if (!empty($base['category'])) {
            $wp_args['tax_query'] = array(array(
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $base['category'],
            ));
        }
        $q = new WP_Query($wp_args);
        $out = array();
        if ($q->have_posts()) {
            foreach ($q->posts as $post) {
                $p = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;
                if (!$p) continue;
                if (!in_array($p->get_type(), $types, true)) continue;
                $out[] = $p;
            }
        }
        wp_reset_postdata();
        return $out;
    }

    public static function list_categories() {
        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'number'     => 30,
        ));
        if (is_wp_error($terms)) {
            return rest_ensure_response(array());
        }
        $out = array();
        foreach ($terms as $term) {
            $out[] = array(
                'id'   => (int) $term->term_id,
                'name' => $term->name,
            );
        }
        return rest_ensure_response($out);
    }

    private static function shape($product) {
        return array(
            'id'         => $product->get_id(),
            'title'      => wp_strip_all_tags($product->get_name()),
            'price'      => (float) $product->get_price(),
            'price_html' => wp_kses_post($product->get_price_html()),
            'image'      => get_the_post_thumbnail_url($product->get_id(), 'thumbnail'),
        );
    }

    public static function save_estimation(WP_REST_Request $r) {
        $nonce = $r->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('bad_nonce', __('Invalid nonce', 'estimation-tool-for-woocommerce'), array('status' => 403));
        }
        $payload = $r->get_json_params();
        if (!empty($payload['website'])) {
            return new WP_Error('spam', 'Spam detected', array('status' => 400));
        }

        $name    = sanitize_text_field((string) ($payload['name'] ?? ''));
        $email   = sanitize_email((string) ($payload['email'] ?? ''));
        $phone   = sanitize_text_field((string) ($payload['phone'] ?? ''));
        $company = sanitize_text_field((string) ($payload['company'] ?? ''));
        $address = sanitize_text_field((string) ($payload['address'] ?? ''));
        $notes   = sanitize_textarea_field((string) ($payload['customer_notes'] ?? ''));
        $total   = (float) ($payload['total'] ?? 0);
        $products = isset($payload['products']) && is_array($payload['products']) ? $payload['products'] : array();

        if (empty($name) || !is_email($email) || empty($phone) || empty($products)) {
            return new WP_Error('invalid_input', __('Please fill all required fields correctly.', 'estimation-tool-for-woocommerce'), array('status' => 400));
        }

        $clean = array();
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $clean[] = array(
                'id'       => isset($p['id']) ? absint($p['id']) : 0,
                'title'    => isset($p['title']) ? sanitize_text_field($p['title']) : '',
                'price'    => isset($p['price']) ? (float) $p['price'] : 0,
                'quantity' => isset($p['quantity']) ? max(1, absint($p['quantity'])) : 1,
                'image'    => isset($p['image']) ? esc_url_raw($p['image']) : '',
                'note'     => isset($p['note']) ? sanitize_text_field($p['note']) : '',
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'estimation_submissions';
        $row = array(
            'name'           => $name,
            'email'          => $email,
            'phone'          => $phone,
            'company'        => $company,
            'address'        => $address,
            'customer_notes' => $notes,
            'products'       => wp_json_encode($clean),
            'total'          => $total,
            'status'         => 'publish',
            'workflow_status'=> 'new',
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table.
        $result = $wpdb->insert($table, $row);
        if (false === $result) {
            return new WP_Error('save_failed', __('Failed to save estimation.', 'estimation-tool-for-woocommerce'), array('status' => 500));
        }
        $id = (int) $wpdb->insert_id;

        do_action('estitofo_after_submit', $id, $clean);

        return rest_ensure_response(array(
            'success' => true,
            'id'      => $id,
            'message' => __('Estimation saved.', 'estimation-tool-for-woocommerce'),
        ));
    }

    public static function resume(WP_REST_Request $r) {
        if (!(int) Estitofo_Options::get('enable_save_resume', 1)) {
            return new WP_Error('disabled', __('Save & resume is disabled.', 'estimation-tool-for-woocommerce'), array('status' => 404));
        }
        $token = (string) $r->get_param('token');
        if (strlen($token) < 16) {
            return new WP_Error('bad_token', __('Invalid token.', 'estimation-tool-for-woocommerce'), array('status' => 400));
        }
        global $wpdb;
        $table = $wpdb->prefix . 'estimation_submissions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE resume_token = %s AND status = %s LIMIT 1", $token, 'publish'));
        if (!$row) {
            return new WP_Error('not_found', __('Estimation not found or already expired.', 'estimation-tool-for-woocommerce'), array('status' => 404));
        }
        if ($row->resume_expires && strtotime($row->resume_expires) < time()) {
            return new WP_Error('expired', __('This resume link has expired.', 'estimation-tool-for-woocommerce'), array('status' => 410));
        }
        $products = json_decode((string) $row->products, true);
        return rest_ensure_response(array(
            'id'             => (int) $row->id,
            'name'           => $row->name,
            'email'          => $row->email,
            'phone'          => $row->phone,
            'company'        => $row->company,
            'address'        => $row->address,
            'customer_notes' => $row->customer_notes,
            'products'       => is_array($products) ? $products : array(),
            'total'          => (float) $row->total,
        ));
    }
}

Estitofo_REST::init();
