<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Test_Subscription_For_Woocommerce' ) ) {
	/**
	 * 定期購入の最小フローをまとめたメインクラスです。
	 */
	class Test_Subscription_For_Woocommerce {
	/**
	 * プラグイン内部バージョンです。
	 */
	const PLUGIN_VERSION = '0.2.1';

	/**
	 * 定期購入レコードの注文タイプです。
	 */
	const SUBSCRIPTION_TYPE = 'tsfw_subscription';

	/**
	 * 更新注文を作る定期処理のフック名です。
	 */
	const ACTION_CREATE_RENEWALS = 'tsfw_create_renewal_orders';

	/**
	 * 期限切れ判定の定期処理のフック名です。
	 */
	const ACTION_EXPIRE_SUBSCRIPTIONS = 'tsfw_expire_subscriptions';

	/**
	 * 初期化します。
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_subscription_order_type' ), 5 );
		add_action( 'init', array( $this, 'register_account_endpoints' ), 6 );
		add_action( 'init', array( $this, 'register_cron' ), 20 );
		add_action( 'init', array( $this, 'maybe_refresh_rewrite_rules' ), 99 );
		add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );
		add_filter( 'query_vars', array( $this, 'register_account_query_vars' ) );

		add_filter( 'product_type_options', array( $this, 'add_product_type_option' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );
		add_action( 'admin_footer', array( $this, 'render_admin_script' ) );
		add_filter( 'manage_edit-product_columns', array( $this, 'add_product_list_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_list_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_list_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_list_column' ), 10, 2 );
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_order_list_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_order_list_column' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_head', array( $this, 'render_admin_list_styles' ) );
		add_action( 'template_redirect', array( $this, 'handle_front_actions' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_my_account_menu_item' ) );
		add_action( 'woocommerce_account_tsfw-subscriptions_endpoint', array( $this, 'render_my_account_page' ) );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_archive_subscription_notice' ), 15 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_subscription_notice' ), 11 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'append_price_interval_suffix' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'add_cart_subscription_item_data' ), 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'limit_subscription_payment_gateways' ) );
		add_action( 'wp_head', array( $this, 'render_front_styles' ) );

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'create_subscriptions_from_order' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'create_subscriptions_from_order' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( $this, 'create_subscriptions_from_order' ), 20, 1 );
		add_action( 'woocommerce_thankyou', array( $this, 'create_subscriptions_from_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 20, 3 );

		add_action( self::ACTION_CREATE_RENEWALS, array( $this, 'run_renewal_scheduler' ) );
		add_action( self::ACTION_EXPIRE_SUBSCRIPTIONS, array( $this, 'run_expire_scheduler' ) );
	}

	/**
	 * エンドポイント変更時に 1 回だけ rewrite を更新します。
	 *
	 * @return void
	 */
	public function maybe_refresh_rewrite_rules() {
		$saved_version = get_option( 'tsfw_plugin_version', '' );

		if ( self::PLUGIN_VERSION === $saved_version ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( 'tsfw_plugin_version', self::PLUGIN_VERSION );
	}

	/**
	 * マイアカウント用エンドポイントを登録します。
	 *
	 * @return void
	 */
	public function register_account_endpoints() {
		add_rewrite_endpoint( 'tsfw-subscriptions', EP_ROOT | EP_PAGES );
	}

	/**
	 * マイアカウント用クエリ変数を追加します。
	 *
	 * @param array $vars 既存のクエリ変数。
	 * @return array
	 */
	public function register_account_query_vars( $vars ) {
		$vars[] = 'tsfw-subscriptions';

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
			'定期購入一覧',
			'定期購入一覧',
			'manage_woocommerce',
			'tsfw-subscriptions',
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
				$new_items['tsfw-subscriptions'] = '定期購入一覧';
			}
		}

		if ( ! isset( $new_items['tsfw-subscriptions'] ) ) {
			$new_items['tsfw-subscriptions'] = '定期購入一覧';
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

		if ( 'tsfw-subscriptions' !== $page ) {
			return;
		}

		if ( 'POST' === $this->get_request_method() && ! empty( $_POST['tsfw_action'] ) ) {
			$action = sanitize_text_field( wp_unslash( $_POST['tsfw_action'] ) );

			if ( 'change_status' === $action ) {
				check_admin_referer( 'tsfw_change_subscription_status' );

				$subscription_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;
				$target_status   = isset( $_POST['target_status'] ) ? sanitize_text_field( wp_unslash( $_POST['target_status'] ) ) : '';

				if ( $subscription_id && $this->manually_update_subscription_status( $subscription_id, $target_status ) ) {
					$redirect_url = add_query_arg(
						array(
							'page'            => 'tsfw-subscriptions',
							'subscription_id' => $subscription_id,
							'tsfw_notice'     => rawurlencode( '定期購入状態を更新しました。' ),
							'tsfw_count'      => 1,
						),
						admin_url( 'admin.php' )
					);

					wp_safe_redirect( $redirect_url );
					exit;
				}
			}
		}

		if ( empty( $_GET['tsfw_action'] ) ) {
			return;
		}

		check_admin_referer( 'tsfw_admin_action' );

		$action  = sanitize_text_field( wp_unslash( $_GET['tsfw_action'] ) );
		$count   = 0;
		$message = '';

		if ( 'run_renewal' === $action ) {
			$count   = $this->run_renewal_scheduler();
			$message = '更新注文スキャンを実行しました。';
		} elseif ( 'run_expire' === $action ) {
			$count   = $this->run_expire_scheduler();
			$message = '期限切れ判定を実行しました。';
		} else {
			return;
		}

		$redirect_url = add_query_arg(
			array(
				'page'         => 'tsfw-subscriptions',
				'tsfw_notice'  => rawurlencode( $message ),
				'tsfw_count'   => $count,
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

		$action = isset( $_POST['tsfw_front_action'] ) ? sanitize_text_field( wp_unslash( $_POST['tsfw_front_action'] ) ) : '';
		if ( 'cancel_subscription' !== $action ) {
			return;
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;
		$redirect_url    = add_query_arg(
			array(
				'subscription_id' => $subscription_id,
			),
			wc_get_account_endpoint_url( 'tsfw-subscriptions' )
		);

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'tsfw_front_cancel_subscription' ) ) {
			wc_add_notice( '不正なリクエストです。時間をおいて再度お試しください。', 'error' );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		if ( ! $subscription_id || ! $this->user_owns_subscription( $subscription_id, get_current_user_id() ) ) {
			wc_add_notice( '対象の定期購入が見つかりませんでした。', 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'tsfw-subscriptions' ) );
			exit;
		}

		$status = (string) $this->get_object_meta( $subscription_id, '_tsfw_subscription_status' );
		if ( ! in_array( $status, array( 'pending', 'active', 'on-hold' ), true ) ) {
			wc_add_notice( 'この定期購入は現在キャンセルできません。', 'error' );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		$this->set_subscription_meta(
			$subscription_id,
			array(
				'_tsfw_subscription_status' => 'cancelled',
				'_tsfw_next_payment'        => 0,
			)
		);

		$subscription = wc_get_order( $subscription_id );
		if ( $subscription instanceof WC_Order ) {
			$subscription->add_order_note( 'お客様がマイアカウントから定期購入をキャンセルしました。' );
		}
		$this->send_subscription_cancel_email( $subscription_id, 'customer' );

		wc_add_notice( '定期購入をキャンセルしました。', 'success' );
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
				$new_columns['tsfw_subscription_product'] = '定期購入';
			}
		}

		if ( ! isset( $new_columns['tsfw_subscription_product'] ) ) {
			$new_columns['tsfw_subscription_product'] = '定期購入';
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
		if ( 'tsfw_subscription_product' !== $column ) {
			return;
		}

		if ( $this->is_subscription_product( $post_id ) ) {
			echo '<mark class="order-status status-processing"><span>定期購入商品</span></mark>';
		} else {
			echo '<span style="color:#8c8f94;">-</span>';
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
				$new_columns['tsfw_renewal_order'] = '定期購入';
			}
		}

		if ( ! isset( $new_columns['tsfw_renewal_order'] ) ) {
			$new_columns['tsfw_renewal_order'] = '定期購入';
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
		if ( 'tsfw_renewal_order' !== $column ) {
			return;
		}

		$order = is_numeric( $order_id ) ? wc_get_order( $order_id ) : $order_id;
		if ( ! $order instanceof WC_Order ) {
			echo '<span style="color:#8c8f94;">-</span>';
			return;
		}

		if ( 'yes' === $order->get_meta( '_tsfw_is_renewal_order', true ) ) {
			$subscription_id = absint( $order->get_meta( '_tsfw_subscription_id', true ) );

			echo '<mark class="order-status status-on-hold"><span>更新注文</span></mark>';
			if ( $subscription_id ) {
				echo '<div style="margin-top:4px;color:#50575e;">#' . esc_html( $subscription_id ) . '</div>';
			}
			return;
		}

		if ( 'yes' === $order->get_meta( '_tsfw_has_subscription', true ) ) {
			echo '<mark class="order-status status-processing"><span>初回定期購入注文</span></mark>';
			return;
		}

		echo '<span style="color:#8c8f94;">-</span>';
	}

	/**
	 * 商品一覧と注文一覧のカラム幅を整えます。
	 *
	 * @return void
	 */
	public function render_admin_list_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$is_product_screen = 'edit-product' === $screen->id;
		$is_order_screen   = in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true );
		$is_tsfw_screen    = 'woocommerce_page_tsfw-subscriptions' === $screen->id;

		if ( ! $is_product_screen && ! $is_order_screen && ! $is_tsfw_screen ) {
			return;
		}
		?>
		<style>
			.column-tsfw_subscription_product,
			.column-tsfw_renewal_order {
				width: 110px;
				white-space: nowrap;
			}

			.column-tsfw_subscription_product mark,
			.column-tsfw_renewal_order mark {
				white-space: nowrap;
			}

			.tsfw-admin-grid {
				display: grid;
				grid-template-columns: repeat(4, minmax(160px, 1fr));
				gap: 12px;
				margin: 16px 0 20px;
			}

			.tsfw-admin-card {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				padding: 14px 16px;
			}

			.tsfw-admin-card strong {
				display: block;
				font-size: 24px;
				line-height: 1.2;
				margin-top: 6px;
			}

			.tsfw-admin-table {
				width: 100%;
				border-collapse: collapse;
				background: #fff;
				border: 1px solid #dcdcde;
			}

			.tsfw-admin-table th,
			.tsfw-admin-table td {
				padding: 10px 12px;
				border-bottom: 1px solid #f0f0f1;
				text-align: left;
				vertical-align: top;
			}

			.tsfw-admin-table th {
				background: #f6f7f7;
			}

			.tsfw-admin-detail {
				margin-top: 20px;
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				padding: 16px;
			}

			.tsfw-note-list {
				margin: 12px 0 0;
			}

			.tsfw-note-list li {
				padding: 10px 0;
				border-bottom: 1px solid #f0f0f1;
			}

			.tsfw-admin-meta {
				display: grid;
				grid-template-columns: repeat(2, minmax(220px, 1fr));
				gap: 10px 18px;
				margin: 14px 0 18px;
			}

			.tsfw-admin-meta div {
				padding: 10px 12px;
				background: #f6f7f7;
				border-radius: 6px;
			}

			.tsfw-admin-meta strong {
				display: block;
				margin-bottom: 4px;
			}

			.tsfw-admin-subtable {
				width: 100%;
				border-collapse: collapse;
				margin-top: 14px;
			}

			.tsfw-admin-subtable th,
			.tsfw-admin-subtable td {
				padding: 10px 12px;
				border-bottom: 1px solid #f0f0f1;
				text-align: left;
				vertical-align: top;
			}

			.tsfw-admin-subtable th {
				background: #f6f7f7;
			}
		</style>
		<?php
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

		$subscription_ids = $this->get_all_subscription_ids();
		$selected_id      = $this->get_query_int( 'subscription_id' );
		$selected_order   = $selected_id ? wc_get_order( $selected_id ) : false;
		$counts           = $this->get_subscription_status_counts( $subscription_ids );
		$notice           = $this->get_query_text( 'tsfw_notice' );
		$notice_count     = $this->get_query_int( 'tsfw_count' );
		$renewal_url      = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'tsfw-subscriptions',
					'tsfw_action' => 'run_renewal',
				),
				admin_url( 'admin.php' )
			),
			'tsfw_admin_action'
		);
		$expire_url       = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'tsfw-subscriptions',
					'tsfw_action' => 'run_expire',
				),
				admin_url( 'admin.php' )
			),
			'tsfw_admin_action'
		);
		?>
		<div class="wrap">
			<h1>定期購入一覧</h1>
			<p>このページでは、定期購入プラグインが作成した定期購入レコードと状態の流れをまとめて確認できます。</p>
			<p>
				<a href="<?php echo esc_url( $renewal_url ); ?>" class="button button-primary">今すぐ更新注文スキャンを実行</a>
				<a href="<?php echo esc_url( $expire_url ); ?>" class="button">今すぐ期限切れ判定を実行</a>
			</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( $notice ); ?> 対象件数: <?php echo esc_html( $notice_count ); ?></p>
				</div>
			<?php endif; ?>

			<div class="tsfw-admin-grid">
				<div class="tsfw-admin-card">
					<div>全定期購入件数</div>
					<strong><?php echo esc_html( count( $subscription_ids ) ); ?></strong>
				</div>
				<div class="tsfw-admin-card">
					<div>有効</div>
					<strong><?php echo esc_html( $counts['active'] ); ?></strong>
				</div>
				<div class="tsfw-admin-card">
					<div>一時停止</div>
					<strong><?php echo esc_html( $counts['on-hold'] ); ?></strong>
				</div>
				<div class="tsfw-admin-card">
					<div>期限切れ</div>
					<strong><?php echo esc_html( $counts['expired'] ); ?></strong>
				</div>
			</div>

			<table class="tsfw-admin-table">
				<thead>
					<tr>
						<th>定期購入 ID</th>
						<th>状態</th>
						<th>商品</th>
						<th>親注文</th>
						<th>次回請求日</th>
						<th>終了日</th>
						<th>最終更新注文</th>
						<th>流れ</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $subscription_ids ) ) : ?>
						<tr>
							<td colspan="8">まだ定期購入レコードはありません。</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $subscription_ids as $subscription_id ) : ?>
							<?php
							$status            = (string) $this->get_object_meta( $subscription_id, '_tsfw_subscription_status' );
							$product_id        = absint( $this->get_object_meta( $subscription_id, '_tsfw_product_id' ) );
							$parent_order_id   = absint( $this->get_object_meta( $subscription_id, '_tsfw_parent_order_id' ) );
							$next_payment      = (int) $this->get_object_meta( $subscription_id, '_tsfw_next_payment' );
							$end_date          = (int) $this->get_object_meta( $subscription_id, '_tsfw_end_date' );
							$last_renewal_id   = absint( $this->get_object_meta( $subscription_id, '_tsfw_last_renewal_order' ) );
							$product_title     = $product_id ? get_the_title( $product_id ) : '-';
							$detail_page_url   = add_query_arg(
								array(
									'page'            => 'tsfw-subscriptions',
									'subscription_id' => $subscription_id,
								),
								admin_url( 'admin.php' )
							);
							?>
							<tr>
								<td>#<?php echo esc_html( $subscription_id ); ?></td>
								<td><?php echo wp_kses_post( $this->render_status_badge( $status ) ); ?></td>
								<td>
									<?php if ( $product_id ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>"><?php echo esc_html( $product_title ); ?></a>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $parent_order_id ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $parent_order_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $parent_order_id ); ?></a>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $this->format_timestamp( $next_payment ) ); ?></td>
								<td><?php echo esc_html( $this->format_timestamp( $end_date ) ); ?></td>
								<td>
									<?php if ( $last_renewal_id ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $last_renewal_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $last_renewal_id ); ?></a>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
								<td><a href="<?php echo esc_url( $detail_page_url ); ?>">詳細を見る</a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $selected_order instanceof WC_Order ) : ?>
				<?php
				$selected_status   = (string) $this->get_object_meta( $selected_id, '_tsfw_subscription_status' );
				$selected_product  = absint( $this->get_object_meta( $selected_id, '_tsfw_product_id' ) );
				$selected_parent   = absint( $this->get_object_meta( $selected_id, '_tsfw_parent_order_id' ) );
				$selected_customer = absint( $this->get_object_meta( $selected_id, '_tsfw_customer_id' ) );
				$selected_start    = (int) $this->get_object_meta( $selected_id, '_tsfw_schedule_start' );
				$selected_next     = (int) $this->get_object_meta( $selected_id, '_tsfw_next_payment' );
				$selected_end      = (int) $this->get_object_meta( $selected_id, '_tsfw_end_date' );
				$interval_count    = max( 1, absint( $this->get_object_meta( $selected_id, '_tsfw_interval_count' ) ) );
				$interval_unit     = (string) $this->get_object_meta( $selected_id, '_tsfw_interval_unit' );
				$renewal_ids       = $this->get_object_meta( $selected_id, '_tsfw_renewal_order_ids' );
				$renewal_ids       = is_array( $renewal_ids ) ? $renewal_ids : array();
				$order_notes       = wc_get_order_notes(
					array(
						'order_id' => $selected_id,
						'orderby'  => 'date_created',
						'order'    => 'ASC',
					)
				);
				?>
				<div class="tsfw-admin-detail">
					<h2>定期購入 #<?php echo esc_html( $selected_id ); ?> の詳細</h2>
					<p>現在の状態: <?php echo wp_kses_post( $this->render_status_badge( $selected_status ) ); ?></p>

					<div class="tsfw-admin-meta">
						<div>
							<strong>商品</strong>
							<?php if ( $selected_product ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $selected_product ) ); ?>"><?php echo esc_html( get_the_title( $selected_product ) ); ?></a>
							<?php else : ?>
								-
							<?php endif; ?>
						</div>
						<div>
							<strong>親注文</strong>
							<?php if ( $selected_parent ) : ?>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $selected_parent . '&action=edit' ) ); ?>">#<?php echo esc_html( $selected_parent ); ?></a>
							<?php else : ?>
								-
							<?php endif; ?>
						</div>
						<div>
							<strong>顧客 ID</strong>
							<?php echo $selected_customer ? esc_html( '#' . $selected_customer ) : '-'; ?>
						</div>
						<div>
							<strong>支払い方法</strong>
							<?php echo esc_html( $selected_order->get_payment_method_title() ? $selected_order->get_payment_method_title() : '-' ); ?>
						</div>
						<div>
							<strong>更新間隔</strong>
							<?php echo esc_html( $this->format_interval_label( $interval_count, $interval_unit ) ); ?>
						</div>
						<div>
							<strong>開始日時</strong>
							<?php echo esc_html( $this->format_timestamp( $selected_start ) ); ?>
						</div>
						<div>
							<strong>次回請求日</strong>
							<?php echo esc_html( $this->format_timestamp( $selected_next ) ); ?>
						</div>
						<div>
							<strong>終了日</strong>
							<?php echo esc_html( $this->format_timestamp( $selected_end ) ); ?>
						</div>
					</div>

					<h3>管理操作</h3>
					<form method="post" action="">
						<?php wp_nonce_field( 'tsfw_change_subscription_status' ); ?>
						<input type="hidden" name="page" value="tsfw-subscriptions" />
						<input type="hidden" name="tsfw_action" value="change_status" />
						<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $selected_id ); ?>" />
						<label for="tsfw-target-status"><strong>状態を手動変更</strong></label>
						<select id="tsfw-target-status" name="target_status" style="margin:0 8px;">
							<option value="pending" <?php selected( $selected_status, 'pending' ); ?>>保留</option>
							<option value="active" <?php selected( $selected_status, 'active' ); ?>>有効</option>
							<option value="on-hold" <?php selected( $selected_status, 'on-hold' ); ?>>一時停止</option>
							<option value="cancelled" <?php selected( $selected_status, 'cancelled' ); ?>>キャンセル</option>
							<option value="expired" <?php selected( $selected_status, 'expired' ); ?>>期限切れ</option>
						</select>
						<button type="submit" class="button button-secondary">状態を更新</button>
					</form>

					<h3>自動作成された更新注文</h3>
					<?php if ( empty( $renewal_ids ) ) : ?>
						<p>まだ自動作成された更新注文はありません。</p>
					<?php else : ?>
						<table class="tsfw-admin-subtable">
							<thead>
								<tr>
									<th>注文 ID</th>
									<th>状態</th>
									<th>作成日時</th>
									<th>合計</th>
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

					<h3>状態遷移メモ</h3>
					<ul class="tsfw-note-list">
						<?php if ( empty( $order_notes ) ) : ?>
							<li>まだメモはありません。</li>
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
			echo '<div class="woocommerce-info">ログインしてください。</div>';
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

		echo '<h2>定期購入一覧</h2>';

		if ( empty( $subscription_ids ) ) {
			echo '<div class="woocommerce-info">現在ご利用中の定期購入はありません。</div>';
			return;
		}
		?>
		<table class="shop_table shop_table_responsive my_account_orders account-orders-table">
			<thead>
				<tr>
					<th>定期購入 ID</th>
					<th>商品</th>
					<th>状態</th>
					<th>次回請求日</th>
					<th>終了日</th>
					<th>詳細</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $subscription_ids as $subscription_id ) : ?>
					<?php
					$status          = (string) $this->get_object_meta( $subscription_id, '_tsfw_subscription_status' );
					$product_id      = absint( $this->get_object_meta( $subscription_id, '_tsfw_product_id' ) );
					$next_payment    = (int) $this->get_object_meta( $subscription_id, '_tsfw_next_payment' );
					$end_date        = (int) $this->get_object_meta( $subscription_id, '_tsfw_end_date' );
					$detail_url      = add_query_arg(
						array(
							'subscription_id' => $subscription_id,
						),
						wc_get_account_endpoint_url( 'tsfw-subscriptions' )
					);
					?>
					<tr>
						<td data-title="定期購入 ID">#<?php echo esc_html( $subscription_id ); ?></td>
						<td data-title="商品"><?php echo esc_html( $product_id ? get_the_title( $product_id ) : '-' ); ?></td>
							<td data-title="状態"><?php echo wp_kses_post( $this->render_status_badge( $status ) ); ?></td>
						<td data-title="次回請求日"><?php echo esc_html( $this->format_timestamp( $next_payment ) ); ?></td>
						<td data-title="終了日"><?php echo esc_html( $this->format_timestamp( $end_date ) ); ?></td>
						<td data-title="詳細"><a class="button" href="<?php echo esc_url( $detail_url ); ?>">詳細を見る</a></td>
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
		$status          = (string) $this->get_object_meta( $subscription_id, '_tsfw_subscription_status' );
		$product_id      = absint( $this->get_object_meta( $subscription_id, '_tsfw_product_id' ) );
		$parent_order_id = absint( $this->get_object_meta( $subscription_id, '_tsfw_parent_order_id' ) );
		$next_payment    = (int) $this->get_object_meta( $subscription_id, '_tsfw_next_payment' );
		$end_date        = (int) $this->get_object_meta( $subscription_id, '_tsfw_end_date' );
		$start_date      = (int) $this->get_object_meta( $subscription_id, '_tsfw_schedule_start' );
		$renewal_ids     = $this->get_object_meta( $subscription_id, '_tsfw_renewal_order_ids' );
		$renewal_ids     = is_array( $renewal_ids ) ? $renewal_ids : array();
		$back_url        = wc_get_account_endpoint_url( 'tsfw-subscriptions' );
		$notes           = wc_get_order_notes(
			array(
				'order_id' => $subscription_id,
				'orderby'  => 'date_created',
				'order'    => 'DESC',
			)
		);

		echo '<h2>定期購入詳細</h2>';
		echo '<p><a href="' . esc_url( $back_url ) . '">一覧に戻る</a></p>';
		?>
		<table class="shop_table shop_table_responsive">
			<tbody>
				<tr>
					<th>定期購入 ID</th>
					<td>#<?php echo esc_html( $subscription_id ); ?></td>
				</tr>
				<tr>
					<th>商品</th>
					<td><?php echo esc_html( $product_id ? get_the_title( $product_id ) : '-' ); ?></td>
				</tr>
				<tr>
					<th>状態</th>
					<td><?php echo wp_kses_post( $this->render_status_badge( $status ) ); ?></td>
				</tr>
				<tr>
					<th>開始日時</th>
					<td><?php echo esc_html( $this->format_timestamp( $start_date ) ); ?></td>
				</tr>
				<tr>
					<th>次回請求日</th>
					<td><?php echo esc_html( $this->format_timestamp( $next_payment ) ); ?></td>
				</tr>
				<tr>
					<th>終了日</th>
					<td><?php echo esc_html( $this->format_timestamp( $end_date ) ); ?></td>
				</tr>
				<tr>
					<th>初回注文</th>
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
			<h3 style="margin-top:24px;">ご利用中のお手続き</h3>
			<form method="post" action="" onsubmit="return window.confirm('この定期購入をキャンセルします。よろしいですか？');">
				<?php wp_nonce_field( 'tsfw_front_cancel_subscription' ); ?>
				<input type="hidden" name="tsfw_front_action" value="cancel_subscription" />
				<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription_id ); ?>" />
				<button type="submit" class="button">この定期購入をキャンセルする</button>
			</form>
		<?php endif; ?>

		<h3 style="margin-top:24px;">更新注文</h3>
		<?php if ( empty( $renewal_ids ) ) : ?>
			<div class="woocommerce-info">まだ更新注文はありません。</div>
		<?php else : ?>
			<table class="shop_table shop_table_responsive my_account_orders account-orders-table">
				<thead>
					<tr>
						<th>注文 ID</th>
						<th>状態</th>
						<th>合計</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $renewal_ids as $renewal_id ) : ?>
						<?php $renewal_order = wc_get_order( $renewal_id ); ?>
						<tr>
							<td data-title="注文 ID">
								<?php if ( $renewal_order ) : ?>
									<a href="<?php echo esc_url( wc_get_endpoint_url( 'view-order', $renewal_id, wc_get_page_permalink( 'myaccount' ) ) ); ?>">#<?php echo esc_html( $renewal_id ); ?></a>
								<?php else : ?>
									#<?php echo esc_html( $renewal_id ); ?>
								<?php endif; ?>
							</td>
								<td data-title="状態"><?php echo $renewal_order ? wp_kses_post( $this->render_status_badge( $renewal_order->get_status() ) ) : '-'; ?></td>
							<td data-title="合計"><?php echo $renewal_order ? wp_kses_post( $renewal_order->get_formatted_order_total() ) : '-'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3 style="margin-top:24px;">状態遷移履歴</h3>
		<?php if ( empty( $notes ) ) : ?>
			<div class="woocommerce-info">まだ状態の更新履歴はありません。</div>
		<?php else : ?>
			<table class="shop_table shop_table_responsive my_account_orders account-orders-table">
				<thead>
					<tr>
						<th>日時</th>
						<th>内容</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $notes as $note ) : ?>
						<tr>
							<td data-title="日時"><?php echo esc_html( $this->format_datetime_string( $note->date_created ) ); ?></td>
							<td data-title="内容"><?php echo wp_kses_post( $this->link_order_references_in_account_note( $note->content, get_current_user_id() ) ); ?></td>
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

		echo wp_kses_post( $this->get_front_subscription_notice_html( $product_id, 'tsfw-subscription-notice tsfw-subscription-notice-archive' ) );
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

		$interval_label = $this->get_subscription_interval_label( $product_id );
		echo wp_kses_post( sprintf(
			'<p class="tsfw-subscription-notice-single"><span class="tsfw-subscription-badge">定期購入商品</span><span class="tsfw-subscription-interval">%s</span></p>',
			esc_html( $interval_label )
		) );
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

		$count  = max( 1, absint( get_post_meta( $product_id, '_tsfw_interval_count', true ) ) );
		$unit   = (string) get_post_meta( $product_id, '_tsfw_interval_unit', true );
		$suffix = $this->format_price_interval_suffix( $count, $unit );

		if ( '' === $suffix ) {
			return $price_html;
		}

		return $price_html . '<span class="tsfw-price-interval">' . esc_html( $suffix ) . '</span>';
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
			'day'   => '日',
			'week'  => '週',
			'month' => '月',
			'year'  => '年',
		);
		$unit_plural = array(
			'day'   => '日',
			'week'  => '週',
			'month' => 'か月',
			'year'  => '年',
		);

		if ( empty( $unit_single[ $unit ] ) ) {
			return '';
		}

		if ( 1 === $count ) {
			return '/' . $unit_single[ $unit ];
		}

		return '/' . $count . $unit_plural[ $unit ];
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
			'key'   => '定期購入種別',
			'value' => '定期購入商品',
		);

		$item_data[] = array(
			'key'   => '更新間隔',
			'value' => $this->get_subscription_interval_label( $product_id ),
		);

		return $item_data;
	}

	/**
	 * 定期購入商品購入時の支払い方法を銀行振込のみに制限します。
	 *
	 * @param array $available_gateways 利用可能な決済ゲートウェイ。
	 * @return array
	 */
	public function limit_subscription_payment_gateways( $available_gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $available_gateways;
		}

		if ( ! $this->cart_has_subscription_product() ) {
			return $available_gateways;
		}

		$manual_gateway_id = 'bacs';

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			if ( $manual_gateway_id !== $gateway_id ) {
				unset( $available_gateways[ $gateway_id ] );
			}
		}

		if ( isset( $available_gateways[ $manual_gateway_id ] ) ) {
			if ( WC()->session ) {
				WC()->session->set( 'chosen_payment_method', $manual_gateway_id );
			}

			return $available_gateways;
		}

		if ( function_exists( 'wc_has_notice' ) && ! wc_has_notice( '定期購入商品のお支払いには銀行振込を有効化してください。', 'error' ) ) {
			wc_add_notice( '定期購入商品のお支払いには銀行振込を有効化してください。', 'error' );
		}

		return array();
	}

	/**
	 * 前台の定期購入案内用スタイルを出力します。
	 *
	 * @return void
	 */
	public function render_front_styles() {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}

		if ( ! is_shop() && ! is_product_taxonomy() && ! is_product() && ! is_cart() && ! is_checkout() ) {
			return;
		}
		?>
		<style>
			.tsfw-subscription-notice {
				margin-top: 0.75em;
				padding: 0.75em 0.9em;
				border: 1px solid #d9e6d2;
				border-radius: 8px;
				background: #f6fbf2;
				font-size: 0.95em;
				line-height: 1.55;
			}

			.tsfw-subscription-notice strong {
				display: inline-block;
				margin-bottom: 0.2em;
				color: #355b2f;
			}

			.tsfw-subscription-notice-archive {
				font-size: 0.9em;
			}

			.tsfw-subscription-notice-single {
				display: flex;
				align-items: center;
				gap: 0.5em;
				margin: 0.4em 0 0.6em;
				line-height: 1.4;
			}

			.tsfw-subscription-badge {
				display: inline-block;
				padding: 0.1em 0.5em;
				background: #f0f8eb;
				border: 1px solid #c2ddb8;
				border-radius: 4px;
				font-size: 0.8em;
				font-weight: 600;
				color: #355b2f;
			}

			.tsfw-subscription-interval {
				font-size: 0.85em;
				color: #666;
			}

			.tsfw-price-interval {
				font-size: 0.7em;
				font-weight: normal;
				color: #888;
				margin-left: 0.15em;
			}
		</style>
		<?php
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
				$new_options['_tsfw_is_subscription'] = array(
					'id'            => '_tsfw_is_subscription',
					'wrapper_class' => 'show_if_simple show_if_variable',
					'label'         => '定期購入',
					'description'   => 'チェックすると、この商品を定期購入商品として扱います。',
					'default'       => 'no',
				);
			}
		}

		if ( ! isset( $new_options['_tsfw_is_subscription'] ) ) {
			$new_options['_tsfw_is_subscription'] = array(
				'id'            => '_tsfw_is_subscription',
				'wrapper_class' => 'show_if_simple show_if_variable',
				'label'         => '定期購入',
				'description'   => 'チェックすると、この商品を定期購入商品として扱います。',
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
					'name'          => 'テスト定期購入',
					'singular_name' => 'テスト定期購入',
					'menu_name'     => 'テスト定期購入',
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
				'class_name'                       => 'TSFW_Subscription_Order',
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
		$schedules['tsfw_every_thirty_minutes'] = array(
			'interval' => 1800,
			'display'  => '30 分ごと',
		);

		return $schedules;
	}

	/**
	 * 更新注文作成と期限切れ判定の定期処理を登録します。
	 *
	 * @return void
	 */
	public function register_cron() {
		if ( class_exists( 'ActionScheduler' ) && function_exists( 'as_next_scheduled_action' ) ) {
			if ( false === as_next_scheduled_action( self::ACTION_CREATE_RENEWALS ) ) {
				as_schedule_recurring_action( time() + 1800, 1800, self::ACTION_CREATE_RENEWALS );
			}

			if ( false === as_next_scheduled_action( self::ACTION_EXPIRE_SUBSCRIPTIONS ) ) {
				as_schedule_recurring_action( time() + 1800, 1800, self::ACTION_EXPIRE_SUBSCRIPTIONS );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::ACTION_CREATE_RENEWALS ) ) {
			wp_schedule_event( time() + 1800, 'tsfw_every_thirty_minutes', self::ACTION_CREATE_RENEWALS );
		}

		if ( ! wp_next_scheduled( self::ACTION_EXPIRE_SUBSCRIPTIONS ) ) {
			wp_schedule_event( time() + 1800, 'tsfw_every_thirty_minutes', self::ACTION_EXPIRE_SUBSCRIPTIONS );
		}
	}

	/**
	 * 商品編集画面に最小限の定期購入設定を追加します。
	 *
	 * @return void
	 */
	public function render_product_fields() {
		echo '<div class="options_group tsfw-subscription-fields" style="display:none;">';

		woocommerce_wp_text_input(
			array(
				'id'                => '_tsfw_interval_count',
				'label'             => '更新間隔の数値',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => 1,
					'step' => 1,
				),
				'description'       => '例: 1',
			)
		);

		woocommerce_wp_select(
			array(
				'id'          => '_tsfw_interval_unit',
				'label'       => '更新間隔の単位',
				'options'     => array(
					'day'   => '日',
					'week'  => '週',
					'month' => 'か月',
					'year'  => '年',
				),
				'description' => '例: 毎月なら「1 / か月」です。',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_tsfw_expiry_count',
				'label'             => '終了までの数値',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => 0,
					'step' => 1,
				),
				'description'       => '0 または空欄なら無期限です。',
			)
		);

		woocommerce_wp_select(
			array(
				'id'      => '_tsfw_expiry_unit',
				'label'   => '終了までの単位',
				'options' => array(
					''      => '未設定',
					'day'   => '日',
					'week'  => '週',
					'month' => 'か月',
					'year'  => '年',
				),
			)
		);

		echo '</div>';
	}

	/**
	 * 商品編集画面で定期購入チェックの位置調整と表示制御を行います。
	 *
	 * @return void
	 */
	public function render_admin_script() {
		global $post;

		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}
		?>
		<script type="text/javascript">
			jQuery(function($) {
				function tsfwToggleFields() {
					var checked = $('#_tsfw_is_subscription').is(':checked');
					$('.tsfw-subscription-fields').toggle(checked);
				}

				tsfwToggleFields();

				$(document.body).on('wc-init-product-type-options', function() {
					tsfwToggleFields();
				});

				$(document).on('change', '#_tsfw_is_subscription', function() {
					tsfwToggleFields();
				});
			});
		</script>
		<?php
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

		$is_subscription = isset( $_POST['_tsfw_is_subscription'] ) ? 'yes' : 'no';

		update_post_meta( $product_id, '_tsfw_is_subscription', $is_subscription );

		$interval_count = isset( $_POST['_tsfw_interval_count'] ) ? absint( wp_unslash( $_POST['_tsfw_interval_count'] ) ) : 1;
		$interval_unit  = isset( $_POST['_tsfw_interval_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['_tsfw_interval_unit'] ) ) : 'month';
		$expiry_count   = isset( $_POST['_tsfw_expiry_count'] ) ? absint( wp_unslash( $_POST['_tsfw_expiry_count'] ) ) : 0;
		$expiry_unit    = isset( $_POST['_tsfw_expiry_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['_tsfw_expiry_unit'] ) ) : '';

		update_post_meta( $product_id, '_tsfw_interval_count', max( 1, $interval_count ) );
		update_post_meta( $product_id, '_tsfw_interval_unit', $interval_unit );
		update_post_meta( $product_id, '_tsfw_expiry_count', $expiry_count );
		update_post_meta( $product_id, '_tsfw_expiry_unit', $expiry_unit );
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

		if ( 'yes' === $this->get_object_meta( $order_id, '_tsfw_has_subscription' ) ) {
			return;
		}

		if ( ! empty( $this->get_subscription_ids_by_parent_order( $order_id, array( 'pending', 'active', 'on-hold', 'cancelled', 'expired' ) ) ) ) {
			$this->update_object_meta( $order_id, '_tsfw_has_subscription', 'yes' );
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
			$this->update_object_meta( $order_id, '_tsfw_has_subscription', 'yes' );
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
		$subscription = new TSFW_Subscription_Order();
		$subscription->set_status( 'pending' );
		$subscription->set_parent_id( $order->get_id() );
		$subscription->set_customer_id( $order->get_customer_id() );
		$subscription->set_created_via( 'tsfw' );
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

		$this->set_subscription_meta( $post_id, array(
			'_tsfw_parent_order_id'     => $order->get_id(),
			'_tsfw_customer_id'         => $order->get_customer_id(),
			'_tsfw_product_id'          => $product->get_id(),
			'_tsfw_product_name'        => $product->get_name(),
			'_tsfw_product_qty'         => $item->get_quantity(),
			'_tsfw_interval_count'      => max( 1, absint( get_post_meta( $product->get_id(), '_tsfw_interval_count', true ) ) ),
			'_tsfw_interval_unit'       => get_post_meta( $product->get_id(), '_tsfw_interval_unit', true ),
			'_tsfw_expiry_count'        => absint( get_post_meta( $product->get_id(), '_tsfw_expiry_count', true ) ),
			'_tsfw_expiry_unit'         => get_post_meta( $product->get_id(), '_tsfw_expiry_unit', true ),
			'_tsfw_subscription_status' => 'pending',
			'_tsfw_schedule_start'      => 0,
			'_tsfw_next_payment'        => 0,
			'_tsfw_end_date'            => 0,
			'_tsfw_last_renewal_order'  => 0,
			'_tsfw_line_subtotal'       => $item->get_subtotal(),
			'_tsfw_line_subtotal_tax'   => $item->get_subtotal_tax(),
			'_tsfw_line_total'          => $item->get_total(),
			'_tsfw_line_tax'            => $item->get_total_tax(),
			'_tsfw_line_tax_data'       => wp_json_encode( $item->get_taxes() ),
		) );

		$this->update_object_meta( $order->get_id(), '_tsfw_subscription_id', $post_id );
		$order->add_order_note( sprintf( '定期購入レコード #%d を作成しました。', $post_id ) );
		$subscription->add_order_note( sprintf( '親注文 #%d から作成されました。', $order->get_id() ) );

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

		if ( 'yes' === $order->get_meta( '_tsfw_is_renewal_order', true ) ) {
			$this->handle_renewal_order_status_change( $order, $new_status );
			return;
		}

		if ( 'yes' !== $this->get_object_meta( $order_id, '_tsfw_has_subscription' ) ) {
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
			$end_date     = $this->calculate_end_date( $subscription_id, $current_time );

			$this->set_subscription_meta( $subscription_id, array(
				'_tsfw_subscription_status' => 'active',
				'_tsfw_schedule_start'      => $current_time,
				'_tsfw_next_payment'        => $next_payment,
				'_tsfw_end_date'            => $end_date,
			) );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( '初回注文の入金を確認したため、定期購入を有効化しました。' );
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
		$subscription_id = absint( $renewal_order->get_meta( '_tsfw_subscription_id', true ) );
		if ( ! $subscription_id ) {
			return;
		}

		if ( 'failed' === $new_status ) {
			$this->set_subscription_meta( $subscription_id, array(
				'_tsfw_subscription_status' => 'on-hold',
			) );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( sprintf( '更新注文 #%d の支払いが失敗したため、定期購入を一時停止にしました。', $renewal_order->get_id() ) );
			}

			return;
		}

		if ( 'cancelled' === $new_status ) {
			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( sprintf( '更新注文 #%d はキャンセルされましたが、定期購入状態は自動変更していません。必要に応じて手動でキャンセルしてください。', $renewal_order->get_id() ) );
			}

			return;
		}

		if ( in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
			$current_time = current_time( 'timestamp' );
			$next_payment = $this->calculate_next_payment( $subscription_id, $current_time );

			$this->set_subscription_meta( $subscription_id, array(
				'_tsfw_subscription_status' => 'active',
				'_tsfw_next_payment'        => $next_payment,
			) );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( sprintf( '更新注文 #%d の入金を確認したため、定期購入を再開しました。', $renewal_order->get_id() ) );
			}
			$this->send_subscription_reactivated_email( $subscription_id, 'renewal-order' );
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
				'_tsfw_subscription_status' => 'cancelled',
				'_tsfw_next_payment'        => 0,
			) );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( '親注文がキャンセルされたため、この定期購入もキャンセルしました。' );
			}
			$this->send_subscription_cancel_email( $subscription_id, 'parent-order' );
		}
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
						'key'   => '_tsfw_subscription_status',
						'value' => 'active',
					),
					array(
						'key'     => '_tsfw_next_payment',
						'value'   => $current_time,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $subscription_ids as $subscription_id ) {
			if ( $this->create_renewal_order( $subscription_id, $current_time ) ) {
				$processed++;
			}
		}

		return $processed;
	}

	/**
	 * 期限を過ぎた定期購入を期限切れにします。
	 *
	 * @return void
	 */
	public function run_expire_scheduler() {
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
						'key'   => '_tsfw_subscription_status',
						'value' => array( 'active', 'on-hold' ),
					),
					array(
						'key'     => '_tsfw_end_date',
						'value'   => 0,
						'compare' => '!=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_tsfw_end_date',
						'value'   => $current_time,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $subscription_ids as $subscription_id ) {
			$this->set_subscription_meta( $subscription_id, array(
				'_tsfw_subscription_status' => 'expired',
				'_tsfw_next_payment'        => 0,
			) );

			$subscription = wc_get_order( $subscription_id );
			if ( $subscription instanceof WC_Order ) {
				$subscription->add_order_note( '定期購入終了日に到達したため、期限切れにしました。' );
			}
			$this->send_subscription_expired_email( $subscription_id );

			$processed++;
		}

		return $processed;
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

		$product_id = absint( $this->get_object_meta( $subscription_id, '_tsfw_product_id' ) );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			$subscription->add_order_note( '商品が見つからないため、更新注文を作成できませんでした。' );
			return 0;
		}

		$renewal_order = wc_create_order(
			array(
				'status'      => 'pending',
				'customer_id' => absint( $this->get_object_meta( $subscription_id, '_tsfw_customer_id' ) ),
			)
		);

		if ( ! $renewal_order instanceof WC_Order ) {
			return 0;
		}

		$tax_data = $this->get_object_meta( $subscription_id, '_tsfw_line_tax_data' );
		$tax_data = $tax_data ? json_decode( $tax_data, true ) : array();

		$renewal_order->add_product(
			$product,
			max( 1, absint( $this->get_object_meta( $subscription_id, '_tsfw_product_qty' ) ) ),
			array(
				'totals' => array(
					'subtotal'     => (float) $this->get_object_meta( $subscription_id, '_tsfw_line_subtotal' ),
					'subtotal_tax' => (float) $this->get_object_meta( $subscription_id, '_tsfw_line_subtotal_tax' ),
					'total'        => (float) $this->get_object_meta( $subscription_id, '_tsfw_line_total' ),
					'tax'          => (float) $this->get_object_meta( $subscription_id, '_tsfw_line_tax' ),
					'tax_data'     => is_array( $tax_data ) ? $tax_data : array(),
				),
			)
		);

		$renewal_order->set_address( $subscription->get_address( 'billing' ), 'billing' );
		$renewal_order->set_address( $subscription->get_address( 'shipping' ), 'shipping' );
		$renewal_order->set_currency( $subscription->get_currency() );
		$renewal_order->set_payment_method( $subscription->get_payment_method() );
		$renewal_order->set_payment_method_title( $subscription->get_payment_method_title() );
		$renewal_order->calculate_totals();
		$renewal_order->save();

		$this->set_object_meta_bulk(
			$renewal_order->get_id(),
			array(
				'_tsfw_is_renewal_order' => 'yes',
				'_tsfw_subscription_id'  => $subscription_id,
				'_tsfw_parent_order_id'  => absint( $this->get_object_meta( $subscription_id, '_tsfw_parent_order_id' ) ),
			)
		);

		$renewal_ids   = $this->get_object_meta( $subscription_id, '_tsfw_renewal_order_ids' );
		$renewal_ids   = is_array( $renewal_ids ) ? $renewal_ids : array();
		$renewal_ids[] = $renewal_order->get_id();

		$this->set_subscription_meta( $subscription_id, array(
			'_tsfw_last_renewal_order' => $renewal_order->get_id(),
			'_tsfw_renewal_order_ids'  => $renewal_ids,
			'_tsfw_next_payment'       => $this->calculate_next_payment( $subscription_id, $current_time ),
		) );

		$subscription->add_order_note( sprintf( '更新注文 #%d を作成しました。', $renewal_order->get_id() ) );
		$renewal_order->add_order_note( sprintf( 'この更新注文は定期購入 #%d に紐づいています。', $subscription_id ) );

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

		if ( 'yes' === get_post_meta( $product_id, '_tsfw_is_subscription', true ) ) {
			return true;
		}

		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $parent_id && 'yes' === get_post_meta( $parent_id, '_tsfw_is_subscription', true ) ) {
			return true;
		}

		return 'yes' === get_post_meta( $product_id, '_tsfw_is_subscription', true );
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
	 * 商品用の定期購入案内 HTML を返します。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $class      追加クラス。
	 * @return string
	 */
	protected function get_front_subscription_notice_html( $product_id, $class = '' ) {
		$interval_label = $this->get_subscription_interval_label( $product_id );
		$class_attr     = trim( $class );

		return sprintf(
			'<div class="%1$s"><strong>%2$s</strong><div>%3$s: %4$s</div></div>',
			esc_attr( $class_attr ),
			esc_html( '定期購入商品' ),
			esc_html( '更新間隔' ),
			esc_html( $interval_label )
		);
	}

	/**
	 * 商品の更新間隔を表示用ラベルに変換します。
	 *
	 * @param int $product_id 商品 ID。
	 * @return string
	 */
	protected function get_subscription_interval_label( $product_id ) {
		$count = max( 1, absint( get_post_meta( $product_id, '_tsfw_interval_count', true ) ) );
		$unit  = (string) get_post_meta( $product_id, '_tsfw_interval_unit', true );

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
						'key'   => '_tsfw_customer_id',
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
		return (int) $this->get_object_meta( $subscription_id, '_tsfw_customer_id' ) === (int) $user_id;
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
			'expired'   => 0,
			'pending'   => 0,
			'cancelled' => 0,
		);

		foreach ( $subscription_ids as $subscription_id ) {
			$status = (string) $this->get_object_meta( $subscription_id, '_tsfw_subscription_status' );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
		}

		return $counts;
	}

	/**
	 * 状態バッジの HTML を返します。
	 *
	 * @param string $status 状態名。
	 * @return string
	 */
	protected function render_status_badge( $status ) {
		$map = array(
			'pending'   => array( 'status-pending', '保留' ),
			'active'    => array( 'status-processing', '有効' ),
			'on-hold'   => array( 'status-on-hold', '一時停止' ),
			'cancelled' => array( 'status-cancelled', 'キャンセル' ),
			'expired'   => array( 'status-failed', '期限切れ' ),
		);

		$badge = isset( $map[ $status ] ) ? $map[ $status ] : array( 'status-pending', $status ? $status : '-' );

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
						'key'   => '_tsfw_parent_order_id',
						'value' => $order_id,
					),
					array(
						'key'   => '_tsfw_subscription_status',
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
		$count = max( 1, absint( $this->get_object_meta( $subscription_id, '_tsfw_interval_count' ) ) );
		$unit  = $this->get_object_meta( $subscription_id, '_tsfw_interval_unit' );

		return $this->add_interval_to_timestamp( $base_time, $count, $unit );
	}

	/**
	 * 定期購入終了日時を計算します。
	 *
	 * @param int $subscription_id 定期購入 ID。
	 * @param int $base_time       基準時刻。
	 * @return int
	 */
	protected function calculate_end_date( $subscription_id, $base_time ) {
		$count = absint( $this->get_object_meta( $subscription_id, '_tsfw_expiry_count' ) );
		$unit  = $this->get_object_meta( $subscription_id, '_tsfw_expiry_unit' );

		if ( empty( $count ) || empty( $unit ) ) {
			return 0;
		}

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
		$unit_map = array(
			'day'   => '日',
			'week'  => '週',
			'month' => 'か月',
			'year'  => '年',
		);

		if ( empty( $unit_map[ $unit ] ) ) {
			return '-';
		}

		return $count . $unit_map[ $unit ] . 'ごと';
	}

	/**
	 * 管理画面から定期購入状態を手動更新します。
	 *
	 * @param int    $subscription_id 定期購入 ID。
	 * @param string $target_status   変更後の状態。
	 * @return bool
	 */
	protected function manually_update_subscription_status( $subscription_id, $target_status ) {
		$allowed_statuses = array( 'pending', 'active', 'on-hold', 'cancelled', 'expired' );
		if ( ! in_array( $target_status, $allowed_statuses, true ) ) {
			return false;
		}

		$subscription = wc_get_order( $subscription_id );
		if ( ! $subscription instanceof WC_Order ) {
			return false;
		}

		$current_status = (string) $this->get_object_meta( $subscription_id, '_tsfw_subscription_status' );
		$current_time = current_time( 'timestamp' );
		$meta_map     = array(
			'_tsfw_subscription_status' => $target_status,
		);

		if ( 'active' === $target_status ) {
			$current_start = (int) $this->get_object_meta( $subscription_id, '_tsfw_schedule_start' );
			$meta_map['_tsfw_schedule_start'] = $current_start ? $current_start : $current_time;

			$current_next = (int) $this->get_object_meta( $subscription_id, '_tsfw_next_payment' );
			if ( empty( $current_next ) || $current_next <= $current_time ) {
				$meta_map['_tsfw_next_payment'] = $this->calculate_next_payment( $subscription_id, $current_time );
			}

			$current_end = (int) $this->get_object_meta( $subscription_id, '_tsfw_end_date' );
			if ( empty( $current_end ) ) {
				$calculated_end = $this->calculate_end_date( $subscription_id, $current_time );
				if ( $calculated_end ) {
					$meta_map['_tsfw_end_date'] = $calculated_end;
				}
			}
		} elseif ( in_array( $target_status, array( 'cancelled', 'expired' ), true ) ) {
			$meta_map['_tsfw_next_payment'] = 0;
		}

		$this->set_subscription_meta( $subscription_id, $meta_map );
		$subscription->add_order_note( sprintf( '管理画面から定期購入状態を %s に変更しました。', $target_status ) );

		if ( 'cancelled' === $target_status ) {
			$this->send_subscription_cancel_email( $subscription_id, 'admin' );
		} elseif ( 'expired' === $target_status && 'expired' !== $current_status ) {
			$this->send_subscription_expired_email( $subscription_id );
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

		$parent_order_id = absint( $this->get_object_meta( $subscription_id, '_tsfw_parent_order_id' ) );
		$parent_order    = $parent_order_id ? wc_get_order( $parent_order_id ) : false;
		$product_id      = absint( $this->get_object_meta( $subscription_id, '_tsfw_product_id' ) );
		$product_name    = (string) $this->get_object_meta( $subscription_id, '_tsfw_product_name' );
		$product_name    = $product_name ? $product_name : ( $product_id ? get_the_title( $product_id ) : '定期購入商品' );
		$customer_email  = $parent_order instanceof WC_Order ? $parent_order->get_billing_email() : '';
		$admin_email     = get_option( 'admin_email' );

		return array(
			'subscription'    => $subscription,
			'parent_order'    => $parent_order,
			'parent_order_id' => $parent_order_id,
			'product_name'    => $product_name,
			'next_payment'    => (int) $this->get_object_meta( $subscription_id, '_tsfw_next_payment' ),
			'end_date'        => (int) $this->get_object_meta( $subscription_id, '_tsfw_end_date' ),
			'detail_url'      => wc_get_account_endpoint_url( 'tsfw-subscriptions' ),
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

		$cancelled_by_label = 'システム';
		if ( 'customer' === $cancelled_by ) {
			$cancelled_by_label = 'お客様';
		} elseif ( 'admin' === $cancelled_by ) {
			$cancelled_by_label = '管理者';
		} elseif ( 'parent-order' === $cancelled_by ) {
			$cancelled_by_label = '親注文のキャンセル';
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf( '【%s】定期購入キャンセルのお知らせ', $site_name );

		$message  = '<p>定期購入がキャンセルされました。</p>';
		$message .= '<p><strong>定期購入 ID:</strong> #' . esc_html( $subscription_id ) . '<br />';
		$message .= '<strong>商品:</strong> ' . esc_html( $context['product_name'] ) . '<br />';
		$message .= '<strong>状態:</strong> キャンセル<br />';
		$message .= '<strong>キャンセル理由:</strong> ' . esc_html( $cancelled_by_label ) . '<br />';
		$message .= '<strong>親注文:</strong> ' . ( $context['parent_order_id'] ? '#' . esc_html( $context['parent_order_id'] ) : '-' ) . '<br />';
		$message .= '<strong>次回請求日:</strong> ' . esc_html( $this->format_timestamp( $context['next_payment'] ) ) . '</p>';
		$message .= '<p>定期購入内容の確認はマイアカウントよりご確認ください。<br /><a href="' . esc_url( $context['detail_url'] ) . '">' . esc_html( $context['detail_url'] ) . '</a></p>';

		$this->send_subscription_email( $context['recipients'], $subject, '定期購入キャンセルのお知らせ', $message );
	}

	/**
	 * 定期購入期限切れ通知メールを送信します。
	 *
	 * @param int $subscription_id 定期購入 ID。
	 * @return void
	 */
	protected function send_subscription_expired_email( $subscription_id ) {
		$context = $this->get_subscription_email_context( $subscription_id );
		if ( empty( $context ) ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf( '【%s】定期購入期限切れのお知らせ', $site_name );

		$message  = '<p>定期購入が期限切れになりました。</p>';
		$message .= '<p><strong>定期購入 ID:</strong> #' . esc_html( $subscription_id ) . '<br />';
		$message .= '<strong>商品:</strong> ' . esc_html( $context['product_name'] ) . '<br />';
		$message .= '<strong>状態:</strong> 期限切れ<br />';
		$message .= '<strong>終了日:</strong> ' . esc_html( $this->format_timestamp( $context['end_date'] ) ) . '<br />';
		$message .= '<strong>親注文:</strong> ' . ( $context['parent_order_id'] ? '#' . esc_html( $context['parent_order_id'] ) : '-' ) . '</p>';
		$message .= '<p>必要に応じて、管理者へお問い合わせください。<br /><a href="' . esc_url( $context['detail_url'] ) . '">' . esc_html( $context['detail_url'] ) . '</a></p>';

		$this->send_subscription_email( $context['recipients'], $subject, '定期購入期限切れのお知らせ', $message );
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

		$reactivated_by_label = 'システム';
		if ( 'admin' === $reactivated_by ) {
			$reactivated_by_label = '管理者';
		} elseif ( 'renewal-order' === $reactivated_by ) {
			$reactivated_by_label = '更新注文の入金確認';
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf( '【%s】定期購入再開のお知らせ', $site_name );

		$message  = '<p>定期購入が再開されました。</p>';
		$message .= '<p><strong>定期購入 ID:</strong> #' . esc_html( $subscription_id ) . '<br />';
		$message .= '<strong>商品:</strong> ' . esc_html( $context['product_name'] ) . '<br />';
		$message .= '<strong>状態:</strong> 有効<br />';
		$message .= '<strong>再開理由:</strong> ' . esc_html( $reactivated_by_label ) . '<br />';
		$message .= '<strong>次回請求日:</strong> ' . esc_html( $this->format_timestamp( $context['next_payment'] ) ) . '<br />';
		$message .= '<strong>親注文:</strong> ' . ( $context['parent_order_id'] ? '#' . esc_html( $context['parent_order_id'] ) : '-' ) . '</p>';
		$message .= '<p>定期購入内容の確認はマイアカウントよりご確認ください。<br /><a href="' . esc_url( $context['detail_url'] ) . '">' . esc_html( $context['detail_url'] ) . '</a></p>';

		$this->send_subscription_email( $context['recipients'], $subject, '定期購入再開のお知らせ', $message );
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

		return get_post_meta( $object_id, $meta_key, true );
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
		if ( $order instanceof WC_Order ) {
			foreach ( $meta_map as $meta_key => $meta_value ) {
				$order->update_meta_data( $meta_key, $meta_value );
			}
			$order->save();
			return;
		}

		foreach ( $meta_map as $meta_key => $meta_value ) {
			update_post_meta( $object_id, $meta_key, $meta_value );
		}
	}
	}
}
