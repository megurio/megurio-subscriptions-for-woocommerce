<?php
/**
 * Plugin Name: Megurio 定期購入 for WooCommerce
 * Description: WooCommerce で定期購入（サブスクリプション）商品を簡単に管理できるプラグインです。Stripe / WooCommerce Payments によるカード自動課金、更新注文の自動生成、請求管理、プラン設定に対応しています。
 * Version: 0.9.1
 * Author: megurio
 * Text Domain: megurio-subscriptions-for-woocommerce
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 必要なクラスを読み込みます。
 *
 * @return void
 */
function megurio_load_plugin_files() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-megurio-subscription-order.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-megurio-payment-gateway-integration.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-megurio-subscriptions-for-woocommerce.php';
}

/**
 * WooCommerce の読込後に定期購入プラグインを初期化します。
 *
 * @return void
 */
function megurio_bootstrap_plugin() {
	load_plugin_textdomain( 'megurio-subscriptions-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Order' ) ) {
		return;
	}

	megurio_load_plugin_files();

	if ( class_exists( 'Megurio_Subscriptions_For_Woocommerce' ) ) {
		new Megurio_Subscriptions_For_Woocommerce();
	}
}
add_action( 'plugins_loaded', 'megurio_bootstrap_plugin', 20 );

/**
 * WooCommerce HPOS（カスタム注文テーブル）との互換性を宣言します。
 * WooCommerce 7.1 以降のバージョンで互換性警告を抑制します。
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * プラグイン有効化時の処理です。
 *
 * @return void
 */
function megurio_activate_plugin() {
	add_rewrite_endpoint( 'megurio-subscriptions', EP_ROOT | EP_PAGES );
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'megurio_activate_plugin' );

/**
 * プラグイン停止時の処理です。
 *
 * @return void
 */
function megurio_deactivate_plugin() {
	if ( class_exists( 'ActionScheduler' ) && function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'megurio_create_renewal_orders' );
		as_unschedule_all_actions( 'megurio_retry_renewal_payment' );
	}

	wp_clear_scheduled_hook( 'megurio_create_renewal_orders' );
	wp_clear_scheduled_hook( 'megurio_retry_renewal_payment' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'megurio_deactivate_plugin' );
