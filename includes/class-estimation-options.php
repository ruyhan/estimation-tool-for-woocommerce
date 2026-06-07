<?php
/**
 * Centralized options helper.
 *
 * All plugin settings live under a single `estitofo_settings` option
 * (associative array). Migrated from v2.1 individual options once.
 *
 * @package estimation-tool-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estitofo_Options {

	const OPTION_NAME    = 'estitofo_settings';
	const MIGRATION_FLAG = 'estitofo_options_migrated';

	/**
	 * Default values for every supported key.
	 *
	 * Pro can extend via the `estitofo_default_settings` filter.
	 */
	public static function defaults() {
		$defaults = array(
			// General.
			'heading'               => '',
			'subheading'            => '',
			'default_country'       => '',
			'restrict_country'      => '',
			'primary_color'         => '#2563eb',
			'accent_color'          => '#1e40af',

			// PDF branding.
			'company_name'          => '',
			'company_tagline'       => '',
			'logo_url'              => '',
			'logo_id'               => 0,
			'footer_text'           => '',
			'locations'             => '',
			'pdf_author'            => '',

			// Form.
			'collect_company'       => 0,
			'collect_address'       => 0,
			'collect_notes'         => 1,
			'submit_button_text'    => '',
			'success_message'       => '',

			// Notifications.
			'admin_notify'          => 1,
			'admin_recipients'      => '',
			'customer_notify'       => 1,
			'attach_pdf_email'      => 1,
			'from_name'             => '',
			'from_email'            => '',
			'email_subject'         => '',
			'email_body'            => '',

			// Save & resume.
			'enable_save_resume'    => 1,
			'resume_link_ttl'       => 7, // days.

			// Dashboard.
			'show_dashboard_widget' => 1,
		);
		return apply_filters( 'estitofo_default_settings', $defaults );
	}

	/**
	 * Read the full settings array (merged with defaults).
	 */
	public static function all() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Read a single key.
	 *
	 * @param string $key
	 * @param mixed  $fallback
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $fallback;
	}

	/**
	 * Set a single key (writes the whole array).
	 */
	public static function set( $key, $value ) {
		$all         = self::all();
		$all[ $key ] = $value;
		update_option( self::OPTION_NAME, $all );
	}

	/**
	 * Replace the whole settings array (after sanitization).
	 */
	public static function update_many( array $values ) {
		$merged = wp_parse_args( $values, self::all() );
		update_option( self::OPTION_NAME, $merged );
	}

	/**
	 * One-time migration from v2.1 individual options to the single array.
	 */
	public static function maybe_migrate() {
		if ( get_option( self::MIGRATION_FLAG ) ) {
			return;
		}
		$legacy_keys = array(
			'company_name'     => 'estitofo_company_name',
			'company_tagline'  => 'estitofo_company_tagline',
			'logo_url'         => 'estitofo_logo_url',
			'footer_text'      => 'estitofo_footer_text',
			'locations'        => 'estitofo_locations',
			'heading'          => 'estitofo_heading',
			'default_country'  => 'estitofo_default_country',
			'restrict_country' => 'estitofo_restrict_country',
			'pdf_author'       => 'estitofo_pdf_author',
		);
		$migrated    = self::all();
		foreach ( $legacy_keys as $new => $old ) {
			$value = get_option( $old, null );
			if ( null !== $value && '' !== $value ) {
				$migrated[ $new ] = $value;
			}
		}
		update_option( self::OPTION_NAME, $migrated );
		update_option( self::MIGRATION_FLAG, 1 );
	}
}
