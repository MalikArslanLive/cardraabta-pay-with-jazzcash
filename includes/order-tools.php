<?php
/** WooCommerce order checks, refund reconciliation and payment health. */
defined( 'ABSPATH' ) || exit;

function crj_refund_recorded_minor( WC_Order $order ): int {
	return (int) round( (float) $order->get_total_refunded() * 100 );
}

function crj_merchant_history_page( int $page, ?array $settings = null ): ?array {
	$response = crj_provider_request( 'history', array(), array( 'PageNumber' => $page, 'PageSize' => 50, 'SortOrder' => 'DESC', 'SortBy' => 'pp_TxnDateTime' ), $settings );
	if ( ! is_array( $response ) || (string) ( $response['pp_ResponseCode'] ?? '' ) !== '000' || ! is_array( $response['pp_Items'] ?? null ) ) {
		return null;
	}
	$items = array_slice( $response['pp_Items'], 0, 50 );
	return array(
		'rows' => array_values( array_filter( $items, 'is_array' ) ),
		'more' => count( $items ) === 50 && ( ! is_numeric( $response['pp_TxnCount'] ?? null ) || $page * 50 < (int) $response['pp_TxnCount'] ),
	);
}

function crj_recent_merchant_history(): ?array {
	return crj_merchant_history_page( 1 )['rows'] ?? null;
}

/** Match an exact paid attempt before writing local records for refunds made outside WooCommerce. */
function crj_sync_merchant_refund( int $id, array $row ): string {
	$balance = crj_history_refund_balance( $row );
	if ( $balance === null ) {
		return 'unverified';
	}
	crj_security_lock( 'order:' . $id );
	$order = wc_get_order( $id );
	if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || ! crj_refundable_attempt( $order )
		|| ( $row['pp_BillReference'] ?? '' ) !== 'ORDER-' . $id || ( $row['pp_TxnRefNo'] ?? '' ) !== $order->get_transaction_id()
		|| (int) $row['pp_Amount'] !== (int) crj_minor_amount( $order->get_total() ) ) {
		return 'unverified';
	}
	$settings = (array) get_option( 'woocommerce_crj_card_settings', array() );
	$merchant = (string) ( $settings['live_merchant_id'] ?? '' );
	if ( ( $row['pp_MerchantID'] ?? $merchant ) !== $merchant ) {
		return 'unverified';
	}
	$key = crj_direct_refund_key( $merchant, (string) $order->get_transaction_id() );
	crj_security_lock( $key );
	$record = $order->get_meta( '_crj_refund_in_flight' );
	$local = crj_refund_recorded_minor( $order );
	if ( $balance['refunded'] === $local ) {
		return 'current';
	}
	if ( $balance['refunded'] < $local || is_array( $record ) || get_option( $key . '_linked_dispatch', '' ) === 'dispatching'
		|| get_option( $key, '' ) === 'dispatching' ) {
		return 'unverified';
	}
	// Local accounting only. This call cannot send a second provider refund.
	$refund = wc_create_refund( array(
		'order_id'       => $id,
		'amount'         => wc_format_decimal( ( $balance['refunded'] - $local ) / 100, 2 ),
		'reason'         => __( 'Refund verified in JazzCash merchant history', 'cardraabta-pay-with-jazzcash' ),
		'refund_payment' => false,
		'restock_items'  => false,
	) );
	if ( ! $refund instanceof WC_Order_Refund ) {
		return 'unverified';
	}
	$refund->set_refunded_payment( true );
	$refund->update_meta_data( '_crj_original_provider_ref', $order->get_transaction_id() );
	$refund->save();
	$order->add_order_note( __( 'CardRaabta synced the verified JazzCash refund to this order.', 'cardraabta-pay-with-jazzcash' ) );
	return 'synced';
}

function crj_sync_recent_merchant_refunds(): void {
	$rows = crj_recent_merchant_history();
	if ( $rows === null ) {
		return;
	}
	$counts = array_count_values( array_filter( array_column( $rows, 'pp_TxnRefNo' ), 'is_string' ) );
	$checked = 0;
	foreach ( $rows as $row ) {
		if ( crj_history_refund_balance( $row ) === null || ! is_string( $row['pp_TxnRefNo'] ?? null ) || ( $counts[ $row['pp_TxnRefNo'] ] ?? 0 ) !== 1
			|| ! is_string( $row['pp_BillReference'] ?? null ) || ! preg_match( '/^ORDER-([1-9][0-9]*)$/D', $row['pp_BillReference'], $match ) ) {
			continue;
		}
		try {
			crj_sync_merchant_refund( (int) $match[1], $row );
		} catch ( Throwable $error ) {
			// The next run or a manager check can retry busy orders.
		}
		if ( ++$checked === 20 ) {
			break;
		}
	}
}

