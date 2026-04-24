<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TSFW_Subscription_Order' ) ) {
	/**
	 * 定期購入レコードを WooCommerce の注文として扱うための薄いラッパーです。
	 */
	class TSFW_Subscription_Order extends WC_Order {
		/**
		 * 内部タイプ名を返します。
		 *
		 * @return string
		 */
		public function get_type() {
			return 'tsfw_subscription';
		}
	}
}
