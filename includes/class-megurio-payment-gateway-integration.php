<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ゲートウェイ連携：更新注文の自動決済を処理します。
 *
 * 現在サポート済み:
 *   - WooCommerce Payments (woocommerce_payments) — WCPay カード決済（Stripe バックエンド）
 *   - Stripe for WooCommerce (stripe)             — Stripe 公式プラグイン カード決済
 *
 * == 自動課金の共通動作要件 ==
 * 各ゲートウェイは `woocommerce_scheduled_subscription_payment_{gateway_id}` アクションを
 * リッスンして、顧客の保存済みカード（Stripe Customer ID）を使ってオフセッション課金します。
 *
 * - WooCommerce Payments : ユーザーメタ `_wcpay_customer_id_*` を参照
 * - Stripe for WooCommerce: ユーザーメタ `_stripe_customer_id` を参照
 *
 * == Stripe UPE（Universal Payment Element）に関する注意 ==
 * Stripe を UPE モードで使用する場合、ゲートウェイ ID は `stripe` のままですが
 * iDEAL・SEPA・Klarna など複数の支払い方法が表示されます。
 * このプラグイン側でカードのみに絞ります。
 */
if ( ! class_exists( 'Megurio_Payment_Gateway_Integration' ) ) {
	class Megurio_Payment_Gateway_Integration {

		/**
		 * 自動課金に対応しているゲートウェイ ID の一覧。
		 * 定期購入ではこの一覧に載っているカード決済のみ許可します。
		 */
		const AUTO_CHARGE_GATEWAYS = array(
			'woocommerce_payments', // WooCommerce Payments（WCPay） — 公式カード決済プラグイン
			'stripe',               // Stripe for WooCommerce — カードのみ
		);

		/**
		 * 指定のゲートウェイが自動課金をサポートしているか返します。
		 *
		 * @param string $gateway_id ゲートウェイ ID。
		 * @return bool
		 */
		public function is_auto_charge_gateway( $gateway_id ) {
			return in_array( $gateway_id, self::AUTO_CHARGE_GATEWAYS, true );
		}

		/**
		 * 初回注文の支払い確認後、将来の更新課金に使う WC 決済トークンを
		 * 定期購入レコードに保存します。
		 *
		 * 注文に紐づくトークン → 顧客のデフォルトトークンの順でフォールバックします。
		 *
		 * @param int      $subscription_id 定期購入レコード ID。
		 * @param WC_Order $order           支払い済みの親注文。
		 * @return void
		 */
		public function save_payment_token_from_order( $subscription_id, WC_Order $order ) {
			if ( ! $this->is_auto_charge_gateway( $order->get_payment_method() ) ) {
				return;
			}

			// 注文に直接紐づくトークンを優先して使用する。
			$tokens = WC_Payment_Tokens::get_order_tokens( $order->get_id() );
			if ( ! empty( $tokens ) ) {
				$token = reset( $tokens );
				$this->update_subscription_meta( $subscription_id, '_megurio_payment_token_id', $token->get_id() );
				return;
			}

			// フォールバック: 顧客の当該ゲートウェイのデフォルトトークンを使用する。
			$customer_id = $order->get_customer_id();
			if ( ! $customer_id ) {
				return;
			}

			$customer_tokens = WC_Payment_Tokens::get_customer_tokens( $customer_id, $order->get_payment_method() );
			if ( ! empty( $customer_tokens ) ) {
				$token = reset( $customer_tokens );
				$this->update_subscription_meta( $subscription_id, '_megurio_payment_token_id', $token->get_id() );
			}
		}

		/**
		 * HPOS 対応：定期購入レコードのメタを保存します。
		 * wc_get_order() が返す場合は WC Order API 経由、それ以外は post_meta にフォールバックします。
		 *
		 * @param int    $subscription_id 定期購入 ID。
		 * @param string $meta_key        メタキー。
		 * @param mixed  $meta_value      メタ値。
		 * @return void
		 */
		protected function update_subscription_meta( $subscription_id, $meta_key, $meta_value ) {
			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->update_meta_data( $meta_key, $meta_value );
				$subscription->save();
				return;
			}
			update_post_meta( $subscription_id, $meta_key, $meta_value );
		}

		/**
		 * HPOS 対応：定期購入レコードのメタを取得します。
		 * wc_get_order() が返す場合は WC Order API 経由、それ以外は post_meta にフォールバックします。
		 *
		 * @param int    $subscription_id 定期購入 ID。
		 * @param string $meta_key        メタキー。
		 * @return mixed
		 */
		protected function get_subscription_meta( $subscription_id, $meta_key ) {
			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				return $subscription->get_meta( $meta_key, true );
			}
			return get_post_meta( $subscription_id, $meta_key, true );
		}

		/**
		 * 更新注文の自動課金を試みます。
		 *
		 * `woocommerce_scheduled_subscription_payment_{gateway_id}` を発火させることで
		 * WooCommerce Payments（WCPay）など互換ゲートウェイに処理を委ねます。
		 *
		 * @param int      $subscription_id 定期購入レコード ID。
		 * @param WC_Order $renewal_order   作成済みの更新注文。
		 * @return void
		 */
		public function process_renewal_payment( $subscription_id, WC_Order $renewal_order ) {
			$gateway_id = $renewal_order->get_payment_method();

			if ( ! $this->is_auto_charge_gateway( $gateway_id ) ) {
				$renewal_order->update_status( 'failed', __( 'Only Stripe or WooPayments card payment is supported for subscriptions.', 'megurio-subscriptions-for-woocommerce' ) );
				return;
			}

			// 保存済みトークンを更新注文に紐づける（ゲートウェイが参照できるよう）。
			$token_id = (int) $this->get_subscription_meta( $subscription_id, '_megurio_payment_token_id' );
			if ( $token_id ) {
				$token = WC_Payment_Tokens::get( $token_id );
				if ( $token && (int) $token->get_user_id() === (int) $renewal_order->get_customer_id() ) {
					$renewal_order->add_payment_token( $token );
					$renewal_order->update_meta_data( '_megurio_payment_token_id', $token_id );
					$renewal_order->save();
				}
			}

			/**
			 * 自動課金を実行する前にカスタム処理を挟めるフックです。
			 * false を返すと以降の課金処理をスキップします。
			 *
			 * @param bool     $should_charge    true なら課金を継続。
			 * @param WC_Order $renewal_order    更新注文。
			 * @param int      $subscription_id  定期購入 ID。
			 * @param string   $gateway_id       ゲートウェイ ID。
			 */
			$should_charge = apply_filters(
				'megurio_should_process_renewal_payment',
				true,
				$renewal_order,
				$subscription_id,
				$gateway_id
			);

			if ( ! $should_charge ) {
				return;
			}

			/**
			 * WooCommerce Payments・Stripe・PayPal など互換ゲートウェイが
			 * オフセッション課金を処理するための標準フックです。
			 *
			 * フックシグネチャ:
			 *   do_action( 'woocommerce_scheduled_subscription_payment_{gateway_id}', float $amount, WC_Order $renewal_order )
			 *
			 */
			do_action(
				'woocommerce_scheduled_subscription_payment_' . $gateway_id,
				(float) $renewal_order->get_total(),
				$renewal_order
			);

			/**
			 * 課金フック発火後に呼ばれます。
			 * 課金結果の確認や後処理に使用できます。
			 *
			 * @param WC_Order $renewal_order   更新注文。
			 * @param int      $subscription_id 定期購入 ID。
			 * @param string   $gateway_id      ゲートウェイ ID。
			 */
			do_action( 'megurio_after_renewal_payment', $renewal_order, $subscription_id, $gateway_id );
		}
	}
}
