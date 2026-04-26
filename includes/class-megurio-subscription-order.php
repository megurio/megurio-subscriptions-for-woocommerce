<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Megurio_Subscription_Order' ) ) {
	/**
	 * 定期購入レコードを WooCommerce の注文として扱うための薄いラッパーです。
	 */
	class Megurio_Subscription_Order extends WC_Order {
		/**
		 * 内部タイプ名を返します。
		 *
		 * @return string
		 */
		public function get_type() {
			return 'megurio_subscription';
		}
	}
}
