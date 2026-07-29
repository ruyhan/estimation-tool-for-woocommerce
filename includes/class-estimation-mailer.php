<?php
/**
 * Email dispatcher.
 *
 * @package quotely-estimates-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estitofo_Mailer {

	public static function init() {
		add_action( 'estitofo_after_submit', array( __CLASS__, 'on_submit' ), 10, 2 );
	}

	/**
	 * Triggered after a submission is saved.
	 *
	 * @param int   $id            Submission ID.
	 * @param array $clean_products Sanitized product list.
	 */
	public static function on_submit( $id, $clean_products ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}estimation_submissions WHERE id = %d", $id ) );

		if ( ! $row ) {
			return;
		}

		if ( (int) Estitofo_Options::get( 'admin_notify', 1 ) ) {
			self::send_admin_notification( $row, $clean_products );
		}
		if ( (int) Estitofo_Options::get( 'customer_notify', 1 ) ) {
			self::send_customer_confirmation( $row, $clean_products );
		}
	}

	private static function from_headers() {
		$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_name = (string) Estitofo_Options::get( 'from_name', '' );
		$from_mail = (string) Estitofo_Options::get( 'from_email', '' );
		if ( '' === $from_name ) {
			$from_name = get_bloginfo( 'name' );
		}
		if ( ! is_email( $from_mail ) ) {
			$from_mail = get_option( 'admin_email' );
		}
		$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_mail );
		return $headers;
	}

	/**
	 * Format a money amount for e-mail as plain text.
	 *
	 * Uses wc_price() so the store's currency symbol, position and decimal
	 * settings are respected, then strips the markup and decodes entities —
	 * amounts used to be printed as bare numbers ("150.00"), which made a
	 * quote e-mail ambiguous about its currency.
	 *
	 * @param float $amount
	 * @return string
	 */
	public static function money( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			$out = html_entity_decode(
				wp_strip_all_tags( wc_price( (float) $amount ) ),
				ENT_QUOTES | ENT_HTML5,
				'UTF-8'
			);
			// wc_price() ends with &nbsp; which decodes to U+00A0 — plain trim()
			// leaves it, producing "18,094.00৳ ." in sentences. Strip it too.
			return trim( $out, " \t\n\r\0\x0B\xC2\xA0" );
		}
		return number_format_i18n( (float) $amount, 2 );
	}

	/**
	 * The business name to show in e-mails: the configured PDF company name
	 * when set, otherwise the WordPress site title. Keeps e-mail branding
	 * consistent with the PDFs instead of showing the raw site name.
	 *
	 * @return string
	 */
	public static function business_name() {
		$company = (string) Estitofo_Options::get( 'company_name', '' );
		return '' !== trim( $company ) ? $company : (string) get_bloginfo( 'name' );
	}

	/**
	 * The brand colour used across every e-mail, taken from the plugin's own
	 * appearance settings so mail matches the PDFs and the site.
	 *
	 * Public because the Pro template pack builds on the same palette.
	 */
	public static function brand_color() {
		$hex = trim( (string) Estitofo_Options::get( 'primary_color', '' ) );
		if ( '' === $hex || ! preg_match( '/^#[0-9a-f]{3,6}$/i', $hex ) ) {
			$hex = '#4f46e5';
		}
		return $hex;
	}

	/** Shared font stack. Modern faces first, with the classic web-safe fallbacks. */
	public static function font_stack() {
		return "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";
	}

	/**
	 * Line-item table.
	 *
	 * Deliberately mirrors the PDF: letterspaced small-capital column labels, a
	 * brand hairline under them, hairline row rules and a single emphasised
	 * total. Built with a real <table> and inline styles because that is the
	 * only layout mail clients render consistently (Outlook in particular
	 * ignores most modern CSS).
	 */
	private static function products_html_table( $products, $total ) {
		$brand = self::brand_color();
		$font  = self::font_stack();
		// One light rule under the column labels and one brand rule above the
		// total — nothing else. Row borders are a very low-contrast grey so the
		// table reads as aligned columns rather than a grid of lines.
		$th    = 'font-size:10px;font-weight:700;letter-spacing:1.3px;text-transform:uppercase;color:#9aa0ae;padding:0 0 9px;border-bottom:1px solid #e6e9f0;';
		$td    = 'padding:12px 0;border-bottom:1px solid #f2f4f8;color:#3d4351;font-size:14px;';

		$rows = '';
		foreach ( (array) $products as $p ) {
			$title    = isset( $p['title'] ) ? esc_html( $p['title'] ) : '';
			$price    = isset( $p['price'] ) ? (float) $p['price'] : 0;
			$qty      = isset( $p['quantity'] ) ? max( 1, absint( $p['quantity'] ) ) : 1;
			$subtotal = $price * $qty;
			$note     = isset( $p['note'] ) ? trim( (string) $p['note'] ) : '';
			$rows    .= '<tr>'
				. '<td style="' . $td . 'font-weight:600;color:#1f2430;">' . $title
				. ( '' !== $note ? '<div style="font-weight:400;font-size:12px;color:#8b91a1;margin-top:3px;">' . esc_html( $note ) . '</div>' : '' )
				. '</td>'
				. '<td style="' . $td . 'text-align:center;white-space:nowrap;">' . (int) $qty . '</td>'
				. '<td style="' . $td . 'text-align:right;white-space:nowrap;">' . esc_html( self::money( $price ) ) . '</td>'
				. '<td style="' . $td . 'text-align:right;white-space:nowrap;font-weight:600;color:#1f2430;">' . esc_html( self::money( $subtotal ) ) . '</td>'
				. '</tr>';
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="width:100%;border-collapse:collapse;font-family:' . $font . ';">'
			. '<thead><tr>'
			. '<th style="text-align:left;' . $th . '">' . esc_html__( 'Item', 'quotely-estimates-for-woocommerce' ) . '</th>'
			. '<th style="text-align:center;' . $th . '">' . esc_html__( 'Qty', 'quotely-estimates-for-woocommerce' ) . '</th>'
			. '<th style="text-align:right;' . $th . '">' . esc_html__( 'Rate', 'quotely-estimates-for-woocommerce' ) . '</th>'
			. '<th style="text-align:right;' . $th . '">' . esc_html__( 'Amount', 'quotely-estimates-for-woocommerce' ) . '</th>'
			. '</tr></thead>'
			. '<tbody>' . $rows . '</tbody>'
			// The rule is its own full-width row. Putting border-top on only the
			// last two cells drew a part-width line that collided with the row
			// above it and read as a rendering fault.
			. '<tfoot>'
			. '<tr><td colspan="4" style="padding:0;height:14px;line-height:14px;font-size:0;">&nbsp;</td></tr>'
			. '<tr><td colspan="4" style="padding:0;height:1px;line-height:1px;font-size:0;background:' . $brand . ';">&nbsp;</td></tr>'
			. '<tr>'
			. '<td colspan="2" style="padding:13px 0 0;"></td>'
			. '<td style="padding:13px 0 0;text-align:right;font-size:10px;font-weight:700;letter-spacing:1.3px;text-transform:uppercase;color:#9aa0ae;">'
			. esc_html__( 'Total', 'quotely-estimates-for-woocommerce' ) . '</td>'
			. '<td style="padding:13px 0 0;text-align:right;font-size:18px;font-weight:700;color:' . $brand . ';white-space:nowrap;">'
			. esc_html( self::money( (float) $total ) ) . '</td>'
			. '</tr></tfoot></table>';
	}

	/**
	 * AJAX: render the customer e-mail body with sample data for the preview.
	 *
	 * Renders whatever is currently in the editor (unsaved included) so the
	 * button reflects what you are looking at, falling back to the saved value
	 * and then the default.
	 */
	public static function ajax_preview() {
		check_ajax_referer( 'estitofo_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$body = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';
		if ( '' === trim( $body ) ) {
			$body = (string) Estitofo_Options::get( 'email_body', '' );
		}
		if ( '' === trim( $body ) ) {
			$body = self::default_body();
		}

		$sample = array(
			array(
				'title'    => __( 'Custom oak dining table — 8 seats', 'quotely-estimates-for-woocommerce' ),
				'sku'      => 'OAK-TBL-08',
				'price'    => 1249.00,
				'quantity' => 1,
				'note'     => __( 'Includes white-glove delivery', 'quotely-estimates-for-woocommerce' ),
			),
			array( 'title' => __( 'Upholstered dining chair', 'quotely-estimates-for-woocommerce' ), 'sku' => 'CHR-UPH-01', 'price' => 189.50, 'quantity' => 8 ),
			array( 'title' => __( 'Assembly & installation', 'quotely-estimates-for-woocommerce' ), 'sku' => 'SVC-ASM-01', 'price' => 150.00, 'quantity' => 1 ),
		);
		$total = 0.0;
		foreach ( $sample as $s ) {
			$total += (float) $s['price'] * (int) $s['quantity'];
		}

		// replace_tokens() expects a submission row object.
		$row = (object) array(
			'name'  => __( 'Jane Sample', 'quotely-estimates-for-woocommerce' ),
			'email' => 'jane@example.com',
			'phone' => '+1 555 0100',
			'total' => $total,
		);

		wp_send_json_success( array( 'html' => self::replace_tokens( $body, $row, $sample ) ) );
	}

	private static function replace_tokens( $template, $row, $products ) {
		$tokens = array(
			'{{name}}'           => esc_html( $row->name ),
			'{{email}}'          => esc_html( $row->email ),
			'{{phone}}'          => esc_html( $row->phone ),
			'{{total}}'          => esc_html( self::money( (float) $row->total ) ),
			// {{site}} resolves to the configured company name when there is one
			// so e-mails match the PDFs; {{company}} is the explicit alias.
			'{{site}}'           => esc_html( self::business_name() ),
			'{{company}}'        => esc_html( self::business_name() ),
			'{{site_title}}'     => esc_html( get_bloginfo( 'name' ) ),
			'{{site_url}}'       => esc_url( home_url( '/' ) ),
			// Postal address for the mail footer. Commercial email is expected to
			// carry one, and hard-coding it into a template would go stale the
			// moment the business moves.
			'{{company_address}}' => esc_html( (string) Estitofo_Options::get( 'company_address', '' ) ),
			'{{products_table}}' => self::products_html_table( $products, $row->total ),
		);
		$tokens = apply_filters( 'estitofo_email_tokens', $tokens, $row, $products );
		return strtr( (string) $template, $tokens );
	}

	private static function default_subject() {
		return sprintf(
			/* translators: %s: site name */
			__( 'Your estimation from %s', 'quotely-estimates-for-woocommerce' ),
			get_bloginfo( 'name' )
		);
	}

	/**
	 * Default customer e-mail.
	 *
	 * A full branded card rather than a handful of bare <p> tags — this is what
	 * a free-plugin user's customers actually receive, so it has to look
	 * finished out of the box. Table-based and inline-styled for mail clients.
	 */
	public static function default_body() {
		$brand = self::brand_color();
		$font  = self::font_stack();

		return '<div style="background:#f2f4f8;padding:28px 12px;font-family:' . $font . ';">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"'
			. ' style="width:600px;max-width:600px;border-collapse:collapse;background:#ffffff;border:1px solid #e4e8f0;border-radius:12px;overflow:hidden;">'

			// header
			. '<tr><td style="padding:26px 32px 0;">'
			. '<div style="font-size:17px;font-weight:700;color:#1f2430;letter-spacing:-0.2px;">{{site}}</div>'
			. '<div style="font-size:10px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:' . $brand . ';margin-top:6px;">'
			. esc_html__( 'Your estimate', 'quotely-estimates-for-woocommerce' ) . '</div>'
			. '</td></tr>'
			. '<tr><td style="padding:16px 32px 0;"><div style="height:1px;background:' . $brand . ';line-height:1px;font-size:0;">&nbsp;</div></td></tr>'

			// body
			. '<tr><td style="padding:22px 32px 0;">'
			. '<p style="margin:0 0 12px;font-size:15px;color:#1f2430;">' . esc_html__( 'Hi {{name}},', 'quotely-estimates-for-woocommerce' ) . '</p>'
			. '<p style="margin:0 0 22px;font-size:14px;line-height:1.65;color:#5a6070;">'
			. esc_html__( 'Thank you for your request. Your estimate comes to {{total}} — the full breakdown is below.', 'quotely-estimates-for-woocommerce' )
			. '</p>'
			. '</td></tr>'
			. '<tr><td style="padding:0 32px;">{{products_table}}</td></tr>'
			. '<tr><td style="padding:22px 32px 26px;">'
			. '<p style="margin:0;font-size:14px;line-height:1.65;color:#5a6070;">'
			. esc_html__( 'We will review the details and be in touch shortly. If anything needs changing, just reply to this email.', 'quotely-estimates-for-woocommerce' )
			. '</p>'
			. '</td></tr>'

			// footer
			. '<tr><td style="padding:16px 32px;background:#f8f9fc;border-top:1px solid #edf0f5;">'
			. '<p style="margin:0;font-size:12px;color:#8b91a1;">{{site}} &middot; '
			. '<a href="{{site_url}}" style="color:' . $brand . ';text-decoration:none;">{{site_url}}</a></p>'
			. '</td></tr>'

			. '</table></td></tr></table></div>';
	}

	private static function send_customer_confirmation( $row, $clean_products ) {
		$subject_tpl = (string) Estitofo_Options::get( 'email_subject', '' );
		$body_tpl    = (string) Estitofo_Options::get( 'email_body', '' );
		if ( '' === $subject_tpl ) {
			$subject_tpl = self::default_subject();
		}
		if ( '' === $body_tpl ) {
			$body_tpl = self::default_body();
		}

		$subject = self::replace_tokens( $subject_tpl, $row, $clean_products );
		$body    = self::replace_tokens( $body_tpl, $row, $clean_products );

		$attachments = array();
		if ( (int) Estitofo_Options::get( 'attach_pdf_email', 1 ) && class_exists( 'Estitofo_TCPDF' ) && class_exists( 'Estitofo_PDF' ) ) {
			$attachments = self::write_pdf_attachment( $row, $clean_products );
		}

		wp_mail( $row->email, wp_strip_all_tags( $subject ), $body, self::from_headers(), $attachments );

		foreach ( $attachments as $file ) {
			wp_delete_file( $file );
		}
	}

	private static function send_admin_notification( $row, $clean_products ) {
		$recipients = Estitofo_Settings::admin_recipients();
		if ( empty( $recipients ) ) {
			return;
		}
		$subject = sprintf(
			/* translators: %s: customer name */
			__( 'New estimation from %s', 'quotely-estimates-for-woocommerce' ),
			$row->name
		);
		$admin_url = admin_url( 'admin.php?page=estimation-data' );
		$body      = '<p>' . sprintf(
			/* translators: 1: customer name, 2: customer email, 3: customer phone */
			esc_html__( 'A new estimation was submitted by %1$s (%2$s, %3$s).', 'quotely-estimates-for-woocommerce' ),
			esc_html( $row->name ),
			esc_html( $row->email ),
			esc_html( $row->phone )
		) . '</p>';
		$body .= self::products_html_table( $clean_products, $row->total );
		$body .= '<p><a href="' . esc_url( $admin_url ) . '">' . esc_html__( 'View in admin', 'quotely-estimates-for-woocommerce' ) . '</a></p>';

		wp_mail( $recipients, $subject, $body, self::from_headers() );
	}

	private static function write_pdf_attachment( $row, $clean_products ) {
		try {
			$generator = new Estitofo_PDF();
			$pdf       = $generator->generate(
				$clean_products,
				(float) $row->total,
				array(
					// 'id' lets add-ons resolve per-submission extension meta, so the
					// emailed PDF carries the same expiry date as the downloaded one.
					'id'      => (int) $row->id,
					'name'    => $row->name,
					'email'   => $row->email,
					'phone'   => $row->phone,
					'date'    => $row->created_at,
					'company' => $row->company ?? '',
					'address' => $row->address ?? '',
					'notes'   => $row->customer_notes ?? '',
				)
			);
			$upload    = wp_upload_dir();
			$tmp_dir   = trailingslashit( $upload['basedir'] ) . 'wc-estimation-tmp';
			if ( ! is_dir( $tmp_dir ) ) {
				wp_mkdir_p( $tmp_dir );
				// Drop an index.html to prevent directory listing — via WP_Filesystem.
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				global $wp_filesystem;
				if ( WP_Filesystem() ) {
					$wp_filesystem->put_contents( $tmp_dir . '/index.html', '' );
				}
			}
			$file = $tmp_dir . '/estimation-' . absint( $row->id ) . '-' . wp_generate_password( 8, false ) . '.pdf';
			$pdf->Output( $file, 'F' );
			return array( $file );
		} catch ( Exception $e ) {
			return array();
		}
	}

	public static function send_test( $to ) {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Estimation Tool test email from %s', 'quotely-estimates-for-woocommerce' ),
			get_bloginfo( 'name' )
		);
		$body  = '<p>' . esc_html__( 'If you can read this, the Estimation Tool plugin can send email from this site.', 'quotely-estimates-for-woocommerce' ) . '</p>';
		$body .= '<p>' . sprintf(
			/* translators: %s: timestamp */
			esc_html__( 'Sent at %s.', 'quotely-estimates-for-woocommerce' ),
			esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
		) . '</p>';
		return (bool) wp_mail( $to, $subject, $body, self::from_headers() );
	}
}

Estitofo_Mailer::init();
