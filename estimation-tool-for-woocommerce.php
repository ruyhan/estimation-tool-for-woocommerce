<?php
/**
 * Plugin Name: Estimation Tool for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/estimation-tool-for-woocommerce/
 * Description: Adds a WooCommerce product estimation interface with PDF downloads and admin submission management.
 * Version: 3.16.8
 * Author: ruyhan
 * Author URI: https://ruyhan.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: estimation-tool-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 9.3
 */

defined('ABSPATH') || exit;

if (!class_exists('Estitofo_Plugin')) {

    final class Estitofo_Plugin {

        const VERSION = '3.16.8';
        const DB_VERSION = '1.2';
        const TEXT_DOMAIN = 'estimation-tool-for-woocommerce';
        const MIN_ELEMENTOR_VERSION = '3.5.0';
        const STATUSES = array('new', 'contacted', 'quoted', 'won', 'lost');

        private static $instance = null;

        public static function instance() {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public static function create_estimation_table() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'estimation_submissions';
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$table_name} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                name varchar(100) NOT NULL,
                email varchar(100) NOT NULL,
                phone varchar(30) NOT NULL,
                company varchar(150) NOT NULL DEFAULT '',
                address varchar(255) NOT NULL DEFAULT '',
                customer_notes text NOT NULL,
                products longtext NOT NULL,
                total decimal(10,2) NOT NULL DEFAULT 0.00,
                status varchar(20) NOT NULL DEFAULT 'publish',
                workflow_status varchar(20) NOT NULL DEFAULT 'new',
                admin_notes text NOT NULL,
                resume_token varchar(64) NOT NULL DEFAULT '',
                resume_expires datetime NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NULL,
                deleted_at datetime NULL,
                PRIMARY KEY  (id),
                KEY status (status),
                KEY workflow_status (workflow_status),
                KEY created_at (created_at),
                KEY resume_token (resume_token)
            ) {$charset_collate};";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; backfill on activation.
            $wpdb->query("UPDATE {$table_name} SET status = 'publish' WHERE status = '' OR status IS NULL");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table.
            $wpdb->query("UPDATE {$table_name} SET workflow_status = 'new' WHERE workflow_status = '' OR workflow_status IS NULL");
            update_option('estitofo_db_version', self::DB_VERSION);
        }

        private function __construct() {
            $this->define_constants();
            $this->includes();
            $this->init_hooks();
        }

        private function define_constants() {
            if (!defined('ESTITOFO_VERSION')) {
                define('ESTITOFO_VERSION', self::VERSION);
            }
            if (!defined('ESTITOFO_PATH')) {
                define('ESTITOFO_PATH', plugin_dir_path(__FILE__));
            }
            if (!defined('ESTITOFO_URL')) {
                define('ESTITOFO_URL', plugin_dir_url(__FILE__));
            }
            if (!defined('ESTITOFO_ASSETS_URL')) {
                define('ESTITOFO_ASSETS_URL', ESTITOFO_URL . 'assets/');
            }
            if (!defined('ESTITOFO_FILE')) {
                define('ESTITOFO_FILE', __FILE__);
            }
        }

        private function includes() {
            require_once ESTITOFO_PATH . 'includes/class-estimation-options.php';
            require_once ESTITOFO_PATH . 'includes/class-estimation-pdf.php';
            require_once ESTITOFO_PATH . 'includes/class-estimation-list-table.php';
            require_once ESTITOFO_PATH . 'includes/class-estimation-settings.php';
            require_once ESTITOFO_PATH . 'includes/class-estimation-mailer.php';
            require_once ESTITOFO_PATH . 'includes/class-estimation-dashboard.php';
            require_once ESTITOFO_PATH . 'includes/class-estimation-rest.php';
            if (!class_exists('TCPDF') && file_exists(ESTITOFO_PATH . 'tcpdf/tcpdf.php')) {
                // Skip TCPDF's bundled tcpdf_config.php — it calls define() WITHOUT
                // if (!defined()) guards (regression in 6.11.x), which causes
                // "Constant already defined" warnings whenever we pre-define a
                // constant below. tcpdf_autoconfig.php still runs and IS guarded,
                // so all PDF_* / K_* defaults are still applied — same values,
                // just without the warnings.
                if (!defined('K_TCPDF_EXTERNAL_CONFIG')) {
                    define('K_TCPDF_EXTERNAL_CONFIG', true);
                }
                // Make TCPDF throw catchable exceptions instead of die()-ing on errors
                // (e.g. unsupported image formats). Must be defined BEFORE the require.
                if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) {
                    define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
                }
                require_once ESTITOFO_PATH . 'tcpdf/tcpdf.php';
            }
        }

        private function init_hooks() {
            add_action('before_woocommerce_init', array($this, 'declare_wc_compatibility'));
            add_action('plugins_loaded', array($this, 'check_dependencies'), 20);
            add_action('admin_init', array($this, 'maybe_upgrade_db'));
            add_action('init', array($this, 'register_shortcodes'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('elementor/widgets/register', array($this, 'register_elementor_widgets'));
            add_action('elementor/elements/categories_registered', array($this, 'register_elementor_category'));
            add_action('elementor/preview/enqueue_scripts', array($this, 'enqueue_assets_in_editor'));
            add_action('wp_ajax_estitofo_search_products', array($this, 'ajax_search_products'));
            add_action('wp_ajax_nopriv_estitofo_search_products', array($this, 'ajax_search_products'));
            add_action('wp_ajax_estitofo_generate_pdf', array($this, 'generate_pdf'));
            add_action('wp_ajax_nopriv_estitofo_generate_pdf', array($this, 'generate_pdf'));
            // Public, token-protected PDF download by submission id (used in share/copy links).
            add_action('wp_ajax_estitofo_public_pdf', array($this, 'public_pdf'));
            add_action('wp_ajax_nopriv_estitofo_public_pdf', array($this, 'public_pdf'));
            add_action('wp_ajax_estitofo_submit', array($this, 'submit_estimation'));
            add_action('wp_ajax_nopriv_estitofo_submit', array($this, 'submit_estimation'));
            add_action('wp_ajax_estitofo_suggested', array($this, 'ajax_suggested_products'));
            add_action('wp_ajax_nopriv_estitofo_suggested', array($this, 'ajax_suggested_products'));
            add_action('wp_ajax_estitofo_save_resume', array($this, 'ajax_save_resume'));
            add_action('wp_ajax_nopriv_estitofo_save_resume', array($this, 'ajax_save_resume'));
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('wp_ajax_estitofo_get_products', array($this, 'get_estimation_products_ajax'));
            add_action('admin_post_estitofo_admin_pdf', array($this, 'download_admin_pdf'));
            add_action('wp_ajax_estitofo_update_workflow', array($this, 'ajax_update_workflow'));
            add_action('wp_ajax_estitofo_save_admin_notes', array($this, 'ajax_save_admin_notes'));
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
        }

        public function ajax_update_workflow() {
            check_ajax_referer('estitofo_admin', '_ajax_nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error('forbidden', 403);
            }
            $id     = isset($_POST['id']) ? absint($_POST['id']) : 0;
            $status = isset($_POST['workflow_status']) ? sanitize_text_field(wp_unslash($_POST['workflow_status'])) : '';
            $valid  = Estitofo_Dashboard::statuses();
            if ($id <= 0 || !array_key_exists($status, $valid)) {
                wp_send_json_error('invalid', 400);
            }
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table.
            $wpdb->update(
                $wpdb->prefix . 'estimation_submissions',
                array('workflow_status' => $status, 'updated_at' => current_time('mysql')),
                array('id' => $id),
                array('%s', '%s'),
                array('%d')
            );
            wp_send_json_success(array('status' => $status));
        }

        public function ajax_save_admin_notes() {
            check_ajax_referer('estitofo_admin', '_ajax_nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error('forbidden', 403);
            }
            $id    = isset($_POST['id']) ? absint($_POST['id']) : 0;
            $notes = isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '';
            if ($id <= 0) {
                wp_send_json_error('invalid', 400);
            }
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table.
            $wpdb->update(
                $wpdb->prefix . 'estimation_submissions',
                array('admin_notes' => $notes, 'updated_at' => current_time('mysql')),
                array('id' => $id),
                array('%s', '%s'),
                array('%d')
            );
            wp_send_json_success();
        }

        /**
         * Persistent secret used to sign public PDF URLs.
         * Generated on first use; stays the same across requests.
         */
        /**
         * Minimal ISO-2 → country dial-code lookup, used for server-side
         * phone country verification. Covers the countries WooCommerce sites
         * typically operate in; falls back to '' for unknown codes.
         */
        public static function dial_code_for($iso2) {
            $iso2 = strtolower((string) $iso2);
            $map = array(
                'us' => '1',  'ca' => '1',  'gb' => '44', 'au' => '61', 'nz' => '64',
                'in' => '91', 'bd' => '880','pk' => '92', 'lk' => '94', 'np' => '977',
                'cn' => '86', 'jp' => '81', 'kr' => '82', 'sg' => '65', 'my' => '60',
                'th' => '66', 'id' => '62', 'ph' => '63', 'vn' => '84', 'hk' => '852',
                'ae' => '971','sa' => '966','qa' => '974','kw' => '965','eg' => '20',
                'za' => '27', 'ng' => '234','ke' => '254','gh' => '233','ma' => '212',
                'de' => '49', 'fr' => '33', 'it' => '39', 'es' => '34', 'pt' => '351',
                'nl' => '31', 'be' => '32', 'ch' => '41', 'at' => '43', 'se' => '46',
                'no' => '47', 'dk' => '45', 'fi' => '358','ie' => '353','pl' => '48',
                'cz' => '420','ro' => '40', 'hu' => '36', 'gr' => '30', 'ru' => '7',
                'ua' => '380','tr' => '90', 'br' => '55', 'mx' => '52', 'ar' => '54',
                'cl' => '56', 'co' => '57', 'pe' => '51',
            );
            return isset($map[$iso2]) ? $map[$iso2] : '';
        }

        public static function pdf_secret() {
            $secret = get_option('estitofo_pdf_secret', '');
            if (!$secret || strlen($secret) < 32) {
                $secret = wp_generate_password(64, false, false);
                update_option('estitofo_pdf_secret', $secret, false);
            }
            return $secret;
        }

        /**
         * Stable, non-guessable token for a submission id.
         * Used by the public PDF share URL — anyone with the URL can download the PDF.
         */
        public static function pdf_token($id) {
            return hash_hmac('sha256', (string) absint($id), self::pdf_secret());
        }

        /**
         * Build the public PDF download URL for a submission.
         */
        public static function public_pdf_url($id) {
            return add_query_arg(array(
                'action' => 'estitofo_public_pdf',
                'id'     => absint($id),
                'token'  => self::pdf_token($id),
            ), admin_url('admin-ajax.php'));
        }

        /**
         * Public, token-protected PDF download.
         * Streams the PDF for the matching submission when the HMAC token validates.
         */
        public function public_pdf() {
            // Public token-gated endpoint: the URL itself carries an HMAC token
            // (validated below via hash_equals) so a WP nonce is neither required
            // nor possible here — recipients click the link from email.
            // phpcs:disable WordPress.Security.NonceVerification.Recommended
            $id    = isset($_REQUEST['id']) ? absint($_REQUEST['id']) : 0;
            $token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';
            $disp  = (isset($_REQUEST['dl']) && (int) $_REQUEST['dl'] === 0) ? 'I' : 'D';
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

            if ($id <= 0 || !$token || !hash_equals(self::pdf_token($id), $token)) {
                status_header(403);
                wp_die(esc_html__('Invalid PDF link.', 'estimation-tool-for-woocommerce'), '', array('response' => 403));
            }
            if (!class_exists('TCPDF')) {
                status_header(500);
                wp_die(esc_html__('PDF library not loaded.', 'estimation-tool-for-woocommerce'), '', array('response' => 500));
            }

            global $wpdb;
            $table = $wpdb->prefix . 'estimation_submissions';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
            if (!$row) {
                status_header(404);
                wp_die(esc_html__('Estimation not found.', 'estimation-tool-for-woocommerce'), '', array('response' => 404));
            }
            $products = json_decode((string) $row->products, true);
            if (!is_array($products)) {
                status_header(400);
                wp_die(esc_html__('Bad estimation data.', 'estimation-tool-for-woocommerce'), '', array('response' => 400));
            }
            // Don't let WP buffer output and corrupt the PDF binary.
            while (ob_get_level() > 0) { ob_end_clean(); }
            nocache_headers();
            try {
                $generator = new Estitofo_PDF();
                $pdf = $generator->generate($products, (float) $row->total, array(
                    'name'  => sanitize_text_field($row->name),
                    'email' => sanitize_email($row->email),
                    'phone' => sanitize_text_field($row->phone),
                    'date'  => $row->created_at,
                ));
                $filename = sanitize_file_name(sprintf(
                    'estimation-%d-%s.pdf',
                    (int) $row->id,
                    gmdate('Y-m-d', strtotime($row->created_at))
                ));
                $pdf->Output($filename, $disp);
            } catch (Exception $e) {
                status_header(500);
                wp_die(esc_html__('PDF generation failed.', 'estimation-tool-for-woocommerce'), '', array('response' => 500));
            }
            exit;
        }

        /**
         * Read structured "extra meta" attached to a submission. Pro and
         * other add-ons use this to store per-submission data
         * (expiration dates, conditional-field answers, Stripe deposit
         * receipts, etc.) without polluting the customer-facing
         * `customer_notes` field or admin-facing `admin_notes` field.
         *
         * Stored as a single JSON object in the wp_options table at
         * `estitofo_meta_{submission_id}`.
         *
         * @param int    $submission_id
         * @param string $key      Optional sub-key.
         * @param mixed  $default
         * @return mixed
         */
        public static function get_submission_meta($submission_id, $key = '', $default = null) {
            $id = absint($submission_id);
            if ($id <= 0) return $default;
            $blob = get_option('estitofo_meta_' . $id, array());
            if (!is_array($blob)) $blob = array();
            if ($key === '') return $blob;
            return array_key_exists($key, $blob) ? $blob[$key] : $default;
        }

        /**
         * Update / merge structured meta into a submission record.
         *
         * @param int   $submission_id
         * @param array $values  Key/value pairs to merge.
         * @return bool
         */
        public static function update_submission_meta($submission_id, array $values) {
            $id = absint($submission_id);
            if ($id <= 0) return false;
            $blob = get_option('estitofo_meta_' . $id, array());
            if (!is_array($blob)) $blob = array();
            $blob = array_merge($blob, $values);
            return update_option('estitofo_meta_' . $id, $blob, false);
        }

        /**
         * Delete the entire meta blob for a submission (called from
         * uninstall.php and from row deletes).
         */
        public static function delete_submission_meta($submission_id) {
            $id = absint($submission_id);
            if ($id <= 0) return false;
            return delete_option('estitofo_meta_' . $id);
        }

        public function plugin_action_links($links) {
            $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=estimation-settings')) . '">' . esc_html__('Settings', 'estimation-tool-for-woocommerce') . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        }

        public function declare_wc_compatibility() {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ESTITOFO_FILE, true);
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', ESTITOFO_FILE, true);
            }
        }

        public function check_dependencies() {
            if (!class_exists('WooCommerce')) {
                add_action('admin_notices', array($this, 'wc_missing_notice'));
            }
        }

        public function wc_missing_notice() {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p>' .
                esc_html__('Estimation Tool for WooCommerce requires WooCommerce to be installed and active.', 'estimation-tool-for-woocommerce') .
                '</p></div>';
        }

        public function maybe_upgrade_db() {
            if (get_option('estitofo_db_version') !== self::DB_VERSION) {
                self::create_estimation_table();
            }
            Estitofo_Options::maybe_migrate();
        }

        public function register_shortcodes() {
            add_shortcode('estitofo_form', array($this, 'render_estimation_page'));
        }

        public function enqueue_assets($force = false) {
            if (is_admin() && !$force) {
                return;
            }
            wp_enqueue_style('estitofo-css', ESTITOFO_ASSETS_URL . 'css/estimation.css', array(), ESTITOFO_VERSION);
            wp_enqueue_style('intl-tel-input', ESTITOFO_ASSETS_URL . 'libs/intl-tel-input/css/intlTelInput.min.css', array(), '17.0.9');
            wp_enqueue_script('intl-tel-input', ESTITOFO_ASSETS_URL . 'libs/intl-tel-input/js/intlTelInput.min.js', array(), '17.0.9', true);
            // Load utils.js (the country-rules library) explicitly so it's
            // guaranteed available before our init runs. intl-tel-input's
            // built-in lazy-load via `utilsScript` is fragile on some hosts
            // (HTTPS mixed-content, CSP, slow networks) — pre-enqueuing
            // sidesteps that entirely.
            wp_enqueue_script('intl-tel-input-utils', ESTITOFO_ASSETS_URL . 'libs/intl-tel-input/js/utils.js', array('intl-tel-input'), '17.0.9', true);
            wp_enqueue_script('estitofo-js', ESTITOFO_ASSETS_URL . 'js/estimation.js', array('jquery', 'intl-tel-input', 'intl-tel-input-utils'), ESTITOFO_VERSION, true);

            // Read from the consolidated settings option (not the legacy
            // standalone option, which is empty on fresh installs).
            $default_country  = strtolower((string) Estitofo_Options::get('default_country', ''));
            $restrict_country = strtolower((string) Estitofo_Options::get('restrict_country', ''));

            wp_localize_script('estitofo-js', 'estitofoData', array(
                'ajax_url'         => admin_url('admin-ajax.php'),
                'rest_url'         => esc_url_raw(rest_url('estitofo/v1/')),
                'rest_nonce'       => wp_create_nonce('wp_rest'),
                'nonce'            => wp_create_nonce('estitofo_nonce'),
                'currency_symbol'  => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '',
                'currency_pos'     => get_option('woocommerce_currency_pos', 'left'),
                'assets_url'       => ESTITOFO_ASSETS_URL,
                'default_country'  => $default_country,
                'restrict_country' => $restrict_country,
                'i18n'            => array(
                    'no_products'        => __('No products added yet', 'estimation-tool-for-woocommerce'),
                    'fill_required'      => __('Please fill all required fields correctly.', 'estimation-tool-for-woocommerce'),
                    'invalid_phone'      => __('Please enter a valid phone number.', 'estimation-tool-for-woocommerce'),
                    'valid_number'       => __('Valid number', 'estimation-tool-for-woocommerce'),
                    /* translators: %s: country name */
                    'valid_number_country'      => __('Valid %s number', 'estimation-tool-for-woocommerce'),
                    'phone_invalid_country_code'=> __('Invalid country dial code. Please pick a country from the dropdown.', 'estimation-tool-for-woocommerce'),
                    /* translators: %s: country name */
                    'phone_too_short_country'   => __('Number is too short for %s.', 'estimation-tool-for-woocommerce'),
                    'phone_too_short'           => __('Phone number is too short.', 'estimation-tool-for-woocommerce'),
                    /* translators: %s: country name */
                    'phone_too_long_country'    => __('Number is too long for %s.', 'estimation-tool-for-woocommerce'),
                    'phone_too_long'            => __('Phone number is too long.', 'estimation-tool-for-woocommerce'),
                    'phone_not_a_number'        => __('Please enter digits only.', 'estimation-tool-for-woocommerce'),
                    /* translators: %s: country name */
                    'phone_invalid_for_country' => __("Doesn't look like a valid %s number.", 'estimation-tool-for-woocommerce'),
                    'phone_loading'             => __('Checking phone number…', 'estimation-tool-for-woocommerce'),
                    'add_products_first' => __('Please add products to generate the estimation.', 'estimation-tool-for-woocommerce'),
                    'already_added'      => __('This product is already in your estimation', 'estimation-tool-for-woocommerce'),
                    'generating'         => __('Generating your estimation...', 'estimation-tool-for-woocommerce'),
                    'thank_you'          => __('Thank you! Your estimation is downloading...', 'estimation-tool-for-woocommerce'),
                    'new_estimation'     => __('New Estimation', 'estimation-tool-for-woocommerce'),
                    'submission_error'   => __('Submission error. Please try again.', 'estimation-tool-for-woocommerce'),
                    'pdf_error'          => __('Could not generate PDF. Please try again.', 'estimation-tool-for-woocommerce'),
                    'add_more'           => __('Add More Products', 'estimation-tool-for-woocommerce'),
                    'loading_suggested'  => __('Loading suggestions...', 'estimation-tool-for-woocommerce'),
                    'no_suggested'       => __('No suggestions available at this time', 'estimation-tool-for-woocommerce'),
                    'no_results'         => __('No products match your search.', 'estimation-tool-for-woocommerce'),
                    'item'               => __('item', 'estimation-tool-for-woocommerce'),
                    'items'              => __('items', 'estimation-tool-for-woocommerce'),
                    'added_to_cart'      => __('Added', 'estimation-tool-for-woocommerce'),
                    'empty_hint'         => __('Search above to add products to your estimation.', 'estimation-tool-for-woocommerce'),
                    'total'              => __('Total', 'estimation-tool-for-woocommerce'),
                    'product'            => __('Product', 'estimation-tool-for-woocommerce'),
                    'price'              => __('Price', 'estimation-tool-for-woocommerce'),
                    'quantity'           => __('Quantity', 'estimation-tool-for-woocommerce'),
                    'subtotal'           => __('Subtotal', 'estimation-tool-for-woocommerce'),
                    'action'             => __('Action', 'estimation-tool-for-woocommerce'),
                    'remove'             => __('Remove', 'estimation-tool-for-woocommerce'),
                    'add_note'           => __('Add note…', 'estimation-tool-for-woocommerce'),
                    'all'                => __('All', 'estimation-tool-for-woocommerce'),
                    'step_build'         => __('Build', 'estimation-tool-for-woocommerce'),
                    'step_contact'       => __('Contact', 'estimation-tool-for-woocommerce'),
                    'step_done'          => __('Done', 'estimation-tool-for-woocommerce'),
                    'share_text'         => __('My estimation total: ', 'estimation-tool-for-woocommerce'),
                    'copy_link'          => __('Copy PDF link', 'estimation-tool-for-woocommerce'),
                    'copied'             => __('Copied!', 'estimation-tool-for-woocommerce'),
                    'download_pdf'       => __('Download PDF', 'estimation-tool-for-woocommerce'),
                    'resume_email_required' => __('Please enter your email first.', 'estimation-tool-for-woocommerce'),
                    'resume_sent'        => __('Check your inbox for the resume link.', 'estimation-tool-for-woocommerce'),
                    'resume_loaded'      => __('Your estimation has been restored.', 'estimation-tool-for-woocommerce'),
                ),
            ));
        }

        public function enqueue_assets_in_editor() {
            $this->enqueue_assets(true);
        }

        public function enqueue_admin_assets($hook) {
            if ('toplevel_page_estimation-data' !== $hook && 'estimations_page_estimation-settings' !== $hook && 'estimation_page_estimation-settings' !== $hook) {
                return;
            }
            wp_enqueue_style('estitofo-admin-css', ESTITOFO_ASSETS_URL . 'css/admin-estimation.css', array(), ESTITOFO_VERSION);
            wp_enqueue_script('estitofo-admin-js', ESTITOFO_ASSETS_URL . 'js/admin-estimation.js', array('jquery'), ESTITOFO_VERSION, true);
            wp_localize_script('estitofo-admin-js', 'estitofo_admin', array(
                'ajax_url'        => admin_url('admin-ajax.php'),
                'admin_post_url'  => admin_url('admin-post.php'),
                'nonce_products'  => wp_create_nonce('estitofo_products_nonce'),
                'nonce_download'  => wp_create_nonce('estitofo_admin_pdf'),
                'nonce_admin'     => wp_create_nonce('estitofo_admin'),
                'i18n'            => array(
                    'loading'       => __('Loading...', 'estimation-tool-for-woocommerce'),
                    'edit_notes'    => __('Edit notes', 'estimation-tool-for-woocommerce'),
                    'save'          => __('Save', 'estimation-tool-for-woocommerce'),
                    'cancel'        => __('Cancel', 'estimation-tool-for-woocommerce'),
                    'saved'         => __('Saved', 'estimation-tool-for-woocommerce'),
                    'admin_notes_placeholder' => __('Internal notes about this estimation…', 'estimation-tool-for-woocommerce'),
                ),
            ));

            if (false !== strpos($hook, 'estimation-settings')) {
                wp_enqueue_media();
                wp_enqueue_style('wp-color-picker');
                wp_enqueue_script('wp-color-picker');
                // Settings-only JS: media picker, color picker init, copy buttons.
                wp_enqueue_script(
                    'estitofo-admin-settings',
                    ESTITOFO_ASSETS_URL . 'js/admin-settings.js',
                    array('jquery', 'wp-color-picker'),
                    ESTITOFO_VERSION,
                    true
                );
                wp_localize_script('estitofo-admin-settings', 'estitofoAdmin', array(
                    'i18n' => array(
                        'copied' => __('Copied!', 'estimation-tool-for-woocommerce'),
                    ),
                ));
            }
        }

        public function register_elementor_widgets($widgets_manager) {
            if (!class_exists('\Elementor\Widget_Base')) {
                return;
            }
            if (defined('ELEMENTOR_VERSION') && version_compare(ELEMENTOR_VERSION, self::MIN_ELEMENTOR_VERSION, '<')) {
                return;
            }
            require_once ESTITOFO_PATH . 'includes/class-elementor-estimation-widget.php';
            $widgets_manager->register(new \Estitofo_Elementor_Widget());
        }

        public function register_elementor_category($elements_manager) {
            if (!class_exists('\Elementor\Elements_Manager')) {
                return;
            }
            $elements_manager->add_category(
                'wc-estimation',
                array(
                    'title' => esc_html__('Estimation Tool', 'estimation-tool-for-woocommerce'),
                    'icon' => 'fa fa-calculator',
                ),
                1
            );
        }

        public function add_admin_menu() {
            $hook = add_menu_page(
                esc_html__('Estimation Data', 'estimation-tool-for-woocommerce'),
                esc_html__('Estimations', 'estimation-tool-for-woocommerce'),
                'manage_options',
                'estimation-data',
                array($this, 'render_estimation_data_page'),
                'dashicons-calculator',
                30
            );
            add_submenu_page(
                'estimation-data',
                esc_html__('Submissions', 'estimation-tool-for-woocommerce'),
                esc_html__('Submissions', 'estimation-tool-for-woocommerce'),
                'manage_options',
                'estimation-data',
                array($this, 'render_estimation_data_page')
            );
            add_submenu_page(
                'estimation-data',
                esc_html__('Settings', 'estimation-tool-for-woocommerce'),
                esc_html__('Settings', 'estimation-tool-for-woocommerce'),
                'manage_options',
                'estimation-settings',
                array('Estitofo_Settings', 'render_settings_page')
            );
            add_action("load-$hook", array($this, 'add_screen_options'));
        }

        public function add_screen_options() {
            $option = 'per_page';
            $args = array(
                'label' => esc_html__('Estimations per page', 'estimation-tool-for-woocommerce'),
                'default' => 20,
                'option' => 'estimations_per_page',
            );
            add_screen_option($option, $args);
        }

        public function render_estimation_data_page() {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('Unauthorized access', 'estimation-tool-for-woocommerce'));
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- handle_bulk_actions() verifies the nonce.
            if (isset($_REQUEST['action']) || isset($_REQUEST['action2'])) {
                $this->handle_bulk_actions();
            }
            $table = new Estitofo_List_Table();
            $table->prepare_items();
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline"><?php esc_html_e('Estimation Submissions', 'estimation-tool-for-woocommerce'); ?></h1>
                <?php settings_errors('estimation_data_messages'); ?>
                <form method="post">
                    <?php wp_nonce_field('estimation_data_bulk_action', 'estimation_data_nonce'); ?>
                    <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page slug from menu link. ?>
                    <input type="hidden" name="page" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_REQUEST['page'] ?? ''))); ?>" />
                    <?php $table->views(); ?>
                    <?php $table->search_box(esc_html__('Search', 'estimation-tool-for-woocommerce'), 'search'); ?>
                    <?php $table->display(); ?>
                </form>
            </div>
            <div id="estimation-products-modal" class="estimation-products-modal" style="display:none;">
                <div class="estimation-modal-content">
                    <div class="estimation-modal-header">
                        <h2><?php esc_html_e('Estimated Products', 'estimation-tool-for-woocommerce'); ?></h2>
                        <button class="button button-secondary close-modal">&times;</button>
                    </div>
                    <div class="estimation-modal-body" id="products-list-container"></div>
                    <div class="estimation-modal-footer">
                        <button class="button download-pdf-btn"><?php esc_html_e('Download PDF', 'estimation-tool-for-woocommerce'); ?></button>
                        <button class="button button-primary close-modal"><?php esc_html_e('Close', 'estimation-tool-for-woocommerce'); ?></button>
                    </div>
                </div>
            </div>
            <?php
        }

        public function ajax_suggested_products() {
            check_ajax_referer('estitofo_nonce', 'nonce');
            if (!function_exists('wc_get_products')) {
                wp_send_json_error(esc_html__('WooCommerce is not active.', 'estimation-tool-for-woocommerce'));
            }
            $products = wc_get_products(array(
                'status'  => 'publish',
                'limit'   => 8,
                'orderby' => 'rand',
                'type'    => apply_filters(
                    'estitofo_search_product_types',
                    array('simple', 'variable', 'grouped', 'external')
                ),
            ));
            $results = array();
            foreach ($products as $product) {
                $results[] = array(
                    'id'         => $product->get_id(),
                    'title'      => wp_strip_all_tags($product->get_name()),
                    'price'      => floatval($product->get_price()),
                    'price_html' => wp_kses_post($product->get_price_html()),
                    'image'      => get_the_post_thumbnail_url($product->get_id(), 'thumbnail'),
                );
            }
            wp_send_json_success($results);
        }

        public function download_admin_pdf() {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('Unauthorized access', 'estimation-tool-for-woocommerce'), '', array('response' => 403));
            }
            $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'estitofo_admin_pdf')) {
                wp_die(esc_html__('Invalid request', 'estimation-tool-for-woocommerce'), '', array('response' => 403));
            }
            $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
            if ($id <= 0) {
                wp_die(esc_html__('Invalid estimation ID', 'estimation-tool-for-woocommerce'), '', array('response' => 400));
            }
            global $wpdb;
            $table_name = $wpdb->prefix . 'estimation_submissions';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; query is prepared.
            $estimation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id));
            if (!$estimation) {
                wp_die(esc_html__('Estimation not found', 'estimation-tool-for-woocommerce'), '', array('response' => 404));
            }
            $products = json_decode($estimation->products, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($products)) {
                wp_die(esc_html__('Invalid product data', 'estimation-tool-for-woocommerce'), '', array('response' => 400));
            }
            if (!class_exists('TCPDF')) {
                wp_die(esc_html__('PDF library not loaded', 'estimation-tool-for-woocommerce'), '', array('response' => 500));
            }
            try {
                $pdf_generator = new Estitofo_PDF();
                $pdf = $pdf_generator->generate(
                    $products,
                    floatval($estimation->total),
                    array(
                        'name'  => sanitize_text_field($estimation->name),
                        'email' => sanitize_email($estimation->email),
                        'phone' => sanitize_text_field($estimation->phone),
                        'date'  => $estimation->created_at,
                    )
                );
                $filename = sanitize_file_name(sprintf('product-estimation-%d-%s.pdf', $estimation->id, gmdate('Y-m-d', strtotime($estimation->created_at))));
                $pdf->Output($filename, 'D');
                exit;
            } catch (Exception $e) {
                wp_die(esc_html__('PDF generation failed.', 'estimation-tool-for-woocommerce'), '', array('response' => 500));
            }
        }

        public function get_estimation_products_ajax() {
            check_ajax_referer('estitofo_products_nonce', '_ajax_nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(esc_html__('Unauthorized access', 'estimation-tool-for-woocommerce'), 403);
            }
            $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
            if ($id <= 0) {
                wp_send_json_error(esc_html__('Invalid estimation ID', 'estimation-tool-for-woocommerce'), 400);
            }
            global $wpdb;
            $table_name = $wpdb->prefix . 'estimation_submissions';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; query is prepared.
            $estimation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id));
            if (!$estimation) {
                wp_send_json_error(esc_html__('Estimation not found', 'estimation-tool-for-woocommerce'), 404);
            }
            $products = json_decode($estimation->products, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($products)) {
                wp_send_json_error(esc_html__('Invalid product data format', 'estimation-tool-for-woocommerce'), 400);
            }
            if (!empty($_POST['include_notes'])) {
                wp_send_json_success(array(
                    'admin_notes' => $estimation->admin_notes ?? '',
                ));
            }
            ob_start();
            ?>
            <div class="estimation-details">
                <h3><?php echo esc_html(sprintf(
                    /* translators: %d: estimation submission ID */
                    __('Estimation #%d', 'estimation-tool-for-woocommerce'),
                    $estimation->id
                )); ?></h3>
                <div class="customer-info">
                    <p><strong><?php esc_html_e('Name:', 'estimation-tool-for-woocommerce'); ?></strong> <?php echo esc_html($estimation->name); ?></p>
                    <p><strong><?php esc_html_e('Email:', 'estimation-tool-for-woocommerce'); ?></strong> <?php echo esc_html($estimation->email); ?></p>
                    <p><strong><?php esc_html_e('Phone:', 'estimation-tool-for-woocommerce'); ?></strong> <?php echo esc_html($estimation->phone); ?></p>
                    <p><strong><?php esc_html_e('Date:', 'estimation-tool-for-woocommerce'); ?></strong> <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($estimation->created_at))); ?></p>
                </div>
                <table class="product-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Product', 'estimation-tool-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Image', 'estimation-tool-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Price', 'estimation-tool-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Qty', 'estimation-tool-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Subtotal', 'estimation-tool-for-woocommerce'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product) :
                            $image_url = !empty($product['image']) ? esc_url($product['image']) : wc_placeholder_img_src();
                            $title    = isset($product['title']) ? (string) $product['title'] : __('N/A', 'estimation-tool-for-woocommerce');
                            $price    = wc_price(floatval($product['price'] ?? 0));
                            $qty      = absint($product['quantity'] ?? 0);
                            $subtotal = wc_price(floatval($product['price'] ?? 0) * $qty);
                        ?>
                        <tr>
                            <td><?php echo esc_html($title); ?></td>
                            <td><img src="<?php echo esc_url($image_url); ?>" width="50" height="50" alt="" style="object-fit:contain;"></td>
                            <td><?php echo wp_kses_post($price); ?></td>
                            <td><?php echo esc_html($qty); ?></td>
                            <td><?php echo wp_kses_post($subtotal); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" style="text-align:right"><?php esc_html_e('Total:', 'estimation-tool-for-woocommerce'); ?></th>
                            <th><?php echo wp_kses_post(wc_price(floatval($estimation->total))); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php
            wp_send_json_success(array('html' => ob_get_clean()));
        }

        private function handle_bulk_actions() {
            if (!current_user_can('manage_options')) {
                return;
            }
            if (!isset($_POST['estimation_data_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['estimation_data_nonce'])), 'estimation_data_bulk_action')) {
                return;
            }
            $action = '';
            if (!empty($_REQUEST['action2']) && '-1' !== $_REQUEST['action2']) {
                $action = sanitize_text_field(wp_unslash($_REQUEST['action2']));
            } elseif (!empty($_REQUEST['action']) && '-1' !== $_REQUEST['action']) {
                $action = sanitize_text_field(wp_unslash($_REQUEST['action']));
            }
            if (empty($action) || !isset($_REQUEST['estimation']) || !is_array($_REQUEST['estimation'])) {
                return;
            }
            $ids = array_map('absint', wp_parse_id_list(implode(',', array_map('sanitize_text_field', wp_unslash($_REQUEST['estimation'])))));
            if (empty($ids)) {
                return;
            }
            global $wpdb;
            $table_name = $wpdb->prefix . 'estimation_submissions';
            $updated = 0;
            if ('trash' === $action) {
                foreach ($ids as $id) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table.
                    $result = $wpdb->update($table_name, array('status' => 'trash', 'deleted_at' => current_time('mysql')), array('id' => $id), array('%s', '%s'), array('%d'));
                    if (false !== $result) {
                        $updated++;
                    }
                }
                if ($updated) {
                    add_settings_error('estimation_data_messages', 'estimation_data_message', sprintf(
                        /* translators: %d: number of estimations moved to trash */
                        _n('%d estimation moved to trash.', '%d estimations moved to trash.', $updated, 'estimation-tool-for-woocommerce'),
                        $updated
                    ), 'updated');
                }
            } elseif ('delete' === $action) {
                foreach ($ids as $id) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table.
                    $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));
                    if (false !== $result) {
                        $updated++;
                    }
                }
                if ($updated) {
                    add_settings_error('estimation_data_messages', 'estimation_data_message', sprintf(
                        /* translators: %d: number of estimations permanently deleted */
                        _n('%d estimation permanently deleted.', '%d estimations permanently deleted.', $updated, 'estimation-tool-for-woocommerce'),
                        $updated
                    ), 'updated');
                }
            } elseif ('restore' === $action) {
                foreach ($ids as $id) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table.
                    $result = $wpdb->update($table_name, array('status' => 'publish', 'deleted_at' => null), array('id' => $id), array('%s', '%s'), array('%d'));
                    if (false !== $result) {
                        $updated++;
                    }
                }
                if ($updated) {
                    add_settings_error('estimation_data_messages', 'estimation_data_message', sprintf(
                        /* translators: %d: number of estimations restored */
                        _n('%d estimation restored.', '%d estimations restored.', $updated, 'estimation-tool-for-woocommerce'),
                        $updated
                    ), 'updated');
                }
            } elseif ('export' === $action) {
                $this->export_csv($ids);
            }
        }

        private function export_csv(array $ids) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'estimation_submissions';
            $ids = array_map('absint', $ids);
            $ids = array_filter($ids);
            if (empty($ids)) {
                return;
            }
            $id_list = implode(',', $ids);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- IDs cast to int; safe interpolation.
            $rows = $wpdb->get_results("SELECT * FROM {$table_name} WHERE id IN ({$id_list})");
            if (!$rows) {
                return;
            }
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=estimations-' . gmdate('Ymd-His') . '.csv');

            $csv  = "\xEF\xBB\xBF"; // UTF-8 BOM for Excel.
            $csv .= self::csv_line(array(
                'ID', 'Name', 'Email', 'Phone', 'Company', 'Address',
                'Workflow Status', 'Total', 'Created At',
                'Customer Notes', 'Admin Notes', 'Products',
            ));
            foreach ($rows as $r) {
                $products_str = '';
                $decoded = json_decode((string) $r->products, true);
                if (is_array($decoded)) {
                    $parts = array();
                    foreach ($decoded as $p) {
                        $parts[] = sprintf('%s x%d @%s',
                            isset($p['title']) ? $p['title'] : '',
                            isset($p['quantity']) ? (int) $p['quantity'] : 0,
                            isset($p['price']) ? number_format((float) $p['price'], 2) : '0.00'
                        );
                    }
                    $products_str = implode(' | ', $parts);
                }
                $csv .= self::csv_line(array(
                    $r->id, $r->name, $r->email, $r->phone, $r->company ?? '', $r->address ?? '',
                    $r->workflow_status ?? 'new', $r->total, $r->created_at,
                    $r->customer_notes ?? '', $r->admin_notes ?? '', $products_str,
                ));
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download; fields RFC-4180 escaped via csv_line().
            echo $csv;
            exit;
        }

        private static function csv_line(array $fields) {
            $escaped = array();
            foreach ($fields as $f) {
                $s = (string) $f;
                if (preg_match('/[",\r\n]/', $s)) {
                    $s = '"' . str_replace('"', '""', $s) . '"';
                }
                $escaped[] = $s;
            }
            return implode(',', $escaped) . "\r\n";
        }

        public function render_estimation_page($atts = array()) {
            $default_heading = (string) Estitofo_Options::get('heading', '');
            if ($default_heading === '') {
                $default_heading = __('My plan and price estimation', 'estimation-tool-for-woocommerce');
            }
            $default_subheading = (string) Estitofo_Options::get('subheading', '');
            // No hardcoded fallback string here — admins who clear the field
            // should see *no* subheading. We only fall back to a tagline on
            // very first run when the field is at its default empty value.
            $atts = shortcode_atts(array(
                'heading'    => $default_heading,
                'subheading' => $default_subheading,
            ), $atts, 'estitofo_form');

            if (!class_exists('WooCommerce')) {
                return '<div class="wc-estimation-notice">' . esc_html__('WooCommerce is required for the estimation tool.', 'estimation-tool-for-woocommerce') . '</div>';
            }

            $opts = Estitofo_Options::all();
            $submit_text = (string) $opts['submit_button_text'];
            if ($submit_text === '') {
                $submit_text = __('Get My Estimation PDF', 'estimation-tool-for-woocommerce');
            }
            $collect_company = (int) $opts['collect_company'];
            $collect_address = (int) $opts['collect_address'];
            $collect_notes   = (int) $opts['collect_notes'];
            $primary         = Estitofo_Settings::sanitize_color((string) $opts['primary_color']);
            $accent          = Estitofo_Settings::sanitize_color((string) $opts['accent_color']);
            $enable_resume   = (int) $opts['enable_save_resume'];

            $currency = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '';
            ob_start();
            $style_vars = '';
            if ($primary) $style_vars .= '--wc-est-primary:' . $primary . ';';
            if ($accent)  $style_vars .= '--wc-est-accent:' . $accent . ';';
            ?>
            <div class="wc-estimation-wrapper"<?php echo $style_vars ? ' style="' . esc_attr($style_vars) . '"' : ''; ?>>
                <header class="wc-est-hero">
                    <h2 class="wc-est-hero__title"><?php echo esc_html($atts['heading']); ?></h2>
                    <?php if (!empty($atts['subheading'])) : ?>
                        <p class="wc-est-hero__subtitle"><?php echo esc_html($atts['subheading']); ?></p>
                    <?php endif; ?>
                </header>

                <ol class="wc-est-stepper" aria-label="<?php esc_attr_e('Estimation progress', 'estimation-tool-for-woocommerce'); ?>">
                    <li class="wc-est-stepper__item is-active" data-step="1"><span class="wc-est-stepper__num">1</span><span class="wc-est-stepper__label"><?php esc_html_e('Browse', 'estimation-tool-for-woocommerce'); ?></span></li>
                    <li class="wc-est-stepper__item" data-step="2"><span class="wc-est-stepper__num">2</span><span class="wc-est-stepper__label"><?php esc_html_e('Your details', 'estimation-tool-for-woocommerce'); ?></span></li>
                    <li class="wc-est-stepper__item" data-step="3"><span class="wc-est-stepper__num">3</span><span class="wc-est-stepper__label"><?php esc_html_e('Done', 'estimation-tool-for-woocommerce'); ?></span></li>
                </ol>

                <section class="wc-est-search-card">
                    <div class="wc-est-categories" role="group" aria-label="<?php esc_attr_e('Filter by category', 'estimation-tool-for-woocommerce'); ?>"></div>
                    <div class="wc-search-container">
                        <svg class="wc-search-icon" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="2"/><path d="M14 14l3 3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        <input type="text" class="wc-search-input" placeholder="<?php echo esc_attr__('Search products by name or SKU…', 'estimation-tool-for-woocommerce'); ?>" aria-label="<?php esc_attr_e('Search products', 'estimation-tool-for-woocommerce'); ?>">
                        <div class="wc-search-results" role="listbox"></div>
                    </div>
                </section>

                <!-- Featured / popular products grid (auto-populated when cart is empty) -->
                <section class="wc-est-featured" style="display:none;">
                    <header class="wc-est-section-head">
                        <h3><?php esc_html_e('Popular products', 'estimation-tool-for-woocommerce'); ?></h3>
                    </header>
                    <div class="wc-est-featured-grid"></div>
                </section>

                <div class="wc-estimation-container">
                    <section class="wc-estimation-products">
                        <header class="wc-est-section-head">
                            <h3><?php esc_html_e('Your selection', 'estimation-tool-for-woocommerce'); ?></h3>
                            <span class="wc-est-count" aria-live="polite">0</span>
                        </header>
                        <div class="wc-estimation-list" aria-live="polite">
                            <div class="wc-est-empty">
                                <svg class="wc-est-empty__icon" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                                    <rect x="12" y="14" width="40" height="44" rx="4" stroke="currentColor" stroke-width="2.5" fill="none"/>
                                    <path d="M22 8h20a2 2 0 0 1 2 2v6H20v-6a2 2 0 0 1 2-2z" stroke="currentColor" stroke-width="2.5" fill="none"/>
                                    <path d="M22 30h20M22 38h20M22 46h12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                                </svg>
                                <p class="wc-est-empty__title"><?php esc_html_e('No products yet', 'estimation-tool-for-woocommerce'); ?></p>
                                <p class="wc-est-empty__hint"><?php esc_html_e('Search above to add products to your estimation.', 'estimation-tool-for-woocommerce'); ?></p>
                            </div>
                        </div>
                    </section>

                    <aside class="wc-estimation-summary">
                        <div class="wc-est-summary-sticky">
                            <span class="wc-est-summary-label"><?php esc_html_e('Estimated total', 'estimation-tool-for-woocommerce'); ?></span>
                            <div class="wc-estimation-total"><?php echo esc_html($currency); ?> 0.00</div>
                            <div class="wc-est-summary-meta">
                                <span class="wc-est-summary-meta__items">0 <?php esc_html_e('items', 'estimation-tool-for-woocommerce'); ?></span>
                            </div>
                            <button type="button" id="initiate-estimation-btn" class="wc-download-btn" style="display: none;">
                                <?php echo esc_html($submit_text); ?>
                                <svg viewBox="0 0 20 20" width="18" height="18" fill="none" aria-hidden="true"><path d="M5 10l4 4 6-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div id="estimation-form-container" style="display: none;">
                                <form id="estimation-form" class="estimation-form" autocomplete="off" novalidate>
                                    <div class="form-group">
                                        <label for="estimation-name"><?php esc_html_e('Full name', 'estimation-tool-for-woocommerce'); ?><span class="req">*</span></label>
                                        <input type="text" id="estimation-name" name="name" required placeholder="<?php esc_attr_e('Jane Doe', 'estimation-tool-for-woocommerce'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="estimation-email"><?php esc_html_e('Email address', 'estimation-tool-for-woocommerce'); ?><span class="req">*</span></label>
                                        <input type="email" id="estimation-email" name="email" required placeholder="you@example.com">
                                    </div>
                                    <div class="form-group">
                                        <label for="estimation-phone"><?php esc_html_e('Phone number', 'estimation-tool-for-woocommerce'); ?><span class="req">*</span></label>
                                        <input type="tel" id="estimation-phone" name="phone" required>
                                    </div>
                                    <?php if ($collect_company) : ?>
                                    <div class="form-group">
                                        <label for="estimation-company"><?php esc_html_e('Company', 'estimation-tool-for-woocommerce'); ?></label>
                                        <input type="text" id="estimation-company" name="company">
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($collect_address) : ?>
                                    <div class="form-group">
                                        <label for="estimation-address"><?php esc_html_e('Delivery address', 'estimation-tool-for-woocommerce'); ?></label>
                                        <input type="text" id="estimation-address" name="address">
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($collect_notes) : ?>
                                    <div class="form-group">
                                        <label for="estimation-notes"><?php esc_html_e('Anything we should know?', 'estimation-tool-for-woocommerce'); ?></label>
                                        <textarea id="estimation-notes" name="customer_notes" rows="3" placeholder="<?php esc_attr_e('Special requests, delivery date, color preferences…', 'estimation-tool-for-woocommerce'); ?>"></textarea>
                                    </div>
                                    <?php endif; ?>
                                    <div class="form-group wc-estimation-honeypot" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;">
                                        <label for="estimation-website"><?php esc_html_e('Website', 'estimation-tool-for-woocommerce'); ?></label>
                                        <input type="text" id="estimation-website" name="website" tabindex="-1" autocomplete="off">
                                    </div>
                                    <?php do_action('estitofo_form_extra_fields', $opts); ?>
                                    <button type="submit" class="wc-download-btn"><?php echo esc_html($submit_text); ?>
                                        <svg viewBox="0 0 20 20" width="18" height="18" fill="none" aria-hidden="true"><path d="M10 4v9m0 0l-3-3m3 3l3-3M4 16h12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </button>
                                    <?php if ($enable_resume) : ?>
                                        <div class="wc-estimation-resume">
                                            <button type="button" class="wc-est-link-btn" id="wc-est-save-resume"><?php esc_html_e('Email me a link to resume later', 'estimation-tool-for-woocommerce'); ?></button>
                                        </div>
                                    <?php endif; ?>
                                </form>
                                <div id="estimation-success" style="display: none;"></div>
                            </div>
                        </div>
                    </aside>
                </div>

                <div class="wc-est-toast-host" aria-live="polite" aria-atomic="true"></div>
            </div>
            <?php
            return ob_get_clean();
        }

        private function rate_limit($key, $limit = 5, $window = 60) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
            $transient_key = 'estitofo_rl_' . md5($key . '|' . $ip);
            $count = (int) get_transient($transient_key);
            if ($count >= $limit) {
                return false;
            }
            set_transient($transient_key, $count + 1, $window);
            return true;
        }

        public function submit_estimation() {
            check_ajax_referer('estitofo_nonce', 'nonce');

            if (!empty($_POST['website'])) {
                wp_send_json_error(esc_html__('Spam detected.', 'estimation-tool-for-woocommerce'));
            }

            if (!is_user_logged_in() && !$this->rate_limit('submit', 5, 60)) {
                wp_send_json_error(esc_html__('Too many requests. Please try again in a moment.', 'estimation-tool-for-woocommerce'), 429);
            }

            $name    = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $email   = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            $phone   = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
            $company = sanitize_text_field(wp_unslash($_POST['company'] ?? ''));
            $address = sanitize_text_field(wp_unslash($_POST['address'] ?? ''));
            $cnotes  = sanitize_textarea_field(wp_unslash($_POST['customer_notes'] ?? ''));
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; each field is sanitized in the loop below.
            $products = json_decode(wp_unslash($_POST['products'] ?? '[]'), true);
            $total = floatval($_POST['total'] ?? 0);

            if (empty($name) || !is_email($email) || empty($phone) || !is_array($products) || empty($products)) {
                wp_send_json_error(esc_html__('Please fill all required fields correctly.', 'estimation-tool-for-woocommerce'));
            }

            // Cross-check the phone format on the server: when a restrict_country
            // is set or when the number is in E.164 (starts with '+'), the
            // country prefix has to map to a known dial code.
            $phone_digits = preg_replace('/\D/', '', $phone);
            if (strlen($phone_digits) < 7 || strlen($phone_digits) > 15) {
                wp_send_json_error(esc_html__('That phone number looks too short or too long. Please check it.', 'estimation-tool-for-woocommerce'));
            }
            $restrict = strtolower((string) Estitofo_Options::get('restrict_country', ''));
            if ($restrict && strpos($phone, '+') === 0) {
                $expected_dial = self::dial_code_for($restrict);
                if ($expected_dial && strpos($phone_digits, $expected_dial) !== 0) {
                    wp_send_json_error(esc_html__('Phone number does not match the required country.', 'estimation-tool-for-woocommerce'));
                }
            }

            $clean_products = array();
            foreach ($products as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $clean_products[] = array(
                    'id'       => isset($p['id']) ? absint($p['id']) : 0,
                    'title'    => isset($p['title']) ? sanitize_text_field($p['title']) : '',
                    'price'    => isset($p['price']) ? floatval($p['price']) : 0,
                    'quantity' => isset($p['quantity']) ? max(1, absint($p['quantity'])) : 1,
                    'image'    => isset($p['image']) ? esc_url_raw($p['image']) : '',
                    'note'     => isset($p['note']) ? sanitize_text_field($p['note']) : '',
                );
            }

            global $wpdb;
            $table_name = $wpdb->prefix . 'estimation_submissions';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table insert.
            $result = $wpdb->insert($table_name, array(
                'name'           => $name,
                'email'          => $email,
                'phone'          => $phone,
                'company'        => $company,
                'address'        => $address,
                'customer_notes' => $cnotes,
                'products'       => wp_json_encode($clean_products),
                'total'          => $total,
                'workflow_status'=> 'new',
            ));

            if ($result === false) {
                wp_send_json_error(esc_html__('Failed to save estimation.', 'estimation-tool-for-woocommerce'));
            }

            $new_id = (int) $wpdb->insert_id;

            /**
             * Fires after an estimation submission is saved.
             *
             * @param int   $id            New row id.
             * @param array $clean_products Sanitized product list.
             */
            do_action('estitofo_after_submit', $new_id, $clean_products);

            wp_send_json_success(array(
                'success' => true,
                'id'      => $new_id,
                'pdf_url' => self::public_pdf_url($new_id),
                'message' => esc_html__('Estimation saved successfully.', 'estimation-tool-for-woocommerce'),
            ));
        }

        public function ajax_save_resume() {
            check_ajax_referer('estitofo_nonce', 'nonce');

            if (!(int) Estitofo_Options::get('enable_save_resume', 1)) {
                wp_send_json_error(esc_html__('Save & resume is disabled.', 'estimation-tool-for-woocommerce'), 404);
            }
            if (!empty($_POST['website'])) {
                wp_send_json_error(esc_html__('Spam detected.', 'estimation-tool-for-woocommerce'));
            }
            if (!is_user_logged_in() && !$this->rate_limit('resume', 3, 300)) {
                wp_send_json_error(esc_html__('Too many requests. Please try again in a few minutes.', 'estimation-tool-for-woocommerce'), 429);
            }

            $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            if (!is_email($email)) {
                wp_send_json_error(esc_html__('A valid email is required.', 'estimation-tool-for-woocommerce'), 400);
            }
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; sanitized in loop.
            $products = json_decode(wp_unslash($_POST['products'] ?? '[]'), true);
            if (!is_array($products) || empty($products)) {
                wp_send_json_error(esc_html__('Nothing to save yet.', 'estimation-tool-for-woocommerce'), 400);
            }

            $clean = array();
            foreach ($products as $p) {
                if (!is_array($p)) continue;
                $clean[] = array(
                    'id'       => isset($p['id']) ? absint($p['id']) : 0,
                    'title'    => isset($p['title']) ? sanitize_text_field($p['title']) : '',
                    'price'    => isset($p['price']) ? floatval($p['price']) : 0,
                    'quantity' => isset($p['quantity']) ? max(1, absint($p['quantity'])) : 1,
                    'image'    => isset($p['image']) ? esc_url_raw($p['image']) : '',
                    'note'     => isset($p['note']) ? sanitize_text_field($p['note']) : '',
                );
            }
            $total = floatval($_POST['total'] ?? 0);

            $token  = wp_generate_password(32, false, false);
            $ttl    = max(1, min(60, (int) Estitofo_Options::get('resume_link_ttl', 7)));
            $expires = gmdate('Y-m-d H:i:s', time() + $ttl * DAY_IN_SECONDS);

            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table.
            $wpdb->insert($wpdb->prefix . 'estimation_submissions', array(
                'name'           => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                'email'          => $email,
                'phone'          => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
                'company'        => sanitize_text_field(wp_unslash($_POST['company'] ?? '')),
                'address'        => sanitize_text_field(wp_unslash($_POST['address'] ?? '')),
                'customer_notes' => sanitize_textarea_field(wp_unslash($_POST['customer_notes'] ?? '')),
                'products'       => wp_json_encode($clean),
                'total'          => $total,
                'status'         => 'publish',
                'workflow_status'=> 'new',
                'resume_token'   => $token,
                'resume_expires' => $expires,
            ));

            $page_url = wp_get_referer() ?: home_url('/');
            $link = add_query_arg('estitofo_resume', $token, $page_url);

            $subject = sprintf(
                /* translators: %s: site name */
                __('Resume your estimation at %s', 'estimation-tool-for-woocommerce'),
                get_bloginfo('name')
            );
            $body = '<p>' . sprintf(
                /* translators: %s: resume link */
                esc_html__('Click the link below to resume your estimation: %s', 'estimation-tool-for-woocommerce'),
                '<a href="' . esc_url($link) . '">' . esc_html($link) . '</a>'
            ) . '</p>';
            $body .= '<p>' . sprintf(
                /* translators: %d: number of days */
                esc_html__('This link expires in %d days.', 'estimation-tool-for-woocommerce'),
                $ttl
            ) . '</p>';

            $headers = array('Content-Type: text/html; charset=UTF-8');
            wp_mail($email, $subject, $body, $headers);

            wp_send_json_success(array(
                'message' => esc_html__('Check your inbox for the resume link.', 'estimation-tool-for-woocommerce'),
            ));
        }

        public function ajax_search_products() {
            check_ajax_referer('estitofo_nonce', 'nonce');
            if (!function_exists('wc_get_products')) {
                wp_send_json_error(esc_html__('WooCommerce is not active.', 'estimation-tool-for-woocommerce'));
            }
            $search_term = sanitize_text_field(wp_unslash($_REQUEST['term'] ?? ''));
            if (mb_strlen($search_term) < 2) {
                wp_send_json_success(array());
            }
            $category = isset($_REQUEST['category']) ? absint($_REQUEST['category']) : 0;
            $products = class_exists('Estitofo_REST')
                ? Estitofo_REST::run_product_search($search_term, $category, 10)
                : array();
            $results = array();
            foreach ($products as $product) {
                $results[] = array(
                    'id'         => $product->get_id(),
                    'title'      => wp_strip_all_tags($product->get_name()),
                    'price'      => floatval($product->get_price()),
                    'price_html' => wp_kses_post($product->get_price_html()),
                    'image'      => get_the_post_thumbnail_url($product->get_id(), 'thumbnail'),
                );
            }
            wp_send_json_success($results);
        }

        public function generate_pdf() {
            check_ajax_referer('estitofo_nonce', 'nonce');

            if (!is_user_logged_in() && !$this->rate_limit('pdf', 5, 60)) {
                wp_send_json_error(esc_html__('Too many requests. Please try again in a moment.', 'estimation-tool-for-woocommerce'), 429);
            }

            if (!class_exists('TCPDF')) {
                wp_send_json_error(esc_html__('PDF library not loaded', 'estimation-tool-for-woocommerce'));
                return;
            }
            if (!class_exists('WooCommerce')) {
                wp_send_json_error(esc_html__('WooCommerce is not active.', 'estimation-tool-for-woocommerce'));
                return;
            }
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload sanitized inside Estitofo_PDF::generate().
            $products = json_decode(wp_unslash($_POST['products'] ?? '[]'), true);
            $total = floatval($_POST['total'] ?? 0);
            $user_info = array(
                'name'  => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
                'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            );
            try {
                $pdf_generator = new Estitofo_PDF();
                $pdf = $pdf_generator->generate($products, $total, $user_info);
                $pdf->Output('product-estimation-' . gmdate('Y-m-d') . '.pdf', 'D');
            } catch (Exception $e) {
                wp_send_json_error(esc_html__('PDF generation failed.', 'estimation-tool-for-woocommerce'));
            }
            wp_die();
        }
    }
}

register_activation_hook(__FILE__, array('Estitofo_Plugin', 'create_estimation_table'));

function estitofo_form() {
    return Estitofo_Plugin::instance();
}
estitofo_form();