function crj_order_tools_actions( array $actions, $order = null ): array {
	global $theorder;
	$order = $order instanceof WC_Order ? $order : $theorder;
	if ( $order instanceof WC_Order && $order->get_payment_method() === 'crj_card'
		&& current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_shop_order', $order->get_id() ) ) {
		$actions['crj_check_status'] = __( 'CardRaabta: Sync with JazzCash', 'cardraabta-pay-with-jazzcash' );
	}
	return $actions;
}

function crj_order_tools_check( WC_Order $order ): void {
	$id = $order->get_id();
	if ( $order->get_payment_method() !== 'crj_card' || ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $id ) ) {
		return;
	}
	$args = array( $id );
	try {
		crj_security_lock( 'order-history:' . $id );
		if ( wp_next_scheduled( 'crj_continue_order_history', $args ) ) {
			return;
		}
		$order->update_meta_data( '_crj_history_scan', array(
			'page'     => 1,
			'merchant' => (string) ( ( (array) get_option( 'woocommerce_crj_card_settings', array() ) )['live_merchant_id'] ?? '' ),
			'ref'      => (string) $order->get_meta( '_crj_ref' ),
			'rows'     => array(),
			'previous' => '',
		) );
		$order->update_meta_data( '_crj_last_status_check', array(
			'time'    => time(),
			'pending' => true,
			'message' => __( 'Checking JazzCash transactions for this order. You can close this page.', 'cardraabta-pay-with-jazzcash' ),
		) );
		$order->save_meta_data();
		if ( ! wp_schedule_single_event( time() + 1, 'crj_continue_order_history', $args ) ) {
			$order->delete_meta_data( '_crj_history_scan' );
			$order->update_meta_data( '_crj_last_status_check', array(
				'time'    => time(),
				'message' => __( 'CardRaabta could not schedule the JazzCash check. Please try again.', 'cardraabta-pay-with-jazzcash' ),
			) );
			$order->save_meta_data();
		}
	} catch ( Throwable $error ) {
		// A check already running for the same order can finish normally.
	}
}

function crj_continue_order_history( int $id ): void {
	try {
		crj_security_lock( 'order-history:' . $id );
	} catch ( RuntimeException $error ) {
		if ( $error->getMessage() === 'Payment_request_busy' && ! wp_next_scheduled( 'crj_continue_order_history', array( $id ) ) ) {
			wp_schedule_single_event( time() + 10, 'crj_continue_order_history', array( $id ) );
		}
		return;
	}
	$order = wc_get_order( $id );
	if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'crj_card' ) {
		return;
	}
	$scan = $order->get_meta( '_crj_history_scan' );
	if ( ! is_array( $scan ) ) {
		return;
	}
	$settings = (array) get_option( 'woocommerce_crj_card_settings', array() );
	$message = __( 'JazzCash history could not be checked. The order was left unchanged.', 'cardraabta-pay-with-jazzcash' );
	if ( $scan['merchant'] === (string) ( $settings['live_merchant_id'] ?? '' ) && $scan['ref'] === (string) $order->get_meta( '_crj_ref' ) ) {
		$page = crj_merchant_history_page( (int) $scan['page'], $settings );
		if ( $page !== null ) {
			$fingerprint = hash( 'sha256', (string) wp_json_encode( array_column( $page['rows'], 'pp_TxnRefNo' ) ) );
			if ( $fingerprint !== $scan['previous'] ) {
				$columns = array_flip( array( 'pp_TxnRefNo', 'pp_BillReference', 'pp_TxnCurrency', 'pp_TxnType', 'pp_MerchantID', 'pp_Amount', 'pp_ResponseCode', 'pp_Status', 'pp_RefundedAmount', 'pp_RemainingRefundAmount' ) );
				foreach ( $page['rows'] as $row ) {
					if ( ( $row['pp_BillReference'] ?? '' ) === 'ORDER-' . $id ) {
						$clean = array_intersect_key( array_filter( $row, 'is_scalar' ), $columns );
						$scan['rows'][ hash( 'sha256', (string) wp_json_encode( $clean ) ) ] = $clean;
					}
				}
				if ( ! $page['more'] ) {
					$message = crj_apply_order_history( $order, array_values( $scan['rows'] ) );
				} else {
					$scan['page']++;
					$scan['previous'] = $fingerprint;
					$order->update_meta_data( '_crj_history_scan', $scan );
					$order->update_meta_data( '_crj_last_status_check', array(
						'time'    => time(),
						'pending' => true,
						/* translators: %s: next JazzCash history page. */
						'message' => sprintf( __( 'Checking JazzCash transactions for this order. Next batch: %s. You can close this page.', 'cardraabta-pay-with-jazzcash' ), number_format_i18n( $scan['page'] ) ),
					) );
					$order->save_meta_data();
					if ( wp_next_scheduled( 'crj_continue_order_history', array( $id ) )
						|| wp_schedule_single_event( time() + 10, 'crj_continue_order_history', array( $id ) ) ) {
						return;
					}
				}
			}
		}
	}
	$order->delete_meta_data( '_crj_history_scan' );
	$order->update_meta_data( '_crj_last_status_check', array( 'time' => time(), 'message' => $message ) );
	$order->save_meta_data();
	$order->add_order_note( $message );
}

