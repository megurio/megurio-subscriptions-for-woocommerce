<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Megurio_Subscriptions_For_Woocommerce' ) ) {
	/**
	 * 定期購入の最小フローをまとめたメインクラスです。
	 */
	class Megurio_Subscriptions_For_Woocommerce {
	/**
	 * プラグイン内部バージョンです。
	 */
	const PLUGIN_VERSION = MEGURIO_SUBSCRIPTIONS_FOR_WOOCOMMERCE_VERSION;

	/**
	 * 決済ゲートウェイ連携インスタンスです。
	 *
	 * @var Megurio_Payment_Gateway_Integration
	 */
	protected $gateway_integration;

	/**
	 * 定期購入レコードの注文タイプです。
	 */
	const SUBSCRIPTION_TYPE = 'megurio_subscription';

	/**
	 * 更新注文を作る定期処理のフック名です。
	 */
	const ACTION_CREATE_RENEWALS = 'megurio_create_renewal_orders';

	/**
	 * 更新課金リトライのスケジュールフック名です。
	 */
	const ACTION_RETRY_RENEWAL = 'megurio_retry_renewal_payment';

	/**
	 * 自動リトライの最大回数です。
	 */
	const RENEWAL_MAX_RETRIES = 3;

	/**
	 * リトライ間隔（日数）です。インデックス 0 が 1 回目のリトライ前の待機日数です。
	 * 合計 2+3+2 = 7 日以内に 3 回リトライします。
	 */
	const RENEWAL_RETRY_INTERVALS = array( 2, 3, 2 );

	/**
	 * 初期化します。
	 */
	public function __construct() {
		$this->gateway_integration = new Megurio_Payment_Gateway_Integration();

		add_action( 'init', array( $this, 'register_subscription_order_type' ), 5 );
		add_action( 'init', array( $this, 'register_account_endpoints' ), 6 );
		add_action( 'init', array( $this, 'register_cron' ), 20 );
		add_action( 'init', array( $this, 'maybe_refresh_rewrite_rules' ), 99 );
		add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );
		add_filter( 'query_vars', array( $this, 'register_account_query_vars' ) );

		add_filter( 'product_type_options', array( $this, 'add_product_type_option' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_filter( 'manage_edit-product_columns', array( $this, 'add_product_list_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_list_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_list_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_list_column' ), 10, 2 );
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_order_list_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_order_list_column' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'template_redirect', array( $this, 'handle_front_actions' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_my_account_menu_item' ) );
		add_action( 'woocommerce_account_megurio-subscriptions_endpoint', array( $this, 'render_my_account_page' ) );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_archive_subscription_notice' ), 15 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_subscription_notice' ), 11 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'append_price_interval_suffix' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'add_cart_subscription_item_data' ), 10, 2 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_signup_fee_to_cart' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'limit_subscription_payment_gateways' ) );
		add_filter( 'wc_stripe_upe_params', array( $this, 'limit_stripe_upe_to_card_for_subscription' ) );
		add_filter( 'wc_stripe_generate_create_intent_request', array( $this, 'limit_stripe_intent_to_card_for_subscription' ), 20, 3 );
		add_filter( 'wc_stripe_show_payment_request_on_cart', array( $this, 'hide_subscription_express_checkout' ) );
		add_filter( 'wc_stripe_show_payment_request_on_checkout', array( $this, 'hide_subscription_express_checkout' ) );
		add_filter( 'wc_stripe_hide_payment_request_on_product_page', array( $this, 'hide_stripe_subscription_product_express_checkout' ), 20, 2 );
		add_filter( 'wc_stripe_force_save_payment_method', array( $this, 'force_stripe_save_payment_method_for_subscription' ), 20, 2 );
		add_filter( 'wc_stripe_display_save_payment_method_checkbox', array( $this, 'hide_save_payment_method_checkbox_for_subscription' ) );
		add_filter( 'wcpay_payment_fields_js_config', array( $this, 'limit_wcpay_fields_to_card_for_subscription' ), 100 );
		add_filter( 'wcpay_express_checkout_js_params', array( $this, 'disable_wcpay_express_checkout_for_subscription' ), 100 );
		add_filter( 'wcpay_payment_request_is_cart_supported', array( $this, 'disable_wcpay_subscription_cart_express_checkout' ), 20, 2 );
		add_filter( 'wcpay_payment_request_is_product_supported', array( $this, 'disable_wcpay_subscription_product_express_checkout' ), 20, 2 );
		add_filter( 'wc_payments_display_save_payment_method_checkbox', array( $this, 'hide_save_payment_method_checkbox_for_subscription' ) );
		add_filter( 'wcpay_api_request_params', array( $this, 'limit_wcpay_api_request_to_card_for_subscription' ), 20, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );

		// サブスク購入時に各ゲートウェイの「カードを保存」フラグを強制的に立てる。
		// Stripe: wc-stripe-new-payment-method
		// これにより setup_future_usage: off_session で PaymentIntent が作成され、
		// 初回 3DS 認証が MIT 免除として登録されるため、更新時の再認証を回避できる。
		add_action( 'woocommerce_checkout_process', array( $this, 'force_save_payment_method_for_subscription' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_subscription_card_payment_method' ), 20 );

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'create_subscriptions_from_order' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'create_subscriptions_from_order' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( $this, 'create_subscriptions_from_order' ), 20, 1 );
		add_action( 'woocommerce_thankyou', array( $this, 'create_subscriptions_from_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 20, 3 );

		add_action( self::ACTION_CREATE_RENEWALS, array( $this, 'run_renewal_scheduler' ) );
		add_action( self::ACTION_RETRY_RENEWAL, array( $this, 'run_retry_renewal_payment' ) );
	}

	/**
	 * エンドポイント変更時に 1 回だけ rewrite を更新します。
	 *
	 * @return void
	 */
	public function maybe_refresh_rewrite_rules() {
		$saved_version = get_option( 'megurio_plugin_version', '' );

		if ( self::PLUGIN_VERSION === $saved_version ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( 'megurio_plugin_version', self::PLUGIN_VERSION );
	}

	/**
	 * マイアカウント用エンドポイントを登録します。
	 *
	 * @return void
	 */
	public function register_account_endpoints() {
		add_rewrite_endpoint( 'megurio-subscriptions', EP_ROOT | EP_PAGES );
	}

	/**
	 * マイアカウント用クエリ変数を追加します。
	 *
	 * @param array $vars 既存のクエリ変数。
	 * @return array
	 */
	public function register_account_query_vars( $vars ) {
		$vars[] = 'megurio-subscriptions';

		return $vars;
	}

	/**
	 * 管理画面メニューを追加します。
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Subscription List', 'megurio-subscriptions-for-woocommerce' ),
			__( 'Subscription List', 'megurio-subscriptions-for-woocommerce' ),
			'manage_woocommerce',
			'megurio-subscriptions',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * マイアカウントメニューに定期購入一覧を追加します。
	 *
	 * @param array $items 既存メニュー。
	 * @return array
	 */
	public function add_my_account_menu_item( $items ) {
		$new_items = array();

		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;

			if ( 'orders' === $key ) {
				$new_items['megurio-subscriptions'] = __( 'Subscription List', 'megurio-subscriptions-for-woocommerce' );
			}
		}

		if ( ! isset( $new_items['megurio-subscriptions'] ) ) {
			$new_items['megurio-subscriptions'] = __( 'Subscription List', 'megurio-subscriptions-for-woocommerce' );
		}

		return $new_items;
	}

	/**
	 * 管理画面の手動実行アクションを処理します。
	 *
	 * @return void
	 */
	public function handle_admin_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$page = '';
		if ( isset( $_REQUEST['page'] ) ) {
			$page = sanitize_text_field( wp_unslash( $_REQUEST['page'] ) );
		}

		if ( 'megurio-subscriptions' !== $page ) {
			return;
		}

		if ( 'POST' === $this->get_request_method() && ! empty( $_POST['megurio_action'] ) ) {
			$action = sanitize_text_field( wp_unslash( $_POST['megurio_action'] ) );

			if ( 'change_status' === $action ) {
				check_admin_referer( 'megurio_change_subscription_status' );

				$subscription_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;
				$target_status   = isset( $_POST['target_status'] ) ? sanitize_text_field( wp_unslash( $_POST['target_status'] ) ) : '';

				if ( $subscription_id && $this->manually_update_subscription_status( $subscription_id, $target_status ) ) {
					$redirect_url = add_query_arg(
						array(
							'page'            => 'megurio-subscriptions',
							'subscription_id' => $subscription_id,
							'megurio_notice'     => rawurlencode( __( 'Subscription status updated.', 'megurio-subscriptions-for-woocommerce' ) ),
							'megurio_count'      => 1,
						),
						admin_url( 'admin.php' )
					);

					wp_safe_redirect( $redirect_url );
					exit;
				}
			} elseif ( 'save_email_settings' === $action ) {
				check_admin_referer( 'megurio_save_email_settings' );

				$defaults  = $this->get_email_template_defaults();
				$templates = array();

				foreach ( array_keys( $defaults ) as $type ) {
					$templates[ $type ] = array(
						'subject' => sanitize_text_field( wp_unslash( $_POST[ 'megurio_email_' . $type . '_subject' ] ?? '' ) ),
						'heading' => sanitize_text_field( wp_unslash( $_POST[ 'megurio_email_' . $type . '_heading' ] ?? '' ) ),
						'body'    => wp_kses_post( wp_unslash( $_POST[ 'megurio_email_' . $type . '_body' ] ?? '' ) ),
					);
				}

				update_option( 'megurio_email_templates', $templates );

				wp_safe_redirect( add_query_arg(
					array(
						'page'           => 'megurio-subscriptions',
						'tab'            => 'email-settings',
						'megurio_notice' => rawurlencode( __( 'Email settings saved.', 'megurio-subscriptions-for-woocommerce' ) ),
					),
					admin_url( 'admin.php' )
				) );
				exit;
			}
		}

		if ( isset( $_GET['email_preview'] ) ) {
			check_admin_referer( 'megurio_email_preview' );
			$this->output_email_preview( sanitize_text_field( wp_unslash( $_GET['email_preview'] ) ) );
			exit;
		}

		if ( empty( $_GET['megurio_action'] ) ) {
			return;
		}

		check_admin_referer( 'megurio_admin_action' );

		$action  = sanitize_text_field( wp_unslash( $_GET['megurio_action'] ) );
		$count   = 0;
		$message = '';

		if ( 'run_renewal' === $action ) {
			$count   = $this->run_renewal_scheduler();
			$message = __( 'Renewal order scan executed.', 'megurio-subscriptions-for-woocommerce' );
		} else {
			return;
		}

		$redirect_url = add_query_arg(
			array(
				'page'         => 'megurio-subscriptions',
				'megurio_notice'  => rawurlencode( $message ),
				'megurio_count'   => $count,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * マイアカウント上の定期購入操作を処理します。
	 *
	 * @return void
	 */
	public function handle_front_actions() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}

		if ( 'POST' !== $this->get_request_method() ) {
			return;
		}

		$action          = isset( $_POST['megurio_front_action'] ) ? sanitize_text_field( wp_unslash( $_POST['megurio_front_action'] ) ) : '';
		$allowed_actions = array( 'cancel_subscription', 'pause_subscription', 'resume_subscription', 'change_payment_method' );
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			return;
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;
		$redirect_url    = add_query_arg(
			array( 'subscription_id' => $subscription_id ),
			wc_get_account_endpoint_url( 'megurio-subscriptions' )
		);

		$nonce_map = array(
			'cancel_subscription'  => 'megurio_front_cancel_subscription',
			'pause_subscription'   => 'megurio_front_pause_subscription',
			'resume_subscription'  => 'megurio_front_resume_subscription',
			'change_payment_method' => 'megurio_front_change_payment_method',
		);
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), $nonce_map[ $action ] ) ) {
			wc_add_notice( __( 'Invalid request. Please try again later.', 'megurio-subscriptions-for-woocommerce' ), 'error' );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		if ( ! $subscription_id || ! $this->user_owns_subscription( $subscription_id, get_current_user_id() ) ) {
			wc_add_notice( __( 'Subscription not found.', 'megurio-subscriptions-for-woocommerce' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'megurio-subscriptions' ) );
			exit;
		}

		$status = (string) $this->get_object_meta( $subscription_id, '_megurio_subscription_status' );

		if ( 'cancel_subscription' === $action ) {
			if ( ! in_array( $status, array( 'pending', 'active', 'on-hold' ), true ) ) {
				wc_add_notice( __( 'This subscription cannot be cancelled at this time.', 'megurio-subscriptions-for-woocommerce' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			$this->set_subscription_meta( $subscription_id, array(
				'_megurio_subscription_status' => 'cancelled',
				'_megurio_next_payment'        => 0,
			) );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( __( 'Customer cancelled subscription from My Account.', 'megurio-subscriptions-for-woocommerce' ) );
			}
			$this->send_subscription_cancel_email( $subscription_id, 'customer' );

			wc_add_notice( __( 'Your subscription has been cancelled.', 'megurio-subscriptions-for-woocommerce' ), 'success' );

		} elseif ( 'pause_subscription' === $action ) {
			if ( 'active' !== $status ) {
				wc_add_notice( __( 'This subscription cannot be paused at this time.', 'megurio-subscriptions-for-woocommerce' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			$this->set_subscription_meta( $subscription_id, array(
				'_megurio_subscription_status' => 'on-hold',
				'_megurio_next_payment'        => 0,
			) );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( __( 'Customer paused subscription from My Account.', 'megurio-subscriptions-for-woocommerce' ) );
			}

			wc_add_notice( __( 'Your subscription has been paused.', 'megurio-subscriptions-for-woocommerce' ), 'success' );

		} elseif ( 'resume_subscription' === $action ) {
			if ( 'on-hold' !== $status ) {
				wc_add_notice( __( 'This subscription cannot be resumed at this time.', 'megurio-subscriptions-for-woocommerce' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			$current_time = current_time( 'timestamp' );
			$this->set_subscription_meta( $subscription_id, array(
				'_megurio_subscription_status' => 'active',
				'_megurio_next_payment'        => $this->calculate_next_payment( $subscription_id, $current_time ),
			) );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( __( 'Customer resumed subscription from My Account.', 'megurio-subscriptions-for-woocommerce' ) );
			}
			$this->send_subscription_reactivated_email( $subscription_id, 'customer' );

			wc_add_notice( __( 'Your subscription has been resumed.', 'megurio-subscriptions-for-woocommerce' ), 'success' );

		} elseif ( 'change_payment_method' === $action ) {
			if ( ! in_array( $status, array( 'active', 'on-hold' ), true ) ) {
				wc_add_notice( __( 'Payment method cannot be changed for this subscription.', 'megurio-subscriptions-for-woocommerce' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			$token_id = isset( $_POST['payment_token_id'] ) ? absint( wp_unslash( $_POST['payment_token_id'] ) ) : 0;
			if ( ! $token_id ) {
				wc_add_notice( __( 'Please select a payment method.', 'megurio-subscriptions-for-woocommerce' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			$token = WC_Payment_Tokens::get( $token_id );
			if ( ! $token || (int) $token->get_user_id() !== get_current_user_id() ) {
				wc_add_notice( __( 'Invalid payment method selected.', 'megurio-subscriptions-for-woocommerce' ), 'error' );
				wp_safe_redirect( $redirect_url );
				exit;
			}

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->update_meta_data( '_megurio_payment_token_id', $token_id );
				$subscription->save();
				$subscription->add_order_note( sprintf(
					/* translators: %s: masked card label e.g. "Visa ending in 1234" */
					__( 'Customer changed payment method to: %s', 'megurio-subscriptions-for-woocommerce' ),
					$token->get_display_name()
				) );
			}

			wc_add_notice( __( 'Payment method updated.', 'megurio-subscriptions-for-woocommerce' ), 'success' );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * 商品一覧に定期購入列を追加します。
	 *
	 * @param array $columns 既存カラム。
	 * @return array
	 */
	public function add_product_list_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'sku' === $key ) {
				$new_columns['megurio_subscription_product'] = __( 'Subscription', 'megurio-subscriptions-for-woocommerce' );
			}
		}

		if ( ! isset( $new_columns['megurio_subscription_product'] ) ) {
			$new_columns['megurio_subscription_product'] = __( 'Subscription', 'megurio-subscriptions-for-woocommerce' );
		}

		return $new_columns;
	}

	/**
	 * 商品一覧の定期購入列を表示します。
	 *
	 * @param string $column  カラム名。
	 * @param int    $post_id 商品 ID。
	 * @return void
	 */
	public function render_product_list_column( $column, $post_id ) {
		if ( 'megurio_subscription_product' !== $column ) {
			return;
		}

		if ( $this->is_subscription_product( $post_id ) ) {
			echo '<mark class="order-status status-processing"><span>' . esc_html__( 'Subscription Product', 'megurio-subscriptions-for-woocommerce' ) . '</span></mark>';
		} else {
			echo '<span class="megurio-muted">-</span>';
		}
	}

	/**
	 * 注文一覧に更新注文列を追加します。
	 *
	 * @param array $columns 既存カラム。
	 * @return array
	 */
	public function add_order_list_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'order_total' === $key ) {
				$new_columns['megurio_renewal_order'] = __( 'Subscription', 'megurio-subscriptions-for-woocommerce' );
			}
		}

		if ( ! isset( $new_columns['megurio_renewal_order'] ) ) {
			$new_columns['megurio_renewal_order'] = __( 'Subscription', 'megurio-subscriptions-for-woocommerce' );
		}

		return $new_columns;
	}

	/**
	 * 注文一覧の更新注文列を表示します。
	 *
	 * @param string     $column   カラム名。
	 * @param int|object $order_id 注文 ID または注文オブジェクト。
	 * @return void
	 */
	public function render_order_list_column( $column, $order_id ) {
		if ( 'megurio_renewal_order' !== $column ) {
			return;
		}

		$order = is_numeric( $order_id ) ? wc_get_order( $order_id ) : $order_id;
		if ( ! $order instanceof WC_Order ) {
			echo '<span class="megurio-muted">-</span>';
			return;
		}

		if ( 'yes' === $order->get_meta( '_megurio_is_renewal_order', true ) ) {
			$subscription_id = absint( $order->get_meta( '_megurio_subscription_id', true ) );

			echo '<mark class="order-status status-on-hold"><span>' . esc_html__( 'Renewal Order', 'megurio-subscriptions-for-woocommerce' ) . '</span></mark>';
			if ( $subscription_id ) {
				echo '<div class="megurio-order-reference">#' . esc_html( $subscription_id ) . '</div>';
			}
			return;
		}

		if ( 'yes' === $order->get_meta( '_megurio_has_subscription', true ) ) {
			echo '<mark class="order-status status-processing"><span>' . esc_html__( 'Initial Subscription Order', 'megurio-subscriptions-for-woocommerce' ) . '</span></mark>';
			return;
		}

		echo '<span class="megurio-muted">-</span>';
	}

	/**
	 * 商品一覧と注文一覧のカラム幅を整えます。
	 *
	 * @return void
	 */
	public function enqueue_admin_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$screen_post_type  = isset( $screen->post_type ) ? $screen->post_type : '';
		$is_product_screen = 'edit-product' === $screen->id;
		$is_product_editor = 'product' === $screen_post_type;
		$is_order_screen   = in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true );
		$is_megurio_screen    = 'woocommerce_page_megurio-subscriptions' === $screen->id;

		if ( ! $is_product_screen && ! $is_product_editor && ! $is_order_screen && ! $is_megurio_screen ) {
			return;
		}

		wp_enqueue_style(
			'megurio-admin',
			plugins_url( 'assets/css/admin.css', dirname( __DIR__ ) . '/megurio-subscriptions-for-woocommerce.php' ),
			array(),
			self::PLUGIN_VERSION
		);
	}

	/**
	 * 定期購入の管理ページを表示します。
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'subscriptions';
		$notice      = $this->get_query_text( 'megurio_notice' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Megurio Subscriptions', 'megurio-subscriptions-for-woocommerce' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=megurio-subscriptions' ) ); ?>" class="nav-tab <?php echo 'subscriptions' === $current_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Subscriptions', 'megurio-subscriptions-for-woocommerce' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=megurio-subscriptions&tab=email-settings' ) ); ?>" class="nav-tab <?php echo 'email-settings' === $current_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Email Settings', 'megurio-subscriptions-for-woocommerce' ); ?></a>
			</nav>

		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html( $notice ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'email-settings' === $current_tab ) :
			$this->render_email_settings_tab();
		else :
		$subscription_ids = $this->get_all_subscription_ids();
		$selected_id      = $this->get_query_int( 'subscription_id' );
		$selected_order   = $selected_id ? wc_get_order( $selected_id ) : false;
		$counts           = $this->get_subscription_status_counts( $subscription_ids );
		$notice_count     = $this->get_query_int( 'megurio_count' );
		$renewal_url      = wp_nonce_url(
			add_query_arg(
				array(
					'page'           => 'megurio-subscriptions',
					'megurio_action' => 'run_renewal',
				),
				admin_url( 'admin.php' )
			),
			'megurio_admin_action'
		);
		?>
			<p><?php esc_html_e( 'This page shows all subscription records and status flows created by the subscription plugin.', 'megurio-subscriptions-for-woocommerce' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $renewal_url ); ?>" class="button button-primary"><?php esc_html_e( 'Run Renewal Order Scan Now', 'megurio-subscriptions-for-woocommerce' ); ?></a>
			</p>

		<?php if ( $notice_count ) : ?>
			<div class="notice notice-info is-dismissible">
				<p><?php esc_html_e( 'Count:', 'megurio-subscriptions-for-woocommerce' ); ?> <?php echo esc_html( $notice_count ); ?></p>
			</div>
		<?php endif; ?>

				<div class="megurio-admin-grid">
					<div class="megurio-admin-card">
						<div><?php esc_html_e( 'Total Subscriptions', 'megurio-subscriptions-for-woocommerce' ); ?></div>
						<strong><?php echo esc_html( count( $subscription_ids ) ); ?></strong>
					</div>
					<div class="megurio-admin-card">
						<div><?php esc_html_e( 'Active', 'megurio-subscriptions-for-woocommerce' ); ?></div>
						<strong><?php echo esc_html( $counts['active'] ); ?></strong>
					</div>
					<div class="megurio-admin-card">
						<div><?php esc_html_e( 'On Hold', 'megurio-subscriptions-for-woocommerce' ); ?></div>
						<strong><?php echo esc_html( $counts['on-hold'] ); ?></strong>
					</div>
					<div class="megurio-admin-card">
						<div><?php esc_html_e( 'Cancelled', 'megurio-subscriptions-for-woocommerce' ); ?></div>
						<strong><?php echo esc_html( $counts['cancelled'] ); ?></strong>
					</div>
				</div>

			<table class="megurio-admin-table">
				<thead>
					<tr>
							<th><?php esc_html_e( 'Subscription ID', 'megurio-subscriptions-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Status', 'megurio-subscriptions-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Product', 'megurio-subscriptions-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Total', 'megurio-subscriptions-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Parent Order', 'megurio-subscriptions-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Next Billing Date', 'megurio-subscriptions-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Last Renewal Order', 'megurio-subscriptions-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Flow', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
						<?php if ( empty( $subscription_ids ) ) : ?>
							<tr>
								<td colspan="8"><?php esc_html_e( 'No subscription records found.', 'megurio-subscriptions-for-woocommerce' ); ?></td>
							</tr>
					<?php else : ?>
						<?php foreach ( $subscription_ids as $subscription_id ) : ?>
								<?php
								$status          = (string) $this->get_object_meta( $subscription_id, '_megurio_subscription_status' );
								$subscription    = wc_get_order( $subscription_id );
								$product_id      = absint( $this->get_object_meta( $subscription_id, '_megurio_product_id' ) );
								$parent_order_id = absint( $this->get_object_meta( $subscription_id, '_megurio_parent_order_id' ) );
								$next_payment    = (int) $this->get_object_meta( $subscription_id, '_megurio_next_payment' );
								$last_renewal_id = absint( $this->get_object_meta( $subscription_id, '_megurio_last_renewal_order' ) );
								$product_title   = $product_id ? get_the_title( $product_id ) : '-';
								$detail_page_url = add_query_arg(
									array(
										'page'            => 'megurio-subscriptions',
										'subscription_id' => $subscription_id,
									),
									admin_url( 'admin.php' )
								);
								?>
							<tr>
								<td>#<?php echo esc_html( $subscription_id ); ?></td>
								<td><?php echo wp_kses_post( $this->render_status_badge( $status, $subscription_id ) ); ?></td>
								<td>
									<?php if ( $product_id ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>"><?php echo esc_html( $product_title ); ?></a>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
								<td><?php echo $subscription instanceof WC_Order ? wp_kses_post( $subscription->get_formatted_order_total() ) : '-'; ?></td>
								<td>
									<?php if ( $parent_order_id ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $parent_order_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $parent_order_id ); ?></a>
									<?php else : ?>
										-
									<?php endif; ?>
									</td>
									<td><?php echo esc_html( $this->format_timestamp( $next_payment ) ); ?></td>
									<td>
									<?php if ( $last_renewal_id ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $last_renewal_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $last_renewal_id ); ?></a>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
								<td><a href="<?php echo esc_url( $detail_page_url ); ?>"><?php esc_html_e( 'View Details', 'megurio-subscriptions-for-woocommerce' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $selected_order instanceof WC_Order ) : ?>
				<?php
					$selected_status   = (string) $this->get_object_meta( $selected_id, '_megurio_subscription_status' );
					$selected_product  = absint( $this->get_object_meta( $selected_id, '_megurio_product_id' ) );
					$selected_parent   = absint( $this->get_object_meta( $selected_id, '_megurio_parent_order_id' ) );
					$selected_customer = absint( $this->get_object_meta( $selected_id, '_megurio_customer_id' ) );
					$selected_start    = (int) $this->get_object_meta( $selected_id, '_megurio_schedule_start' );
					$selected_next     = (int) $this->get_object_meta( $selected_id, '_megurio_next_payment' );
					$interval_count    = max( 1, absint( $this->get_object_meta( $selected_id, '_megurio_interval_count' ) ) );
					$interval_unit     = (string) $this->get_object_meta( $selected_id, '_megurio_interval_unit' );
					$payment_title     = $this->get_subscription_payment_method_title( $selected_id, $selected_order );
					$renewal_ids       = $this->get_object_meta( $selected_id, '_megurio_renewal_order_ids' );
					$renewal_ids       = is_array( $renewal_ids ) ? $renewal_ids : array();
					$runtime_status    = $this->get_subscription_runtime_status( $selected_id );
					$order_notes       = wc_get_order_notes(
						array(
							'order_id' => $selected_id,
							'orderby'  => 'date_created',
							'order'    => 'ASC',
						)
					);
				?>
				<div class="megurio-admin-detail">
					<h2><?php echo esc_html( sprintf( __( 'Subscription #%d Details', 'megurio-subscriptions-for-woocommerce' ), $selected_id ) ); ?></h2>
					<?php if ( ! empty( $runtime_status['notice'] ) ) : ?>
						<p class="megurio-muted">
							<?php echo esc_html( $runtime_status['notice'] ); ?>
							<?php if ( ! empty( $runtime_status['renewal_order_id'] ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $runtime_status['renewal_order_id'] . '&action=edit' ) ); ?>"><?php echo esc_html( sprintf( __( 'Renewal Order #%d', 'megurio-subscriptions-for-woocommerce' ), $runtime_status['renewal_order_id'] ) ); ?></a>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<div class="megurio-admin-meta">
						<div>
							<strong><?php esc_html_e( 'Current Status', 'megurio-subscriptions-for-woocommerce' ); ?></strong>
							<?php echo wp_kses_post( $this->render_status_badge( $selected_status, $selected_id ) ); ?>
						</div>
						<div>
							<strong><?php esc_html_e( 'Product', 'megurio-subscriptions-for-woocommerce' ); ?></strong>
							<?php if ( $selected_product ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $selected_product ) ); ?>"><?php echo esc_html( get_the_title( $selected_product ) ); ?></a>
							<?php else : ?>
								-
							<?php endif; ?>
						</div>
						<div>
							<strong><?php esc_html_e( 'Parent Order', 'megurio-subscriptions-for-woocommerce' ); ?></strong>
							<?php if ( $selected_parent ) : ?>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $selected_parent . '&action=edit' ) ); ?>">#<?php echo esc_html( $selected_parent ); ?></a>
							<?php else : ?>
								-
							<?php endif; ?>
						</div>
						<div>
							<strong><?php esc_html_e( 'Customer ID', 'megurio-subscriptions-for-woocommerce' ); ?></strong>
							<?php echo $selected_customer ? esc_html( '#' . $selected_customer ) : '-'; ?>
						</div>
						<div>
							<strong><?php esc_html_e( 'Payment Method', 'megurio-subscriptions-for-woocommerce' ); ?></strong>
							<?php echo esc_html( $payment_title ? $payment_title : '-' ); ?>
						</div>
						<div>
							<strong><?php esc_html_e( 'Renewal Interval', 'megurio-subscriptions-for-woocommerce' ); ?></strong>
							<?php echo esc_html( $this->format_interval_label( $interval_count, $interval_unit ) ); ?>
						</div>
						<div>
							<strong><?php esc_html_e( 'Start Date', 'megurio-subscriptions-for-woocommerce' ); ?></strong>
							<?php echo esc_html( $this->format_timestamp( $selected_start ) ); ?>
						</div>
						<div>
							<strong><?php esc_html_e( 'Next Billing Date', 'megurio-subscriptions-for-woocommerce' ); ?></strong>
							<?php echo esc_html( $this->format_timestamp( $selected_next ) ); ?>
						</div>
					</div>

					<h3><?php esc_html_e( 'Admin Actions', 'megurio-subscriptions-for-woocommerce' ); ?></h3>
					<form method="post" action="">
						<?php wp_nonce_field( 'megurio_change_subscription_status' ); ?>
						<input type="hidden" name="page" value="megurio-subscriptions" />
						<input type="hidden" name="megurio_action" value="change_status" />
						<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $selected_id ); ?>" />
						<label for="megurio-target-status"><strong><?php esc_html_e( 'Manually Change Status', 'megurio-subscriptions-for-woocommerce' ); ?></strong></label>
						<select id="megurio-target-status" class="megurio-target-status" name="target_status">
							<option value="pending" <?php selected( $selected_status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'megurio-subscriptions-for-woocommerce' ); ?></option>
							<option value="active" <?php selected( $selected_status, 'active' ); ?>><?php esc_html_e( 'Active', 'megurio-subscriptions-for-woocommerce' ); ?></option>
							<option value="on-hold" <?php selected( $selected_status, 'on-hold' ); ?>><?php esc_html_e( 'On Hold', 'megurio-subscriptions-for-woocommerce' ); ?></option>
							<option value="cancelled" <?php selected( $selected_status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'megurio-subscriptions-for-woocommerce' ); ?></option>
						</select>
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Update Status', 'megurio-subscriptions-for-woocommerce' ); ?></button>
					</form>

					<h3><?php esc_html_e( 'Auto-Generated Renewal Orders', 'megurio-subscriptions-for-woocommerce' ); ?></h3>
					<?php if ( empty( $renewal_ids ) ) : ?>
						<p><?php esc_html_e( 'No renewal orders have been created yet.', 'megurio-subscriptions-for-woocommerce' ); ?></p>
					<?php else : ?>
						<table class="megurio-admin-subtable">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Order ID', 'megurio-subscriptions-for-woocommerce' ); ?></th>
									<th><?php esc_html_e( 'Status', 'megurio-subscriptions-for-woocommerce' ); ?></th>
									<th><?php esc_html_e( 'Created Date', 'megurio-subscriptions-for-woocommerce' ); ?></th>
									<th><?php esc_html_e( 'Total', 'megurio-subscriptions-for-woocommerce' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $renewal_ids as $renewal_id ) : ?>
									<?php $renewal_order = wc_get_order( $renewal_id ); ?>
									<tr>
										<td>
											<?php if ( $renewal_order instanceof WC_Order ) : ?>
												<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $renewal_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $renewal_id ); ?></a>
											<?php else : ?>
												#<?php echo esc_html( $renewal_id ); ?>
											<?php endif; ?>
										</td>
										<td><?php echo $renewal_order instanceof WC_Order ? esc_html( wc_get_order_status_name( $renewal_order->get_status() ) ) : '-'; ?></td>
										<td><?php echo $renewal_order instanceof WC_Order ? esc_html( $this->format_datetime_string( $renewal_order->get_date_created() ) ) : '-'; ?></td>
										<td><?php echo $renewal_order instanceof WC_Order ? wp_kses_post( $renewal_order->get_formatted_order_total() ) : '-'; ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<h3><?php esc_html_e( 'Status History Notes', 'megurio-subscriptions-for-woocommerce' ); ?></h3>
					<ul class="megurio-note-list">
						<?php if ( empty( $order_notes ) ) : ?>
							<li><?php esc_html_e( 'No notes yet.', 'megurio-subscriptions-for-woocommerce' ); ?></li>
						<?php else : ?>
							<?php foreach ( $order_notes as $note ) : ?>
								<li>
									<div><strong><?php echo esc_html( $this->format_datetime_string( $note->date_created ) ); ?></strong></div>
									<div><?php echo wp_kses_post( wpautop( $this->link_order_references_in_note( $note->content ) ) ); ?></div>
								</li>
							<?php endforeach; ?>
						<?php endif; ?>
					</ul>
				</div>
			<?php endif; ?>
		<?php endif; // end subscriptions tab ?>
		</div>
		<?php
	}

	/**
	 * マイアカウントの定期購入一覧ページを表示します。
	 *
	 * @return void
	 */
	public function render_my_account_page() {
		if ( ! is_user_logged_in() ) {
			echo '<div class="woocommerce-info">' . esc_html__( 'Please log in.', 'megurio-subscriptions-for-woocommerce' ) . '</div>';
			return;
		}

		wc_print_notices();

		$user_id         = get_current_user_id();
		$subscription_id = $this->get_query_int( 'subscription_id' );

		if ( $subscription_id && $this->user_owns_subscription( $subscription_id, $user_id ) ) {
			$this->render_my_account_subscription_detail( $subscription_id );
			return;
		}

		$subscription_ids = $this->get_user_subscription_ids( $user_id );

		echo '<h2>' . esc_html__( 'Subscription List', 'megurio-subscriptions-for-woocommerce' ) . '</h2>';

		if ( empty( $subscription_ids ) ) {
			echo '<div class="woocommerce-info">' . esc_html__( 'You have no active subscriptions.', 'megurio-subscriptions-for-woocommerce' ) . '</div>';
			return;
		}
		?>
		<table class="shop_table shop_table_responsive my_account_orders account-orders-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Subscription ID', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Product', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Status', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Next Billing Date', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Details', 'megurio-subscriptions-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $subscription_ids as $subscription_id ) : ?>
					<?php
					$status          = (string) $this->get_object_meta( $subscription_id, '_megurio_subscription_status' );
					$product_id      = absint( $this->get_object_meta( $subscription_id, '_megurio_product_id' ) );
					$next_payment    = (int) $this->get_object_meta( $subscription_id, '_megurio_next_payment' );
					$detail_url      = add_query_arg(
						array(
							'subscription_id' => $subscription_id,
						),
						wc_get_account_endpoint_url( 'megurio-subscriptions' )
					);
					?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Subscription ID', 'megurio-subscriptions-for-woocommerce' ); ?>">#<?php echo esc_html( $subscription_id ); ?></td>
							<td data-title="<?php esc_attr_e( 'Product', 'megurio-subscriptions-for-woocommerce' ); ?>"><?php echo esc_html( $product_id ? get_the_title( $product_id ) : '-' ); ?></td>
								<td data-title="<?php esc_attr_e( 'Status', 'megurio-subscriptions-for-woocommerce' ); ?>"><?php echo wp_kses_post( $this->render_status_badge( $status, $subscription_id ) ); ?></td>
							<td data-title="<?php esc_attr_e( 'Next Billing Date', 'megurio-subscriptions-for-woocommerce' ); ?>"><?php echo esc_html( $this->format_timestamp( $next_payment ) ); ?></td>
							<td data-title="<?php esc_attr_e( 'Details', 'megurio-subscriptions-for-woocommerce' ); ?>"><a class="button" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'View Details', 'megurio-subscriptions-for-woocommerce' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * マイアカウントの定期購入詳細ページを表示します。
	 *
	 * @param int $subscription_id 定期購入 ID。
	 * @return void
	 */
	protected function render_my_account_subscription_detail( $subscription_id ) {
		$status          = (string) $this->get_object_meta( $subscription_id, '_megurio_subscription_status' );
			$product_id      = absint( $this->get_object_meta( $subscription_id, '_megurio_product_id' ) );
			$parent_order_id = absint( $this->get_object_meta( $subscription_id, '_megurio_parent_order_id' ) );
			$next_payment    = (int) $this->get_object_meta( $subscription_id, '_megurio_next_payment' );
			$start_date      = (int) $this->get_object_meta( $subscription_id, '_megurio_schedule_start' );
		$renewal_ids     = $this->get_object_meta( $subscription_id, '_megurio_renewal_order_ids' );
		$renewal_ids     = is_array( $renewal_ids ) ? $renewal_ids : array();
		$back_url        = wc_get_account_endpoint_url( 'megurio-subscriptions' );

		echo '<h2>' . esc_html__( 'Subscription Details', 'megurio-subscriptions-for-woocommerce' ) . '</h2>';
		echo '<p><a href="' . esc_url( $back_url ) . '">' . esc_html__( 'Back to List', 'megurio-subscriptions-for-woocommerce' ) . '</a></p>';
		?>
		<table class="shop_table shop_table_responsive">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Subscription ID', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<td>#<?php echo esc_html( $subscription_id ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Product', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<td><?php echo esc_html( $product_id ? get_the_title( $product_id ) : '-' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Status', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<td><?php echo wp_kses_post( $this->render_status_badge( $status, $subscription_id ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Start Date', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<td><?php echo esc_html( $this->format_timestamp( $start_date ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Next Billing Date', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<td><?php echo esc_html( $this->format_timestamp( $next_payment ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Initial Order', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<td>
						<?php if ( $parent_order_id ) : ?>
							<a href="<?php echo esc_url( wc_get_endpoint_url( 'view-order', $parent_order_id, wc_get_page_permalink( 'myaccount' ) ) ); ?>">#<?php echo esc_html( $parent_order_id ); ?></a>
						<?php else : ?>
							-
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( in_array( $status, array( 'pending', 'active', 'on-hold' ), true ) ) : ?>
			<h3 class="megurio-account-heading"><?php esc_html_e( 'Manage Subscription', 'megurio-subscriptions-for-woocommerce' ); ?></h3>
			<div class="megurio-subscription-actions">
				<?php if ( 'active' === $status ) : ?>
					<form method="post" action="" style="display:inline">
						<?php wp_nonce_field( 'megurio_front_pause_subscription' ); ?>
						<input type="hidden" name="megurio_front_action" value="pause_subscription" />
						<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription_id ); ?>" />
						<button type="submit" class="button"><?php esc_html_e( 'Pause This Subscription', 'megurio-subscriptions-for-woocommerce' ); ?></button>
					</form>
				<?php elseif ( 'on-hold' === $status ) : ?>
					<form method="post" action="" style="display:inline">
						<?php wp_nonce_field( 'megurio_front_resume_subscription' ); ?>
						<input type="hidden" name="megurio_front_action" value="resume_subscription" />
						<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription_id ); ?>" />
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Resume This Subscription', 'megurio-subscriptions-for-woocommerce' ); ?></button>
					</form>
				<?php endif; ?>
				<form method="post" action="" style="display:inline">
					<?php wp_nonce_field( 'megurio_front_cancel_subscription' ); ?>
					<input type="hidden" name="megurio_front_action" value="cancel_subscription" />
					<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription_id ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Cancel This Subscription', 'megurio-subscriptions-for-woocommerce' ); ?></button>
				</form>
			</div>

			<?php
			$sub_order        = wc_get_order( $subscription_id );
			$payment_method   = $this->get_subscription_payment_method( $subscription_id, $sub_order );
			$current_token_id = (int) $this->get_object_meta( $subscription_id, '_megurio_payment_token_id' );
			$saved_tokens     = $this->gateway_integration->is_auto_charge_gateway( $payment_method )
				? WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), $payment_method )
				: array();
			?>
			<?php if ( ! empty( $saved_tokens ) && in_array( $status, array( 'active', 'on-hold' ), true ) ) : ?>
				<details class="megurio-change-payment-details">
					<summary><?php esc_html_e( 'Change Payment Method', 'megurio-subscriptions-for-woocommerce' ); ?></summary>
					<form method="post" action="" class="megurio-change-payment-form">
						<?php wp_nonce_field( 'megurio_front_change_payment_method' ); ?>
						<input type="hidden" name="megurio_front_action" value="change_payment_method" />
						<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription_id ); ?>" />
						<ul class="megurio-token-list">
							<?php foreach ( $saved_tokens as $token ) : ?>
								<li>
									<label>
										<input type="radio" name="payment_token_id" value="<?php echo esc_attr( $token->get_id() ); ?>" <?php checked( $token->get_id(), $current_token_id ); ?> required>
										<?php echo esc_html( $token->get_display_name() ); ?>
										<?php if ( $token->get_id() === $current_token_id ) : ?>
											<span class="megurio-current-badge"><?php esc_html_e( 'Current', 'megurio-subscriptions-for-woocommerce' ); ?></span>
										<?php endif; ?>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Payment Method', 'megurio-subscriptions-for-woocommerce' ); ?></button>
					</form>
				</details>
			<?php endif; ?>
		<?php endif; ?>

		<h3 class="megurio-account-heading"><?php esc_html_e( 'Renewal Orders', 'megurio-subscriptions-for-woocommerce' ); ?></h3>
		<?php if ( empty( $renewal_ids ) ) : ?>
			<div class="woocommerce-info"><?php esc_html_e( 'No renewal orders yet.', 'megurio-subscriptions-for-woocommerce' ); ?></div>
		<?php else : ?>
			<table class="shop_table shop_table_responsive my_account_orders account-orders-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order ID', 'megurio-subscriptions-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'megurio-subscriptions-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Total', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $renewal_ids as $renewal_id ) : ?>
						<?php $renewal_order = wc_get_order( $renewal_id ); ?>
						<tr>
							<td data-title="<?php esc_attr_e( 'Order ID', 'megurio-subscriptions-for-woocommerce' ); ?>">
								<?php if ( $renewal_order ) : ?>
									<a href="<?php echo esc_url( wc_get_endpoint_url( 'view-order', $renewal_id, wc_get_page_permalink( 'myaccount' ) ) ); ?>">#<?php echo esc_html( $renewal_id ); ?></a>
								<?php else : ?>
									#<?php echo esc_html( $renewal_id ); ?>
								<?php endif; ?>
							</td>
								<td data-title="<?php esc_attr_e( 'Status', 'megurio-subscriptions-for-woocommerce' ); ?>"><?php echo $renewal_order ? wp_kses_post( $this->render_status_badge( $renewal_order->get_status() ) ) : '-'; ?></td>
							<td data-title="<?php esc_attr_e( 'Total', 'megurio-subscriptions-for-woocommerce' ); ?>"><?php echo $renewal_order ? wp_kses_post( $renewal_order->get_formatted_order_total() ) : '-'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php
	}

	/**
	 * 商品一覧で定期購入商品の案内を表示します。
	 *
	 * @return void
	 */
	public function render_archive_subscription_notice() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->get_id();
		if ( ! $this->is_subscription_product( $product_id ) ) {
			return;
		}

		echo wp_kses_post( $this->get_front_subscription_notice_html( $product_id, 'megurio-subscription-notice megurio-subscription-notice-archive' ) );
	}

	/**
	 * 商品詳細で定期購入商品の案内を表示します。
	 *
	 * @return void
	 */
	public function render_single_subscription_notice() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->get_id();
		if ( ! $this->is_subscription_product( $product_id ) ) {
			return;
		}

		echo wp_kses_post( $this->get_front_subscription_notice_html( $product_id, 'megurio-subscription-notice megurio-subscription-notice-single' ) );
	}

	/**
	 * 定期購入商品の価格 HTML に更新間隔サフィックスを追加します。
	 *
	 * @param string     $price_html 価格 HTML。
	 * @param WC_Product $product    商品オブジェクト。
	 * @return string
	 */
	public function append_price_interval_suffix( $price_html, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $price_html;
		}

		$product_id = $product->get_id();
		if ( ! $this->is_subscription_product( $product_id ) ) {
			return $price_html;
		}

		$meta_id = $this->get_meta_product_id( $product_id );
		$count   = max( 1, absint( $this->get_product_meta( $meta_id, '_megurio_interval_count' ) ) );
		$unit    = (string) $this->get_product_meta( $meta_id, '_megurio_interval_unit' );
		$suffix  = $this->format_price_interval_suffix( $count, $unit );

		if ( '' === $suffix ) {
			return $price_html;
		}

		return $price_html . '<span class="megurio-price-interval">' . esc_html( $suffix ) . '</span>';
	}

	/**
	 * 価格表示用の短い間隔サフィックスを返します。例: /月、/2か月
	 *
	 * @param int    $count 数値。
	 * @param string $unit  単位。
	 * @return string
	 */
	protected function format_price_interval_suffix( $count, $unit ) {
		$unit_single = array(
			'day'   => __( 'day', 'megurio-subscriptions-for-woocommerce' ),
			'week'  => __( 'week', 'megurio-subscriptions-for-woocommerce' ),
			'month' => __( 'month', 'megurio-subscriptions-for-woocommerce' ),
			'year'  => __( 'year', 'megurio-subscriptions-for-woocommerce' ),
		);
		$unit_plural = array(
			'day'   => __( 'days', 'megurio-subscriptions-for-woocommerce' ),
			'week'  => __( 'weeks', 'megurio-subscriptions-for-woocommerce' ),
			'month' => __( 'months', 'megurio-subscriptions-for-woocommerce' ),
			'year'  => __( 'years', 'megurio-subscriptions-for-woocommerce' ),
		);

		if ( empty( $unit_single[ $unit ] ) ) {
			return '';
		}

		if ( 1 === $count ) {
			/* translators: %s: time unit (day, week, month, year) */
			return sprintf( __( '/%s', 'megurio-subscriptions-for-woocommerce' ), $unit_single[ $unit ] );
		}

		/* translators: 1: count, 2: time unit (days, weeks, months, years) */
		return sprintf( __( '/%1$d %2$s', 'megurio-subscriptions-for-woocommerce' ), $count, $unit_plural[ $unit ] );
	}

	/**
	 * カートと購入手続き画面に定期購入情報を追加します。
	 *
	 * @param array $item_data 既存の表示データ。
	 * @param array $cart_item カート行データ。
	 * @return array
	 */
	public function add_cart_subscription_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['product_id'] ) ) {
			return $item_data;
		}

		$product_id = absint( $cart_item['product_id'] );
		if ( ! $this->is_subscription_product( $product_id ) ) {
			return $item_data;
		}

		$item_data[] = array(
			'key'   => __( 'Subscription Type', 'megurio-subscriptions-for-woocommerce' ),
			'value' => __( 'Subscription Product', 'megurio-subscriptions-for-woocommerce' ),
		);

		$item_data[] = array(
			'key'   => __( 'Renewal Interval', 'megurio-subscriptions-for-woocommerce' ),
			'value' => $this->get_subscription_interval_label( $product_id ),
		);

		$signup_fee = (float) $this->get_product_meta( $this->get_meta_product_id( $product_id ), '_megurio_signup_fee' );
		if ( $signup_fee > 0 ) {
			$item_data[] = array(
				'key'   => __( 'Sign-up Fee', 'megurio-subscriptions-for-woocommerce' ),
				'value' => wc_price( $signup_fee ),
			);
		}

		return $item_data;
	}

	/**
	 * カート内の定期購入商品に初期費用を追加します。
	 * 更新注文には適用されず、初回チェックアウト時のみ加算されます。
	 *
	 * @param WC_Cart $cart カートオブジェクト。
	 * @return void
	 */
	public function add_signup_fee_to_cart( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$total_signup_fee = 0.0;
		foreach ( $cart->get_cart() as $cart_item ) {
			$product_id = absint( $cart_item['product_id'] );
			if ( ! $this->is_subscription_product( $product_id ) ) {
				continue;
			}

			$fee = (float) $this->get_product_meta( $this->get_meta_product_id( $product_id ), '_megurio_signup_fee' );
			if ( $fee > 0 ) {
				$total_signup_fee += $fee * absint( $cart_item['quantity'] );
			}
		}

		if ( $total_signup_fee > 0 ) {
			$cart->add_fee( __( 'Sign-up Fee', 'megurio-subscriptions-for-woocommerce' ), $total_signup_fee );
		}
	}

	/**
	 * 定期購入商品購入時の支払い方法を Stripe 公式プラグインのカード決済に制限します。
	 *
	 * @param array $available_gateways 利用可能な決済ゲートウェイ。
	 * @return array
	 */
	public function limit_subscription_payment_gateways( $available_gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $available_gateways;
		}

		if ( ! $this->is_subscription_checkout_context() ) {
			return $available_gateways;
		}

		// 定期購入で使用できるゲートウェイ。
		// 自動更新に使える保存済み Stripe カードを作成できるゲートウェイのみ許可する。
		// ─────────────────────────────────────────────────────────────────
		// 自動課金対応ゲートウェイは Megurio_Payment_Gateway_Integration::AUTO_CHARGE_GATEWAYS
		// で管理しています。現在は Stripe 公式プラグインのみ許可します。
		// ─────────────────────────────────────────────────────────────────
		$allowed_gateways = Megurio_Payment_Gateway_Integration::AUTO_CHARGE_GATEWAYS;

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			if ( ! in_array( $gateway_id, $allowed_gateways, true ) ) {
				unset( $available_gateways[ $gateway_id ] );
			}
		}

		if ( ! empty( $available_gateways ) ) {
			return $available_gateways;
		}

		if ( function_exists( 'wc_has_notice' ) && ! wc_has_notice( __( 'Please enable Stripe card payment for subscription products.', 'megurio-subscriptions-for-woocommerce' ), 'error' ) ) {
			wc_add_notice( __( 'Please enable Stripe card payment for subscription products.', 'megurio-subscriptions-for-woocommerce' ), 'error' );
		}

		return array();
	}

	/**
	 * 定期購入を含む注文で Stripe の「カードを保存」フラグを強制的に立てます。
	 *
	 * @return void
	 */
	public function force_save_payment_method_for_subscription() {
		if ( ! $this->cart_has_subscription_product() ) {
			return;
		}

		$gateway_id = WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
		if ( ! $gateway_id && isset( $_POST['payment_method'] ) ) {
			$gateway_id = wc_clean( wp_unslash( $_POST['payment_method'] ) );
		}

		if ( ! $this->gateway_integration->is_auto_charge_gateway( $gateway_id ) ) {
			return;
		}

		// Stripe が読み取る「カードを保存する」POST フラグを強制的にセットする。
		$_POST[ 'wc-' . $gateway_id . '-new-payment-method' ] = '1';
	}

	/**
	 * 定期購入の Stripe UPE をカードのみに制限します。
	 *
	 * @param array $stripe_params Stripe UPE の JS 設定。
	 * @return array
	 */
	public function limit_stripe_upe_to_card_for_subscription( $stripe_params ) {
		if ( ! $this->is_subscription_checkout_context() || ! is_array( $stripe_params ) ) {
			return $stripe_params;
		}

		if ( isset( $stripe_params['paymentMethodsConfig'] ) ) {
			$stripe_params['excludedPaymentMethodTypes'] = $this->merge_excluded_payment_method_types(
				isset( $stripe_params['excludedPaymentMethodTypes'] ) ? $stripe_params['excludedPaymentMethodTypes'] : array(),
				$this->get_non_card_payment_method_ids( $stripe_params['paymentMethodsConfig'] )
			);
			$stripe_params['paymentMethodsConfig'] = $this->card_only_payment_methods_config( $stripe_params['paymentMethodsConfig'] );
		}

		$stripe_params['isExpressCheckoutEnabled'] = false;
		$stripe_params['isAmazonPayEnabled']       = false;
		$stripe_params['isLinkEnabled']            = false;

		return $stripe_params;
	}

	/**
	 * 定期購入の Stripe PaymentIntent 作成リクエストをカードのみに制限します。
	 *
	 * @param array    $request         PaymentIntent 作成リクエスト。
	 * @param WC_Order $order           注文。
	 * @param mixed    $prepared_source Stripe ソース情報。
	 * @return array
	 */
	public function limit_stripe_intent_to_card_for_subscription( $request, $order, $prepared_source = null ) {
		if ( ! $this->is_subscription_payment_context( $order ) || ! is_array( $request ) ) {
			return $request;
		}

		$request['payment_method_types'] = array( 'card' );
		unset( $request['automatic_payment_methods'] );

		return $request;
	}

	/**
	 * 定期購入カートでは Stripe のエクスプレスチェックアウトを非表示にします。
	 *
	 * @param bool $show 表示可否。
	 * @return bool
	 */
	public function hide_subscription_express_checkout( $show ) {
		return $this->is_subscription_checkout_context() ? false : $show;
	}

	/**
	 * 定期購入商品ページでは Stripe のエクスプレスチェックアウトを非表示にします。
	 *
	 * @param bool  $hide 非表示にするか。
	 * @param mixed $post 商品投稿。
	 * @return bool
	 */
	public function hide_stripe_subscription_product_express_checkout( $hide, $post = null ) {
		if ( $this->is_subscription_checkout_context() || $this->is_subscription_context_product( $post ) ) {
			return true;
		}

		return $hide;
	}

	/**
	 * 定期購入では Stripe の保存フラグを強制します。
	 *
	 * @param bool $force_save 保存を強制するか。
	 * @param int  $order_id   注文 ID。
	 * @return bool
	 */
	public function force_stripe_save_payment_method_for_subscription( $force_save, $order_id = 0 ) {
		if ( $order_id ? $this->is_subscription_payment_context( $order_id ) : $this->is_subscription_checkout_context() ) {
			return true;
		}

		return $force_save;
	}

	/**
	 * 定期購入では「カードを保存」チェックボックスを表示せず、内部的に保存します。
	 *
	 * @param bool $display 表示可否。
	 * @return bool
	 */
	public function hide_save_payment_method_checkbox_for_subscription( $display ) {
		return $this->is_subscription_checkout_context() ? false : $display;
	}

	/**
	 * 定期購入の WooPayments 支払いフィールドをカードのみに制限します。
	 *
	 * @param array $config WooPayments の JS 設定。
	 * @return array
	 */
	public function limit_wcpay_fields_to_card_for_subscription( $config ) {
		if ( ! $this->is_subscription_checkout_context() || ! is_array( $config ) ) {
			return $config;
		}

		if ( isset( $config['paymentMethodsConfig'] ) ) {
			$config['paymentMethodsConfig'] = $this->card_only_payment_methods_config( $config['paymentMethodsConfig'] );
		}

		$config['isPaymentRequestEnabled']                  = false;
		$config['isAmazonPayEnabled']                       = false;
		$config['isExpressCheckoutInPaymentMethodsEnabled'] = false;
		$config['isWooPayEnabled']                          = false;
		$config['shouldShowWooPayButton']                   = false;
		$config['isWooPayEmailInputEnabled']                = false;

		return $config;
	}

	/**
	 * 定期購入カートでは WooPayments のエクスプレスチェックアウト設定を空にします。
	 *
	 * @param array $params エクスプレスチェックアウト JS 設定。
	 * @return array
	 */
	public function disable_wcpay_express_checkout_for_subscription( $params ) {
		if ( ! $this->is_subscription_checkout_context() || ! is_array( $params ) ) {
			return $params;
		}

		$params['enabled_methods'] = array();

		return $params;
	}

	/**
	 * 定期購入カートでは WooPayments の Payment Request を無効化します。
	 *
	 * @param bool $is_supported 利用可否。
	 * @param mixed $product     商品。
	 * @return bool
	 */
	public function disable_wcpay_subscription_cart_express_checkout( $is_supported, $product = null ) {
		if ( $this->is_subscription_checkout_context() || $this->is_subscription_context_product( $product ) ) {
			return false;
		}

		return $is_supported;
	}

	/**
	 * 定期購入商品ページでは WooPayments の Payment Request を無効化します。
	 *
	 * @param bool  $is_supported 利用可否。
	 * @param mixed $product      商品。
	 * @return bool
	 */
	public function disable_wcpay_subscription_product_express_checkout( $is_supported, $product = null ) {
		if ( $this->is_subscription_context_product( $product ) ) {
			return false;
		}

		return $is_supported;
	}

	/**
	 * 定期購入の WooPayments API リクエストをカードのみに制限します。
	 *
	 * @param array  $params API リクエストパラメータ。
	 * @param string $api    API パス。
	 * @param string $method HTTP メソッド。
	 * @return array
	 */
	public function limit_wcpay_api_request_to_card_for_subscription( $params, $api = '', $method = '' ) {
		if ( ! is_array( $params ) || ! $this->is_wcpay_subscription_request_context( $params ) ) {
			return $params;
		}

		$params['payment_method_types'] = array( 'card' );
		unset( $params['automatic_payment_methods'] );

		return $params;
	}

	/**
	 * Checkout 送信時の念のためのチェック。定期購入では card 以外を拒否します。
	 *
	 * @return void
	 */
	public function validate_subscription_card_payment_method() {
		if ( ! $this->cart_has_subscription_product() ) {
			return;
		}

		$gateway_id = $this->get_posted_scalar_value( 'payment_method' );
		if ( ! in_array( $gateway_id, Megurio_Payment_Gateway_Integration::AUTO_CHARGE_GATEWAYS, true ) ) {
			wc_add_notice( __( 'Only Stripe card payment is available for subscriptions.', 'megurio-subscriptions-for-woocommerce' ), 'error' );
			return;
		}

		$posted_payment_types = array_filter(
			array(
				$this->get_posted_scalar_value( 'wc-stripe-payment-type' ),
				$this->get_posted_scalar_value( 'selected_upe_payment_type' ),
				$this->get_posted_scalar_value( 'express_payment_type' ),
				$this->get_posted_scalar_value( 'wcpay-express-payment-type' ),
			)
		);

		foreach ( $posted_payment_types as $payment_type ) {
			if ( 'card' !== $payment_type ) {
				wc_add_notice( __( 'Only card payment is available for subscriptions. Please enter your card information.', 'megurio-subscriptions-for-woocommerce' ), 'error' );
				return;
			}
		}
	}

	/**
	 * 前台の定期購入案内用アセットを読み込みます。
	 *
	 * @return void
	 */
	public function enqueue_front_assets() {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}

		if ( ! is_shop() && ! is_product_taxonomy() && ! is_product() && ! is_cart() && ! is_checkout() && ! is_account_page() ) {
			return;
		}

		wp_enqueue_style(
			'megurio-front',
			plugins_url( 'assets/css/front.css', dirname( __DIR__ ) . '/megurio-subscriptions-for-woocommerce.php' ),
			array(),
			self::PLUGIN_VERSION
		);

		wp_enqueue_script(
			'megurio-front',
			plugins_url( 'assets/js/front.js', dirname( __DIR__ ) . '/megurio-subscriptions-for-woocommerce.php' ),
			array(),
			self::PLUGIN_VERSION,
			true
		);

		// Express Checkout（Apple Pay / Google Pay）注意文の表示制御データを JS へ渡す。
		// wp_enqueue_scripts 実行時は global $product が未設定のため
		// get_queried_object_id() で商品 ID を取得する。
		$is_subscription_product = false;
		if ( is_product() ) {
			$product_id = get_queried_object_id();
			if ( $product_id ) {
				$is_subscription_product = $this->is_subscription_product( $product_id );
			}
		}

		wp_localize_script(
			'megurio-front',
			'megurio_params',
			array(
				'is_subscription_context' => is_product() ? $is_subscription_product : $this->cart_has_subscription_product(),
				'express_notice_text'     => __( '* Apple Pay and Google Pay do not support automatic renewal for subscriptions. Please enter your card information directly.', 'megurio-subscriptions-for-woocommerce' ),
			)
		);
	}

	/**
	 * 商品タイプ行のチェックボックスに定期購入を追加します。
	 *
	 * WooCommerce の既存配列に追加することで、
	 * バーチャルとダウンロード可能を壊さずに表示します。
	 *
	 * @param array $options 既存のオプション定義。
	 * @return array
	 */
	public function add_product_type_option( $options ) {
		$new_options = array();

		foreach ( $options as $key => $option ) {
			$new_options[ $key ] = $option;

			if ( '_downloadable' === $key ) {
				$new_options['megurio_is_subscription'] = array(
					'id'            => '_megurio_is_subscription',
					'wrapper_class' => 'show_if_simple show_if_variable',
					'label'         => __( 'Subscription', 'megurio-subscriptions-for-woocommerce' ),
					'description'   => __( 'Check to treat this product as a subscription product.', 'megurio-subscriptions-for-woocommerce' ),
					'default'       => 'no',
				);
			}
		}

		if ( ! isset( $new_options['megurio_is_subscription'] ) ) {
			$new_options['megurio_is_subscription'] = array(
				'id'            => '_megurio_is_subscription',
				'wrapper_class' => 'show_if_simple show_if_variable',
				'label'         => __( 'Subscription', 'megurio-subscriptions-for-woocommerce' ),
				'description'   => __( 'Check to treat this product as a subscription product.', 'megurio-subscriptions-for-woocommerce' ),
				'default'       => 'no',
			);
		}

		return $new_options;
	}

	/**
	 * 定期購入レコード用の注文タイプを登録します。
	 *
	 * @return void
	 */
	public function register_subscription_order_type() {
		if ( ! function_exists( 'wc_register_order_type' ) ) {
			return;
		}

		wc_register_order_type(
			self::SUBSCRIPTION_TYPE,
			array(
				'labels' => array(
					'name'          => __( 'Megurio Subscriptions', 'megurio-subscriptions-for-woocommerce' ),
					'singular_name' => __( 'Megurio Subscription', 'megurio-subscriptions-for-woocommerce' ),
					'menu_name'     => __( 'Megurio Subscriptions', 'megurio-subscriptions-for-woocommerce' ),
				),
				'public'                           => false,
				'show_ui'                          => true,
				'capability_type'                  => 'shop_order',
				'map_meta_cap'                     => true,
				'publicly_queryable'               => false,
				'exclude_from_search'              => true,
				'show_in_menu'                     => false,
				'hierarchical'                     => false,
				'show_in_nav_menus'                => false,
				'rewrite'                          => false,
				'query_var'                        => false,
				'supports'                         => array( 'title', 'comments', 'custom-fields' ),
				'has_archive'                      => false,
				'exclude_from_orders_screen'       => true,
				'add_order_meta_boxes'             => true,
				'exclude_from_order_count'         => true,
				'exclude_from_order_views'         => true,
				'exclude_from_order_webhooks'      => true,
				'exclude_from_order_reports'       => true,
				'exclude_from_order_sales_reports' => true,
				'class_name'                       => 'Megurio_Subscription_Order',
			)
		);
	}

	/**
	 * 30 分間隔の WP-Cron 用スケジュールを追加します。
	 *
	 * @param array $schedules 既存スケジュール一覧。
	 * @return array
	 */
	public function register_cron_interval( $schedules ) {
		$schedules['megurio_every_thirty_minutes'] = array(
			'interval' => 1800,
			'display'  => __( 'Every 30 Minutes', 'megurio-subscriptions-for-woocommerce' ),
		);

		return $schedules;
	}

	/**
	 * 更新注文作成の定期処理を登録します。
	 *
	 * @return void
	 */
	public function register_cron() {
		if ( class_exists( 'ActionScheduler' ) && function_exists( 'as_next_scheduled_action' ) ) {
			if ( false === as_next_scheduled_action( self::ACTION_CREATE_RENEWALS ) ) {
				as_schedule_recurring_action( time() + 1800, 1800, self::ACTION_CREATE_RENEWALS );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::ACTION_CREATE_RENEWALS ) ) {
			wp_schedule_event( time() + 1800, 'megurio_every_thirty_minutes', self::ACTION_CREATE_RENEWALS );
		}
	}

	/**
	 * 商品編集画面に最小限の定期購入設定を追加します。
	 *
	 * @return void
	 */
	public function render_product_fields() {
		echo '<div class="options_group megurio-subscription-fields">';

		woocommerce_wp_text_input(
			array(
				'id'                => '_megurio_interval_count',
				'label'             => __( 'Renewal Interval Number', 'megurio-subscriptions-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => 1,
					'step' => 1,
				),
				'description'       => __( 'e.g. 1', 'megurio-subscriptions-for-woocommerce' ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'          => '_megurio_interval_unit',
				'label'       => __( 'Renewal Interval Unit', 'megurio-subscriptions-for-woocommerce' ),
				'options'     => array(
					'day'   => __( 'day', 'megurio-subscriptions-for-woocommerce' ),
					'week'  => __( 'week', 'megurio-subscriptions-for-woocommerce' ),
					'month' => __( 'month', 'megurio-subscriptions-for-woocommerce' ),
					'year'  => __( 'year', 'megurio-subscriptions-for-woocommerce' ),
				),
				'description' => __( 'e.g. For monthly billing, enter "1" and select "month".', 'megurio-subscriptions-for-woocommerce' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_megurio_signup_fee',
				'label'             => __( 'Sign-up Fee', 'megurio-subscriptions-for-woocommerce' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'description' => __( 'One-time fee charged on the first order only. Leave blank or 0 for no sign-up fee.', 'megurio-subscriptions-for-woocommerce' ),
				'desc_tip'    => true,
			)
		);

		echo '</div>';
	}

	/**
	 * 商品編集画面で定期購入チェックの位置調整と表示制御を行います。
	 *
	 * @return void
	 */
	public function enqueue_admin_scripts() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_post_type = isset( $screen->post_type ) ? $screen->post_type : '';
		if ( ! $screen || 'product' !== $screen_post_type ) {
			return;
		}

		wp_enqueue_script(
			'megurio-admin-product',
			plugins_url( 'assets/js/admin-product.js', dirname( __DIR__ ) . '/megurio-subscriptions-for-woocommerce.php' ),
			array( 'jquery' ),
			self::PLUGIN_VERSION,
			true
		);
	}

	/**
	 * 商品の定期購入設定を保存します。
	 *
	 * @param int $product_id 商品 ID。
	 * @return void
	 */
	public function save_product_fields( $product_id ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		$is_subscription = isset( $_POST['_megurio_is_subscription'] ) ? 'yes' : 'no';

		$interval_count = isset( $_POST['_megurio_interval_count'] ) ? absint( wp_unslash( $_POST['_megurio_interval_count'] ) ) : 1;
		$interval_unit  = isset( $_POST['_megurio_interval_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['_megurio_interval_unit'] ) ) : 'month';
		$signup_fee     = isset( $_POST['_megurio_signup_fee'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_megurio_signup_fee'] ) ) ) : '';

		$this->set_product_meta_bulk(
			$product_id,
			array(
				'_megurio_is_subscription' => $is_subscription,
				'_megurio_interval_count'  => max( 1, $interval_count ),
				'_megurio_interval_unit'   => $interval_unit,
				'_megurio_signup_fee'      => $signup_fee,
			)
		);
	}

	/**
	 * 注文確定直後に定期購入レコードを作成します。
	 *
	 * @param int             $order_id    注文 ID。
	 * @param array|string    $posted_data チェックアウト送信値。
	 * @param WC_Order|false  $order       注文オブジェクト。
	 * @return void
	 */
	public function create_subscriptions_from_order( $order_id, $posted_data = array(), $order = false ) {
		if ( $order_id instanceof WC_Order ) {
			$order = $order_id;
		}

		if ( $order instanceof WC_Order && empty( $order_id ) ) {
			$order_id = $order->get_id();
		}

		if ( ! $order instanceof WC_Order && ! empty( $order_id ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order_id = $order->get_id();

		if ( self::SUBSCRIPTION_TYPE === $order->get_type() ) {
			return;
		}

		if ( 'yes' === $this->get_object_meta( $order_id, '_megurio_has_subscription' ) ) {
			return;
		}

			if ( ! empty( $this->get_subscription_ids_by_parent_order( $order_id, array( 'pending', 'active', 'on-hold', 'cancelled' ) ) ) ) {
				$this->update_object_meta( $order_id, '_megurio_has_subscription', 'yes' );
				return;
			}

		$created = false;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$product_id = $product ? $product->get_id() : 0;

			if ( ! $product || ! $this->is_subscription_product( $product_id ) ) {
				continue;
			}

			$subscription_id = $this->create_subscription_record( $order, $item, $product );

			if ( $subscription_id ) {
				$created = true;
			}
		}

		if ( $created ) {
			$this->update_object_meta( $order_id, '_megurio_has_subscription', 'yes' );
		}
	}

	/**
	 * 定期購入レコードを 1 件作成します。
	 *
	 * @param WC_Order         $order   親注文。
	 * @param WC_Order_Item_Product $item    注文明細。
	 * @param WC_Product       $product 商品。
	 * @return int
	 */
	protected function create_subscription_record( WC_Order $order, WC_Order_Item_Product $item, WC_Product $product ) {
		$subscription = new Megurio_Subscription_Order();
		$subscription->set_status( 'pending' );
		$subscription->set_parent_id( $order->get_id() );
		$subscription->set_customer_id( $order->get_customer_id() );
		$subscription->set_created_via( 'megurio' );
		$subscription->set_currency( $order->get_currency() );
		$subscription->set_prices_include_tax( $order->get_prices_include_tax() );
		$subscription->save();

		$post_id = $subscription->get_id();
		if ( ! $post_id ) {
			return 0;
		}

		$subscription->set_address( $order->get_address( 'billing' ), 'billing' );
		$subscription->set_address( $order->get_address( 'shipping' ), 'shipping' );
		$subscription->set_currency( $order->get_currency() );
		$subscription->set_payment_method( $order->get_payment_method() );
		$subscription->set_payment_method_title( $order->get_payment_method_title() );
		$subscription->set_customer_id( $order->get_customer_id() );

		$variation_attributes = method_exists( $item, 'get_variation_attributes' ) ? $item->get_variation_attributes() : array();

		$subscription->add_product(
			$product,
			$item->get_quantity(),
			array(
				'variation' => $variation_attributes,
				'totals'    => array(
					'subtotal'     => $item->get_subtotal(),
					'subtotal_tax' => $item->get_subtotal_tax(),
					'total'        => $item->get_total(),
					'tax'          => $item->get_total_tax(),
					'tax_data'     => $item->get_taxes(),
				),
			)
		);

		$subscription->calculate_totals();
		$subscription->save();

		$meta_product_id = $this->get_meta_product_id( $product->get_id() );

		$this->set_subscription_meta( $post_id, array(
			'_megurio_parent_order_id'     => $order->get_id(),
			'_megurio_customer_id'         => $order->get_customer_id(),
			'_megurio_product_id'          => $product->get_id(),
			'_megurio_product_name'        => $product->get_name(),
			'_megurio_payment_method'      => $order->get_payment_method(),
			'_megurio_payment_method_title' => $order->get_payment_method_title(),
			'_megurio_product_qty'         => $item->get_quantity(),
			'_megurio_interval_count'      => max( 1, absint( $this->get_product_meta( $meta_product_id, '_megurio_interval_count' ) ) ),
			'_megurio_interval_unit'       => $this->get_product_meta( $meta_product_id, '_megurio_interval_unit' ),
			'_megurio_signup_fee'          => (float) $this->get_product_meta( $meta_product_id, '_megurio_signup_fee' ),
			'_megurio_subscription_status' => 'pending',
			'_megurio_schedule_start'      => 0,
			'_megurio_next_payment'        => 0,
			'_megurio_last_renewal_order'  => 0,
			'_megurio_line_subtotal'       => $item->get_subtotal(),
			'_megurio_line_subtotal_tax'   => $item->get_subtotal_tax(),
			'_megurio_line_total'          => $item->get_total(),
			'_megurio_line_tax'            => $item->get_total_tax(),
			'_megurio_line_tax_data'       => wp_json_encode( $item->get_taxes() ),
		) );

		$this->update_object_meta( $order->get_id(), '_megurio_subscription_id', $post_id );
		$order->add_order_note( sprintf( __( 'Subscription record #%d created.', 'megurio-subscriptions-for-woocommerce' ), $post_id ) );
		$subscription->add_order_note( sprintf( __( 'Created from parent order #%d.', 'megurio-subscriptions-for-woocommerce' ), $order->get_id() ) );

		return $post_id;
	}

	/**
	 * 注文状態の変化に応じて定期購入状態を動かします。
	 *
	 * @param int    $order_id    注文 ID。
	 * @param string $old_status  変更前。
	 * @param string $new_status  変更後。
	 * @return void
	 */
	public function handle_order_status_change( $order_id, $old_status, $new_status ) {
		if ( $old_status === $new_status ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'yes' === $order->get_meta( '_megurio_is_renewal_order', true ) ) {
			$this->handle_renewal_order_status_change( $order, $new_status );
			return;
		}

		if ( 'yes' !== $this->get_object_meta( $order_id, '_megurio_has_subscription' ) ) {
			return;
		}

		if ( in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
			$this->activate_pending_subscriptions( $order );
		} elseif ( 'cancelled' === $new_status ) {
			$this->cancel_parent_order_subscriptions( $order );
		}
	}

	/**
	 * 初回注文の成功時に定期購入を有効化します。
	 *
	 * @param WC_Order $order 親注文。
	 * @return void
	 */
	protected function activate_pending_subscriptions( WC_Order $order ) {
		$subscription_ids = $this->get_subscription_ids_by_parent_order( $order->get_id(), array( 'pending' ) );

		if ( empty( $subscription_ids ) ) {
			return;
		}

		$current_time = current_time( 'timestamp' );

		foreach ( $subscription_ids as $subscription_id ) {
			$next_payment = $this->calculate_next_payment( $subscription_id, $current_time );

			$this->set_subscription_meta( $subscription_id, array(
				'_megurio_subscription_status' => 'active',
				'_megurio_schedule_start'      => $current_time,
				'_megurio_next_payment'        => $next_payment,
				'_megurio_payment_method'      => $order->get_payment_method(),
				'_megurio_payment_method_title' => $order->get_payment_method_title(),
			) );

			// 自動課金ゲートウェイの場合、将来の更新課金に使う決済トークンを保存する。
			$this->gateway_integration->save_payment_token_from_order( $subscription_id, $order );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->set_payment_method( $order->get_payment_method() );
				$subscription->set_payment_method_title( $order->get_payment_method_title() );
				$subscription->save();
				$subscription->add_order_note( __( 'Subscription activated after confirming initial order payment.', 'megurio-subscriptions-for-woocommerce' ) );

				// トークンが保存されなかった場合（Apple Pay / Google Pay など）は管理者に警告する。
				if ( $this->gateway_integration->is_auto_charge_gateway( $order->get_payment_method() )
					&& ! $this->get_object_meta( $subscription_id, '_megurio_payment_token_id' )
				) {
					$subscription->add_order_note(
						__( '⚠️ Initial order was paid via express checkout (e.g. Apple Pay / Google Pay), so a reusable payment token could not be saved. Automatic renewal may fail. Please ask the customer to update their payment method by entering card details directly.', 'megurio-subscriptions-for-woocommerce' )
					);
				}
			}
		}
	}

	/**
	 * 更新注文の状態変化を定期購入に反映します。
	 *
	 * @param WC_Order $renewal_order 更新注文。
	 * @param string   $new_status    変更後の状態。
	 * @return void
	 */
	protected function handle_renewal_order_status_change( WC_Order $renewal_order, $new_status ) {
		$subscription_id = absint( $renewal_order->get_meta( '_megurio_subscription_id', true ) );
		if ( ! $subscription_id ) {
			return;
		}

		if ( 'failed' === $new_status ) {
			$subscription = wc_get_order( $subscription_id );
			if ( ! $this->gateway_integration->is_auto_charge_gateway( $renewal_order->get_payment_method() ) ) {
				$this->pause_subscription_for_unsupported_payment_method( $subscription_id, $renewal_order->get_payment_method() );
				if ( $subscription instanceof WC_Order ) {
					$subscription->add_order_note( sprintf( __( 'Renewal order #%d failed due to unsupported payment method. Subscription has been paused.', 'megurio-subscriptions-for-woocommerce' ), $renewal_order->get_id() ) );
				}
				return;
			}

			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( sprintf( __( 'Renewal order #%d payment failed. Subscription remains active during grace period.', 'megurio-subscriptions-for-woocommerce' ), $renewal_order->get_id() ) );
			}

			$this->handle_renewal_payment_retry( $renewal_order, $subscription_id );

			return;
		}

		if ( 'cancelled' === $new_status ) {
			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( sprintf( __( 'Renewal order #%d was cancelled, but subscription status was not automatically changed. Please cancel manually if needed.', 'megurio-subscriptions-for-woocommerce' ), $renewal_order->get_id() ) );
			}

			return;
		}

		if ( in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
			// 支払い成功：スケジュール済みのリトライをすべてキャンセルする。
			$this->cancel_renewal_retries( $renewal_order->get_id() );

			// order-pay 画面で新しいカードが使われた場合、次回以降の自動更新用トークンを更新する。
			$this->gateway_integration->save_payment_token_from_order( $subscription_id, $renewal_order );

			$current_status = (string) $this->get_object_meta( $subscription_id, '_megurio_subscription_status' );
			$current_time = current_time( 'timestamp' );
			$next_payment = $this->calculate_next_payment( $subscription_id, $current_time );

			$this->set_subscription_meta( $subscription_id, array(
				'_megurio_subscription_status' => 'active',
				'_megurio_next_payment'        => $next_payment,
			) );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				if ( 'active' === $current_status ) {
					$subscription->add_order_note( sprintf( __( 'Subscription continued after confirming renewal order #%d payment.', 'megurio-subscriptions-for-woocommerce' ), $renewal_order->get_id() ) );
				} else {
					$subscription->add_order_note( sprintf( __( 'Subscription reactivated after confirming renewal order #%d payment.', 'megurio-subscriptions-for-woocommerce' ), $renewal_order->get_id() ) );
				}
			}

			if ( 'active' !== $current_status ) {
				$this->send_subscription_reactivated_email( $subscription_id, 'renewal-order' );
			}
		}
	}

	/**
	 * 親注文がキャンセルされたら関連定期購入もキャンセルします。
	 *
	 * @param WC_Order $order 親注文。
	 * @return void
	 */
	protected function cancel_parent_order_subscriptions( WC_Order $order ) {
		$subscription_ids = $this->get_subscription_ids_by_parent_order( $order->get_id(), array( 'pending', 'active', 'on-hold' ) );

		foreach ( $subscription_ids as $subscription_id ) {
			$this->set_subscription_meta( $subscription_id, array(
				'_megurio_subscription_status' => 'cancelled',
				'_megurio_next_payment'        => 0,
			) );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( __( 'Subscription cancelled because parent order was cancelled.', 'megurio-subscriptions-for-woocommerce' ) );
			}
			$this->send_subscription_cancel_email( $subscription_id, 'parent-order' );
		}
	}

	/**
	 * 更新課金失敗時のリトライロジックを制御します。
	 *
	 * リトライ残数がある場合はスケジュールと通知メールを送り、
	 * 上限に達した場合は最終失敗メールを送って自動リトライを停止します。
	 *
	 * @param WC_Order $renewal_order   失敗した更新注文。
	 * @param int      $subscription_id 定期購入 ID。
	 * @return void
	 */
	protected function handle_renewal_payment_retry( WC_Order $renewal_order, $subscription_id ) {
		$retry_count = (int) $renewal_order->get_meta( '_megurio_retry_count', true );

		if ( $retry_count < self::RENEWAL_MAX_RETRIES ) {
			$retry_count++;
			$intervals    = self::RENEWAL_RETRY_INTERVALS;
			$interval_days = isset( $intervals[ $retry_count - 1 ] ) ? $intervals[ $retry_count - 1 ] : 2;

			$renewal_order->update_meta_data( '_megurio_retry_count', $retry_count );
			$renewal_order->save();

			$this->schedule_renewal_retry( $renewal_order->get_id(), $interval_days );
			$this->send_renewal_payment_failed_email( $renewal_order, $retry_count, $interval_days );

			$renewal_order->add_order_note( sprintf(
				/* translators: 1: current retry count, 2: max retries, 3: days until next retry */
				__( 'Retry %1$d/%2$d scheduled for %3$d days later.', 'megurio-subscriptions-for-woocommerce' ),
				$retry_count,
				self::RENEWAL_MAX_RETRIES,
				$interval_days
			) );
		} else {
			$renewal_order->update_meta_data( '_megurio_retry_exhausted', 'yes' );
			$renewal_order->save();

			$this->set_subscription_meta( $subscription_id, array(
				'_megurio_subscription_status' => 'on-hold',
				'_megurio_next_payment'        => 0,
			) );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( sprintf(
					/* translators: 1: renewal order ID, 2: max retries */
					__( 'Automatic retries for renewal order #%1$d reached %2$d. Subscription paused, no further auto-charging.', 'megurio-subscriptions-for-woocommerce' ),
					$renewal_order->get_id(),
					self::RENEWAL_MAX_RETRIES
				) );
			}

			$this->send_renewal_payment_exhausted_email( $renewal_order );
		}
	}

	/**
	 * リトライをスケジュールします。
	 *
	 * @param int $renewal_order_id 更新注文 ID。
	 * @param int $interval_days    次回リトライまでの日数。
	 * @return void
	 */
	protected function schedule_renewal_retry( $renewal_order_id, $interval_days ) {
		$timestamp = current_time( 'timestamp' ) + ( absint( $interval_days ) * DAY_IN_SECONDS );
		$args      = array( absint( $renewal_order_id ) );

		if ( class_exists( 'ActionScheduler' ) && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, self::ACTION_RETRY_RENEWAL, $args );
		} else {
			wp_schedule_single_event( $timestamp, self::ACTION_RETRY_RENEWAL, $args );
		}
	}

	/**
	 * 指定した更新注文に紐づくリトライスケジュールをすべてキャンセルします。
	 *
	 * @param int $renewal_order_id 更新注文 ID。
	 * @return void
	 */
	protected function cancel_renewal_retries( $renewal_order_id ) {
		$args = array( absint( $renewal_order_id ) );

		if ( class_exists( 'ActionScheduler' ) && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_RETRY_RENEWAL, $args );
		} else {
			wp_clear_scheduled_hook( self::ACTION_RETRY_RENEWAL, $args );
		}
	}

	/**
	 * スケジュールされたリトライを実行します。
	 *
	 * 注文を「保留中」にリセットしてから再度課金を試みます。
	 * 結果は woocommerce_order_status_changed 経由で通常フローに戻ります。
	 *
	 * @param int $renewal_order_id 更新注文 ID。
	 * @return void
	 */
	public function run_retry_renewal_payment( $renewal_order_id ) {
		$renewal_order = wc_get_order( absint( $renewal_order_id ) );
		if ( ! $renewal_order instanceof WC_Order ) {
			return;
		}

		if ( 'yes' === $renewal_order->get_meta( '_megurio_retry_exhausted', true ) ) {
			return;
		}

		$retry_count = (int) $renewal_order->get_meta( '_megurio_retry_count', true );

		// 再試行のため注文を保留中にリセットする。
		// これにより自動決済処理が再度課金を試みられる状態になる。
		$renewal_order->set_status( 'pending' );
		$renewal_order->add_order_note( sprintf(
			/* translators: 1: current retry count, 2: max retries */
			__( 'Starting auto-retry (%1$d/%2$d).', 'megurio-subscriptions-for-woocommerce' ),
			$retry_count,
			self::RENEWAL_MAX_RETRIES
		) );
		$renewal_order->save();

		$subscription_id = absint( $renewal_order->get_meta( '_megurio_subscription_id', true ) );
		$this->gateway_integration->process_renewal_payment( $subscription_id, $renewal_order );
	}

	/**
	 * 次回請求日を過ぎた定期購入に対して更新注文を作成します。
	 *
	 * @return void
	 */
	public function run_renewal_scheduler() {
		$current_time = current_time( 'timestamp' );
		$processed    = 0;

		$subscription_ids = wc_get_orders(
			array(
				'limit'      => -1,
				'type'       => self::SUBSCRIPTION_TYPE,
				'return'     => 'ids',
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => '_megurio_subscription_status',
						'value' => 'active',
					),
					array(
						'key'     => '_megurio_next_payment',
						'value'   => $current_time,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $subscription_ids as $subscription_id ) {
			if ( $this->has_unpaid_renewal_order( $subscription_id ) ) {
				continue;
			}

			if ( $this->create_renewal_order( $subscription_id, $current_time ) ) {
				$processed++;
			}
		}

		return $processed;
	}

	/**
	 * 未決済の更新注文が残っているかを返します。
	 *
	 * @param int $subscription_id 定期購入 ID。
	 * @return bool
	 */
	protected function has_unpaid_renewal_order( $subscription_id ) {
		$renewal_order = $this->get_last_renewal_order( $subscription_id );
		if ( ! $renewal_order instanceof WC_Order ) {
			return false;
		}

		return ! in_array( $renewal_order->get_status(), array( 'processing', 'completed', 'cancelled', 'refunded' ), true );
	}

	/**
	 * 対応外の支払い方法が設定された定期購入を停止します。
	 *
	 * @param int    $subscription_id 定期購入 ID。
	 * @param string $payment_method  支払い方法 ID。
	 * @return void
	 */
	protected function pause_subscription_for_unsupported_payment_method( $subscription_id, $payment_method = '' ) {
		$this->set_subscription_meta( $subscription_id, array(
			'_megurio_subscription_status' => 'on-hold',
			'_megurio_next_payment'        => 0,
		) );

		$subscription = wc_get_order( $subscription_id );
		if ( $subscription instanceof WC_Order ) {
			$subscription->add_order_note( sprintf(
				/* translators: %s: payment method ID */
				__( 'Payment method %s is not supported for subscriptions and has been paused. Please change to Stripe card payment.', 'megurio-subscriptions-for-woocommerce' ),
				$payment_method ? $payment_method : '-'
			) );
		}
	}

	/**
	 * 最後に作成された更新注文を取得します。
	 *
	 * @param int $subscription_id 定期購入 ID。
	 * @return WC_Order|null
	 */
	protected function get_last_renewal_order( $subscription_id ) {
		$last_renewal_id = absint( $this->get_object_meta( $subscription_id, '_megurio_last_renewal_order' ) );
		if ( ! $last_renewal_id ) {
			return null;
		}

		$renewal_order = wc_get_order( $last_renewal_id );
		if ( ! $renewal_order instanceof WC_Order ) {
			return null;
		}

		if ( 'yes' !== $renewal_order->get_meta( '_megurio_is_renewal_order', true ) ) {
			return null;
		}

		if ( absint( $renewal_order->get_meta( '_megurio_subscription_id', true ) ) !== absint( $subscription_id ) ) {
			return null;
		}

		return $renewal_order;
	}

	/**
	 * 定期購入の親注文を取得します。
	 *
	 * @param int           $subscription_id 定期購入 ID。
	 * @param WC_Order|null $subscription    定期購入注文。
	 * @return WC_Order|null
	 */
	protected function get_subscription_parent_order( $subscription_id, $subscription = null ) {
		$parent_order_id = absint( $this->get_object_meta( $subscription_id, '_megurio_parent_order_id' ) );

		if ( ! $parent_order_id && $subscription instanceof WC_Order ) {
			$parent_order_id = absint( $subscription->get_parent_id() );
		}

		if ( ! $parent_order_id ) {
			return null;
		}

		$parent_order = wc_get_order( $parent_order_id );
		return $parent_order instanceof WC_Order ? $parent_order : null;
	}

	/**
	 * 定期購入の支払い方法 ID を取得します。
	 *
	 * @param int           $subscription_id 定期購入 ID。
	 * @param WC_Order|null $subscription    定期購入注文。
	 * @return string
	 */
	protected function get_subscription_payment_method( $subscription_id, $subscription = null ) {
		$subscription = $subscription instanceof WC_Order ? $subscription : wc_get_order( $subscription_id );
		$method       = '';

		if ( $subscription instanceof WC_Order ) {
			$method = (string) $subscription->get_payment_method();
		}

		if ( ! $method ) {
			$method = (string) $this->get_object_meta( $subscription_id, '_megurio_payment_method' );
		}

		if ( ! $method ) {
			$parent_order = $this->get_subscription_parent_order( $subscription_id, $subscription );
			if ( $parent_order instanceof WC_Order ) {
				$method = (string) $parent_order->get_payment_method();
			}
		}

		return $method;
	}

	/**
	 * 定期購入の支払い方法名を取得します。
	 *
	 * @param int           $subscription_id 定期購入 ID。
	 * @param WC_Order|null $subscription    定期購入注文。
	 * @return string
	 */
	protected function get_subscription_payment_method_title( $subscription_id, $subscription = null ) {
		$subscription = $subscription instanceof WC_Order ? $subscription : wc_get_order( $subscription_id );
		$title        = '';

		if ( $subscription instanceof WC_Order ) {
			$title = (string) $subscription->get_payment_method_title();
		}

		if ( ! $title ) {
			$title = (string) $this->get_object_meta( $subscription_id, '_megurio_payment_method_title' );
		}

		if ( ! $title ) {
			$parent_order = $this->get_subscription_parent_order( $subscription_id, $subscription );
			if ( $parent_order instanceof WC_Order ) {
				$title = (string) $parent_order->get_payment_method_title();
			}
		}

		$method = $this->get_subscription_payment_method( $subscription_id, $subscription );
		if ( ! $title && $method && function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( isset( $gateways[ $method ] ) ) {
				if ( method_exists( $gateways[ $method ], 'get_title' ) ) {
					$title = (string) $gateways[ $method ]->get_title();
				} elseif ( isset( $gateways[ $method ]->title ) ) {
					$title = (string) $gateways[ $method ]->title;
				}
			}
		}

		return $title ? $title : $method;
	}

	/**
	 * 管理画面表示用に現在の運用状態を組み立てます。
	 *
	 * @param int $subscription_id 定期購入 ID。
	 * @return array
	 */
	protected function get_subscription_runtime_status( $subscription_id ) {
		$subscription_status = (string) $this->get_object_meta( $subscription_id, '_megurio_subscription_status' );
		$renewal_order       = $this->get_last_renewal_order( $subscription_id );
		$runtime_status      = array(
			'label'                      => __( 'Normal', 'megurio-subscriptions-for-woocommerce' ),
			'description'                => __( 'No unpaid renewal orders.', 'megurio-subscriptions-for-woocommerce' ),
			'is_grace_period'            => false,
			'grace_remaining_label'      => '-',
			'retry_label'                => '-',
			'next_retry_label'           => '-',
			'notice'                     => '',
			'renewal_order_id'           => 0,
			'renewal_order_status'       => '',
			'renewal_order_status_label' => '',
		);

		if ( 'pending' === $subscription_status ) {
			$runtime_status['label']       = __( 'Pending', 'megurio-subscriptions-for-woocommerce' );
			$runtime_status['description'] = __( 'Waiting for initial order confirmation.', 'megurio-subscriptions-for-woocommerce' );
		} elseif ( 'on-hold' === $subscription_status ) {
			$runtime_status['label']       = __( 'On Hold', 'megurio-subscriptions-for-woocommerce' );
			$runtime_status['description'] = __( 'Auto-charging is paused.', 'megurio-subscriptions-for-woocommerce' );
		} elseif ( 'cancelled' === $subscription_status ) {
			$runtime_status['label']       = __( 'Cancelled', 'megurio-subscriptions-for-woocommerce' );
			$runtime_status['description'] = __( 'No further auto-charging.', 'megurio-subscriptions-for-woocommerce' );
		} elseif ( 'active' !== $subscription_status ) {
			$runtime_status['label']       = $subscription_status ? $subscription_status : '-';
			$runtime_status['description'] = '';
		}

		if ( ! $renewal_order instanceof WC_Order ) {
			return $runtime_status;
		}

		$renewal_order_id     = $renewal_order->get_id();
		$renewal_status       = $renewal_order->get_status();
		$retry_count          = (int) $renewal_order->get_meta( '_megurio_retry_count', true );
		$retry_exhausted      = 'yes' === $renewal_order->get_meta( '_megurio_retry_exhausted', true );
		$next_retry_timestamp = $this->get_next_renewal_retry_timestamp( $renewal_order_id );
		$grace_expires_at     = $this->get_grace_period_expires_at( $next_retry_timestamp, $retry_count );
		$is_unpaid_renewal    = ! in_array( $renewal_status, array( 'processing', 'completed', 'cancelled', 'refunded' ), true );

		$runtime_status['renewal_order_id']           = $renewal_order_id;
		$runtime_status['renewal_order_status']       = $renewal_status;
		$runtime_status['renewal_order_status_label'] = wc_get_order_status_name( $renewal_status );

		if ( $retry_count > 0 || $retry_exhausted ) {
			$runtime_status['retry_label'] = sprintf(
				'%d/%d 回',
				min( $retry_count, self::RENEWAL_MAX_RETRIES ),
				self::RENEWAL_MAX_RETRIES
			);
		}

		if ( $next_retry_timestamp ) {
			/* translators: 1: timestamp, 2: remaining time */
			$runtime_status['next_retry_label'] = sprintf(
				__( '%1$s (%2$s remaining)', 'megurio-subscriptions-for-woocommerce' ),
				$this->format_timestamp( $next_retry_timestamp ),
				$this->format_remaining_time( $next_retry_timestamp )
			);
		}

		if ( $is_unpaid_renewal && 'active' === $subscription_status && $retry_count > 0 && ! $retry_exhausted ) {
			$runtime_status['label']           = __( 'Grace Period', 'megurio-subscriptions-for-woocommerce' );
			$runtime_status['description']     = sprintf( __( 'Renewal order #%d payment is incomplete. Service remains active.', 'megurio-subscriptions-for-woocommerce' ), $renewal_order_id );
			$runtime_status['is_grace_period'] = true;
			if ( $grace_expires_at ) {
				/* translators: 1: timestamp, 2: remaining time */
				$runtime_status['grace_remaining_label'] = sprintf(
					__( '%1$s (%2$s remaining)', 'megurio-subscriptions-for-woocommerce' ),
					$this->format_timestamp( $grace_expires_at ),
					$this->format_remaining_time( $grace_expires_at )
				);
			}
			/* translators: 1: retry label, 2: next retry label, 3: grace remaining label */
			$runtime_status['notice'] = sprintf(
				__( 'Renewal payment failed, but service is active during grace period. Retries: %1$s, Next retry: %2$s, Grace remaining: %3$s.', 'megurio-subscriptions-for-woocommerce' ),
				$runtime_status['retry_label'],
				$runtime_status['next_retry_label'],
				$runtime_status['grace_remaining_label']
			);
		} elseif ( $is_unpaid_renewal && $retry_exhausted ) {
			$runtime_status['label']       = __( 'On Hold', 'megurio-subscriptions-for-woocommerce' );
			$runtime_status['description'] = sprintf( __( 'Max retries reached for renewal order #%d. Auto-charging is paused.', 'megurio-subscriptions-for-woocommerce' ), $renewal_order_id );
			$runtime_status['notice']      = __( 'Paused because max renewal retries were reached. Will resume when the pending order is paid.', 'megurio-subscriptions-for-woocommerce' );
		} elseif ( $is_unpaid_renewal ) {
			$runtime_status['description'] = sprintf( __( 'Renewal order #%d payment is incomplete.', 'megurio-subscriptions-for-woocommerce' ), $renewal_order_id );
			$runtime_status['notice']      = __( 'Renewal order payment is incomplete.', 'megurio-subscriptions-for-woocommerce' );
		}

		return $runtime_status;
	}

	/**
	 * 指定した更新注文の次回リトライ予定時刻を返します。
	 *
	 * @param int $renewal_order_id 更新注文 ID。
	 * @return int
	 */
	protected function get_next_renewal_retry_timestamp( $renewal_order_id ) {
		$args = array( absint( $renewal_order_id ) );

		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$timestamp = as_next_scheduled_action( self::ACTION_RETRY_RENEWAL, $args );
			return $timestamp ? (int) $timestamp : 0;
		}

		$timestamp = wp_next_scheduled( self::ACTION_RETRY_RENEWAL, $args );
		return $timestamp ? (int) $timestamp : 0;
	}

	/**
	 * 現在のリトライ段階から猶予終了予定時刻を推定します。
	 *
	 * @param int $next_retry_timestamp 次回リトライ予定時刻。
	 * @param int $retry_count          現在のリトライ回数。
	 * @return int
	 */
	protected function get_grace_period_expires_at( $next_retry_timestamp, $retry_count ) {
		if ( ! $next_retry_timestamp || $retry_count <= 0 ) {
			return 0;
		}

		$expires_at = (int) $next_retry_timestamp;
		$intervals  = array_slice( self::RENEWAL_RETRY_INTERVALS, $retry_count );

		foreach ( $intervals as $interval_days ) {
			$expires_at += absint( $interval_days ) * DAY_IN_SECONDS;
		}

		return $expires_at;
	}

	/**
	 * 指定時刻までの残り時間を短く表示します。
	 *
	 * @param int $timestamp UNIX タイムスタンプ。
	 * @return string
	 */
	protected function format_remaining_time( $timestamp ) {
		$remaining = (int) $timestamp - current_time( 'timestamp' );
		if ( $remaining <= 0 ) {
			return __( 'soon', 'megurio-subscriptions-for-woocommerce' );
		}

		$days    = floor( $remaining / DAY_IN_SECONDS );
		$hours   = floor( ( $remaining % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		$minutes = floor( ( $remaining % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

		if ( $days > 0 ) {
			/* translators: 1: days, 2: hours */
			return sprintf( __( '%1$dd %2$dh', 'megurio-subscriptions-for-woocommerce' ), $days, $hours );
		}

		if ( $hours > 0 ) {
			/* translators: 1: hours, 2: minutes */
			return sprintf( __( '%1$dh %2$dm', 'megurio-subscriptions-for-woocommerce' ), $hours, $minutes );
		}

		/* translators: %d: minutes */
		return sprintf( __( '%dm', 'megurio-subscriptions-for-woocommerce' ), max( 1, $minutes ) );
	}

	/**
	 * 更新注文を 1 件作成します。
	 *
	 * @param int $subscription_id 定期購入 ID。
	 * @param int $current_time    現在時刻。
	 * @return int
	 */
	protected function create_renewal_order( $subscription_id, $current_time ) {
		$subscription = wc_get_order( $subscription_id );
		if ( ! $subscription instanceof WC_Order ) {
			return 0;
		}

		$product_id = absint( $this->get_object_meta( $subscription_id, '_megurio_product_id' ) );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			$subscription->add_order_note( __( 'Product not found. Renewal order could not be created.', 'megurio-subscriptions-for-woocommerce' ) );
			return 0;
		}

		$payment_method       = $this->get_subscription_payment_method( $subscription_id, $subscription );
		$payment_method_title = $this->get_subscription_payment_method_title( $subscription_id, $subscription );
		if ( ! $this->gateway_integration->is_auto_charge_gateway( $payment_method ) ) {
			$this->pause_subscription_for_unsupported_payment_method( $subscription_id, $payment_method );
			return 0;
		}

		$renewal_order = wc_create_order(
			array(
				'status'      => 'pending',
				'customer_id' => absint( $this->get_object_meta( $subscription_id, '_megurio_customer_id' ) ),
			)
		);

		if ( ! $renewal_order instanceof WC_Order ) {
			return 0;
		}

		$tax_data = $this->get_object_meta( $subscription_id, '_megurio_line_tax_data' );
		$tax_data = $tax_data ? json_decode( $tax_data, true ) : array();

		$renewal_order->add_product(
			$product,
			max( 1, absint( $this->get_object_meta( $subscription_id, '_megurio_product_qty' ) ) ),
			array(
				'totals' => array(
					'subtotal'     => (float) $this->get_object_meta( $subscription_id, '_megurio_line_subtotal' ),
					'subtotal_tax' => (float) $this->get_object_meta( $subscription_id, '_megurio_line_subtotal_tax' ),
					'total'        => (float) $this->get_object_meta( $subscription_id, '_megurio_line_total' ),
					'tax'          => (float) $this->get_object_meta( $subscription_id, '_megurio_line_tax' ),
					'tax_data'     => is_array( $tax_data ) ? $tax_data : array(),
				),
			)
		);

		$renewal_order->set_address( $subscription->get_address( 'billing' ), 'billing' );
		$renewal_order->set_address( $subscription->get_address( 'shipping' ), 'shipping' );
		$renewal_order->set_currency( $subscription->get_currency() );
		$renewal_order->set_payment_method( $payment_method );
		$renewal_order->set_payment_method_title( $payment_method_title );
		$renewal_order->calculate_totals();
		$renewal_order->save();

		$this->set_object_meta_bulk(
			$renewal_order->get_id(),
			array(
				'_megurio_is_renewal_order' => 'yes',
				'_megurio_subscription_id'  => $subscription_id,
				'_megurio_parent_order_id'  => absint( $this->get_object_meta( $subscription_id, '_megurio_parent_order_id' ) ),
			)
		);

		$renewal_ids   = $this->get_object_meta( $subscription_id, '_megurio_renewal_order_ids' );
		$renewal_ids   = is_array( $renewal_ids ) ? $renewal_ids : array();
		$renewal_ids[] = $renewal_order->get_id();

		$this->set_subscription_meta( $subscription_id, array(
			'_megurio_last_renewal_order' => $renewal_order->get_id(),
			'_megurio_renewal_order_ids'  => $renewal_ids,
			'_megurio_next_payment'       => $this->calculate_next_payment( $subscription_id, $current_time ),
		) );

		$subscription->add_order_note( sprintf( __( 'Renewal order #%d created.', 'megurio-subscriptions-for-woocommerce' ), $renewal_order->get_id() ) );
		$renewal_order->add_order_note( sprintf( __( 'This renewal order is linked to subscription #%d.', 'megurio-subscriptions-for-woocommerce' ), $subscription_id ) );

		$this->gateway_integration->process_renewal_payment( $subscription_id, $renewal_order );

		return $renewal_order->get_id();
	}

	/**
	 * 商品が定期購入商品かどうかを返します。
	 *
	 * @param int $product_id 商品 ID。
	 * @return bool
	 */
	protected function is_subscription_product( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return false;
		}

		if ( 'yes' === $this->get_product_meta( $product_id, '_megurio_is_subscription' ) ) {
			return true;
		}

		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $parent_id && 'yes' === $this->get_product_meta( $parent_id, '_megurio_is_subscription' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * バリエーション商品の場合は親商品 ID を、そうでなければそのままの ID を返します。
	 * 定期購入設定メタ（間隔・初期費用）は親商品に保存されているため、
	 * バリエーション ID で読む際に親 ID へフォールバックします。
	 *
	 * @param int $product_id 商品 ID（バリエーション ID でも可）。
	 * @return int 親商品 ID またはそのままの ID。
	 */
	protected function get_meta_product_id( $product_id ) {
		$product_id = absint( $product_id );
		$parent_id  = wp_get_post_parent_id( $product_id );
		return $parent_id ? $parent_id : $product_id;
	}

	/**
	 * 商品メタを WooCommerce Product CRUD 経由で取得します。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $meta_key   メタキー。
	 * @return mixed
	 */
	protected function get_product_meta( $product_id, $meta_key ) {
		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product ) {
			return $product->get_meta( $meta_key, true );
		}

		return null;
	}

	/**
	 * 商品メタを WooCommerce Product CRUD 経由でまとめて保存します。
	 *
	 * @param int   $product_id 商品 ID。
	 * @param array $meta_map   保存するメタ。
	 * @return void
	 */
	protected function set_product_meta_bulk( $product_id, array $meta_map ) {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		foreach ( $meta_map as $meta_key => $meta_value ) {
			$product->update_meta_data( $meta_key, $meta_value );
		}
		$product->save();
	}

	/**
	 * カート内に定期購入商品が含まれているかを返します。
	 *
	 * @return bool
	 */
	protected function cart_has_subscription_product() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = 0;

			if ( ! empty( $cart_item['variation_id'] ) ) {
				$product_id = absint( $cart_item['variation_id'] );
			} elseif ( ! empty( $cart_item['product_id'] ) ) {
				$product_id = absint( $cart_item['product_id'] );
			}

			if ( $product_id && $this->is_subscription_product( $product_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 通常 checkout または更新注文の order-pay ページが定期購入文脈かを返します。
	 *
	 * @return bool
	 */
	protected function is_subscription_checkout_context() {
		if ( $this->cart_has_subscription_product() ) {
			return true;
		}

		$order_id = $this->get_pay_for_order_id();
		if ( ! $order_id ) {
			return false;
		}

		return $this->is_subscription_payment_context( $order_id );
	}

	/**
	 * order-pay 画面の注文 ID を取得します。
	 *
	 * @return int
	 */
	protected function get_pay_for_order_id() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return 0;
		}

		$order_id = absint( get_query_var( 'order-pay' ) );

		return $order_id;
	}

	/**
	 * 商品・投稿オブジェクトが定期購入商品かどうかを返します。
	 *
	 * @param mixed $product 商品、投稿、ID。
	 * @return bool
	 */
	protected function is_subscription_context_product( $product ) {
		if ( $product instanceof WC_Product ) {
			return $this->is_subscription_product( $product->get_id() );
		}

		if ( $product instanceof WP_Post ) {
			return $this->is_subscription_product( $product->ID );
		}

		if ( is_numeric( $product ) ) {
			return $this->is_subscription_product( $product );
		}

		return false;
	}

	/**
	 * 注文または現在カートが定期購入支払いの文脈かを返します。
	 *
	 * @param mixed $order_or_id 注文、注文 ID、または空。
	 * @return bool
	 */
	protected function is_subscription_payment_context( $order_or_id = null ) {
		if ( $this->cart_has_subscription_product() ) {
			return true;
		}

		if ( empty( $order_or_id ) || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		if ( 'yes' === $order->get_meta( '_megurio_has_subscription', true ) ) {
			return true;
		}

		if ( 'yes' === $order->get_meta( '_megurio_is_renewal_order', true ) ) {
			return true;
		}

		if ( absint( $order->get_meta( '_megurio_subscription_id', true ) ) ) {
			return true;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( $product && $this->is_subscription_product( $product->get_id() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * WooPayments API フィルターで現在リクエストが定期購入関連か判定します。
	 *
	 * @param array $params API リクエストパラメータ。
	 * @return bool
	 */
	protected function is_wcpay_subscription_request_context( array $params ) {
		if ( $this->cart_has_subscription_product() ) {
			return true;
		}

		$order_id = 0;

		if ( isset( $params['order_id'] ) ) {
			$order_id = absint( $params['order_id'] );
		} elseif ( isset( $params['metadata']['order_id'] ) ) {
			$order_id = absint( $params['metadata']['order_id'] );
		} elseif ( isset( $params['metadata']['order_number'] ) ) {
			$order_id = absint( $params['metadata']['order_number'] );
		}

		return $order_id ? $this->is_subscription_payment_context( $order_id ) : false;
	}

	/**
	 * paymentMethodsConfig を card のみに絞ります。
	 *
	 * @param mixed $config paymentMethodsConfig。
	 * @return mixed
	 */
	protected function card_only_payment_methods_config( $config ) {
		if ( ! is_array( $config ) || ! isset( $config['card'] ) ) {
			return $config;
		}

		return array(
			'card' => $config['card'],
		);
	}

	/**
	 * paymentMethodsConfig から card 以外の ID を抽出します。
	 *
	 * @param mixed $config paymentMethodsConfig。
	 * @return array
	 */
	protected function get_non_card_payment_method_ids( $config ) {
		$ids = array(
			'acss_debit',
			'affirm',
			'afterpay_clearpay',
			'alipay',
			'amazon_pay',
			'bacs_debit',
			'bancontact',
			'blik',
			'boleto',
			'cashapp',
			'eps',
			'giropay',
			'google_pay',
			'ideal',
			'klarna',
			'konbini',
			'link',
			'mobilepay',
			'multibanco',
			'oxxo',
			'p24',
			'paynow',
			'revolut_pay',
			'sepa_debit',
			'sofort',
			'twint',
			'wechat_pay',
		);

		if ( is_array( $config ) ) {
			foreach ( array_keys( $config ) as $method_id ) {
				if ( 'card' !== $method_id ) {
					$ids[] = $method_id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Stripe の excludedPaymentMethodTypes に card 以外の ID を追加します。
	 *
	 * @param mixed  $existing 既存値。
	 * @param array  $extra    追加する ID。
	 * @return array
	 */
	protected function merge_excluded_payment_method_types( $existing, array $extra ) {
		$existing = is_array( $existing ) ? $existing : array();
		$merged   = array_merge( $existing, $extra );
		$merged   = array_filter(
			array_map( 'sanitize_key', $merged ),
			function ( $method_id ) {
				return $method_id && 'card' !== $method_id;
			}
		);

		return array_values( array_unique( $merged ) );
	}

	/**
	 * POST されたスカラー値を安全に取得します。
	 *
	 * @param string $key POST キー。
	 * @return string
	 */
	protected function get_posted_scalar_value( $key ) {
		if ( ! isset( $_POST[ $key ] ) || is_array( $_POST[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * 商品用の定期購入案内 HTML を返します。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $class      追加クラス。
	 * @return string
	 */
	protected function get_front_subscription_notice_html( $product_id, $class = '' ) {
		$interval_label = $this->get_subscription_interval_label( $product_id );
		$signup_fee     = (float) $this->get_product_meta( $this->get_meta_product_id( $product_id ), '_megurio_signup_fee' );
		$class_attr     = trim( $class );
		$details        = sprintf(
			'<div>%1$s: %2$s</div>',
			esc_html__( 'Renewal Interval', 'megurio-subscriptions-for-woocommerce' ),
			esc_html( $interval_label )
		);

		if ( $signup_fee > 0 ) {
			$details .= sprintf(
				'<div>%1$s: %2$s</div>',
				esc_html__( 'Sign-up Fee', 'megurio-subscriptions-for-woocommerce' ),
				wp_kses_post( wc_price( $signup_fee ) )
			);
		}

		return sprintf(
			'<div class="%1$s"><strong>%2$s</strong>%3$s</div>',
			esc_attr( $class_attr ),
			esc_html__( 'Subscription Product', 'megurio-subscriptions-for-woocommerce' ),
			$details
		);
	}

	/**
	 * 商品の更新間隔を表示用ラベルに変換します。
	 *
	 * @param int $product_id 商品 ID。
	 * @return string
	 */
	protected function get_subscription_interval_label( $product_id ) {
		$meta_id = $this->get_meta_product_id( $product_id );
		$count   = max( 1, absint( $this->get_product_meta( $meta_id, '_megurio_interval_count' ) ) );
		$unit    = (string) $this->get_product_meta( $meta_id, '_megurio_interval_unit' );

		return $this->format_interval_label( $count, $unit );
	}

	/**
	 * すべての定期購入 ID を取得します。
	 *
	 * @return array
	 */
	protected function get_all_subscription_ids() {
		return wc_get_orders(
			array(
				'limit'   => -1,
				'type'    => self::SUBSCRIPTION_TYPE,
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'DESC',
			)
		);
	}

	/**
	 * 指定ユーザーの定期購入 ID 一覧を取得します。
	 *
	 * @param int $user_id ユーザー ID。
	 * @return array
	 */
	protected function get_user_subscription_ids( $user_id ) {
		return wc_get_orders(
			array(
				'limit'      => -1,
				'type'       => self::SUBSCRIPTION_TYPE,
				'return'     => 'ids',
				'orderby'    => 'ID',
				'order'      => 'DESC',
				'meta_query' => array(
					array(
						'key'   => '_megurio_customer_id',
						'value' => $user_id,
					),
				),
			)
		);
	}

	/**
	 * 指定ユーザーが定期購入の所有者か確認します。
	 *
	 * @param int $subscription_id 定期購入 ID。
	 * @param int $user_id ユーザー ID。
	 * @return bool
	 */
	protected function user_owns_subscription( $subscription_id, $user_id ) {
		return (int) $this->get_object_meta( $subscription_id, '_megurio_customer_id' ) === (int) $user_id;
	}

	/**
	 * 状態ごとの件数を集計します。
	 *
	 * @param array $subscription_ids 定期購入 ID 一覧。
	 * @return array
	 */
	protected function get_subscription_status_counts( array $subscription_ids ) {
		$counts = array(
			'active'    => 0,
			'on-hold'   => 0,
			'pending'   => 0,
			'cancelled' => 0,
		);

		foreach ( $subscription_ids as $subscription_id ) {
			$status = (string) $this->get_object_meta( $subscription_id, '_megurio_subscription_status' );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
		}

		return $counts;
	}

	/**
	 * 状態バッジの HTML を返します。
	 *
	 * @param string $status          状態名。
	 * @param int    $subscription_id 定期購入 ID。
	 * @return string
	 */
	protected function render_status_badge( $status, $subscription_id = 0 ) {
		$map = array(
			'pending'   => array( 'status-pending', __( 'Pending', 'megurio-subscriptions-for-woocommerce' ) ),
			'active'    => array( 'status-processing', __( 'Active', 'megurio-subscriptions-for-woocommerce' ) ),
			'on-hold'   => array( 'status-on-hold', __( 'On Hold', 'megurio-subscriptions-for-woocommerce' ) ),
			'cancelled' => array( 'status-cancelled', __( 'Cancelled', 'megurio-subscriptions-for-woocommerce' ) ),
		);

		$badge = isset( $map[ $status ] ) ? $map[ $status ] : array( 'status-pending', $status ? $status : '-' );
		if ( $subscription_id && 'active' === $status ) {
			$runtime_status = $this->get_subscription_runtime_status( $subscription_id );
			if ( ! empty( $runtime_status['is_grace_period'] ) ) {
				$badge[1] = sprintf(
					/* translators: 1: subscription status label, 2: retrying payment label */
					__( '%1$s (%2$s)', 'megurio-subscriptions-for-woocommerce' ),
					$badge[1],
					__( 'Payment failed - retrying', 'megurio-subscriptions-for-woocommerce' )
				);
			}
		}

		return '<mark class="order-status ' . esc_attr( $badge[0] ) . '"><span>' . esc_html( $badge[1] ) . '</span></mark>';
	}

	/**
	 * タイムスタンプを管理画面向け表示に整えます。
	 *
	 * @param int $timestamp UNIX タイムスタンプ。
	 * @return string
	 */
	protected function format_timestamp( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return '-';
		}

		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * WC_DateTime を文字列に整形します。
	 *
	 * @param mixed $datetime WC_DateTime。
	 * @return string
	 */
	protected function format_datetime_string( $datetime ) {
		if ( ! $datetime || ! is_object( $datetime ) || ! method_exists( $datetime, 'getTimestamp' ) ) {
			return '-';
		}

		return wp_date( 'Y-m-d H:i:s', $datetime->getTimestamp() );
	}

	/**
	 * 親注文に紐づく定期購入 ID 一覧を取得します。
	 *
	 * @param int   $order_id 親注文 ID。
	 * @param array $statuses 取得対象の定期購入状態。
	 * @return array
	 */
	protected function get_subscription_ids_by_parent_order( $order_id, array $statuses ) {
		return wc_get_orders(
			array(
				'limit'      => -1,
				'type'       => self::SUBSCRIPTION_TYPE,
				'return'     => 'ids',
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => '_megurio_parent_order_id',
						'value' => $order_id,
					),
					array(
						'key'   => '_megurio_subscription_status',
						'value' => $statuses,
					),
				),
			)
		);
	}

	/**
	 * 次回請求日時を計算します。
	 *
	 * @param int $subscription_id 定期購入 ID。
	 * @param int $base_time       基準時刻。
	 * @return int
	 */
	protected function calculate_next_payment( $subscription_id, $base_time ) {
		$count = max( 1, absint( $this->get_object_meta( $subscription_id, '_megurio_interval_count' ) ) );
		$unit  = $this->get_object_meta( $subscription_id, '_megurio_interval_unit' );

		return $this->add_interval_to_timestamp( $base_time, $count, $unit );
	}

	/**
	 * 指定した間隔を時刻に加算します。
	 *
	 * @param int    $timestamp 基準時刻。
	 * @param int    $count     数値。
	 * @param string $unit      単位。
	 * @return int
	 */
	protected function add_interval_to_timestamp( $timestamp, $count, $unit ) {
		switch ( $unit ) {
			case 'day':
				return strtotime( '+' . $count . ' days', $timestamp );
			case 'week':
				return strtotime( '+' . $count . ' weeks', $timestamp );
			case 'month':
				return strtotime( '+' . $count . ' months', $timestamp );
			case 'year':
				return strtotime( '+' . $count . ' years', $timestamp );
			default:
				return 0;
		}
	}

	/**
	 * 更新間隔を日本語ラベルに変換します。
	 *
	 * @param int    $count 数値。
	 * @param string $unit  単位。
	 * @return string
	 */
	protected function format_interval_label( $count, $unit ) {
		$unit_singular = array(
			'day'   => __( 'day', 'megurio-subscriptions-for-woocommerce' ),
			'week'  => __( 'week', 'megurio-subscriptions-for-woocommerce' ),
			'month' => __( 'month', 'megurio-subscriptions-for-woocommerce' ),
			'year'  => __( 'year', 'megurio-subscriptions-for-woocommerce' ),
		);
		$unit_plural = array(
			'day'   => __( 'days', 'megurio-subscriptions-for-woocommerce' ),
			'week'  => __( 'weeks', 'megurio-subscriptions-for-woocommerce' ),
			'month' => __( 'months', 'megurio-subscriptions-for-woocommerce' ),
			'year'  => __( 'years', 'megurio-subscriptions-for-woocommerce' ),
		);

		if ( empty( $unit_singular[ $unit ] ) ) {
			return '-';
		}

		if ( 1 === $count ) {
			/* translators: %s: time unit (day, week, month, year) */
			return sprintf( __( 'Every %s', 'megurio-subscriptions-for-woocommerce' ), $unit_singular[ $unit ] );
		}

		/* translators: 1: count, 2: time unit (days, weeks, months, years) */
		return sprintf( __( 'Every %1$d %2$s', 'megurio-subscriptions-for-woocommerce' ), $count, $unit_plural[ $unit ] );
	}

	/**
	 * 管理画面から定期購入状態を手動更新します。
	 *
	 * @param int    $subscription_id 定期購入 ID。
	 * @param string $target_status   変更後の状態。
	 * @return bool
	 */
	protected function manually_update_subscription_status( $subscription_id, $target_status ) {
		$allowed_statuses = array( 'pending', 'active', 'on-hold', 'cancelled' );
		if ( ! in_array( $target_status, $allowed_statuses, true ) ) {
			return false;
		}

		$subscription = wc_get_order( $subscription_id );
		if ( ! $subscription instanceof WC_Order ) {
			return false;
		}

		$current_status = (string) $this->get_object_meta( $subscription_id, '_megurio_subscription_status' );
		$current_time = current_time( 'timestamp' );
		$meta_map     = array(
			'_megurio_subscription_status' => $target_status,
		);

		if ( 'active' === $target_status ) {
			$current_start = (int) $this->get_object_meta( $subscription_id, '_megurio_schedule_start' );
			$meta_map['_megurio_schedule_start'] = $current_start ? $current_start : $current_time;

			$current_next = (int) $this->get_object_meta( $subscription_id, '_megurio_next_payment' );
			if ( empty( $current_next ) || $current_next <= $current_time ) {
				$meta_map['_megurio_next_payment'] = $this->calculate_next_payment( $subscription_id, $current_time );
			}
		} elseif ( 'cancelled' === $target_status ) {
			$meta_map['_megurio_next_payment'] = 0;
		}

		$this->set_subscription_meta( $subscription_id, $meta_map );
		$subscription->add_order_note( sprintf( __( 'Subscription status manually changed to %s from admin.', 'megurio-subscriptions-for-woocommerce' ), $target_status ) );

		if ( 'cancelled' === $target_status ) {
			$this->send_subscription_cancel_email( $subscription_id, 'admin' );
		} elseif ( 'active' === $target_status && 'active' !== $current_status ) {
			$this->send_subscription_reactivated_email( $subscription_id, 'admin' );
		}

		return true;
	}

	/**
	 * 定期購入通知に必要なメール情報を集めます。
	 *
	 * @param int $subscription_id 定期購入 ID。
	 * @return array
	 */
	protected function get_subscription_email_context( $subscription_id ) {
		$subscription = wc_get_order( $subscription_id );
		if ( ! $subscription instanceof WC_Order ) {
			return array();
		}

		$parent_order_id = absint( $this->get_object_meta( $subscription_id, '_megurio_parent_order_id' ) );
		$parent_order    = $parent_order_id ? wc_get_order( $parent_order_id ) : false;
		$product_id      = absint( $this->get_object_meta( $subscription_id, '_megurio_product_id' ) );
		$product_name    = (string) $this->get_object_meta( $subscription_id, '_megurio_product_name' );
		$product_name    = $product_name ? $product_name : ( $product_id ? get_the_title( $product_id ) : __( 'Subscription Product', 'megurio-subscriptions-for-woocommerce' ) );
		$customer_email  = $parent_order instanceof WC_Order ? $parent_order->get_billing_email() : '';
		$admin_email     = get_option( 'admin_email' );

		return array(
			'subscription'    => $subscription,
			'parent_order'    => $parent_order,
				'parent_order_id' => $parent_order_id,
				'product_name'    => $product_name,
				'next_payment'    => (int) $this->get_object_meta( $subscription_id, '_megurio_next_payment' ),
				'detail_url'      => wc_get_account_endpoint_url( 'megurio-subscriptions' ),
				'recipients'      => array_unique( array_filter( array( $customer_email, $admin_email ) ) ),
			);
	}

	/**
	 * HTML メールを送信します。
	 *
	 * @param array  $recipients 送信先一覧。
	 * @param string $subject    件名。
	 * @param string $heading    見出し。
	 * @param string $message    本文 HTML。
	 * @return void
	 */
	protected function send_subscription_email( array $recipients, $subject, $heading, $message ) {
		$recipients = array_unique( array_filter( $recipients ) );
		if ( empty( $recipients ) ) {
			return;
		}

		$mailer  = WC()->mailer();
		$content = $mailer->wrap_message( $heading, $message );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		foreach ( $recipients as $recipient ) {
			wc_mail( $recipient, $subject, $content, $headers );
		}
	}

	/**
	 * 定期購入キャンセル通知メールを送信します。
	 *
	 * @param int    $subscription_id 定期購入 ID。
	 * @param string $cancelled_by    キャンセルした主体。
	 * @return void
	 */
	protected function send_subscription_cancel_email( $subscription_id, $cancelled_by = '' ) {
		$context = $this->get_subscription_email_context( $subscription_id );
		if ( empty( $context ) ) {
			return;
		}

		$cancelled_by_label = __( 'System', 'megurio-subscriptions-for-woocommerce' );
		if ( 'customer' === $cancelled_by ) {
			$cancelled_by_label = __( 'Customer', 'megurio-subscriptions-for-woocommerce' );
		} elseif ( 'admin' === $cancelled_by ) {
			$cancelled_by_label = __( 'Admin', 'megurio-subscriptions-for-woocommerce' );
		} elseif ( 'parent-order' === $cancelled_by ) {
			$cancelled_by_label = __( 'Parent Order Cancellation', 'megurio-subscriptions-for-woocommerce' );
		}

		$tpl     = $this->get_email_template( 'cancel' );
		$vars    = array(
			'subscription_id'  => $subscription_id,
			'product_name'     => $context['product_name'],
			'cancelled_by'     => $cancelled_by_label,
			'parent_order_id'  => $context['parent_order_id'] ? '#' . $context['parent_order_id'] : '-',
			'next_payment'     => $this->format_timestamp( $context['next_payment'] ),
			'detail_url'       => $context['detail_url'],
		);
		$subject = $this->render_email_body( $tpl['subject'], $vars );
		$heading = $this->render_email_body( $tpl['heading'], $vars );
		$message = $this->render_email_body( $tpl['body'], $vars );

		$this->send_subscription_email( $context['recipients'], $subject, $heading, $message );
	}

	/**
	 * 定期購入再開通知メールを送信します。
	 *
	 * @param int    $subscription_id 定期購入 ID。
	 * @param string $reactivated_by  再開主体。
	 * @return void
	 */
	protected function send_subscription_reactivated_email( $subscription_id, $reactivated_by = '' ) {
		$context = $this->get_subscription_email_context( $subscription_id );
		if ( empty( $context ) ) {
			return;
		}

		$reactivated_by_label = __( 'System', 'megurio-subscriptions-for-woocommerce' );
		if ( 'admin' === $reactivated_by ) {
			$reactivated_by_label = __( 'Admin', 'megurio-subscriptions-for-woocommerce' );
		} elseif ( 'customer' === $reactivated_by ) {
			$reactivated_by_label = __( 'Customer', 'megurio-subscriptions-for-woocommerce' );
		} elseif ( 'renewal-order' === $reactivated_by ) {
			$reactivated_by_label = __( 'Renewal Order Payment Confirmed', 'megurio-subscriptions-for-woocommerce' );
		}

		$tpl     = $this->get_email_template( 'reactivated' );
		$vars    = array(
			'subscription_id' => $subscription_id,
			'product_name'    => $context['product_name'],
			'reactivated_by'  => $reactivated_by_label,
			'next_payment'    => $this->format_timestamp( $context['next_payment'] ),
			'parent_order_id' => $context['parent_order_id'] ? '#' . $context['parent_order_id'] : '-',
			'detail_url'      => $context['detail_url'],
		);
		$subject = $this->render_email_body( $tpl['subject'], $vars );
		$heading = $this->render_email_body( $tpl['heading'], $vars );
		$message = $this->render_email_body( $tpl['body'], $vars );

		$this->send_subscription_email( $context['recipients'], $subject, $heading, $message );
	}

	/**
	 * 更新課金が失敗したとき、リトライ案内メールを送ります。
	 *
	 * @param WC_Order $renewal_order   失敗した更新注文。
	 * @param int      $retry_count     現在のリトライ回数（1〜MAX_RETRIES）。
	 * @param int      $next_retry_days 次回リトライまでの日数。
	 * @return void
	 */
	protected function send_renewal_payment_failed_email( WC_Order $renewal_order, $retry_count = 0, $next_retry_days = 0 ) {
		$customer_email = $renewal_order->get_billing_email();
		if ( ! $customer_email ) {
			return;
		}

		$pay_url     = $renewal_order->get_checkout_payment_url();
		$order_total = wc_price( $renewal_order->get_total(), array( 'currency' => $renewal_order->get_currency() ) );
		$customer    = $renewal_order->get_billing_first_name() . ' ' . $renewal_order->get_billing_last_name();

		if ( $next_retry_days > 0 ) {
			/* translators: 1: days until retry, 2: current retry count, 3: max retries */
			$retry_line = sprintf(
				__( 'We will automatically retry in %1$d days (retry %2$d/%3$d).<br>You can also pay immediately using the link below.', 'megurio-subscriptions-for-woocommerce' ),
				$next_retry_days,
				$retry_count,
				self::RENEWAL_MAX_RETRIES
			);
		} else {
			$retry_line = __( 'Please complete your payment using the link below.', 'megurio-subscriptions-for-woocommerce' );
		}

		$tpl     = $this->get_email_template( 'payment_failed' );
		$vars    = array(
			'customer_name'   => $customer,
			'order_id'        => $renewal_order->get_id(),
			'order_total'     => $order_total,
			'retry_count'     => $retry_count,
			'max_retries'     => self::RENEWAL_MAX_RETRIES,
			'next_retry_days' => $next_retry_days,
			'retry_line'      => $retry_line,
			'pay_url'         => esc_url( $pay_url ),
		);
		$subject = $this->render_email_body( $tpl['subject'], $vars );
		$heading = $this->render_email_body( $tpl['heading'], $vars );
		$message = $this->render_email_body( $tpl['body'], $vars );

		$this->send_subscription_email( array( $customer_email ), $subject, $heading, $message );
		/* translators: 1: current retry count, 2: max retries, 3: customer email */
		$renewal_order->add_order_note( sprintf( __( 'Retry %1$d/%2$d notification email sent to %3$s.', 'megurio-subscriptions-for-woocommerce' ), $retry_count, self::RENEWAL_MAX_RETRIES, $customer_email ) );
	}

	/**
	 * リトライ上限に達したとき、最終失敗通知メールを送ります。
	 *
	 * @param WC_Order $renewal_order 更新注文。
	 * @return void
	 */
	protected function send_renewal_payment_exhausted_email( WC_Order $renewal_order ) {
		$customer_email = $renewal_order->get_billing_email();
		if ( ! $customer_email ) {
			return;
		}

		$pay_url     = $renewal_order->get_checkout_payment_url();
		$order_total = wc_price( $renewal_order->get_total(), array( 'currency' => $renewal_order->get_currency() ) );
		$customer    = $renewal_order->get_billing_first_name() . ' ' . $renewal_order->get_billing_last_name();

		$tpl     = $this->get_email_template( 'payment_exhausted' );
		$vars    = array(
			'customer_name'  => $customer,
			'order_id'       => $renewal_order->get_id(),
			'order_total'    => $order_total,
			'total_attempts' => self::RENEWAL_MAX_RETRIES + 1,
			'pay_url'        => esc_url( $pay_url ),
		);
		$subject = $this->render_email_body( $tpl['subject'], $vars );
		$heading = $this->render_email_body( $tpl['heading'], $vars );
		$message = $this->render_email_body( $tpl['body'], $vars );

		$this->send_subscription_email( array( $customer_email ), $subject, $heading, $message );
		/* translators: 1: max retries, 2: customer email */
		$renewal_order->add_order_note( sprintf( __( 'All %1$d retries exhausted. Final notification email sent to %2$s. Auto-charging stopped.', 'megurio-subscriptions-for-woocommerce' ), self::RENEWAL_MAX_RETRIES, $customer_email ) );
	}

	/**
	 * メールテンプレートのデフォルト値を返します。
	 * subject/heading/body はそれぞれ管理画面から上書き可能です。
	 *
	 * @return array
	 */
	protected function get_email_template_defaults() {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return array(
			'cancel' => array(
				/* translators: %s: site name */
				'subject' => sprintf( __( '[%s] Subscription Cancellation Notice', 'megurio-subscriptions-for-woocommerce' ), $site_name ),
				'heading' => __( 'Subscription Cancellation Notice', 'megurio-subscriptions-for-woocommerce' ),
				'body'    =>
					'<p>' . __( 'Your subscription has been cancelled.', 'megurio-subscriptions-for-woocommerce' ) . '</p>' .
					'<p>' .
					'<strong>' . __( 'Subscription ID:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> #{subscription_id}<br>' .
					'<strong>' . __( 'Product:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> {product_name}<br>' .
					'<strong>' . __( 'Status:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> ' . __( 'Cancelled', 'megurio-subscriptions-for-woocommerce' ) . '<br>' .
					'<strong>' . __( 'Cancellation Reason:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> {cancelled_by}<br>' .
					'<strong>' . __( 'Parent Order:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> {parent_order_id}<br>' .
					'<strong>' . __( 'Next Billing Date:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> {next_payment}' .
					'</p>' .
					'<p>' . __( 'Please check your My Account for subscription details.', 'megurio-subscriptions-for-woocommerce' ) . '<br><a href="{detail_url}">{detail_url}</a></p>',
			),
			'reactivated' => array(
				/* translators: %s: site name */
				'subject' => sprintf( __( '[%s] Subscription Reactivation Notice', 'megurio-subscriptions-for-woocommerce' ), $site_name ),
				'heading' => __( 'Subscription Reactivation Notice', 'megurio-subscriptions-for-woocommerce' ),
				'body'    =>
					'<p>' . __( 'Your subscription has been reactivated.', 'megurio-subscriptions-for-woocommerce' ) . '</p>' .
					'<p>' .
					'<strong>' . __( 'Subscription ID:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> #{subscription_id}<br>' .
					'<strong>' . __( 'Product:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> {product_name}<br>' .
					'<strong>' . __( 'Status:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> ' . __( 'Active', 'megurio-subscriptions-for-woocommerce' ) . '<br>' .
					'<strong>' . __( 'Reactivation Reason:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> {reactivated_by}<br>' .
					'<strong>' . __( 'Next Billing Date:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> {next_payment}<br>' .
					'<strong>' . __( 'Parent Order:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> {parent_order_id}' .
					'</p>' .
					'<p>' . __( 'Please check your My Account for subscription details.', 'megurio-subscriptions-for-woocommerce' ) . '<br><a href="{detail_url}">{detail_url}</a></p>',
			),
			'payment_failed' => array(
				/* translators: %d: renewal order ID */
				'subject' => sprintf( __( '[Important] Subscription renewal payment failed (Order #%d)', 'megurio-subscriptions-for-woocommerce' ), '{order_id}' ),
				'heading' => __( 'Subscription Renewal Payment Notice', 'megurio-subscriptions-for-woocommerce' ),
				'body'    =>
					'<p>' . __( 'Dear {customer_name},', 'megurio-subscriptions-for-woocommerce' ) . '</p>' .
					'<p>' . __( 'The automatic renewal payment for your subscription (Order #{order_id}, Amount: {order_total}) has failed.', 'megurio-subscriptions-for-woocommerce' ) . '</p>' .
					'<p>{retry_line}</p>' .
					'<p><a href="{pay_url}" style="display:inline-block;padding:10px 20px;background:#7f54b3;color:#fff;text-decoration:none;border-radius:4px;">' . __( 'Pay Now', 'megurio-subscriptions-for-woocommerce' ) . '</a></p>' .
					'<p>' . __( 'Your subscription will be automatically resumed once payment is confirmed.<br>Please contact us if you have any questions.', 'megurio-subscriptions-for-woocommerce' ) . '</p>',
			),
			'payment_exhausted' => array(
				/* translators: %d: renewal order ID */
				'subject' => sprintf( __( '[Important] Subscription renewal could not be completed (Order #%d)', 'megurio-subscriptions-for-woocommerce' ), '{order_id}' ),
				'heading' => __( 'Subscription Renewal Notice', 'megurio-subscriptions-for-woocommerce' ),
				'body'    =>
					'<p>' . __( 'Dear {customer_name},', 'megurio-subscriptions-for-woocommerce' ) . '</p>' .
					'<p>' . __( 'We attempted to renew your subscription (Order #{order_id}, Amount: {order_total}) {total_attempts} times, but all attempts failed.', 'megurio-subscriptions-for-woocommerce' ) . '</p>' .
					'<p>' . __( 'No further automatic retries will be made.', 'megurio-subscriptions-for-woocommerce' ) . '</p>' .
					'<p>' . __( 'To continue your subscription, please complete payment using the link below.<br>Your subscription will be automatically resumed after payment.', 'megurio-subscriptions-for-woocommerce' ) . '</p>' .
					'<p><a href="{pay_url}" style="display:inline-block;padding:10px 20px;background:#7f54b3;color:#fff;text-decoration:none;border-radius:4px;">' . __( 'Pay Now', 'megurio-subscriptions-for-woocommerce' ) . '</a></p>' .
					'<p>' . __( 'Please contact us if you have any questions.', 'megurio-subscriptions-for-woocommerce' ) . '</p>',
			),
		);
	}

	/**
	 * 指定タイプのメールテンプレートを返します。
	 * 管理画面で保存済みの場合はその値を、そうでなければデフォルトを使います。
	 *
	 * @param string $type cancel|reactivated|payment_failed|payment_exhausted
	 * @return array { subject: string, heading: string, body: string }
	 */
	protected function get_email_template( $type ) {
		$defaults  = $this->get_email_template_defaults();
		$default   = isset( $defaults[ $type ] ) ? $defaults[ $type ] : array( 'subject' => '', 'heading' => '', 'body' => '' );
		$saved     = get_option( 'megurio_email_templates', array() );
		$saved     = isset( $saved[ $type ] ) ? $saved[ $type ] : array();

		return array(
			'subject' => ! empty( $saved['subject'] ) ? $saved['subject'] : $default['subject'],
			'heading' => ! empty( $saved['heading'] ) ? $saved['heading'] : $default['heading'],
			'body'    => ! empty( $saved['body'] )    ? $saved['body']    : $default['body'],
		);
	}

	/**
	 * テンプレート文字列内の {placeholder} を実際の値に置換します。
	 *
	 * @param string $template テンプレート文字列。
	 * @param array  $vars     プレースホルダーと値のマップ。
	 * @return string
	 */
	protected function render_email_body( $template, array $vars ) {
		$search  = array();
		$replace = array();
		foreach ( $vars as $key => $value ) {
			$search[]  = '{' . $key . '}';
			$replace[] = (string) $value;
		}
		return str_replace( $search, $replace, $template );
	}

	/**
	 * メール設定タブのフォームを描画します。
	 *
	 * @return void
	 */
	protected function render_email_settings_tab() {
		$defaults = $this->get_email_template_defaults();
		$saved    = get_option( 'megurio_email_templates', array() );

		$email_types = array(
			'cancel'            => __( 'Subscription Cancelled', 'megurio-subscriptions-for-woocommerce' ),
			'reactivated'       => __( 'Subscription Reactivated', 'megurio-subscriptions-for-woocommerce' ),
			'payment_failed'    => __( 'Renewal Payment Failed (with retry)', 'megurio-subscriptions-for-woocommerce' ),
			'payment_exhausted' => __( 'Renewal Payment Failed (retries exhausted)', 'megurio-subscriptions-for-woocommerce' ),
		);

		$vars_help = array(
			'cancel'            => '{subscription_id}, {product_name}, {cancelled_by}, {parent_order_id}, {next_payment}, {detail_url}',
			'reactivated'       => '{subscription_id}, {product_name}, {reactivated_by}, {next_payment}, {parent_order_id}, {detail_url}',
			'payment_failed'    => '{customer_name}, {order_id}, {order_total}, {retry_count}, {max_retries}, {next_retry_days}, {retry_line}, {pay_url}',
			'payment_exhausted' => '{customer_name}, {order_id}, {order_total}, {total_attempts}, {pay_url}',
		);

		$form_action = admin_url( 'admin.php?page=megurio-subscriptions&tab=email-settings' );
		?>
		<form method="post" action="<?php echo esc_url( $form_action ); ?>">
			<?php wp_nonce_field( 'megurio_save_email_settings' ); ?>
			<input type="hidden" name="megurio_action" value="save_email_settings">

			<?php foreach ( $email_types as $type => $label ) :
				$tpl = array(
					'subject' => ! empty( $saved[ $type ]['subject'] ) ? $saved[ $type ]['subject'] : $defaults[ $type ]['subject'],
					'heading' => ! empty( $saved[ $type ]['heading'] ) ? $saved[ $type ]['heading'] : $defaults[ $type ]['heading'],
					'body'    => ! empty( $saved[ $type ]['body'] )    ? $saved[ $type ]['body']    : $defaults[ $type ]['body'],
				);
			?>
			<h2><?php echo esc_html( $label ); ?></h2>
			<p class="description"><?php esc_html_e( 'Available variables:', 'megurio-subscriptions-for-woocommerce' ); ?> <code><?php echo esc_html( $vars_help[ $type ] ); ?></code></p>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Subject', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<td><input type="text" name="megurio_email_<?php echo esc_attr( $type ); ?>_subject" value="<?php echo esc_attr( $tpl['subject'] ); ?>" class="large-text"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Heading', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<td><input type="text" name="megurio_email_<?php echo esc_attr( $type ); ?>_heading" value="<?php echo esc_attr( $tpl['heading'] ); ?>" class="large-text"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Body', 'megurio-subscriptions-for-woocommerce' ); ?></th>
					<td>
						<?php
						wp_editor( $tpl['body'], 'megurio_email_' . $type, array(
							'textarea_name' => 'megurio_email_' . $type . '_body',
							'textarea_rows' => 12,
							'media_buttons' => false,
							'teeny'         => false,
							'quicktags'     => true,
						) );
						?>
						<p>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=megurio-subscriptions&email_preview=' . $type ), 'megurio_email_preview' ) ); ?>" target="_blank" class="button"><?php esc_html_e( 'Preview (saved)', 'megurio-subscriptions-for-woocommerce' ); ?></a>
							<span class="description"><?php esc_html_e( 'Opens the last saved template with sample data in a new window.', 'megurio-subscriptions-for-woocommerce' ); ?></span>
						</p>
					</td>
				</tr>
			</table>
			<hr>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save Email Settings', 'megurio-subscriptions-for-woocommerce' ) ); ?>
		</form>
		<?php
	}

	/**
	 * メールプレビューを HTML ページとして出力して終了します。
	 * WooCommerce メーラーのスタイルで実際の見た目を確認できます。
	 *
	 * @param string $type cancel|reactivated|payment_failed|payment_exhausted
	 * @return void
	 */
	protected function output_email_preview( $type ) {
		$valid_types = array( 'cancel', 'reactivated', 'payment_failed', 'payment_exhausted' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			wp_die( esc_html__( 'Invalid preview type.', 'megurio-subscriptions-for-woocommerce' ) );
		}

		$tpl     = $this->get_email_template( $type );
		$vars    = $this->get_email_preview_sample_vars( $type );
		$subject = $this->render_email_body( $tpl['subject'], $vars );
		$heading = $this->render_email_body( $tpl['heading'], $vars );
		$body    = $this->render_email_body( $tpl['body'], $vars );

		$mailer  = WC()->mailer();
		$html    = $mailer->wrap_message( $heading, $body );

		echo '<!DOCTYPE html><html><head>';
		echo '<meta charset="UTF-8">';
		echo '<title>' . esc_html( $subject ) . '</title>';
		echo '<style>body{background:#f7f7f7;padding:20px;font-family:sans-serif} .preview-bar{background:#fff;border:1px solid #ddd;padding:12px 16px;margin-bottom:20px;border-radius:4px;font-size:13px;color:#444}</style>';
		echo '</head><body>';
		echo '<div class="preview-bar"><strong>' . esc_html__( 'Subject:', 'megurio-subscriptions-for-woocommerce' ) . '</strong> ' . esc_html( $subject ) . '</div>';
		echo wp_kses_post( $html );
		echo '</body></html>';
	}

	/**
	 * メールプレビュー用のサンプル変数を返します。
	 *
	 * @param string $type メールタイプ。
	 * @return array
	 */
	protected function get_email_preview_sample_vars( $type ) {
		$detail_url  = wc_get_endpoint_url( 'megurio-subscriptions', '', wc_get_page_permalink( 'myaccount' ) );
		$next_payment = date_i18n( get_option( 'date_format' ), strtotime( '+1 month' ) );
		$order_total  = wc_price( 1980 );
		$pay_url      = wc_get_checkout_url();

		$common = array(
			'subscription_id' => '123',
			'product_name'    => __( 'Monthly Subscription Plan', 'megurio-subscriptions-for-woocommerce' ),
			'next_payment'    => $next_payment,
			'parent_order_id' => '#456',
			'detail_url'      => $detail_url,
			'customer_name'   => __( 'Taro Yamada', 'megurio-subscriptions-for-woocommerce' ),
			'order_id'        => '789',
			'order_total'     => $order_total,
			'pay_url'         => $pay_url,
		);

		switch ( $type ) {
			case 'cancel':
				return array_merge( $common, array(
					'cancelled_by' => __( 'Customer', 'megurio-subscriptions-for-woocommerce' ),
				) );
			case 'reactivated':
				return array_merge( $common, array(
					'reactivated_by' => __( 'Admin', 'megurio-subscriptions-for-woocommerce' ),
				) );
			case 'payment_failed':
				/* translators: 1: days until retry, 2: current retry count, 3: max retries */
				$retry_line = sprintf(
					__( 'We will automatically retry in %1$d days (retry %2$d/%3$d).<br>You can also pay immediately using the link below.', 'megurio-subscriptions-for-woocommerce' ),
					2, 1, self::RENEWAL_MAX_RETRIES
				);
				return array_merge( $common, array(
					'retry_count'     => '1',
					'max_retries'     => (string) self::RENEWAL_MAX_RETRIES,
					'next_retry_days' => '2',
					'retry_line'      => $retry_line,
				) );
			case 'payment_exhausted':
				return array_merge( $common, array(
					'total_attempts' => (string) ( self::RENEWAL_MAX_RETRIES + 1 ),
				) );
		}

		return $common;
	}

	/**
	 * リクエストメソッドを安全に取得します。
	 *
	 * @return string
	 */
	protected function get_request_method() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return '';
		}

		return strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	/**
	 * GET パラメータから整数値を取得します。
	 *
	 * @param string $key キー名。
	 * @return int
	 */
	protected function get_query_int( $key ) {
		$value = filter_input( INPUT_GET, $key, FILTER_VALIDATE_INT );

		return $value ? absint( $value ) : 0;
	}

	/**
	 * GET パラメータからテキスト値を取得します。
	 *
	 * @param string $key キー名。
	 * @return string
	 */
	protected function get_query_text( $key ) {
		$value = filter_input( INPUT_GET, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
	}

	/**
	 * 注文番号の表記を管理画面リンクに変換します。
	 *
	 * @param string $content メモ本文。
	 * @return string
	 */
	protected function link_order_references_in_note( $content ) {
		$content = (string) $content;

		return preg_replace_callback(
			'/#(\d+)/',
			function ( $matches ) {
				$order_id = absint( $matches[1] );
				$order    = wc_get_order( $order_id );

				if ( ! $order instanceof WC_Order ) {
					return $matches[0];
				}

				$url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );

				return '<a href="' . esc_url( $url ) . '">#' . esc_html( $order_id ) . '</a>';
			},
			$content
		);
	}

	/**
	 * 注文番号の表記をマイアカウント向けリンクに変換します。
	 *
	 * @param string $content メモ本文。
	 * @param int    $user_id  ユーザー ID。
	 * @return string
	 */
	protected function link_order_references_in_account_note( $content, $user_id ) {
		$content = (string) $content;
		$user_id = absint( $user_id );

		return preg_replace_callback(
			'/#(\d+)/',
			function ( $matches ) use ( $user_id ) {
				$order_id = absint( $matches[1] );
				$order    = wc_get_order( $order_id );

				if ( ! $order instanceof WC_Order || self::SUBSCRIPTION_TYPE === $order->get_type() ) {
					return $matches[0];
				}

				if ( (int) $order->get_customer_id() !== $user_id ) {
					return $matches[0];
				}

				$url = wc_get_endpoint_url( 'view-order', $order_id, wc_get_page_permalink( 'myaccount' ) );

				return '<a href="' . esc_url( $url ) . '">#' . esc_html( $order_id ) . '</a>';
			},
			$content
		);
	}

	/**
	 * 定期購入メタをまとめて保存します。
	 *
	 * @param int   $subscription_id 定期購入 ID。
	 * @param array $meta_map        保存するメタ。
	 * @return void
	 */
	protected function set_subscription_meta( $subscription_id, array $meta_map ) {
		$this->set_object_meta_bulk( $subscription_id, $meta_map );
	}

	/**
	 * 注文または定期購入からメタ値を取得します。
	 *
	 * @param int    $object_id オブジェクト ID。
	 * @param string $meta_key  メタキー。
	 * @return mixed
	 */
	protected function get_object_meta( $object_id, $meta_key ) {
		$order = wc_get_order( $object_id );
		if ( $order instanceof WC_Order ) {
			return $order->get_meta( $meta_key, true );
		}

		return null;
	}

	/**
	 * 注文または定期購入に単一メタを保存します。
	 *
	 * @param int    $object_id オブジェクト ID。
	 * @param string $meta_key  メタキー。
	 * @param mixed  $meta_value メタ値。
	 * @return void
	 */
	protected function update_object_meta( $object_id, $meta_key, $meta_value ) {
		$this->set_object_meta_bulk(
			$object_id,
			array(
				$meta_key => $meta_value,
			)
		);
	}

	/**
	 * 注文または定期購入に複数メタをまとめて保存します。
	 *
	 * @param int   $object_id オブジェクト ID。
	 * @param array $meta_map  メタ一覧。
	 * @return void
	 */
	protected function set_object_meta_bulk( $object_id, array $meta_map ) {
		$order = wc_get_order( $object_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		foreach ( $meta_map as $meta_key => $meta_value ) {
			$order->update_meta_data( $meta_key, $meta_value );
		}
		$order->save();
	}
	}
}
