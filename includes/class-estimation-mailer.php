<?php
/**
 * Email dispatcher.
 *
 * @package estimation-tool-for-woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estitofo_Mailer {

    public static function init() {
        add_action('estitofo_after_submit', array(__CLASS__, 'on_submit'), 10, 2);
    }

    /**
     * Triggered after a submission is saved.
     *
     * @param int   $id            Submission ID.
     * @param array $clean_products Sanitized product list.
     */
    public static function on_submit($id, $clean_products) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}estimation_submissions WHERE id = %d",
            $id
        )); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if (!$row) {
            return;
        }

        if ((int) Estitofo_Options::get('admin_notify', 1)) {
            self::send_admin_notification($row, $clean_products);
        }
        if ((int) Estitofo_Options::get('customer_notify', 1)) {
            self::send_customer_confirmation($row, $clean_products);
        }
    }

    private static function from_headers() {
        $headers   = array('Content-Type: text/html; charset=UTF-8');
        $from_name = (string) Estitofo_Options::get('from_name', '');
        $from_mail = (string) Estitofo_Options::get('from_email', '');
        if ($from_name === '') {
            $from_name = get_bloginfo('name');
        }
        if (!is_email($from_mail)) {
            $from_mail = get_option('admin_email');
        }
        $headers[] = sprintf('From: %s <%s>', $from_name, $from_mail);
        return $headers;
    }

    private static function products_html_table($products, $total) {
        $rows = '';
        foreach ((array) $products as $p) {
            $title    = isset($p['title']) ? esc_html($p['title']) : '';
            $price    = isset($p['price']) ? (float) $p['price'] : 0;
            $qty      = isset($p['quantity']) ? max(1, absint($p['quantity'])) : 1;
            $subtotal = $price * $qty;
            $rows .= sprintf(
                '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:center;">%d</td><td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right;">%s</td></tr>',
                $title,
                $qty,
                esc_html(number_format_i18n($price, 2)),
                esc_html(number_format_i18n($subtotal, 2))
            );
        }
        return sprintf(
            '<table style="width:100%%;border-collapse:collapse;font-family:sans-serif;font-size:14px;"><thead><tr><th style="text-align:left;padding:6px 10px;border-bottom:2px solid #333;">%s</th><th style="padding:6px 10px;border-bottom:2px solid #333;">%s</th><th style="text-align:right;padding:6px 10px;border-bottom:2px solid #333;">%s</th><th style="text-align:right;padding:6px 10px;border-bottom:2px solid #333;">%s</th></tr></thead><tbody>%s</tbody><tfoot><tr><td colspan="3" style="text-align:right;padding:10px;font-weight:bold;">%s</td><td style="text-align:right;padding:10px;font-weight:bold;">%s</td></tr></tfoot></table>',
            esc_html__('Product', 'estimation-tool-for-woocommerce'),
            esc_html__('Qty', 'estimation-tool-for-woocommerce'),
            esc_html__('Price', 'estimation-tool-for-woocommerce'),
            esc_html__('Subtotal', 'estimation-tool-for-woocommerce'),
            $rows,
            esc_html__('Total', 'estimation-tool-for-woocommerce'),
            esc_html(number_format_i18n((float) $total, 2))
        );
    }

    private static function replace_tokens($template, $row, $products) {
        $tokens = array(
            '{{name}}'           => esc_html($row->name),
            '{{email}}'          => esc_html($row->email),
            '{{phone}}'          => esc_html($row->phone),
            '{{total}}'          => esc_html(number_format_i18n((float) $row->total, 2)),
            '{{site}}'           => esc_html(get_bloginfo('name')),
            '{{site_url}}'       => esc_url(home_url('/')),
            '{{products_table}}' => self::products_html_table($products, $row->total),
        );
        $tokens = apply_filters('estitofo_email_tokens', $tokens, $row, $products);
        return strtr((string) $template, $tokens);
    }

    private static function default_subject() {
        return sprintf(
            /* translators: %s: site name */
            __('Your estimation from %s', 'estimation-tool-for-woocommerce'),
            get_bloginfo('name')
        );
    }

    private static function default_body() {
        return "<p>" . esc_html__('Hi {{name}},', 'estimation-tool-for-woocommerce') . "</p>"
             . "<p>" . esc_html__('Thanks for your interest. Your estimation total is {{total}}. Details below:', 'estimation-tool-for-woocommerce') . "</p>"
             . "{{products_table}}"
             . "<p>" . esc_html__('We will be in touch shortly.', 'estimation-tool-for-woocommerce') . "</p>"
             . "<p>{{site}}</p>";
    }

    private static function send_customer_confirmation($row, $clean_products) {
        $subject_tpl = (string) Estitofo_Options::get('email_subject', '');
        $body_tpl    = (string) Estitofo_Options::get('email_body', '');
        if ($subject_tpl === '') $subject_tpl = self::default_subject();
        if ($body_tpl === '')    $body_tpl    = self::default_body();

        $subject = self::replace_tokens($subject_tpl, $row, $clean_products);
        $body    = self::replace_tokens($body_tpl, $row, $clean_products);

        $attachments = array();
        if ((int) Estitofo_Options::get('attach_pdf_email', 1) && class_exists('TCPDF') && class_exists('Estitofo_PDF')) {
            $attachments = self::write_pdf_attachment($row, $clean_products);
        }

        wp_mail($row->email, wp_strip_all_tags($subject), $body, self::from_headers(), $attachments);

        foreach ($attachments as $file) {
            wp_delete_file($file);
        }
    }

    private static function send_admin_notification($row, $clean_products) {
        $recipients = Estitofo_Settings::admin_recipients();
        if (empty($recipients)) {
            return;
        }
        $subject = sprintf(
            /* translators: %s: customer name */
            __('New estimation from %s', 'estimation-tool-for-woocommerce'),
            $row->name
        );
        $admin_url = admin_url('admin.php?page=estimation-data');
        $body  = '<p>' . sprintf(
            /* translators: 1: customer name, 2: customer email, 3: customer phone */
            esc_html__('A new estimation was submitted by %1$s (%2$s, %3$s).', 'estimation-tool-for-woocommerce'),
            esc_html($row->name),
            esc_html($row->email),
            esc_html($row->phone)
        ) . '</p>';
        $body .= self::products_html_table($clean_products, $row->total);
        $body .= '<p><a href="' . esc_url($admin_url) . '">' . esc_html__('View in admin', 'estimation-tool-for-woocommerce') . '</a></p>';

        wp_mail($recipients, $subject, $body, self::from_headers());
    }

    private static function write_pdf_attachment($row, $clean_products) {
        try {
            $generator = new Estitofo_PDF();
            $pdf = $generator->generate($clean_products, (float) $row->total, array(
                'name'  => $row->name,
                'email' => $row->email,
                'phone' => $row->phone,
                'date'  => $row->created_at,
            ));
            $upload   = wp_upload_dir();
            $tmp_dir  = trailingslashit($upload['basedir']) . 'wc-estimation-tmp';
            if (!is_dir($tmp_dir)) {
                wp_mkdir_p($tmp_dir);
                // Drop an index.html to prevent directory listing — via WP_Filesystem.
                if (!function_exists('WP_Filesystem')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                global $wp_filesystem;
                if (WP_Filesystem()) {
                    $wp_filesystem->put_contents($tmp_dir . '/index.html', '');
                }
            }
            $file = $tmp_dir . '/estimation-' . absint($row->id) . '-' . wp_generate_password(8, false) . '.pdf';
            $pdf->Output($file, 'F');
            return array($file);
        } catch (Exception $e) {
            return array();
        }
    }

    public static function send_test($to) {
        if (!is_email($to)) {
            return false;
        }
        $subject = sprintf(
            /* translators: %s: site name */
            __('Estimation Tool test email from %s', 'estimation-tool-for-woocommerce'),
            get_bloginfo('name')
        );
        $body = '<p>' . esc_html__('If you can read this, the Estimation Tool plugin can send email from this site.', 'estimation-tool-for-woocommerce') . '</p>';
        $body .= '<p>' . sprintf(
            /* translators: %s: timestamp */
            esc_html__('Sent at %s.', 'estimation-tool-for-woocommerce'),
            esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format')))
        ) . '</p>';
        return (bool) wp_mail($to, $subject, $body, self::from_headers());
    }
}

Estitofo_Mailer::init();
