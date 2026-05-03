<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ゲートウェイ連携：更新注文の自動決済を処理します。
 *
 * 現在サポート済み:
 *   - Stripe for WooCommerce (stripe)             — Stripe 公式プラグイン カード決済
 *
 * == 自動課金の共通動作要件 ==
 * 各ゲートウェイは `woocommerce_scheduled_subscription_payment_{gateway_id}` アクションを
 * リッスンして、顧客の保存済みカード（Stripe Customer ID）を使ってオフセッション課金します。
 *
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
			'stripe', // Stripe for WooCommerce — カードのみ
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
			}
		}

		/**
		 * HPOS 対応：定期購入レコードのメタを取得します。
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
			return null;
		}

		/**
		 * WooCommerce に登録されている支払いゲートウェイを取得します。
		 *
		 * @param string $gateway_id ゲートウェイ ID。
		 * @return WC_Payment_Gateway|null
		 */
		protected function get_payment_gateway( $gateway_id ) {
			if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
				return null;
			}

			$gateways = WC()->payment_gateways()->payment_gateways();
			return isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ] : null;
		}

		/**
		 * Stripe 公式ゲートウェイの定期課金処理を直接呼び出します。
		 *
		 * WooCommerce Subscriptions がない環境では Stripe 公式プラグインが
		 * scheduled subscription hook を登録しないため、Megurio 側で必要な
		 * Stripe メタを更新注文へ補完してから同じ処理を呼びます。
		 *
		 * @param int                   $subscription_id 定期購入 ID。
		 * @param WC_Order              $renewal_order   更新注文。
		 * @param WC_Payment_Token|null $payment_token   保存済み支払いトークン。
		 * @return bool 処理を試みた場合 true。
		 */
		protected function process_stripe_renewal_payment( $subscription_id, WC_Order $renewal_order, $payment_token = null ) {
			$stripe_gateway = $this->get_payment_gateway( 'stripe' );
			if ( ! $stripe_gateway || ! method_exists( $stripe_gateway, 'process_subscription_payment' ) ) {
				return false;
			}

			$payment_method_id = $payment_token instanceof WC_Payment_Token ? (string) $payment_token->get_token() : '';
			if ( ! $payment_method_id ) {
				$payment_method_id = (string) $renewal_order->get_meta( '_stripe_source_id', true );
			}

			$stripe_customer_id = $this->get_stripe_customer_id_for_renewal( $subscription_id, $renewal_order );

			if ( ! $payment_method_id || ! $stripe_customer_id ) {
				$renewal_order->update_status(
					'failed',
					__( 'Automatic Stripe renewal payment failed because the saved Stripe customer or payment method is missing.', 'megurio-subscriptions-for-woocommerce' )
				);
				return true;
			}

			$this->set_stripe_order_payment_meta( $renewal_order, $stripe_customer_id, $payment_method_id );

			try {
				$renewal_order->add_order_note( __( 'Starting Stripe off-session automatic renewal payment using the saved payment method.', 'megurio-subscriptions-for-woocommerce' ) );
				$stripe_gateway->process_subscription_payment( (float) $renewal_order->get_total(), $renewal_order, true, false );
			} catch ( Throwable $e ) {
				$renewal_order->update_status(
					'failed',
					sprintf(
						/* translators: %s: Stripe error message */
						__( 'Automatic Stripe renewal payment failed: %s', 'megurio-subscriptions-for-woocommerce' ),
						$e->getMessage()
					)
				);
			}

			return true;
		}

		/**
		 * 更新注文で使う Stripe Customer ID を取得します。
		 *
		 * @param int      $subscription_id 定期購入 ID。
		 * @param WC_Order $renewal_order   更新注文。
		 * @return string
		 */
		protected function get_stripe_customer_id_for_renewal( $subscription_id, WC_Order $renewal_order ) {
			$stripe_customer_id = (string) $renewal_order->get_meta( '_stripe_customer_id', true );
			if ( $stripe_customer_id ) {
				return $stripe_customer_id;
			}

			$stripe_customer_id = (string) $this->get_subscription_meta( $subscription_id, '_stripe_customer_id' );
			if ( $stripe_customer_id ) {
				return $stripe_customer_id;
			}

			$parent_order_id = absint( $renewal_order->get_meta( '_megurio_parent_order_id', true ) );
			if ( ! $parent_order_id ) {
				$parent_order_id = absint( $this->get_subscription_meta( $subscription_id, '_megurio_parent_order_id' ) );
			}

			if ( $parent_order_id ) {
				$parent_order = wc_get_order( $parent_order_id );
				if ( $parent_order instanceof WC_Order ) {
					$stripe_customer_id = (string) $parent_order->get_meta( '_stripe_customer_id', true );
					if ( $stripe_customer_id ) {
						return $stripe_customer_id;
					}
				}
			}

			$customer_id = absint( $renewal_order->get_customer_id() );
			if ( $customer_id ) {
				$stripe_customer_id = (string) get_user_option( '_stripe_customer_id', $customer_id );
			}

			return $stripe_customer_id;
		}

		/**
		 * Stripe 公式ゲートウェイが参照する注文メタを更新注文へ保存します。
		 *
		 * @param WC_Order $renewal_order      更新注文。
		 * @param string   $stripe_customer_id Stripe Customer ID。
		 * @param string   $payment_method_id  Stripe PaymentMethod ID。
		 * @return void
		 */
		protected function set_stripe_order_payment_meta( WC_Order $renewal_order, $stripe_customer_id, $payment_method_id ) {
			if ( class_exists( 'WC_Stripe_Order_Helper' ) ) {
				$order_helper = WC_Stripe_Order_Helper::get_instance();
				if ( method_exists( $order_helper, 'update_stripe_customer_id' ) ) {
					$order_helper->update_stripe_customer_id( $renewal_order, $stripe_customer_id );
				}
				if ( method_exists( $order_helper, 'update_stripe_source_id' ) ) {
					$order_helper->update_stripe_source_id( $renewal_order, $payment_method_id );
				}
			} else {
				$renewal_order->update_meta_data( '_stripe_customer_id', $stripe_customer_id );
				$renewal_order->update_meta_data( '_stripe_source_id', $payment_method_id );
			}

			$renewal_order->save();
		}

		/**
		 * 更新注文の自動課金を試みます。
		 *
		 * `woocommerce_scheduled_subscription_payment_{gateway_id}` を発火させることで
		 * Stripe 公式ゲートウェイなど互換ゲートウェイに処理を委ねます。
		 *
		 * @param int      $subscription_id 定期購入レコード ID。
		 * @param WC_Order $renewal_order   作成済みの更新注文。
		 * @return void
		 */
		public function process_renewal_payment( $subscription_id, WC_Order $renewal_order ) {
			$gateway_id = $renewal_order->get_payment_method();

			if ( ! $this->is_auto_charge_gateway( $gateway_id ) ) {
				$renewal_order->update_status( 'failed', __( 'Only Stripe card payment is supported for subscriptions.', 'megurio-subscriptions-for-woocommerce' ) );
				return;
			}

			// 保存済みトークンを更新注文に紐づける（ゲートウェイが参照できるよう）。
			$token_id      = (int) $this->get_subscription_meta( $subscription_id, '_megurio_payment_token_id' );
			$payment_token = null;
			if ( $token_id ) {
				$token = WC_Payment_Tokens::get( $token_id );
				if ( $token
					&& (int) $token->get_user_id() === (int) $renewal_order->get_customer_id()
					&& $token->get_gateway_id() === $gateway_id
				) {
					$renewal_order->add_payment_token( $token );
					$renewal_order->update_meta_data( '_megurio_payment_token_id', $token_id );
					$renewal_order->save();
					$payment_token = $token;
				}
			}

			if ( ! $payment_token ) {
				$customer_tokens = WC_Payment_Tokens::get_customer_tokens( $renewal_order->get_customer_id(), $gateway_id );
				if ( ! empty( $customer_tokens ) ) {
					$payment_token = reset( $customer_tokens );
					$renewal_order->add_payment_token( $payment_token );
					$renewal_order->update_meta_data( '_megurio_payment_token_id', $payment_token->get_id() );
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

			$scheduled_payment_hook = 'woocommerce_scheduled_subscription_payment_' . $gateway_id;
			if ( ! has_action( $scheduled_payment_hook ) ) {
				if ( 'stripe' === $gateway_id && $this->process_stripe_renewal_payment( $subscription_id, $renewal_order, $payment_token ) ) {
					do_action( 'megurio_after_renewal_payment', $renewal_order, $subscription_id, $gateway_id );
					return;
				}

				$renewal_order->update_status(
					'failed',
					sprintf(
						/* translators: 1: payment gateway ID, 2: scheduled payment hook name */
						__( 'Automatic renewal payment failed because no payment gateway handler is registered for %1$s (%2$s).', 'megurio-subscriptions-for-woocommerce' ),
						$gateway_id,
						$scheduled_payment_hook
					)
				);
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
				$scheduled_payment_hook,
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
