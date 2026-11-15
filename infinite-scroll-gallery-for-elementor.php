<?php
/**
 * Plugin Name:       Infinite Scroll Gallery for Elementor
 * Description:       Endless parallax gallery with GSAP Flip image-to-content transitions, video slides, WooCommerce add-to-cart and category filtering. Based on the Codrops "Infinite Scroll Gallery and Flip Transitions in GSAP" demo (MIT).
 * Version:           1.6.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  elementor
 * Author:            IFG
 * License:           GPL-2.0-or-later
 * Text Domain:       ifg
 * Domain Path:       /languages
 * Elementor tested up to: 4.3.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFG_VERSION', '1.6.0' );
define( 'IFG_MIN_ELEMENTOR', '3.5.0' );
define( 'IFG_URL', plugin_dir_url( __FILE__ ) );
define( 'IFG_PATH', plugin_dir_path( __FILE__ ) );

final class IFG_Plugin {

	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	public function init() {
		load_plugin_textdomain( 'ifg', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! did_action( 'elementor/loaded' ) ) {
			add_action( 'admin_notices', [ $this, 'missing_elementor_notice' ] );
			return;
		}

		if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, IFG_MIN_ELEMENTOR, '<' ) ) {
			add_action( 'admin_notices', [ $this, 'old_elementor_notice' ] );
			return;
		}

		add_action( 'elementor/frontend/after_register_scripts', [ $this, 'register_scripts' ] );
		add_action( 'elementor/frontend/after_register_styles', [ $this, 'register_styles' ] );
		add_action( 'elementor/elements/categories_registered', [ $this, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );
		add_action( 'init', [ $this, 'maybe_migrate_display_mode' ], 20 );

		// Frontend category filter (works for logged-in visitors and guests).
		add_action( 'wp_ajax_ifg_filter', [ $this, 'ajax_filter' ] );
		add_action( 'wp_ajax_nopriv_ifg_filter', [ $this, 'ajax_filter' ] );
	}

	/**
	 * AJAX: render the slides of one Infinite Scroll Gallery widget for one product category.
	 *
	 * Read-only and public (it returns what the page already shows), so there is deliberately no nonce:
	 * a nonce baked into a page-cached HTML file would expire and break the filter for visitors.
	 * The widget is looked up from the saved Elementor data of the page, so the browser never sends settings.
	 */
	public function ajax_filter() {
		require_once IFG_PATH . 'includes/class-gallery-widget.php';

		// phpcs:disable WordPress.Security.NonceVerification
		$post_id = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;
		$widget  = isset( $_POST['widget'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', wp_unslash( $_POST['widget'] ) ) : '';
		$term    = isset( $_POST['term'] ) ? absint( wp_unslash( $_POST['term'] ) ) : 0;
		$current = isset( $_POST['current'] ) ? absint( wp_unslash( $_POST['current'] ) ) : 0;
		// phpcs:enable

		if ( ! $post_id || '' === $widget ) {
			wp_send_json_error( [ 'message' => 'Bad request.' ], 400 );
		}

		$payload = \IFG\Gallery_Widget::ajax_payload( $post_id, $widget, $term, $current );
		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( [ 'message' => $payload->get_error_message() ], 404 );
		}

		wp_send_json_success( $payload );
	}

	public function missing_elementor_notice() {
		echo '<div class="notice notice-warning"><p>' .
			esc_html__( 'افزونه «گالری اسکرول بی‌نهایت برای المنتور» به نصب و فعال بودن افزونه المنتور نیاز دارد.', 'ifg' ) .
			'</p></div>';
	}

	public function old_elementor_notice() {
		echo '<div class="notice notice-warning"><p>' .
			sprintf(
				/* translators: %s: minimum Elementor version */
				esc_html__( 'افزونه «گالری اسکرول بی‌نهایت برای المنتور» به المنتور نسخه %s یا بالاتر نیاز دارد. لطفاً المنتور را به‌روزرسانی کنید.', 'ifg' ),
				esc_html( IFG_MIN_ELEMENTOR )
			) .
			'</p></div>';
	}

	public function register_scripts() {
		$v = IFG_VERSION;
		$base = IFG_URL . 'assets/js/';

		wp_register_script( 'ifg-gsap', $base . 'vendor/gsap.min.js', [], '3.13.0', true );
		// This plugin ships its own GSAP. Remember any GSAP the theme / another plugin loaded earlier so ifg.js can
		// hand it back afterwards and never override the globals other scripts depend on.
		wp_add_inline_script(
			'ifg-gsap',
			'window.__ifgPrev={gsap:window.gsap,Flip:window.Flip,SplitText:window.SplitText,Observer:window.Observer};',
			'before'
		);
		wp_register_script( 'ifg-flip', $base . 'vendor/Flip.min.js', [ 'ifg-gsap' ], '3.13.0', true );
		wp_register_script( 'ifg-splittext', $base . 'vendor/SplitText.min.js', [ 'ifg-gsap' ], '3.13.0', true );
		wp_register_script( 'ifg-observer', $base . 'vendor/Observer.min.js', [ 'ifg-gsap' ], '3.13.0', true );
		wp_register_script(
			'ifg-script',
			$base . 'ifg.js',
			[ 'ifg-gsap', 'ifg-flip', 'ifg-splittext', 'ifg-observer' ],
			$v,
			true
		);
	}

	public function register_styles() {
		wp_register_style( 'ifg-style', IFG_URL . 'assets/css/ifg.css', [], IFG_VERSION );
	}

	public function register_category( $elements_manager ) {
		$elements_manager->add_category( 'ifg', [
			'title' => esc_html__( 'گالری بی‌نهایت', 'ifg' ),
			'icon'  => 'eicon-gallery-justified',
		] );
	}

	public function maybe_migrate_display_mode() {
		if ( get_option( 'ifg_display_mode_migrated' ) ) {
			return;
		}
		require_once IFG_PATH . 'includes/class-gallery-widget.php';
		\IFG\Gallery_Widget::migrate_display_mode();
	}

	public function register_widgets( $widgets_manager ) {
		require_once IFG_PATH . 'includes/class-gallery-widget.php';
		$widgets_manager->register( new \IFG\Gallery_Widget() );
	}
}

new IFG_Plugin();