function crj_apply_order_history( WC_Order $order, array $rows ): string {
	$id = $order->get_id();
	$message = __( 'JazzCash history has no matching verified transaction for this order.', 'cardraabta-pay-with-jazzcash' );
	try {
		if ( $order->get_date_paid() ) {
			$match = array_values( array_filter( $rows, static fn( $row ) => ( $row['pp_TxnRefNo'] ?? '' ) === $order->get_transaction_id()
				&& ( $row['pp_BillReference'] ?? '' ) === 'ORDER-' . $id
				&& ( $row['pp_TxnCurrency'] ?? '' ) === 'PKR'
				&& ltrim( (string) ( $row['pp_Amount'] ?? '' ), '0' ) === crj_minor_amount( $order->get_total() ) ) );
			if ( count( $match ) === 1 && crj_refundable_attempt( $order ) ) {
				$code = crj_provider_code( $match[0]['pp_Status'] ?? '' ) ?: crj_provider_code( $match[0]['pp_ResponseCode'] ?? '' );
				$result = crj_sync_merchant_refund( $id, $match[0] );
				/* translators: 1: JazzCash transaction status, 2: JazzCash response code. */
				$message = sprintf( __( 'JazzCash history: %1$s (%2$s).', 'cardraabta-pay-with-jazzcash' ), crj_provider_status_label( $code ), $code );
				if ( $result === 'synced' ) {
					$message .= ' ' . __( 'WooCommerce refund record updated.', 'cardraabta-pay-with-jazzcash' );
				}
			}
		} else {
			$order = CRJ_Orchestrator_Gateway::check_order_history( $order, $rows );
			if ( $order->is_paid() ) {
				$message = __( 'Payment verified in JazzCash. WooCommerce was updated.', 'cardraabta-pay-with-jazzcash' );
			}
		}
	} catch ( Throwable $error ) {
		$message = __( 'CardRaabta could not verify the JazzCash outcome. Refresh and try again.', 'cardraabta-pay-with-jazzcash' );
	}
	return $message;
}

function crj_register_order_activity( string $screen, $object ): void {
	if ( ! in_array( $screen, array( 'shop_order', 'woocommerce_page_wc-orders', 'admin_page_wc-orders' ), true ) ) {
		return;
	}
	$order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : false );
	if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'crj_card'
		|| ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
		return;
	}
	add_meta_box( 'crj-order-activity', __( 'CardRaabta activity', 'cardraabta-pay-with-jazzcash' ), static function () use ( $order ) {
		crj_render_order_activity( $order );
	}, $screen, 'normal', 'default' );
}

