<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

if ( ! class_exists( 'Estitofo_Elementor_Widget' ) && class_exists( 'Elementor\Widget_Base' ) ) {
	class Estitofo_Elementor_Widget extends Widget_Base {

		public function get_name() {
			return 'estitofo_form';
		}

		public function get_title() {
			return esc_html__( 'Estimation Tool', 'estimation-tool-for-woocommerce' );
		}

		public function get_icon() {
			return 'eicon-woocommerce';
		}

		public function get_categories() {
			return array( 'wc-estimation', 'general' );
		}

		public function get_keywords() {
			return array( 'woocommerce', 'estimation', 'pdf', 'quote', 'calculator' );
		}

		protected function register_controls() {
			$this->start_controls_section(
				'section_content',
				array(
					'label' => esc_html__( 'Content', 'estimation-tool-for-woocommerce' ),
				)
			);

			$default_heading    = '';
			$default_subheading = '';
			if ( class_exists( 'Estitofo_Options' ) ) {
				$default_heading    = (string) Estitofo_Options::get( 'heading', '' );
				$default_subheading = (string) Estitofo_Options::get( 'subheading', '' );
			}

			$this->add_control(
				'heading',
				array(
					'label'   => esc_html__( 'Heading', 'estimation-tool-for-woocommerce' ),
					'type'    => Controls_Manager::TEXT,
					'default' => '' !== $default_heading ? $default_heading : esc_html__( 'My plan and price estimation', 'estimation-tool-for-woocommerce' ),
				)
			);

			$this->add_control(
				'subheading',
				array(
					'label'       => esc_html__( 'Subheading', 'estimation-tool-for-woocommerce' ),
					'type'        => Controls_Manager::TEXTAREA,
					'rows'        => 2,
					'default'     => $default_subheading,
					'description' => esc_html__( 'Leave blank to hide the subheading.', 'estimation-tool-for-woocommerce' ),
				)
			);

			$this->end_controls_section();
		}

		protected function render() {
			$settings = $this->get_settings_for_display();
			$output   = Estitofo_Plugin::instance()->render_estimation_page(
				array(
					'heading'    => isset( $settings['heading'] ) ? $settings['heading'] : '',
					'subheading' => isset( $settings['subheading'] ) ? $settings['subheading'] : '',
				)
			);
			// Output is already escaped within render_estimation_page() using esc_html_e / esc_attr_e and esc_html.
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
