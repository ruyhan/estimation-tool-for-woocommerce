<?php
/**
 * Tabbed settings page.
 *
 * Tabs: General / PDF / Forms / Notifications / Tools.
 * Pro can register extra tabs via the `estitofo_settings_tabs` filter
 * and render content via the `estitofo_settings_tab_{slug}` action.
 *
 * @package estimation-tool-for-woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estitofo_Settings {

    const NONCE_ACTION = 'estitofo_save_settings';
    const PAGE_SLUG    = 'estimation-settings';

    public static function init() {
        add_action('admin_init', array(__CLASS__, 'handle_save'));
        add_action('admin_post_estitofo_test_email', array(__CLASS__, 'handle_test_email'));
        add_action('admin_post_estitofo_export_settings', array(__CLASS__, 'handle_export'));
        add_action('admin_post_estitofo_import_settings', array(__CLASS__, 'handle_import'));
    }

    public static function tabs() {
        $tabs = array(
            'general'       => __('General', 'estimation-tool-for-woocommerce'),
            'pdf'           => __('PDF', 'estimation-tool-for-woocommerce'),
            'forms'         => __('Forms', 'estimation-tool-for-woocommerce'),
            'notifications' => __('Notifications', 'estimation-tool-for-woocommerce'),
            'tools'         => __('Tools', 'estimation-tool-for-woocommerce'),
        );
        return apply_filters('estitofo_settings_tabs', $tabs);
    }

    public static function current_tab() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab nav.
        $requested = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $tabs = self::tabs();
        return array_key_exists($requested, $tabs) ? $requested : 'general';
    }

    public static function handle_save() {
        if (!isset($_POST['estitofo_settings_nonce'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['estitofo_settings_nonce'])), self::NONCE_ACTION)) {
            return;
        }

        // Nonce verified above. The raw array is sanitized key-by-key in
        // sanitize_input() (every scalar through the appropriate sanitizer),
        // so the access point itself carries no unsanitized data downstream.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field-by-field in sanitize_input().
        $input = ( isset($_POST['estitofo']) && is_array($_POST['estitofo']) ) ? wp_unslash($_POST['estitofo']) : array();

        $sanitized = self::sanitize_input($input);

        // We pass an EARLY-SANITIZED snapshot of the raw input (every value
        // run through sanitize_text_field) to the filter, so add-on callbacks
        // receive scalar safe strings — never the raw, unsanitised $_POST data.
        // Add-ons that need to handle their own keys can re-sanitise as needed.
        $raw_sanitized = array_map(
            function ($v) {
                if (is_array($v)) {
                    return array_map('sanitize_text_field', $v);
                }
                return sanitize_text_field((string) $v);
            },
            $input
        );

        /**
         * Filter the sanitized payload before persisting.
         * Pro uses this to merge its own keys.
         *
         * @param array $sanitized      Settings as cleaned by this plugin.
         * @param array $raw_sanitized  Every submitted key, lightly text-sanitized
         *                              (for add-on keys this plugin doesn't know about).
         */
        $sanitized = apply_filters('estitofo_save_settings', $sanitized, $raw_sanitized);

        Estitofo_Options::update_many($sanitized);

        add_settings_error('estitofo_messages', 'estitofo_saved', __('Settings saved.', 'estimation-tool-for-woocommerce'), 'updated');
        set_transient('settings_errors', get_settings_errors(), 30);
    }

    public static function sanitize_input(array $input) {
        $clean = array();

        $text_keys = array(
            'heading', 'company_name', 'company_tagline', 'footer_text',
            'pdf_author', 'submit_button_text', 'success_message',
            'from_name', 'email_subject',
        );
        foreach ($text_keys as $k) {
            if (isset($input[$k])) {
                $clean[$k] = sanitize_text_field($input[$k]);
            }
        }
        // Subheading is a longer free-text line, allow textarea-style sanitization.
        if (isset($input['subheading'])) {
            $clean['subheading'] = sanitize_text_field($input['subheading']);
        }

        if (isset($input['logo_url']))         $clean['logo_url']         = esc_url_raw($input['logo_url']);
        if (isset($input['logo_id']))          $clean['logo_id']          = absint($input['logo_id']);
        if (isset($input['admin_recipients'])) $clean['admin_recipients'] = self::sanitize_emails_csv($input['admin_recipients']);
        if (isset($input['from_email']))       $clean['from_email']       = sanitize_email($input['from_email']);
        if (isset($input['email_body']))       $clean['email_body']       = wp_kses_post($input['email_body']);
        if (isset($input['locations']))        $clean['locations']        = self::sanitize_locations($input['locations']);

        // Colors
        if (isset($input['primary_color'])) $clean['primary_color'] = self::sanitize_color($input['primary_color']);
        if (isset($input['accent_color']))  $clean['accent_color']  = self::sanitize_color($input['accent_color']);

        // Countries
        if (isset($input['default_country']))  $clean['default_country']  = self::sanitize_country($input['default_country']);
        if (isset($input['restrict_country'])) $clean['restrict_country'] = self::sanitize_country($input['restrict_country']);

        // Booleans
        foreach (array('collect_company', 'collect_address', 'collect_notes', 'admin_notify', 'customer_notify', 'attach_pdf_email', 'enable_save_resume', 'show_dashboard_widget') as $k) {
            $clean[$k] = !empty($input[$k]) ? 1 : 0;
        }

        // Integers
        if (isset($input['resume_link_ttl'])) {
            $clean['resume_link_ttl'] = max(1, min(60, absint($input['resume_link_ttl'])));
        }

        return $clean;
    }

    public static function sanitize_color($value) {
        $value = trim((string) $value);
        if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $value)) {
            return $value;
        }
        return '';
    }

    public static function sanitize_country($value) {
        $value = strtolower(sanitize_text_field($value));
        return preg_match('/^[a-z]{2}$/', $value) ? $value : '';
    }

    public static function sanitize_emails_csv($value) {
        $parts = array_filter(array_map('trim', explode(',', (string) $value)));
        $clean = array();
        foreach ($parts as $email) {
            $email = sanitize_email($email);
            if (is_email($email)) {
                $clean[] = $email;
            }
        }
        return implode(', ', array_unique($clean));
    }

    public static function sanitize_locations($value) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        $clean = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            $name = sanitize_text_field($parts[0] ?? '');
            $phone = sanitize_text_field($parts[1] ?? '');
            if ($name !== '') {
                $clean[] = $name . ($phone !== '' ? ' | ' . $phone : '');
            }
        }
        return implode("\n", $clean);
    }

    public static function get_locations_array() {
        $value = (string) Estitofo_Options::get('locations', '');
        if ('' === $value) {
            return array();
        }
        $lines = preg_split('/\r\n|\r|\n/', $value);
        $out = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            $out[] = array(
                'name'  => $parts[0] ?? '',
                'phone' => $parts[1] ?? '',
            );
        }
        return $out;
    }

    public static function admin_recipients() {
        $csv = (string) Estitofo_Options::get('admin_recipients', '');
        if ('' === $csv) {
            return array(get_option('admin_email'));
        }
        $list = array_filter(array_map('trim', explode(',', $csv)));
        return $list ?: array(get_option('admin_email'));
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized access', 'estimation-tool-for-woocommerce'));
        }

        $tabs        = self::tabs();
        $current_tab = self::current_tab();
        $base_url    = admin_url('admin.php?page=' . self::PAGE_SLUG);
        ?>
        <div class="wrap wc-estimation-settings">
            <h1><?php esc_html_e('Estimation Tool Settings', 'estimation-tool-for-woocommerce'); ?></h1>

            <?php settings_errors('estitofo_messages'); ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label) :
                    $url   = esc_url(add_query_arg('tab', $slug, $base_url));
                    $class = 'nav-tab' . ($slug === $current_tab ? ' nav-tab-active' : '');
                ?>
                    <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($class); ?>"><?php echo wp_kses($label, array('span' => array('class' => true))); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            /**
             * Tabs that render their own UI (no outer settings form wrapper).
             * 'tools' renders its own forms; Pro adds 'license' here.
             */
            $no_form_tabs = apply_filters('estitofo_settings_no_form_tabs', array('tools'));
            ?>

            <?php if ('tools' === $current_tab) :
                self::render_tools_tab();
            elseif (in_array($current_tab, $no_form_tabs, true)) :
                do_action('estitofo_settings_tab_' . $current_tab);
            else : ?>
                <form method="post" action="">
                    <?php wp_nonce_field(self::NONCE_ACTION, 'estitofo_settings_nonce'); ?>
                    <input type="hidden" name="active_tab" value="<?php echo esc_attr($current_tab); ?>">

                    <?php
                    // Free renders the full customization for every core tab.
                    // Add-ons may *append* extra fields via the `..._extra`
                    // action; they cannot hide or lock the core ones.
                    $core_tabs = array('general', 'pdf', 'forms', 'notifications');
                    switch ($current_tab) {
                        case 'pdf':           self::render_pdf_tab();           break;
                        case 'notifications': self::render_notifications_tab(); break;
                        case 'forms':         self::render_forms_tab();         break;
                        case 'general':       self::render_general_tab();       break;
                        default:
                            // Unknown tab — let add-ons own it
                            // (e.g. Pro's "pro" or "license" tabs).
                            do_action('estitofo_settings_tab_' . $current_tab);
                            break;
                    }
                    // Hook for add-ons to append extra fields after any tab — fires
                    // for ALL tabs (core ones + add-on ones like "pro" / "license").
                    do_action('estitofo_settings_tab_' . $current_tab . '_extra');
                    ?>

                    <?php submit_button(); ?>
                </form>
            <?php endif; ?>

            <?php
            // ----------------------------------------------------------------
            // Modest, single-line discovery hint for the optional Pro add-on.
            //
            // WP.org explicitly permits "pointing out which features are
            // available through a separated plugin" — we just keep it small,
            // non-intrusive, and never disguise it as a locked feature.
            // The hint hides itself when Pro is already installed, so existing
            // customers don't see an upsell for something they already own.
            // ----------------------------------------------------------------
            if (!class_exists('Estitofo_Pro_Plugin')) :
                /**
                 * URL where buyers can purchase the Pro add-on. Falls back to a
                 * CodeCanyon search URL while the author updates the constant
                 * to their live item URL.
                 *
                 * @return string
                 */
                $pro_url = apply_filters(
                    'estitofo_pro_purchase_url',
                    'https://codecanyon.net/search/estimation%20tool%20for%20woocommerce'
                );
                ?>
                <p class="estitofo-pro-hint" style="margin-top:24px;color:#646970;font-size:13px;font-style:italic;">
                    <?php
                    printf(
                        wp_kses(
                            /* translators: %s: link to CodeCanyon item */
                            __('Looking for WooCommerce order conversion, file uploads, reCAPTCHA, analytics or more PDF templates? An optional Pro add-on is available on %s.', 'estimation-tool-for-woocommerce'),
                            array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))
                        ),
                        '<a href="' . esc_url($pro_url) . '" target="_blank" rel="noopener noreferrer">CodeCanyon</a>'
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        // Logo picker / color picker JS is enqueued separately via admin_enqueue_scripts
        // (assets/js/admin-settings.js) — no inline <script> tags here per WP.org guidelines.
    }

    private static function input_text($key, $type = 'text', $class = 'regular-text') {
        $value = Estitofo_Options::get($key, '');
        printf(
            '<input type="%s" name="estitofo[%s]" id="estitofo_%s" class="%s" value="%s">',
            esc_attr($type),
            esc_attr($key),
            esc_attr($key),
            esc_attr($class),
            esc_attr($value)
        );
    }

    private static function input_checkbox($key, $label) {
        $value = Estitofo_Options::get($key, 0);
        printf(
            '<label><input type="checkbox" name="estitofo[%s]" value="1" %s> %s</label>',
            esc_attr($key),
            checked($value, 1, false),
            esc_html($label)
        );
    }

    private static function input_textarea($key, $rows = 4) {
        $value = Estitofo_Options::get($key, '');
        printf(
            '<textarea name="estitofo[%s]" id="estitofo_%s" class="large-text code" rows="%d">%s</textarea>',
            esc_attr($key),
            esc_attr($key),
            (int) $rows,
            esc_textarea($value)
        );
    }

    /**
     * Country selector for default/restrict country settings.
     * Renders a native <select> with all countries (flag emoji + name + dial code).
     */
    private static function input_country_select($key, $blank_label = '') {
        $value = strtolower((string) Estitofo_Options::get($key, ''));
        $countries = self::countries_list();
        printf('<select name="estitofo[%s]" id="estitofo_%s" class="wc-est-country-select" style="min-width:300px;">',
            esc_attr($key), esc_attr($key));
        printf('<option value="">%s</option>', esc_html($blank_label));
        foreach ($countries as $iso => $info) {
            printf('<option value="%s" %s>%s %s (+%s)</option>',
                esc_attr($iso),
                selected($value, $iso, false),
                esc_html($info['flag']),
                esc_html($info['name']),
                esc_html($info['dial'])
            );
        }
        echo '</select>';
    }

    /**
     * ISO-2 → flag emoji + country name + dial code. Hand-curated subset of
     * common WC markets; merged with anything available from intl-tel-input
     * later if needed.
     */
    private static function countries_list() {
        return array(
            'af'=>array('name'=>'Afghanistan','dial'=>'93','flag'=>'🇦🇫'),
            'al'=>array('name'=>'Albania','dial'=>'355','flag'=>'🇦🇱'),
            'dz'=>array('name'=>'Algeria','dial'=>'213','flag'=>'🇩🇿'),
            'ar'=>array('name'=>'Argentina','dial'=>'54','flag'=>'🇦🇷'),
            'au'=>array('name'=>'Australia','dial'=>'61','flag'=>'🇦🇺'),
            'at'=>array('name'=>'Austria','dial'=>'43','flag'=>'🇦🇹'),
            'bh'=>array('name'=>'Bahrain','dial'=>'973','flag'=>'🇧🇭'),
            'bd'=>array('name'=>'Bangladesh','dial'=>'880','flag'=>'🇧🇩'),
            'be'=>array('name'=>'Belgium','dial'=>'32','flag'=>'🇧🇪'),
            'br'=>array('name'=>'Brazil','dial'=>'55','flag'=>'🇧🇷'),
            'bg'=>array('name'=>'Bulgaria','dial'=>'359','flag'=>'🇧🇬'),
            'kh'=>array('name'=>'Cambodia','dial'=>'855','flag'=>'🇰🇭'),
            'ca'=>array('name'=>'Canada','dial'=>'1','flag'=>'🇨🇦'),
            'cl'=>array('name'=>'Chile','dial'=>'56','flag'=>'🇨🇱'),
            'cn'=>array('name'=>'China','dial'=>'86','flag'=>'🇨🇳'),
            'co'=>array('name'=>'Colombia','dial'=>'57','flag'=>'🇨🇴'),
            'hr'=>array('name'=>'Croatia','dial'=>'385','flag'=>'🇭🇷'),
            'cy'=>array('name'=>'Cyprus','dial'=>'357','flag'=>'🇨🇾'),
            'cz'=>array('name'=>'Czech Republic','dial'=>'420','flag'=>'🇨🇿'),
            'dk'=>array('name'=>'Denmark','dial'=>'45','flag'=>'🇩🇰'),
            'eg'=>array('name'=>'Egypt','dial'=>'20','flag'=>'🇪🇬'),
            'fi'=>array('name'=>'Finland','dial'=>'358','flag'=>'🇫🇮'),
            'fr'=>array('name'=>'France','dial'=>'33','flag'=>'🇫🇷'),
            'de'=>array('name'=>'Germany','dial'=>'49','flag'=>'🇩🇪'),
            'gh'=>array('name'=>'Ghana','dial'=>'233','flag'=>'🇬🇭'),
            'gr'=>array('name'=>'Greece','dial'=>'30','flag'=>'🇬🇷'),
            'hk'=>array('name'=>'Hong Kong','dial'=>'852','flag'=>'🇭🇰'),
            'hu'=>array('name'=>'Hungary','dial'=>'36','flag'=>'🇭🇺'),
            'is'=>array('name'=>'Iceland','dial'=>'354','flag'=>'🇮🇸'),
            'in'=>array('name'=>'India','dial'=>'91','flag'=>'🇮🇳'),
            'id'=>array('name'=>'Indonesia','dial'=>'62','flag'=>'🇮🇩'),
            'ir'=>array('name'=>'Iran','dial'=>'98','flag'=>'🇮🇷'),
            'iq'=>array('name'=>'Iraq','dial'=>'964','flag'=>'🇮🇶'),
            'ie'=>array('name'=>'Ireland','dial'=>'353','flag'=>'🇮🇪'),
            'il'=>array('name'=>'Israel','dial'=>'972','flag'=>'🇮🇱'),
            'it'=>array('name'=>'Italy','dial'=>'39','flag'=>'🇮🇹'),
            'jp'=>array('name'=>'Japan','dial'=>'81','flag'=>'🇯🇵'),
            'jo'=>array('name'=>'Jordan','dial'=>'962','flag'=>'🇯🇴'),
            'ke'=>array('name'=>'Kenya','dial'=>'254','flag'=>'🇰🇪'),
            'kw'=>array('name'=>'Kuwait','dial'=>'965','flag'=>'🇰🇼'),
            'lb'=>array('name'=>'Lebanon','dial'=>'961','flag'=>'🇱🇧'),
            'my'=>array('name'=>'Malaysia','dial'=>'60','flag'=>'🇲🇾'),
            'mt'=>array('name'=>'Malta','dial'=>'356','flag'=>'🇲🇹'),
            'mx'=>array('name'=>'Mexico','dial'=>'52','flag'=>'🇲🇽'),
            'ma'=>array('name'=>'Morocco','dial'=>'212','flag'=>'🇲🇦'),
            'np'=>array('name'=>'Nepal','dial'=>'977','flag'=>'🇳🇵'),
            'nl'=>array('name'=>'Netherlands','dial'=>'31','flag'=>'🇳🇱'),
            'nz'=>array('name'=>'New Zealand','dial'=>'64','flag'=>'🇳🇿'),
            'ng'=>array('name'=>'Nigeria','dial'=>'234','flag'=>'🇳🇬'),
            'no'=>array('name'=>'Norway','dial'=>'47','flag'=>'🇳🇴'),
            'om'=>array('name'=>'Oman','dial'=>'968','flag'=>'🇴🇲'),
            'pk'=>array('name'=>'Pakistan','dial'=>'92','flag'=>'🇵🇰'),
            'ph'=>array('name'=>'Philippines','dial'=>'63','flag'=>'🇵🇭'),
            'pl'=>array('name'=>'Poland','dial'=>'48','flag'=>'🇵🇱'),
            'pt'=>array('name'=>'Portugal','dial'=>'351','flag'=>'🇵🇹'),
            'qa'=>array('name'=>'Qatar','dial'=>'974','flag'=>'🇶🇦'),
            'ro'=>array('name'=>'Romania','dial'=>'40','flag'=>'🇷🇴'),
            'ru'=>array('name'=>'Russia','dial'=>'7','flag'=>'🇷🇺'),
            'sa'=>array('name'=>'Saudi Arabia','dial'=>'966','flag'=>'🇸🇦'),
            'sg'=>array('name'=>'Singapore','dial'=>'65','flag'=>'🇸🇬'),
            'sk'=>array('name'=>'Slovakia','dial'=>'421','flag'=>'🇸🇰'),
            'si'=>array('name'=>'Slovenia','dial'=>'386','flag'=>'🇸🇮'),
            'za'=>array('name'=>'South Africa','dial'=>'27','flag'=>'🇿🇦'),
            'kr'=>array('name'=>'South Korea','dial'=>'82','flag'=>'🇰🇷'),
            'es'=>array('name'=>'Spain','dial'=>'34','flag'=>'🇪🇸'),
            'lk'=>array('name'=>'Sri Lanka','dial'=>'94','flag'=>'🇱🇰'),
            'se'=>array('name'=>'Sweden','dial'=>'46','flag'=>'🇸🇪'),
            'ch'=>array('name'=>'Switzerland','dial'=>'41','flag'=>'🇨🇭'),
            'tw'=>array('name'=>'Taiwan','dial'=>'886','flag'=>'🇹🇼'),
            'th'=>array('name'=>'Thailand','dial'=>'66','flag'=>'🇹🇭'),
            'tn'=>array('name'=>'Tunisia','dial'=>'216','flag'=>'🇹🇳'),
            'tr'=>array('name'=>'Turkey','dial'=>'90','flag'=>'🇹🇷'),
            'ua'=>array('name'=>'Ukraine','dial'=>'380','flag'=>'🇺🇦'),
            'ae'=>array('name'=>'United Arab Emirates','dial'=>'971','flag'=>'🇦🇪'),
            'gb'=>array('name'=>'United Kingdom','dial'=>'44','flag'=>'🇬🇧'),
            'us'=>array('name'=>'United States','dial'=>'1','flag'=>'🇺🇸'),
            've'=>array('name'=>'Venezuela','dial'=>'58','flag'=>'🇻🇪'),
            'vn'=>array('name'=>'Vietnam','dial'=>'84','flag'=>'🇻🇳'),
        );
    }

    private static function input_color($key) {
        $value = Estitofo_Options::get($key, '');
        printf(
            '<input type="text" name="estitofo[%s]" class="wc-est-color" value="%s" data-default-color="%s">',
            esc_attr($key),
            esc_attr($value),
            esc_attr($value)
        );
    }

    /**
     * Pro features showcase card — shows at the top of the General tab.
     * Pulls double duty: tells unlicensed sites what Pro unlocks, and
     * licensed sites get a "Pro is active" confirmation.
     */

    private static function render_general_tab() {
        ?>
        <table class="form-table" role="presentation">
            <tr><th><label for="estitofo_heading"><?php esc_html_e('Frontend Heading', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('heading'); ?>
                    <p class="description"><?php esc_html_e('Big title shown above the estimation form. Leave blank to use the default ("My plan and price estimation").', 'estimation-tool-for-woocommerce'); ?></p></td></tr>
            <tr><th><label for="estitofo_subheading"><?php esc_html_e('Frontend Subheading', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('subheading', 'text', 'large-text'); ?>
                    <p class="description"><?php esc_html_e('Smaller line of text shown under the heading. Leave blank to hide.', 'estimation-tool-for-woocommerce'); ?></p></td></tr>
            <tr><th><label for="estitofo_default_country"><?php esc_html_e('Default Phone Country', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_country_select('default_country', __('— Auto-detect by visitor IP —', 'estimation-tool-for-woocommerce')); ?>
                    <p class="description"><?php esc_html_e('Pre-selects this country\'s flag in the phone field on the frontend. Leave blank to auto-detect.', 'estimation-tool-for-woocommerce'); ?></p></td></tr>
            <tr><th><label for="estitofo_restrict_country"><?php esc_html_e('Restrict to Country', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_country_select('restrict_country', __('— Allow any country —', 'estimation-tool-for-woocommerce')); ?>
                    <p class="description"><?php esc_html_e('If set, the phone field is locked to this country — the flag dropdown is hidden and only numbers from this country are accepted.', 'estimation-tool-for-woocommerce'); ?></p></td></tr>
            <tr><th><label><?php esc_html_e('Primary Color', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_color('primary_color'); ?></td></tr>
            <tr><th><label><?php esc_html_e('Accent Color', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_color('accent_color'); ?></td></tr>
            <tr><th><?php esc_html_e('Dashboard Widget', 'estimation-tool-for-woocommerce'); ?></th>
                <td><?php self::input_checkbox('show_dashboard_widget', __('Show recent estimations on the WP dashboard', 'estimation-tool-for-woocommerce')); ?></td></tr>
        </table>
        <?php
    }

    private static function render_pdf_tab() {
        ?>
        <table class="form-table" role="presentation">
            <tr><th><label for="estitofo_company_name"><?php esc_html_e('Company Name', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('company_name'); ?></td></tr>
            <tr><th><label for="estitofo_company_tagline"><?php esc_html_e('Tagline / Subheading', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('company_tagline'); ?></td></tr>
            <tr><th><label for="estitofo_logo_url"><?php esc_html_e('Logo URL', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td class="wc-est-media-field">
                    <?php self::input_text('logo_url', 'url'); ?>
                    <button type="button" class="button wc-est-upload-logo" data-title="<?php esc_attr_e('Select Logo', 'estimation-tool-for-woocommerce'); ?>"><?php esc_html_e('Choose Image', 'estimation-tool-for-woocommerce'); ?></button>
                </td></tr>
            <tr><th><label for="estitofo_footer_text"><?php esc_html_e('Footer Note', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('footer_text'); ?></td></tr>
            <tr><th><label for="estitofo_pdf_author"><?php esc_html_e('PDF Author (metadata)', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('pdf_author'); ?></td></tr>
            <tr><th><label for="estitofo_locations"><?php esc_html_e('Store / Location List', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_textarea('locations', 5); ?>
                    <p class="description"><?php esc_html_e('One per line: "Name | Phone". Shown at the bottom of the PDF.', 'estimation-tool-for-woocommerce'); ?></p></td></tr>
        </table>
        <?php
    }

    private static function render_forms_tab() {
        ?>
        <table class="form-table" role="presentation">
            <tr><th><?php esc_html_e('Optional Fields', 'estimation-tool-for-woocommerce'); ?></th>
                <td>
                    <?php self::input_checkbox('collect_company', __('Collect company name', 'estimation-tool-for-woocommerce')); ?><br>
                    <?php self::input_checkbox('collect_address', __('Collect address', 'estimation-tool-for-woocommerce')); ?><br>
                    <?php self::input_checkbox('collect_notes', __('Allow customer notes', 'estimation-tool-for-woocommerce')); ?>
                </td></tr>
            <tr><th><label for="estitofo_submit_button_text"><?php esc_html_e('Submit Button Text', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('submit_button_text'); ?>
                    <p class="description"><?php esc_html_e('Leave blank to use the default ("Get My Estimation PDF").', 'estimation-tool-for-woocommerce'); ?></p></td></tr>
            <tr><th><label for="estitofo_success_message"><?php esc_html_e('Success Message', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('success_message'); ?></td></tr>
            <tr><th><?php esc_html_e('Save & Resume', 'estimation-tool-for-woocommerce'); ?></th>
                <td><?php self::input_checkbox('enable_save_resume', __('Let customers email themselves a link to resume their estimation', 'estimation-tool-for-woocommerce')); ?></td></tr>
            <tr><th><label for="estitofo_resume_link_ttl"><?php esc_html_e('Resume Link Lifetime (days)', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('resume_link_ttl', 'number', 'small-text'); ?></td></tr>
            <?php do_action('estitofo_settings_forms_extra'); ?>
        </table>
        <?php
    }

    private static function render_notifications_tab() {
        ?>
        <table class="form-table" role="presentation">
            <tr><th><?php esc_html_e('Admin Notification', 'estimation-tool-for-woocommerce'); ?></th>
                <td><?php self::input_checkbox('admin_notify', __('Email admin when a new estimation is submitted', 'estimation-tool-for-woocommerce')); ?></td></tr>
            <tr><th><label for="estitofo_admin_recipients"><?php esc_html_e('Admin Recipients', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('admin_recipients'); ?>
                    <p class="description"><?php esc_html_e('Comma-separated. Leaves the site admin email if blank.', 'estimation-tool-for-woocommerce'); ?></p></td></tr>
            <tr><th><?php esc_html_e('Customer Confirmation', 'estimation-tool-for-woocommerce'); ?></th>
                <td>
                    <?php self::input_checkbox('customer_notify', __('Send a confirmation email to the customer', 'estimation-tool-for-woocommerce')); ?><br>
                    <?php self::input_checkbox('attach_pdf_email', __('Attach the PDF to the customer email', 'estimation-tool-for-woocommerce')); ?>
                </td></tr>
            <tr><th><label for="estitofo_from_name"><?php esc_html_e('From Name', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('from_name'); ?></td></tr>
            <tr><th><label for="estitofo_from_email"><?php esc_html_e('From Email', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('from_email', 'email'); ?></td></tr>
            <tr><th><label for="estitofo_email_subject"><?php esc_html_e('Customer Email Subject', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_text('email_subject'); ?>
                    <p class="description"><?php
                        /* translators: 1: token list */
                        printf(esc_html__('Tokens: %s', 'estimation-tool-for-woocommerce'), '<code>{{name}}</code> <code>{{total}}</code> <code>{{site}}</code>');
                    ?></p></td></tr>
            <tr><th><label for="estitofo_email_body"><?php esc_html_e('Customer Email Body', 'estimation-tool-for-woocommerce'); ?></label></th>
                <td><?php self::input_textarea('email_body', 8); ?>
                    <p class="description"><?php
                        /* translators: 1: token list */
                        printf(esc_html__('HTML allowed. Tokens: %s', 'estimation-tool-for-woocommerce'), '<code>{{name}}</code> <code>{{email}}</code> <code>{{phone}}</code> <code>{{total}}</code> <code>{{products_table}}</code> <code>{{site}}</code>');
                    ?></p></td></tr>
        </table>
        <?php
    }

    /**
     * Onboarding card: how to put the estimation form on a page
     * (shortcode + Elementor widget) — shown in the Tools tab.
     */
    private static function render_usage_guide() {
        $elementor_active = defined('ELEMENTOR_VERSION');
        $shortcode = '[estitofo_form]';
        $shortcode_custom = '[estitofo_form heading="Get your custom quote"]';
        ?>
        <div class="wc-est-guide-card">
            <div class="wc-est-guide-card__head">
                <h2><?php esc_html_e('How to add the estimation form to a page', 'estimation-tool-for-woocommerce'); ?></h2>
                <p class="description" style="margin:0;"><?php esc_html_e('Pick whichever method matches the editor you use. Both render the same modern frontend.', 'estimation-tool-for-woocommerce'); ?></p>
            </div>

            <div class="wc-est-guide-grid">
                <!-- Shortcode -->
                <section class="wc-est-guide-block">
                    <h3>
                        <span class="wc-est-guide-num">1</span>
                        <?php esc_html_e('Shortcode (Block / Classic editor)', 'estimation-tool-for-woocommerce'); ?>
                    </h3>
                    <ol class="wc-est-guide-list">
                        <li><?php
                            /* translators: 1: menu path */
                            printf(esc_html__('Edit the page where you want the form (or create a new one under %s).', 'estimation-tool-for-woocommerce'),
                                '<code>' . esc_html__('Pages → Add New', 'estimation-tool-for-woocommerce') . '</code>'
                            );
                        ?></li>
                        <li><?php
                            /* translators: 1: shortcode block name */
                            printf(esc_html__('Add a %s block (in Classic editor, just paste the shortcode anywhere in the content).', 'estimation-tool-for-woocommerce'),
                                '<code>Shortcode</code>'
                            );
                        ?></li>
                        <li><?php esc_html_e('Paste this shortcode and publish:', 'estimation-tool-for-woocommerce'); ?></li>
                    </ol>
                    <div class="wc-est-code">
                        <code><?php echo esc_html($shortcode); ?></code>
                        <button type="button" class="button button-secondary wc-est-copy" data-copy="<?php echo esc_attr($shortcode); ?>"><?php esc_html_e('Copy', 'estimation-tool-for-woocommerce'); ?></button>
                    </div>

                    <h4 style="margin-top:18px;"><?php esc_html_e('Optional: custom heading', 'estimation-tool-for-woocommerce'); ?></h4>
                    <p class="description"><?php esc_html_e('Override the default frontend heading per page:', 'estimation-tool-for-woocommerce'); ?></p>
                    <div class="wc-est-code">
                        <code><?php echo esc_html($shortcode_custom); ?></code>
                        <button type="button" class="button button-secondary wc-est-copy" data-copy="<?php echo esc_attr($shortcode_custom); ?>"><?php esc_html_e('Copy', 'estimation-tool-for-woocommerce'); ?></button>
                    </div>

                    <details class="wc-est-guide-details" style="margin-top:14px;">
                        <summary><?php esc_html_e('All available attributes', 'estimation-tool-for-woocommerce'); ?></summary>
                        <table class="widefat striped" style="margin-top:10px;">
                            <thead><tr><th><?php esc_html_e('Attribute', 'estimation-tool-for-woocommerce'); ?></th><th><?php esc_html_e('Description', 'estimation-tool-for-woocommerce'); ?></th><th><?php esc_html_e('Default', 'estimation-tool-for-woocommerce'); ?></th></tr></thead>
                            <tbody>
                                <tr><td><code>heading</code></td><td><?php esc_html_e('Title shown above the estimation form.', 'estimation-tool-for-woocommerce'); ?></td><td><code><?php echo esc_html(Estitofo_Options::get('heading', __('My plan and price estimation', 'estimation-tool-for-woocommerce'))); ?></code></td></tr>
                                <tr><td><code>subheading</code></td><td><?php esc_html_e('Smaller text shown under the heading. Leave blank to hide.', 'estimation-tool-for-woocommerce'); ?></td><td><code><?php echo esc_html(Estitofo_Options::get('subheading', '')); ?></code></td></tr>
                            </tbody>
                        </table>
                    </details>
                </section>

                <!-- Elementor widget -->
                <section class="wc-est-guide-block">
                    <h3>
                        <span class="wc-est-guide-num">2</span>
                        <?php esc_html_e('Elementor widget', 'estimation-tool-for-woocommerce'); ?>
                        <?php if (!$elementor_active) : ?>
                            <span class="wc-est-pill wc-est-pill--warn"><?php esc_html_e('Elementor not detected', 'estimation-tool-for-woocommerce'); ?></span>
                        <?php else : ?>
                            <span class="wc-est-pill wc-est-pill--ok"><?php esc_html_e('Elementor ready', 'estimation-tool-for-woocommerce'); ?></span>
                        <?php endif; ?>
                    </h3>
                    <ol class="wc-est-guide-list">
                        <li><?php esc_html_e('Edit any page with Elementor.', 'estimation-tool-for-woocommerce'); ?></li>
                        <li><?php
                            /* translators: 1: widget search term */
                            printf(esc_html__('In the left panel, search for %s.', 'estimation-tool-for-woocommerce'),
                                '<code>' . esc_html__('Estimation Tool', 'estimation-tool-for-woocommerce') . '</code>'
                            );
                        ?></li>
                        <li><?php esc_html_e('Drag the widget into your layout and tweak the heading from the widget settings on the left.', 'estimation-tool-for-woocommerce'); ?></li>
                    </ol>
                    <p class="description">
                        <?php
                        /* translators: 1: category name, 2: minimum Elementor version */
                        printf(esc_html__('The widget lives in the %1$s category and requires Elementor %2$s or higher.', 'estimation-tool-for-woocommerce'),
                            '<strong>' . esc_html__('Estimation Tool', 'estimation-tool-for-woocommerce') . '</strong>',
                            '<code>' . esc_html(Estitofo_Plugin::MIN_ELEMENTOR_VERSION) . '</code>'
                        );
                        ?>
                    </p>
                </section>

                <!-- PHP / theme template -->
                <section class="wc-est-guide-block">
                    <h3>
                        <span class="wc-est-guide-num">3</span>
                        <?php esc_html_e('PHP / theme template', 'estimation-tool-for-woocommerce'); ?>
                    </h3>
                    <p class="description"><?php esc_html_e('Drop this anywhere in a page template if you need pure PHP integration:', 'estimation-tool-for-woocommerce'); ?></p>
                    <div class="wc-est-code">
                        <code>&lt;?php echo do_shortcode(<?php echo "'" . esc_html($shortcode) . "'"; ?>); ?&gt;</code>
                        <button type="button" class="button button-secondary wc-est-copy" data-copy="&lt;?php echo do_shortcode(<?php echo "'" . esc_attr($shortcode) . "'"; ?>); ?&gt;"><?php esc_html_e('Copy', 'estimation-tool-for-woocommerce'); ?></button>
                    </div>
                </section>
            </div>

            <p class="wc-est-guide-tip">
                <strong><?php esc_html_e('Tip:', 'estimation-tool-for-woocommerce'); ?></strong>
                <?php esc_html_e('Set your brand colours and default phone country under the General tab first — they\'re used everywhere the form appears.', 'estimation-tool-for-woocommerce'); ?>
            </p>
        </div>

        <?php
        // Usage-guide styles + copy-button JS are enqueued via admin_enqueue_scripts
        // (assets/css/admin-estimation.css and assets/js/admin-settings.js).
    }

    private static function render_tools_tab() {
        $admin_post = esc_url(admin_url('admin-post.php'));
        self::render_usage_guide();
        ?>
        <h2><?php esc_html_e('Send Test Email', 'estimation-tool-for-woocommerce'); ?></h2>
        <form method="post" action="<?php echo esc_attr($admin_post); ?>" style="margin-bottom:30px;">
            <?php wp_nonce_field('estitofo_test_email', 'estitofo_test_email_nonce'); ?>
            <input type="hidden" name="action" value="estitofo_test_email">
            <input type="email" name="recipient" class="regular-text" placeholder="<?php esc_attr_e('Recipient address', 'estimation-tool-for-woocommerce'); ?>" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" required>
            <?php submit_button(__('Send Test Email', 'estimation-tool-for-woocommerce'), 'secondary', 'submit', false); ?>
        </form>

        <h2><?php esc_html_e('Export Settings', 'estimation-tool-for-woocommerce'); ?></h2>
        <p><?php esc_html_e('Download all plugin settings as JSON so you can import them on another site.', 'estimation-tool-for-woocommerce'); ?></p>
        <form method="post" action="<?php echo esc_attr($admin_post); ?>" style="margin-bottom:30px;">
            <?php wp_nonce_field('estitofo_export_settings', 'estitofo_export_nonce'); ?>
            <input type="hidden" name="action" value="estitofo_export_settings">
            <?php submit_button(__('Download JSON', 'estimation-tool-for-woocommerce'), 'secondary', 'submit', false); ?>
        </form>

        <h2><?php esc_html_e('Import Settings', 'estimation-tool-for-woocommerce'); ?></h2>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_attr($admin_post); ?>">
            <?php wp_nonce_field('estitofo_import_settings', 'estitofo_import_nonce'); ?>
            <input type="hidden" name="action" value="estitofo_import_settings">
            <input type="file" name="settings_file" accept="application/json" required>
            <?php submit_button(__('Upload JSON', 'estimation-tool-for-woocommerce'), 'primary', 'submit', false); ?>
        </form>

        <?php do_action('estitofo_settings_tools_extra'); ?>
        <?php
    }

    public static function handle_test_email() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'estimation-tool-for-woocommerce'), '', array('response' => 403));
        }
        if (!isset($_POST['estitofo_test_email_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['estitofo_test_email_nonce'])), 'estitofo_test_email')) {
            wp_die(esc_html__('Invalid request', 'estimation-tool-for-woocommerce'), '', array('response' => 403));
        }
        $to = isset($_POST['recipient']) ? sanitize_email(wp_unslash($_POST['recipient'])) : '';
        if (!is_email($to)) {
            wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'tab' => 'tools', 'test' => 'invalid'), admin_url('admin.php')));
            exit;
        }

        $sent = Estitofo_Mailer::send_test($to);
        wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'tab' => 'tools', 'test' => $sent ? 'sent' : 'failed'), admin_url('admin.php')));
        exit;
    }

    public static function handle_export() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'estimation-tool-for-woocommerce'), '', array('response' => 403));
        }
        if (!isset($_POST['estitofo_export_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['estitofo_export_nonce'])), 'estitofo_export_settings')) {
            wp_die(esc_html__('Invalid request', 'estimation-tool-for-woocommerce'), '', array('response' => 403));
        }
        $data = array(
            'plugin'   => 'estimation-tool-for-woocommerce',
            'version'  => defined('ESTITOFO_VERSION') ? ESTITOFO_VERSION : '',
            'exported' => gmdate('c'),
            'settings' => Estitofo_Options::all(),
        );
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=wc-estimation-settings-' . gmdate('Ymd-His') . '.json');
        echo wp_json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    public static function handle_import() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'estimation-tool-for-woocommerce'), '', array('response' => 403));
        }
        if (!isset($_POST['estitofo_import_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['estitofo_import_nonce'])), 'estitofo_import_settings')) {
            wp_die(esc_html__('Invalid request', 'estimation-tool-for-woocommerce'), '', array('response' => 403));
        }
        if (empty($_FILES['settings_file']['tmp_name'])) {
            wp_die(esc_html__('No file uploaded', 'estimation-tool-for-woocommerce'));
        }
        // The tmp path is a server-generated upload path, validated below via
        // is_uploaded_file(); sanitize_text_field keeps PCP happy on the access.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated via is_uploaded_file().
        $tmp = sanitize_text_field(wp_unslash($_FILES['settings_file']['tmp_name']));
        if (!is_uploaded_file($tmp)) {
            wp_die(esc_html__('Invalid upload', 'estimation-tool-for-woocommerce'));
        }
        // Read the uploaded JSON file via WP_Filesystem (PCP-friendly).
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        global $wp_filesystem;
        if (!WP_Filesystem()) {
            wp_die(esc_html__('Could not access the filesystem to read the uploaded file.', 'estimation-tool-for-woocommerce'));
        }
        $contents = $wp_filesystem->get_contents($tmp);
        $decoded  = json_decode((string) $contents, true);
        if (!is_array($decoded) || empty($decoded['settings']) || !is_array($decoded['settings'])) {
            wp_die(esc_html__('Invalid settings file', 'estimation-tool-for-woocommerce'));
        }
        $sanitized = self::sanitize_input($decoded['settings']);
        Estitofo_Options::update_many($sanitized);
        wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'tab' => 'tools', 'imported' => 1), admin_url('admin.php')));
        exit;
    }
}

Estitofo_Settings::init();