function crj_render_order_activity( WC_Order $order ): void {
	if ( $order->get_payment_method() !== 'crj_card' || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$activity = array();
	foreach ( (array) $order->get_meta( '_crj_attempts' ) as $attempt ) {
		if ( ! is_array( $attempt ) ) {
			continue;
		}
		$code = crj_provider_code( $attempt['code'] ?? '' );
		$activity[] = array(
			'time' => empty( $attempt['updated'] ?? $attempt['created'] ) ? 0 : ( strtotime( ( $attempt['updated'] ?? $attempt['created'] ) . ' ' . wp_timezone_string() ) ?: 0 ),
			'text' => $code === '' ? (string) ( $attempt['status'] ?? '' ) : crj_provider_status_label( $code ) . ' (' . $code . ')',
			'ref'  => (string) ( ( $attempt['provider_ref'] ?? '' ) ?: ( $attempt['request_ref'] ?? '' ) ),
		);
	}
	foreach ( $order->get_refunds() as $refund ) {
		/* translators: %s: amount recorded as a WooCommerce refund. */
		$activity[] = array( 'time' => $refund->get_date_created()?->getTimestamp() ?? 0, 'text' => sprintf( __( 'WooCommerce refund recorded: PKR %s', 'cardraabta-pay-with-jazzcash' ), number_format_i18n( $refund->get_amount(), 2 ) ) );
	}
	$attempt = crj_refundable_attempt( $order );
	if ( $attempt ) {
		$key = crj_direct_refund_key( $attempt['merchant_id'], $attempt['provider_ref'] );
		$failure = get_option( $key . '_last_result', array() );
		if ( ! empty( $failure['message'] ) || ! empty( $failure['code'] ) ) {
			$activity[] = array(
				'time' => (int) ( $failure['time'] ?? 0 ),
				'text' => __( 'JazzCash refund response:', 'cardraabta-pay-with-jazzcash' ) . ' ' . ( $failure['message'] ?? '' ) . ( empty( $failure['code'] ) ? '' : ' (' . $failure['code'] . ')' ),
			);
		}
	}
	$last = $order->get_meta( '_crj_last_status_check' );
	if ( is_array( $last ) ) {
		$activity[] = array( 'time' => (int) $last['time'], 'text' => (string) $last['message'], 'check' => true );
	}
	usort( $activity, static fn( $a, $b ) => $b['time'] <=> $a['time'] );
	if ( ! $activity ) {
		echo '<p>' . esc_html__( 'No payment or refund activity recorded yet.', 'cardraabta-pay-with-jazzcash' ) . '</p>';
		return;
	}
	echo '<table class="widefat striped crj-order-activity-table"><thead><tr><th scope="col">' . esc_html__( 'Date and time', 'cardraabta-pay-with-jazzcash' ) . '</th><th scope="col">' . esc_html__( 'Activity', 'cardraabta-pay-with-jazzcash' ) . '</th><th scope="col">' . esc_html__( 'Transaction ID', 'cardraabta-pay-with-jazzcash' ) . '</th></tr></thead><tbody>';
	foreach ( array_slice( $activity, 0, 15 ) as $event ) {
		echo '<tr><td>' . esc_html( $event['time'] ? wp_date( 'Y-m-d H:i', $event['time'] ) : '—' ) . '</td>';
		echo ! empty( $event['check'] ) ? '<td class="crj-activity-check-message">' : '<td>';
		echo esc_html( $event['text'] ) . '</td>';
		echo '<td>' . ( ! empty( $event['ref'] ) ? '<code>' . esc_html( $event['ref'] ) . '</code>' : '—' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

function crj_render_payment_health( bool $connected ): void {
	$pending = wc_get_orders( array( 'payment_method' => 'crj_card', 'status' => array( 'pending', 'on-hold' ), 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) );
	$callback = (int) get_option( 'crj_last_verified_live_payment_callback', 0 );
	$rows = array(
		'connection' => array( __( 'JazzCash Live history', 'cardraabta-pay-with-jazzcash' ), $connected ? __( 'Connected', 'cardraabta-pay-with-jazzcash' ) : __( 'Unavailable', 'cardraabta-pay-with-jazzcash' ) ),
		'callback'   => array( __( 'Last verified successful Live callback', 'cardraabta-pay-with-jazzcash' ), $callback ? wp_date( 'Y-m-d H:i:s T', $callback ) : __( 'No callback recorded yet', 'cardraabta-pay-with-jazzcash' ) ),
		'pending'    => array( __( 'CardRaabta orders pending or on hold', 'cardraabta-pay-with-jazzcash' ), number_format_i18n( $pending->total ) ),
	);
	echo '<section class="crj-health" aria-labelledby="crj-health-title"><h2 id="crj-health-title">' . esc_html__( 'Payment Health', 'cardraabta-pay-with-jazzcash' ) . '</h2><dl class="crj-health-cards">';
	foreach ( $rows as $key => $row ) {
		$class = $key === 'connection' ? ( $connected ? ' crj-health-card--connected' : ' crj-health-card--unavailable' ) : '';
		echo '<div class="crj-health-card' . esc_attr( $class ) . '"><dt>' . esc_html( $row[0] ) . '</dt><dd>' . esc_html( $row[1] ) . '</dd></div>';
	}
	echo '</dl></section>';
}

function crj_get_order_check_status( WC_Order $order ): array {
	$last = $order->get_meta( '_crj_last_status_check' );
	return array(
		'message' => is_array( $last ) ? (string) ( $last['message'] ?? '' ) : '',
		'pending' => is_array( $last ) && ! empty( $last['pending'] ),
	);
}

function crj_render_order_check_status( int $id ): void {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $id ) ) {
		return;
	}
	$order = wc_get_order( $id );
	if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'crj_card' ) {
		return;
	}
	$status = crj_get_order_check_status( $order );
	if ( $status['message'] !== '' ) {
		wp_enqueue_script( 'crj-order-check', CRJ_ORCH_URL . 'assets/order-check.js', array(), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/order-check.js' ), true );
		echo '<li class="wide crj-order-check" role="status" data-pending="' . esc_attr( $status['pending'] ? '1' : '0' ) . '" data-order="' . esc_attr( (string) $id ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'crj_order_check_' . $id ) ) . '">' . esc_html( $status['message'] ) . '</li>';
	}
}

function crj_ajax_order_check_status(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The order ID selects the nonce action checked immediately below.
	$id = isset( $_POST['order_id'] ) && is_string( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	if ( ! check_ajax_referer( 'crj_order_check_' . $id, 'nonce', false )
		|| ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $id ) ) {
		wp_send_json_error( null, 403 );
	}
	$order = wc_get_order( $id );
	if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'crj_card' ) {
		wp_send_json_error( null, 404 );
	}
	$status = crj_get_order_check_status( $order );
	if ( ! $status['pending'] ) {
		ob_start();
		crj_render_order_activity( $order );
		$status['activity_html'] = ob_get_clean();
	}
	wp_send_json_success( $status );
}

