<?php
/**
 * Plugin Name: Megurio 定期購入(サブスク) For WooCommerce（日本向け）
 * Description: WooCommerceで定期購入（サブスクリプション）商品を簡単に管理できるプラグインです。定期的な自動決済、注文の自動生成、継続課金の管理、プラン設定など、サブスク運営に必要な機能をまとめて提供します。
 * Version: 0.2.1
 * Author: megurio
 * Text Domain: megurio-subscriptions-for-woocommerce
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
function tsfw_load_plugin_files() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-tsfw-subscription-order.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-megurio-subscriptions-for-woocommerce.php';
}

/**
 * WooCommerce の読込後に定期購入プラグインを初期化します。
 *
 * @return void
 */
function tsfw_bootstrap_plugin() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Order' ) ) {
		return;
	}

	tsfw_load_plugin_files();

	if ( class_exists( 'Test_Subscription_For_Woocommerce' ) ) {
		new Test_Subscription_For_Woocommerce();
	}
}
add_action( 'plugins_loaded', 'tsfw_bootstrap_plugin', 20 );

/**
 * プラグイン有効化時の処理です。
 *
 * @return void
 */
function tsfw_activate_plugin() {
	add_rewrite_endpoint( 'tsfw-subscriptions', EP_ROOT | EP_PAGES );
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'tsfw_activate_plugin' );

/**
 * プラグイン停止時の処理です。
 *
 * @return void
 */
function tsfw_deactivate_plugin() {
	if ( class_exists( 'ActionScheduler' ) && function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'tsfw_create_renewal_orders' );
		as_unschedule_all_actions( 'tsfw_expire_subscriptions' );
	}

	wp_clear_scheduled_hook( 'tsfw_create_renewal_orders' );
	wp_clear_scheduled_hook( 'tsfw_expire_subscriptions' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'tsfw_deactivate_plugin' );