add_filter( 'woocommerce_order_actions', 'crj_order_tools_actions', 10, 2 );
add_action( 'woocommerce_order_action_crj_check_status', 'crj_order_tools_check' );
add_action( 'add_meta_boxes', 'crj_register_order_activity', 10, 2 );
add_action( 'admin_enqueue_scripts', static function () {
	$screen = get_current_screen();
	if ( $screen && in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders', 'admin_page_wc-orders' ), true ) ) {
		wp_add_inline_style( 'common', '#crj-order-activity .crj-order-activity-table td, #crj-order-activity .crj-order-activity-table code { overflow-wrap: anywhere; word-break: normal; } #crj-order-activity .crj-order-activity-table th:first-child { width: 22%; } #crj-order-activity .crj-order-activity-table th:last-child { width: 28%; }' );
	}
} );
add_action( 'woocommerce_order_actions_start', 'crj_render_order_check_status' );
add_action( 'wp_ajax_crj_order_check_status', 'crj_ajax_order_check_status' );
add_action( 'crj_sync_merchant_refunds', 'crj_sync_recent_merchant_refunds' );
add_action( 'crj_continue_order_history', 'crj_continue_order_history' );

add_filter( 'cron_schedules', static function ( array $schedules ): array {
	$schedules['crj_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every five minutes (CardRaabta)', 'cardraabta-pay-with-jazzcash' ),
	);
	return $schedules;
} );

add_action( 'admin_init', static function () {
	$settings = (array) get_option( 'woocommerce_crj_card_settings', array() );
	if ( empty( $settings['live_merchant_id'] ) ) {
		return;
	}
	$hook = 'crj_sync_merchant_refunds';
	$event = wp_get_scheduled_event( $hook );
	if ( $event && $event->schedule === 'crj_five_minutes' ) {
		return;
	}
	if ( $event && wp_clear_scheduled_hook( $hook ) === false ) {
		return;
	}
	wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'crj_five_minutes', $hook );
} );
