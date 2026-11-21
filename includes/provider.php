<?php
/** Live JazzCash provider history and refunds for CardRaabta. */
defined( 'ABSPATH' ) || exit;

function crj_checkout_methods(): array {
	return array(
		array( 'icon' => '💳', 'label' => __( 'Debit/Credit Card', 'cardraabta-pay-with-jazzcash' ) ),
		array( 'icon' => '📱', 'label' => __( 'Mobile Wallet', 'cardraabta-pay-with-jazzcash' ) ),
	);
}

function crj_provider_request( string $operation, array $fields, array $query = array(), ?array $settings = null ) {
	$settings ??= (array) get_option( 'woocommerce_crj_card_settings', array() );
	$merchant = (string) ( $settings['live_merchant_id'] ?? '' );
	$password = (string) ( $settings['live_merchant_password'] ?? '' );
	$salt     = (string) ( $settings['live_integrity_salt'] ?? '' );
	if ( $merchant === '' || $password === '' || $salt === '' ) {
		return new WP_Error( 'crj_credentials', __( 'Enter your Live JazzCash credentials in the CardRaabta payment settings.', 'cardraabta-pay-with-jazzcash' ) );
	}
	$paths = array(
		'history' => '/payment/api/v1/shopify/inquiry',
		'refund'  => '/api/v1/rest/payments/shopify/refund',
	);
	$fields['pp_MerchantID'] = $merchant;
	$fields['pp_Password']   = $password;
	ksort( $fields, SORT_STRING );
	$message = function_exists( 'iconv' ) ? iconv( 'UTF-8', 'ISO-8859-1', $salt . '&' . implode( '&', $fields ) ) : false;
	if ( $message === false ) {
		return new WP_Error( 'crj_credentials', __( 'The JazzCash request contains characters unsupported by this API.', 'cardraabta-pay-with-jazzcash' ) );
	}
	$fields['pp_SecureHash'] = strtoupper( hash_hmac( 'sha256', $message, $salt ) );
	$url = 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator' . $paths[ $operation ];
	$response = wp_remote_post(
		add_query_arg( $query, $url ),
		array(
			'headers'             => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'body'                => wp_json_encode( $fields ),
			'timeout'             => $operation === 'history' ? 8 : 30,
			'sslverify'           => true,
			'redirection'         => 0,
			'limit_response_size' => 524288,
		)
	);
	$secrets = array( $password, $salt, $fields['pp_SecureHash'] );
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'crj_transport', __( 'Could not connect securely to JazzCash.', 'cardraabta-pay-with-jazzcash' ), array(
			'code'    => sanitize_key( $response->get_error_code() ),
			'message' => crj_refund_safe_message( $response->get_error_message(), $secrets ),
		) );
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true, 12 );
	if ( is_array( $data ) ) {
		$data['pp_ResponseMessage'] = crj_refund_safe_message( $data['pp_ResponseMessage'] ?? $data['Message'] ?? '', $secrets );
	}
	if ( $status !== 200 ) {
		/* translators: %d: HTTP status returned by JazzCash. */
		$message = sprintf( __( 'JazzCash returned HTTP %d.', 'cardraabta-pay-with-jazzcash' ), $status );
		return new WP_Error( 'crj_http', $message, array(
			'code'    => crj_provider_code( $data['pp_ResponseCode'] ?? '' ) ?: 'HTTP ' . $status,
			'message' => ! empty( $data['pp_ResponseMessage'] ) ? $data['pp_ResponseMessage'] : $message,
		) );
	}
	if ( ! is_array( $data ) || ! isset( $data['pp_ResponseCode'] ) || ! is_scalar( $data['pp_ResponseCode'] ) ) {
		return new WP_Error( 'crj_invalid_response', __( 'JazzCash returned a response without a valid response code.', 'cardraabta-pay-with-jazzcash' ), array(
			'message' => ! empty( $data['pp_ResponseMessage'] ) ? $data['pp_ResponseMessage'] : '',
		) );
	}
	return $data;
}

function crj_refund_safe_message( $value, array $secrets ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}
	$value = str_replace( $secrets, '[redacted]', $value );
	$value = sanitize_text_field( $value );
	return wp_check_invalid_utf8( substr( $value, 0, 520 ), true );
}

function crj_refund_provider_details( $response ): array {
	if ( is_wp_error( $response ) ) {
		$details = (array) $response->get_error_data();
		return array(
			'code'    => $details['code'] ?? '',
			'message' => ( $details['message'] ?? '' ) ?: $response->get_error_message(),
		);
	}
	return array(
		'code'    => crj_provider_code( $response['pp_ResponseCode'] ?? '' ),
		'message' => (string) ( $response['pp_ResponseMessage'] ?? '' ),
	);
}

function crj_provider_status_label( string $code ): string {
	$labels = array(
		'000' => 'Success', '0000' => 'Success', '121' => 'Merchant confirmed', 'xxxx' => 'Completed',
		'DEL0' => 'Delivered', '124' => 'Voucher awaiting payment', '----' => 'Pending', '157' => 'Pending',
		'210' => 'Pending', '3333' => 'Awaiting confirmation', '5555' => 'Awaiting void', 'CB00' => 'Awaiting action',
		'8888' => 'Awaiting reversal', '7777' => 'Reversed', '4444' => 'System reversed', '6666' => 'Void',
		'RF00' => 'Refunded', 'PRF0' => 'Partially refunded', '1111' => 'Expired', 'TMDO' => 'Timed out',
		'9800' => 'Dropped', '2222' => 'Validation failed', '110' => 'Invalid value', '004' => 'Low balance',
		'405' => 'Payment unsuccessful', '407' => 'System error', '156' => 'Failed', '129' => 'Dropped', '999' => 'Payment not confirmed', '9999' => 'Failed', 'CD00' => 'Disputed',
		'CBCK' => 'Chargeback', '8900' => 'Canceled', 'SP00' => 'AML suspected',
	);
	return $labels[ $code ] ?? __( 'Unknown status', 'cardraabta-pay-with-jazzcash' );
}

function crj_payment_succeeded( string $code ): bool {
	return in_array( $code, array( '000', '0000', '121', 'xxxx', 'DEL0' ), true );
}

function crj_payment_has_insufficient_funds( string $message ): bool {
	return preg_match( '/\b(?:insufficient (?:balance|funds)|not enough funds|not sufficient funds)\b/i', $message ) === 1;
}

function crj_payment_awaits_confirmation( string $code, string $message = '' ): bool {
	if ( $code === '999' ) {
		// JazzCash can return this code while the customer's phone prompt is still open.
		// Its cancellation instructions do not prove that the customer cancelled.
		return true;
	}
	if ( $code === '405' ) {
		return ! crj_payment_has_insufficient_funds( $message );
	}
	return in_array( $code, array( '124', '157', '210', '----', '3333', '5555', 'CB00', '8888' ), true );
}

function crj_payment_declined( string $code, string $message = '' ): bool {
	return in_array( $code, array( '004', '110', '129', '156', '405', '407', '8900', '9800', '9999', '1111', '2222', 'TMDO' ), true )
		&& ! crj_payment_awaits_confirmation( $code, $message );
}

function crj_order_awaits_confirmation( WC_Order $order ): bool {
	if ( $order->get_payment_method() !== 'crj_card' || $order->get_date_paid() ) {
		return false;
	}
	$status = $order->get_meta( '_crj_status' );
	$details = (array) $order->get_meta( '_crj_provider_details' );
	return $status === 'pending' || ( $status === 'not_completed' && crj_payment_awaits_confirmation(
		(string) $order->get_meta( '_crj_response_code' ),
		(string) ( $details['response_message'] ?? '' )
	) );
}

function crj_provider_code( $value ): string {
	return is_scalar( $value ) && preg_match( '/^(?:[A-Za-z0-9]{1,8}|----)$/D', (string) $value ) ? (string) $value : '';
}

function crj_provider_transaction_date( string $value ): ?DateTimeImmutable {
	if ( ! preg_match( '/^[0-9]{14}$/D', $value ) ) {
		return null;
	}
	$date = DateTimeImmutable::createFromFormat( '!YmdHis', $value, new DateTimeZone( 'Asia/Karachi' ) );
	return $date && $date->format( 'YmdHis' ) === $value ? $date : null;
}

function crj_attempt_return_code( WC_Order $order, string $reference ): string {
	if ( $order->get_payment_method() !== 'crj_card' ) {
		return '';
	}
	foreach ( (array) $order->get_meta( '_crj_attempts' ) as $attempt ) {
		if ( is_array( $attempt ) && ! empty( $attempt['event_hash'] )
			&& in_array( $reference, array( $attempt['request_ref'] ?? '', $attempt['provider_ref'] ?? '' ), true ) ) {
			return crj_provider_code( $attempt['signed_return_code'] ?? $attempt['code'] ?? '' );
		}
	}
	return '';
}

function crj_verified_transaction_type( WC_Order $order, string $reference ): string {
	if ( $reference === '' || $order->get_payment_method() !== 'crj_card' ) {
		return '';
	}
	foreach ( (array) $order->get_meta( '_crj_attempts' ) as $attempt ) {
		// Request references represent the hosted link. Use the exact verified payment ID.
		if ( ! is_array( $attempt ) || empty( $attempt['event_hash'] ) || ( $attempt['provider_ref'] ?? '' ) !== $reference ) {
			continue;
		}
		$type = trim( (string) ( $attempt['provider_txn_type'] ?? '' ) );
		if ( $type !== '' ) {
			return $type;
		}
		return array(
			'card'    => 'CARD',
			'wallet'  => 'MWALLET',
			'voucher' => 'VOUCHER',
			'raastqr' => 'RAAST QR',
		)[ $attempt['type'] ?? '' ] ?? '';
	}
	return '';
}

function crj_render_transactions(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You are not allowed to view CardRaabta transactions.', 'cardraabta-pay-with-jazzcash' ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WooCommerce manager page.
	$page = isset( $_GET['crj_page'] ) && is_string( $_GET['crj_page'] ) ? max( 1, min( 100000, absint( $_GET['crj_page'] ) ) ) : 1;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only WooCommerce manager page.
	$search = isset( $_GET['crj_search'] ) && is_string( $_GET['crj_search'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['crj_search'] ) ), 0, 100 ) : '';
	$url = admin_url( 'admin.php?page=crj-transactions' );
	$settings = (array) get_option( 'woocommerce_crj_card_settings', array() );
	$merchant = (string) ( $settings['live_merchant_id'] ?? '' );
	$size = 50;
	$batch = $search === '' ? 1 : 3;
	$first = ( $page - 1 ) * $batch + 1;
	$rows = array();
	$error = '';
	$total = null;
	$more = false;
	for ( $offset = 0; $offset < $batch; ++$offset ) {
		$provider_page = $first + $offset;
		$data = crj_provider_request( 'history', array(), array( 'PageNumber' => $provider_page, 'PageSize' => $size, 'SortOrder' => 'DESC', 'SortBy' => 'pp_TxnDateTime' ), $settings );
		if ( is_wp_error( $data ) || (string) ( $data['pp_ResponseCode'] ?? '' ) !== '000' || ! is_array( $data['pp_Items'] ?? null ) ) {
			$code = is_array( $data ) ? crj_provider_code( $data['pp_ResponseCode'] ?? '' ) : '';
			$error = is_wp_error( $data ) ? $data->get_error_message() : __( 'JazzCash could not return transactions.', 'cardraabta-pay-with-jazzcash' ) . ( $code !== '' ? ' ' . $code . ' · ' . crj_provider_status_label( $code ) : '' );
			$more = false;
			break;
		}
		$items = $data['pp_Items'];
		$total = is_numeric( $data['pp_TxnCount'] ?? null ) ? max( 0, (int) $data['pp_TxnCount'] ) : null;
		foreach ( array_slice( $items, 0, $size ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row = array_filter( $row, 'is_scalar' );
			$ref = (string) ( $row['pp_TxnRefNo'] ?? '' );
			$bill = (string) ( $row['pp_BillReference'] ?? '' );
			if ( $search === '' || stripos( $ref, $search ) !== false || stripos( $bill, $search ) !== false ) {
				$row['_crj_provider_page'] = $provider_page;
				$row['_crj_datetime'] = crj_provider_transaction_date( (string) ( $row['pp_TxnDateTime'] ?? '' ) );
				$rows[] = $row;
			}
		}
		$more = $total === null ? count( $items ) >= $size : count( $items ) > 0 && $provider_page * $size < $total;
		if ( ! $more ) {
			break;
		}
	}
	// The provider can return a page out of order even when SortOrder=DESC.
	usort(
		$rows,
		static fn( $a, $b ) => ( $b['_crj_datetime']?->getTimestamp() ?? PHP_INT_MIN ) <=> ( $a['_crj_datetime']?->getTimestamp() ?? PHP_INT_MIN )
	);
	echo '<div class="wrap crj-history"><div class="crj-banner"><span class="crj-mark" aria-hidden="true">CR</span><h1>CardRaabta Transaction History</h1></div>';
	crj_render_payment_health( $error === '' );
	crj_refund_notice();
	echo '<div class="crj-panel"><div class="crj-toolbar"><form method="get" class="crj-search"><input type="hidden" name="page" value="crj-transactions"><label class="screen-reader-text" for="crj-provider-search">Transaction ID or order reference</label><input id="crj-provider-search" name="crj_search" type="search" maxlength="100" placeholder="Search by transaction ID or order reference" value="' . esc_attr( $search ) . '"><button class="crj-btn">Search</button><a class="crj-btn secondary" href="' . esc_url( $url ) . '">Clear</a></form>';
	if ( $error === '' ) {
		$summary = $search === '' && $total !== null ? sprintf( '%s transactions', number_format_i18n( $total ) ) : sprintf( '%s shown', number_format_i18n( count( $rows ) ) );
		echo '<p class="crj-history-connection"><span class="crj-muted">' . esc_html( $summary ) . '</span></p>';
	} else {
		echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
	}
	echo '</div><div class="crj-table-wrap" role="region" aria-label="CardRaabta transaction history" tabindex="0"><table><thead><tr><th scope="col">Transaction ID</th><th scope="col">Order Reference</th><th scope="col">TXN Type</th><th scope="col">Amount</th><th scope="col">Response Code</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col">Actions</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$ref = (string) ( $row['pp_TxnRefNo'] ?? '' );
		$bill = (string) ( $row['pp_BillReference'] ?? '' );
		$type = trim( (string) ( $row['pp_TxnType'] ?? '' ) );
		$currency = (string) ( $row['pp_TxnCurrency'] ?? '' );
		$code = crj_provider_code( $row['pp_ResponseCode'] ?? '' );
		$status = crj_provider_code( $row['pp_Status'] ?? '' ) ?: $code;
		$minor = (string) ( $row['pp_Amount'] ?? '' );
		$amount = preg_match( '/^[0-9]{1,12}$/D', $minor ) ? trim( $currency . ' ' . number_format_i18n( (int) $minor / 100, 2 ) ) : '—';
		$date = (string) ( $row['pp_TxnDateTime'] ?? '' );
		$date = $row['_crj_datetime']?->format( 'd/m/Y H:i' ) ?? $date;
		$positive = in_array( $status, array( '000', '0000', '121', 'xxxx', 'DEL0' ), true );
		$pending = in_array( $status, array( '----', '124', '157', '210', '3333', '8888', '5555', 'CB00' ), true );
		$color = $positive ? 'ok' : ( $pending ? 'wait' : 'bad' );
		$code_color = 'codeno';
		if ( crj_payment_succeeded( $code ) ) {
			$code_color = 'codeok';
		} elseif ( crj_payment_awaits_confirmation( $code ) && ! in_array( $code, array( '405', '999' ), true ) ) {
			$code_color = 'codewait';
		} elseif ( in_array( $code, array( '004', '110', '156', '405', '407', '999', '9999', '2222' ), true ) ) {
			$code_color = 'codefail';
		}
		$order = $ref !== '' && preg_match( '/^ORDER-([1-9][0-9]*)$/D', $bill, $match ) ? wc_get_order( (int) $match[1] ) : false;
		$return_code = $order instanceof WC_Order ? crj_attempt_return_code( $order, $ref ) : '';
		$display_type = $type;
		if ( $display_type === '' && $order instanceof WC_Order ) {
			$display_type = crj_verified_transaction_type( $order, $ref );
		}
		echo '<tr><td>' . esc_html( $ref ?: '—' ) . '</td><td>' . esc_html( $bill ?: '—' ) . '</td><td><span class="crj-pill type">' . esc_html( $display_type ?: '—' ) . '</span></td><td>' . esc_html( $amount ) . '</td><td><span class="crj-pill ' . esc_attr( $code_color ) . '">' . esc_html( $code ?: '—' ) . '</span>';
		if ( $return_code !== '' && $return_code !== $code ) {
			/* translators: %s: verified payment response code. */
			echo '<div class="crj-muted">' . esc_html( sprintf( __( 'Signed order return: %s', 'cardraabta-pay-with-jazzcash' ), $return_code ) ) . '</div>';
		}
		echo '</td><td><span class="crj-pill ' . esc_attr( $color ) . '">' . esc_html( crj_provider_status_label( $status ) ) . '</span></td><td>' . esc_html( $date ?: '—' ) . '</td><td>';
		$matching_order = $order instanceof WC_Order && $order->get_payment_method() === 'crj_card'
			&& (string) $order->get_transaction_id() === $ref;
		$key = $merchant !== '' && $ref !== '' ? crj_direct_refund_key( $merchant, $ref ) : '';
		$direct_state = $key !== '' ? get_option( $key, '' ) : '';
		$linked_state = $key !== '' ? get_option( $key . '_linked_dispatch', '' ) : '';
		$record = $matching_order ? $order->get_meta( '_crj_refund_in_flight' ) : null;
		$provider_refunded = in_array( $status, array( 'RF00', 'PRF0' ), true );
		$retry_rejected = $key !== '' && crj_direct_refund_snapshot( $row )
			&& crj_explicit_refund_failure( (array) get_option( $key . '_last_result', array() ) );
		if ( $retry_rejected && $direct_state === 'dispatching' ) {
			$direct_state = '';
		}
		if ( $retry_rejected && is_array( $record ) && ( $record['state'] ?? '' ) === 'dispatching' ) {
			$record = null;
		}
		$balance = crj_history_refund_balance( $row );
		if ( $status === 'PRF0' && $balance !== null ) {
			/* translators: 1: refunded amount, 2: remaining refundable amount. */
			echo '<div class="crj-muted">' . esc_html( sprintf( __( 'JazzCash refunded PKR %1$s. Remaining refundable: PKR %2$s.', 'cardraabta-pay-with-jazzcash' ), number_format_i18n( $balance['refunded'] / 100, 2 ), number_format_i18n( $balance['remaining'] / 100, 2 ) ) ) . '</div>';
		} else {
			crj_render_refund_amounts( $status, $currency, $minor, $key );
		}
		$local_refunded = $matching_order && ( $order->has_status( 'refunded' ) || (float) $order->get_total_refunded() > 0 );
		if ( $local_refunded && ! $provider_refunded ) {
			echo '<div class="crj-muted">' . esc_html__( 'WooCommerce has a refund recorded. JazzCash history has not confirmed a refund for this transaction.', 'cardraabta-pay-with-jazzcash' ) . '</div>';
		} elseif ( $provider_refunded && $matching_order && ( ! $local_refunded || ( $status === 'RF00' && (float) $order->get_remaining_refund_amount() > 0 ) ) ) {
			echo '<div class="crj-muted">' . esc_html__( 'JazzCash recorded a refund. Review the WooCommerce refund record.', 'cardraabta-pay-with-jazzcash' ) . '</div>';
		}
		if ( $key !== '' && ! $provider_refunded ) {
			crj_render_last_refund_result( $key );
		}
		if ( $matching_order && is_array( $record ) ) {
			if ( ! empty( $record['provider_confirmed'] ) && ( $record['state'] ?? '' ) === 'confirmed' ) {
				crj_refund_form( $order, $ref, $amount, $page, $search, (int) $row['_crj_provider_page'] );
			} elseif ( ! $provider_refunded ) {
				echo '<div class="crj-muted">' . esc_html__( 'View refund status in the JazzCash Merchant Portal.', 'cardraabta-pay-with-jazzcash' ) . '</div>';
			}
		} elseif ( $matching_order && $balance !== null && $balance['remaining'] > 0
			&& $direct_state === '' && $linked_state !== 'dispatching' && crj_refundable_attempt( $order )
			&& crj_refund_recorded_minor( $order ) === $balance['refunded'] ) {
			crj_refund_form( $order, $ref, $amount, $page, $search, (int) $row['_crj_provider_page'], $balance['remaining'] );
		} elseif ( ! $matching_order && $balance !== null && $balance['remaining'] > 0
			&& $linked_state === '' && in_array( $direct_state, array( '', 'confirmed' ), true )
			&& $balance['refunded'] >= (int) get_option( $key . '_confirmed_amount', 0 ) ) {
			crj_render_direct_refund( $row, $page, $search, $merchant );
		} elseif ( ( $direct_state !== '' || $linked_state !== '' ) && ! $provider_refunded ) {
			$message = ( $direct_state === 'confirmed' || $linked_state === 'confirmed' )
				? __( 'JazzCash accepted the refund request. Its transaction history has not updated yet.', 'cardraabta-pay-with-jazzcash' )
				: __( 'View refund status in the JazzCash Merchant Portal.', 'cardraabta-pay-with-jazzcash' );
			echo '<div class="crj-muted">' . esc_html( $message ) . '</div>';
		} elseif ( ! $provider_refunded && crj_direct_refund_snapshot( $row ) ) {
			if ( ! $matching_order || ( ! $local_refunded && ! crj_refundable_attempt( $order ) ) ) {
				crj_render_direct_refund( $row, $page, $search, $merchant );
			} elseif ( ! $local_refunded && (float) $order->get_remaining_refund_amount() > 0 ) {
				crj_refund_form( $order, $ref, $amount, $page, $search, (int) $row['_crj_provider_page'] );
			}
		}
		echo '</td></tr>';
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="8">' . esc_html( $error === '' ? 'No matching transactions.' : 'Transaction history is unavailable.' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	if ( $page > 1 || $more ) {
		/* translators: %s: page number. */
		echo '<nav class="crj-foot" aria-label="Transaction pages"><span class="crj-page-number">' . esc_html( sprintf( __( 'Page %s', 'cardraabta-pay-with-jazzcash' ), number_format_i18n( $page ) ) ) . '</span>';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( array( 'crj_page' => $page - 1, 'crj_search' => $search ), $url ) ) . '">Previous</a> ';
		}
		if ( $more && $page < 100000 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( array( 'crj_page' => $page + 1, 'crj_search' => $search ), $url ) ) . '">Next</a>';
		}
		echo '</nav>';
	}
	echo '</div></div>';
}

/** Resolve refunds against the exact verified payment that WooCommerce settled. */
/** Show amounts confirmed by history or by a successful API response. */
function crj_history_refund_balance( array $row ): ?array {
	$status = crj_provider_code( $row['pp_Status'] ?? '' ) ?: crj_provider_code( $row['pp_ResponseCode'] ?? '' );
	$amount = $row['pp_Amount'] ?? '';
	if ( ( $row['pp_TxnCurrency'] ?? '' ) !== 'PKR' || ! is_scalar( $amount ) || ! preg_match( '/^[0-9]{1,12}$/D', (string) $amount ) || (int) $amount < 1 ) {
		return null;
	}
	if ( $status === 'RF00' ) {
		return array( 'refunded' => (int) $amount, 'remaining' => 0 );
	}
	$refunded = $row['pp_RefundedAmount'] ?? null;
	$remaining = $row['pp_RemainingRefundAmount'] ?? null;
	if ( $status !== 'PRF0' || ! is_scalar( $refunded ) || ! is_scalar( $remaining )
		|| ! preg_match( '/^[0-9]{1,12}$/D', (string) $refunded ) || ! preg_match( '/^[0-9]{1,12}$/D', (string) $remaining )
		|| (int) $refunded < 1 || (int) $remaining < 1 || (int) $refunded + (int) $remaining !== (int) $amount ) {
		return null;
	}
	return array( 'refunded' => (int) $refunded, 'remaining' => (int) $remaining );
}

function crj_render_refund_amounts( string $status, string $currency, string $captured, string $key ): void {
	if ( $currency !== 'PKR' || ! preg_match( '/^[0-9]{1,12}$/D', $captured ) || (int) $captured <= 0 ) {
		return;
	}
	$confirmed = $key !== '' ? get_option( $key . '_confirmed_amount', '' ) : '';
	$known = is_scalar( $confirmed ) && preg_match( '/^[1-9][0-9]{0,11}$/D', (string) $confirmed ) && (int) $confirmed <= (int) $captured;
	$full = $status === 'RF00' || ( $known && (int) $confirmed === (int) $captured );
	if ( ! $full && ! $known && $status !== 'PRF0' ) {
		return;
	}
	if ( $full || $known ) {
		$amount = number_format_i18n( (int) ( $full ? $captured : $confirmed ) / 100, 2 );
		if ( $full ) {
			/* translators: %s: refunded amount. */
			$message = sprintf( __( 'Refunded by JazzCash: PKR %s', 'cardraabta-pay-with-jazzcash' ), $amount );
		} else {
			/* translators: %s: refunded amount. */
			$message = sprintf( __( 'Refund confirmed through CardRaabta: PKR %s', 'cardraabta-pay-with-jazzcash' ), $amount );
		}
		echo '<div class="crj-muted">' . esc_html( $message ) . '</div>';
	}
	if ( $full ) {
		echo '<div class="crj-muted">' . esc_html__( 'Remaining refundable: PKR 0.00', 'cardraabta-pay-with-jazzcash' ) . '</div>';
		return;
	}
	if ( $known ) {
		/* translators: %s: upper limit calculated from the original payment and the confirmed refund. */
		echo '<div class="crj-muted">' . esc_html( sprintf( __( 'Remaining balance: up to PKR %s. Verify the exact amount in JazzCash.', 'cardraabta-pay-with-jazzcash' ), number_format_i18n( ( (int) $captured - (int) $confirmed ) / 100, 2 ) ) ) . '</div>';
		return;
	}
	echo '<div class="crj-muted">' . esc_html__( 'JazzCash reports a partial refund. See the refund amount and remaining balance in the Merchant Portal.', 'cardraabta-pay-with-jazzcash' ) . '</div>';
}

function crj_refundable_attempt( WC_Order $order ): array {
	$settings = (array) get_option( 'woocommerce_crj_card_settings', array() );
	$merchant = (string) ( $settings['live_merchant_id'] ?? '' );
	$ref      = (string) $order->get_transaction_id();
	if ( $order->get_payment_method() !== 'crj_card' || $order->get_currency() !== 'PKR' || ! $order->get_date_paid() || $merchant === '' || ! preg_match( '/^[A-Za-z0-9_-]{12,64}$/D', $ref ) ) {
		return array();
	}
	foreach ( (array) $order->get_meta( '_crj_attempts' ) as $attempt ) {
		if ( is_array( $attempt ) && ( $attempt['status'] ?? '' ) === 'COMPLETED'
			&& ( $attempt['provider_ref'] ?? '' ) === $ref && ( $attempt['environment'] ?? '' ) === 'live'
			&& ( $attempt['merchant_id'] ?? '' ) === $merchant
			&& in_array( $attempt['provider_txn_type'] ?? '', array( 'MPAY', 'AUTH', 'MWALLET', 'BNPL' ), true ) ) {
			return $attempt;
		}
	}
	return array();
}

/** The same provider reference must never use the two refund paths concurrently. */
function crj_direct_refund_key( string $merchant, string $reference ): string {
	return 'crj_direct_refund_' . hash( 'sha256', $merchant . '|' . $reference );
}

function crj_direct_refund_snapshot( array $row ): array {
	$ref = (string) ( $row['pp_TxnRefNo'] ?? '' );
	$bill = (string) ( $row['pp_BillReference'] ?? '' );
	$amount = ltrim( (string) ( $row['pp_Amount'] ?? '' ), '0' );
	$type = (string) ( $row['pp_TxnType'] ?? '' );
	$status = crj_provider_code( $row['pp_Status'] ?? '' ) ?: crj_provider_code( $row['pp_ResponseCode'] ?? '' );
	$balance = $status === 'PRF0' ? crj_history_refund_balance( $row ) : null;
	if ( ! preg_match( '/^[A-Za-z0-9_-]{12,64}$/D', $ref ) || ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/D', $bill )
		|| ! preg_match( '/^[1-9][0-9]{0,11}$/D', $amount ) || ( $row['pp_TxnCurrency'] ?? '' ) !== 'PKR'
		|| ! in_array( $type, array( 'MPAY', 'AUTH', 'MWALLET', 'BNPL' ), true )
		|| ( $balance === null && ! in_array( $status, array( 'xxxx', '0000', '000', '121' ), true ) ) ) {
		return array();
	}
	$payment = array( 'ref' => $ref, 'bill' => $bill, 'amount' => $amount, 'type' => $type );
	if ( $balance !== null ) {
		$payment['refunded'] = (string) $balance['refunded'];
		$payment['remaining'] = (string) $balance['remaining'];
	}
	return $payment;
}

function crj_render_direct_refund( array $row, int $page, string $search, string $merchant ): void {
	$payment = crj_direct_refund_snapshot( $row );
	if ( ! $payment || $merchant === '' ) {
		echo '<span class="crj-muted">Verify the remaining refundable amount in the JazzCash Merchant Portal.</span>';
		return;
	}
	$provider_page = (int) $row['_crj_provider_page'];
	$key = crj_direct_refund_key( $merchant, $payment['ref'] );
	$minor = (int) ( $payment['remaining'] ?? $payment['amount'] );
	$max = sprintf( '%d.%02d', intdiv( $minor, 100 ), $minor % 100 );
	$fields = $payment + array( 'provider_page' => $provider_page, 'crj_page' => $page, 'crj_search' => $search );
	crj_open_refund_form( __( 'Refund', 'cardraabta-pay-with-jazzcash' ), $payment['ref'], 'PKR ' . wc_format_decimal( (int) $payment['amount'] / 100, 2 ) );
	echo '<input type="hidden" name="action" value="crj_direct_refund">';
	foreach ( $fields as $name => $value ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}
	wp_nonce_field( $key . '|' . implode( '|', $payment ) . '|' . $provider_page );
	echo '<label>Refund amount (PKR) <input name="crj_amount" type="number" min="0.01" step="0.01" max="' . esc_attr( $max ) . '" value="' . esc_attr( $max ) . '" required></label>';
	echo '<div class="crj-refund-actions"><button class="crj-refund-button"><span aria-hidden="true">↻</span> Process Refund</button></div></form></details>';
}

function crj_handle_direct_refund(): void {
	crj_verify_refund_access();
	$payment = array();
	foreach ( array( 'ref', 'bill', 'amount', 'type', 'refunded', 'remaining', 'provider_page', 'crj_page', 'crj_search', 'crj_amount' ) as $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The request is validated by crj_verify_refund_nonce before payment dispatch.
		$payment[ $name ] = isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}
	$settings = (array) get_option( 'woocommerce_crj_card_settings', array() );
	$key = crj_direct_refund_key( (string) ( $settings['live_merchant_id'] ?? '' ), $payment['ref'] );
	$snapshot = array_filter( array_intersect_key( $payment, array_flip( array( 'ref', 'bill', 'amount', 'type', 'refunded', 'remaining' ) ) ), static fn( $value ) => $value !== '' );
	$page = absint( $payment['provider_page'] );
	crj_verify_refund_nonce( $key . '|' . implode( '|', $snapshot ) . '|' . $page );
	$provider_details = array();
	try {
		$outcome = crj_send_direct_refund( $key, $settings, $snapshot, $page, $payment['crj_amount'], $provider_details );
	} catch ( Throwable $error ) {
		$outcome = in_array( $error->getMessage(), array( 'Invalid_amount', 'Payment_request_busy' ), true ) ? $error->getMessage() : 'review';
	}
	crj_send_refund_modal_result( $outcome, $provider_details );
	wp_safe_redirect( add_query_arg( array( 'page' => 'crj-transactions', 'crj_page' => absint( $payment['crj_page'] ), 'crj_search' => substr( $payment['crj_search'], 0, 100 ), 'crj_refund_result' => $outcome ), admin_url( 'admin.php' ) ), 303 );
	exit;
}

/** A missing, duplicate, or no-longer-refundable row cannot authorize a refund. */
function crj_refund_history_payment( string $reference, int $page, array $settings, array &$details, bool $linked = false ): ?array {
	if ( $page < 1 || $page > 300000 ) {
		return array();
	}
	$history = crj_provider_request( 'history', array(), array( 'PageNumber' => $page, 'PageSize' => 50, 'SortOrder' => 'DESC', 'SortBy' => 'pp_TxnDateTime' ), $settings );
	if ( ! is_array( $history ) || (string) ( $history['pp_ResponseCode'] ?? '' ) !== '000' || ! is_array( $history['pp_Items'] ?? null ) ) {
		$details = crj_refund_provider_details( $history );
		return null;
	}
	$matches = array();
	foreach ( $history['pp_Items'] as $row ) {
		if ( is_array( $row ) && ( $row['pp_TxnRefNo'] ?? '' ) === $reference ) {
			$clean = array_filter( $row, 'is_scalar' );
			$balance = $linked ? crj_history_refund_balance( $clean ) : null;
			if ( $balance !== null && $balance['remaining'] > 0 ) {
				$clean['pp_Status'] = 'xxxx';
			}
			$payment = crj_direct_refund_snapshot( $clean );
			$matches[] = $linked && $payment ? $payment + array( 'balance' => $balance ) : $payment;
		}
	}
	return count( $matches ) === 1 ? $matches[0] : array();
}

function crj_record_last_refund_result( string $key, array $details, bool $success ): void {
	if ( $success ) {
		delete_option( $key . '_last_result' );
	} else {
		update_option( $key . '_last_result', $details + array( 'time' => time() ), false );
	}
}

function crj_render_last_refund_result( string $key ): void {
	$details = get_option( $key . '_last_result', array() );
	if ( empty( $details['message'] ) && empty( $details['code'] ) ) {
		return;
	}
	$code = empty( $details['code'] ) ? '' : ' (' . $details['code'] . ')';
	echo '<div class="crj-muted">' . esc_html( __( 'Last JazzCash refund response:', 'cardraabta-pay-with-jazzcash' ) . ' ' . ( $details['message'] ?? '' ) . $code ) . '</div>';
}

/** Code 999 is shared by several outcomes. Match the explicit refund refusal. */
function crj_explicit_refund_failure( array $details ): bool {
	return ( $details['code'] ?? '' ) === '999'
		&& is_string( $details['message'] ?? null )
		&& strcasecmp( trim( $details['message'] ), 'Sorry! Your transaction has failed. Please try again later.' ) === 0;
}

function crj_refund_was_rejected( $response, string $code ): bool {
	return is_array( $response ) && ( in_array( $code, array( '110', '2222' ), true ) || crj_is_missing_merchant_mpin( $response, $code )
		|| crj_explicit_refund_failure( crj_refund_provider_details( $response ) ) );
}

function crj_send_direct_refund( string $key, array $settings, array $snapshot, int $page, string $requested, array &$provider_details = array() ): string {
	$minor = crj_minor_amount( $requested );
	if ( (int) $minor > (int) ( $snapshot['remaining'] ?? $snapshot['amount'] ) || $page < 1 || $page > 300000 ) {
		return 'Invalid_amount';
	}
	$order = null;
	if ( preg_match( '/^ORDER-([1-9][0-9]*)$/D', $snapshot['bill'], $match ) ) {
		crj_security_lock( 'order:' . (int) $match[1] );
		$order = wc_get_order( (int) $match[1] );
	}
	crj_security_lock( $key );
	if ( get_option( $key . '_linked_dispatch', '' ) !== '' ) {
		return 'direct_duplicate';
	}
	$state = get_option( $key, '' );
	$retry_rejected = $state === 'dispatching' && crj_explicit_refund_failure( (array) get_option( $key . '_last_result', array() ) );
	$resume_partial = $state === 'confirmed' && isset( $snapshot['remaining'], $snapshot['refunded'] )
		&& (int) $snapshot['refunded'] >= (int) get_option( $key . '_confirmed_amount', 0 );
	if ( $state !== '' && ! $retry_rejected && ! $resume_partial ) {
		return 'direct_duplicate';
	}
	if ( $order instanceof WC_Order && ( is_array( $order->get_meta( '_crj_refund_in_flight' ) )
		|| ( (string) $order->get_transaction_id() === $snapshot['ref']
			&& ( crj_refundable_attempt( $order ) || $order->has_status( 'refunded' ) || (float) $order->get_total_refunded() > 0 ) ) ) ) {
		return 'stale';
	}
	$history = crj_refund_history_payment( $snapshot['ref'], $page, $settings, $provider_details );
	if ( $history !== $snapshot ) {
		return $history === null ? 'direct_lookup' : 'stale';
	}
	crj_security_lock( $key );
	delete_option( $key . '_last_result' );
	if ( ! $retry_rejected && ! update_option( $key, 'dispatching', false ) ) {
		return 'direct_duplicate';
	}
	$response = crj_provider_request( 'refund', array( 'pp_TxnRefNo' => $snapshot['ref'], 'pp_Amount' => $minor, 'pp_TxnCurrency' => 'PKR' ), array(), $settings );
	$code = is_array( $response ) ? crj_provider_code( $response['pp_ResponseCode'] ?? '' ) : '';
	$provider_details = crj_refund_provider_details( $response );
	crj_record_last_refund_result( $key, $provider_details, in_array( $code, array( '000', '0' ), true ) );
	if ( in_array( $code, array( '000', '0' ), true ) ) {
		update_option( $key . '_confirmed_amount', (int) ( $snapshot['refunded'] ?? 0 ) + (int) $minor, false );
		update_option( $key, 'confirmed', false );
		return 'direct_success';
	}
	if ( is_wp_error( $response ) && $response->get_error_code() === 'crj_credentials' ) {
		if ( $resume_partial ) {
			update_option( $key, 'confirmed', false );
		} else {
			delete_option( $key );
		}
		return 'credentials';
	}
	if ( crj_refund_was_rejected( $response, $code ) ) {
		if ( $resume_partial ) {
			update_option( $key, 'confirmed', false );
		} else {
			delete_option( $key );
		}
		return crj_is_missing_merchant_mpin( $response, $code ) ? 'merchant_mpin' : 'provider_rejected';
	}
	return 'review';
}

function crj_is_missing_merchant_mpin( $response, string $code ): bool {
	$message = is_array( $response ) && is_string( $response['pp_ResponseMessage'] ?? null ) ? trim( $response['pp_ResponseMessage'] ) : '';
	return $code !== '' && strcasecmp( rtrim( $message, '.' ), 'Merchant MPIN not found' ) === 0;
}

function crj_open_refund_form( string $label, string $reference, string $amount ): void {
	echo '<details class="crj-refund" data-cancel="' . esc_attr__( 'Cancel', 'cardraabta-pay-with-jazzcash' ) . '" data-processing="' . esc_attr__( 'Processing refund…', 'cardraabta-pay-with-jazzcash' ) . '" data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"><summary>' . esc_html( $label ) . '</summary>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<h2>' . esc_html( $label === __( 'Refund', 'cardraabta-pay-with-jazzcash' ) ? __( 'CardRaabta Refund', 'cardraabta-pay-with-jazzcash' ) : $label ) . '</h2>';
	echo '<dl class="crj-refund-details"><div><dt>Transaction</dt><dd class="crj-refund-reference">' . esc_html( $reference ) . '</dd></div><div><dt>Original Amount</dt><dd class="crj-refund-original">' . esc_html( $amount ) . '</dd></div></dl>';
}

function crj_refund_form( WC_Order $order, string $reference, string $original_amount, int $page, string $search, int $provider_page, ?int $provider_remaining = null ): void {
	if ( (string) $order->get_transaction_id() !== $reference ) {
		return;
	}
	$record = $order->get_meta( '_crj_refund_in_flight' );
	$recover = is_array( $record ) && ! empty( $record['provider_confirmed'] );

	if ( ! $recover && ( ! crj_refundable_attempt( $order ) || (float) $order->get_remaining_refund_amount() <= 0 ) ) {
		return;
	}
	$id = $order->get_id();
	crj_open_refund_form( $recover ? __( 'Save WooCommerce refund', 'cardraabta-pay-with-jazzcash' ) : __( 'Refund', 'cardraabta-pay-with-jazzcash' ), $reference, $original_amount );
	echo '<input type="hidden" name="action" value="crj_refund"><input type="hidden" name="order_id" value="' . esc_attr( (string) $id ) . '">';
	echo '<input type="hidden" name="crj_page" value="' . esc_attr( (string) $page ) . '"><input type="hidden" name="crj_search" value="' . esc_attr( $search ) . '">';
	$remaining = wc_format_decimal( $order->get_remaining_refund_amount(), 2 );
	echo '<input type="hidden" name="crj_remaining" value="' . esc_attr( $remaining ) . '">';
	$record_id = $recover ? $record['id'] : '';
	echo '<input type="hidden" name="crj_refund_id" value="' . esc_attr( $record_id ) . '">';
	echo '<input type="hidden" name="provider_page" value="' . esc_attr( (string) $provider_page ) . '">';
	wp_nonce_field( 'crj_refund_' . $id . '_' . $remaining . '_' . $record_id . '_' . $provider_page );
	if ( ! $recover ) {
		$limit = $provider_remaining === null ? $remaining : wc_format_decimal( min( (float) $remaining, $provider_remaining / 100 ), 2 );
		echo '<label for="crj-refund-' . esc_attr( (string) $id ) . '">Refund amount (PKR)</label> <input id="crj-refund-' . esc_attr( (string) $id ) . '" name="crj_amount" type="number" min="0.01" step="0.01" max="' . esc_attr( $limit ) . '" value="' . esc_attr( $limit ) . '" required>';
	}
	echo '<div class="crj-refund-actions"><button class="crj-refund-button">' . esc_html( $recover ? 'Save WooCommerce refund' : 'Process Refund' ) . '</button></div>';
	echo '</form></details>';
}

function crj_handle_refund(): void {
	crj_verify_refund_access();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The request is validated by crj_verify_refund_nonce before payment dispatch.
	$id = isset( $_POST['order_id'] ) && is_string( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The request is validated by crj_verify_refund_nonce before payment dispatch.
	$expected = isset( $_POST['crj_remaining'] ) && is_string( $_POST['crj_remaining'] ) ? sanitize_text_field( wp_unslash( $_POST['crj_remaining'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The request is validated by crj_verify_refund_nonce before payment dispatch.
	$record_id = isset( $_POST['crj_refund_id'] ) && is_string( $_POST['crj_refund_id'] ) ? sanitize_text_field( wp_unslash( $_POST['crj_refund_id'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The provider page is included in the nonce action below.
	$provider_page = isset( $_POST['provider_page'] ) && is_string( $_POST['provider_page'] ) ? absint( $_POST['provider_page'] ) : 0;
	crj_verify_refund_nonce( 'crj_refund_' . $id . '_' . $expected . '_' . $record_id . '_' . $provider_page );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The request is validated by crj_verify_refund_nonce before payment dispatch.
	$requested = isset( $_POST['crj_amount'] ) && is_string( $_POST['crj_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['crj_amount'] ) ) : '';
	$provider_details = array();
	try {
		$outcome = crj_refund_order( $id, $requested, $expected, $record_id, $provider_page, $provider_details );
	} catch ( Throwable $error ) {
		$outcome = in_array( $error->getMessage(), array( 'Invalid_amount', 'Payment_request_busy' ), true ) ? $error->getMessage() : 'review';
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The request is validated by crj_verify_refund_nonce before payment dispatch.
	$page = isset( $_POST['crj_page'] ) && is_string( $_POST['crj_page'] ) ? absint( $_POST['crj_page'] ) : 1;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The request is validated by crj_verify_refund_nonce before payment dispatch.
	$search = isset( $_POST['crj_search'] ) && is_string( $_POST['crj_search'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['crj_search'] ) ), 0, 100 ) : '';
	crj_send_refund_modal_result( $outcome, $provider_details );
	wp_safe_redirect( add_query_arg( array( 'page' => 'crj-transactions', 'crj_page' => $page, 'crj_search' => $search, 'crj_refund_result' => $outcome ), admin_url( 'admin.php' ) ), 303 );
	exit;
}

function crj_refund_order( int $id, string $requested, string $expected, string $record_id, int $provider_page, array &$provider_details = array() ): string {
	crj_security_lock( 'order:' . $id );
	$order = wc_get_order( $id );
	if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_payment_method() !== 'crj_card' ) {
		return 'ineligible';
	}
	$settings = (array) get_option( 'woocommerce_crj_card_settings', array() );
	$direct_key = crj_direct_refund_key( (string) ( $settings['live_merchant_id'] ?? '' ), (string) $order->get_transaction_id() );
	crj_security_lock( $direct_key );
	$direct_state = get_option( $direct_key, '' );
	$explicit_failure = crj_explicit_refund_failure( (array) get_option( $direct_key . '_last_result', array() ) );
	if ( $direct_state !== '' && ( $direct_state !== 'dispatching' || ! $explicit_failure || $record_id !== '' ) ) {
		return 'direct_duplicate';
	}
	$record = $order->get_meta( '_crj_refund_in_flight' );
	if ( $record_id === '' && is_array( $record ) && ( $record['state'] ?? '' ) === 'dispatching'
		&& ( $record['ref'] ?? '' ) === $order->get_transaction_id()
		&& $explicit_failure ) {
		$record = null;
	}
	if ( ( is_array( $record ) ? $record['id'] : '' ) !== $record_id ) {
		return 'stale';
	}
	if ( is_array( $record ) && ( empty( $record['provider_confirmed'] ) || ( $record['state'] ?? '' ) !== 'confirmed' ) ) {
		return 'review';
	}
	if ( ! is_array( $record ) ) {
		$attempt = crj_refundable_attempt( $order );
		if ( ! $attempt || $order->has_status( 'refunded' ) ) {
			return 'ineligible';
		}
		$previous_dispatch = get_option( $direct_key . '_linked_dispatch', '' );
		if ( $previous_dispatch === 'dispatching' ) {
			return 'review';
		}
		$minor = crj_minor_amount( $requested );
		$remaining = wc_format_decimal( $order->get_remaining_refund_amount(), 2 );
		if ( $remaining !== $expected ) {
			return 'stale';
		}
		if ( (float) $remaining <= 0 || (int) $minor > (int) crj_minor_amount( $remaining ) ) {
			return 'Invalid_amount';
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Use the exact paid attempt amount from the durable ledger.
		$captured = $wpdb->get_var( $wpdb->prepare( "SELECT amount FROM %i WHERE order_id=%d AND request_ref=%s AND provider_ref=%s AND currency='PKR' AND txn_status='COMPLETED'", crj_ledger_table(), $id, $attempt['request_ref'], $attempt['provider_ref'] ) );
		if ( $captured === null ) {
			return 'ineligible';
		}
		$payment = crj_refund_history_payment( $attempt['provider_ref'], $provider_page, $settings, $provider_details, true );
		if ( $payment === null ) {
			return 'direct_lookup';
		}
		if ( ! $payment || $payment['bill'] !== 'ORDER-' . $id || $payment['amount'] !== crj_minor_amount( $captured ) ) {
			return 'stale';
		}
		$balance = $payment['balance'];
		$recorded = crj_refund_recorded_minor( $order );
		$confirmed = (int) get_option( $direct_key . '_confirmed_amount', 0 );
		if ( ( $recorded > 0 || $previous_dispatch === 'confirmed' || $balance !== null )
			&& ( $balance === null || $balance['refunded'] !== $recorded || $balance['refunded'] < $confirmed ) ) {
			return 'stale';
		}
		if ( (int) $minor > ( $balance['remaining'] ?? (int) $payment['amount'] ) ) {
			return 'Invalid_amount';
		}
		$record = array(
			'state'  => 'dispatching',
			'id'     => wp_generate_uuid4(),
			'amount' => $minor,
			'ref'    => $attempt['provider_ref'],
		);
		delete_option( $direct_key . '_last_result' );
		if ( $direct_state === 'dispatching' ) {
			delete_option( $direct_key );
		}
		// A crash or uncertain transport must not lead to a second money movement on retry.
		$order->update_meta_data( '_crj_refund_in_flight', $record );
		$order->save_meta_data();
		crj_security_lock( 'order:' . $id );
		$order->read_meta_data( true );
		if ( $order->get_meta( '_crj_refund_in_flight' ) !== $record ) {
			return 'review';
		}
		crj_security_lock( $direct_key );
		// Keep the dispatch marker even if the WooCommerce order is permanently deleted.
		if ( ! update_option( $direct_key . '_linked_dispatch', 'dispatching', false ) ) {
			return 'review';
		}
		$response = crj_provider_request(
			'refund',
			array( 'pp_TxnRefNo' => $record['ref'], 'pp_Amount' => $minor, 'pp_TxnCurrency' => 'PKR' ),
			array(),
			$settings
		);
		crj_security_lock( 'order:' . $id );
		$code = is_array( $response ) ? crj_provider_code( $response['pp_ResponseCode'] ?? '' ) : '';
		$provider_details = crj_refund_provider_details( $response );
		crj_record_last_refund_result( $direct_key, $provider_details, in_array( $code, array( '000', '0' ), true ) );
		$mpin_missing = ! in_array( $code, array( '000', '0' ), true ) && crj_is_missing_merchant_mpin( $response, $code );
		if ( ( is_wp_error( $response ) && $response->get_error_code() === 'crj_credentials' ) || crj_refund_was_rejected( $response, $code ) ) {
			$order->delete_meta_data( '_crj_refund_in_flight' );
			$order->save_meta_data();
			if ( $previous_dispatch === 'confirmed' ) {
				update_option( $direct_key . '_linked_dispatch', 'confirmed', false );
			} else {
				delete_option( $direct_key . '_linked_dispatch' );
			}
			return $mpin_missing ? 'merchant_mpin' : ( is_wp_error( $response ) ? 'credentials' : 'provider_rejected' );
		}
		if ( ! in_array( $code, array( '000', '0' ), true ) ) {
			$order->add_order_note( 'CardRaabta JazzCash refund requires provider reconciliation.' . ( $code !== '' ? ' Response code: ' . $code . ' (' . crj_provider_status_label( $code ) . ').' : '' ) );
			return 'review';
		}
		$record['state'] = 'confirmed';
		$record['provider_confirmed'] = true;
		update_option( $direct_key . '_confirmed_amount', $recorded + (int) $minor, false );
		update_option( $direct_key . '_linked_dispatch', 'confirmed', false );
		$order->update_meta_data( '_crj_refund_in_flight', $record );
		$order->save_meta_data();
	}
	$minor = (int) $record['amount'];
	$amount = sprintf( '%d.%02d', intdiv( $minor, 100 ), $minor % 100 );
	$reason = 'CardRaabta JazzCash refund ' . $record['id'];
	$refund = null;
	foreach ( $order->get_refunds() as $existing ) {
		if ( $existing->get_reason() === $reason ) {
			$refund = $existing;
			break;
		}
	}
	if ( ! $refund ) {
		$refund = wc_create_refund(
			array(
				'order_id'       => $id,
				'amount'         => $amount,
				'reason'         => $reason,
				'refund_payment' => false,
				'restock_items'  => false,
			)
		);
	}
	if ( is_wp_error( $refund ) || ! $refund instanceof WC_Order_Refund ) {
		return 'local_pending';
	}
	$refund->set_refunded_payment( true );
	$refund->update_meta_data( '_crj_original_provider_ref', $record['ref'] );
	$refund->save();
	$order->delete_meta_data( '_crj_refund_in_flight' );
	$order->save_meta_data();
	return 'success';
}

function crj_refund_messages(): array {
	return array(
		'direct_success' => 'JazzCash confirmed the refund. Reconcile the WooCommerce order separately if needed.',
		'direct_lookup' => 'CardRaabta could not reverify this payment in JazzCash. Refresh the transaction list before trying again.',
		'direct_duplicate' => 'A direct refund was already submitted for this transaction. Check JazzCash before taking further action.',
		'merchant_mpin' => 'JazzCash rejected the refund: Merchant MPIN not found. Check refund provisioning with JazzCash merchant support.',
		'success' => 'JazzCash confirmed the refund and CardRaabta recorded it in WooCommerce.',
		'ineligible' => 'This order is ineligible for a CardRaabta JazzCash refund.',
		'Invalid_amount' => 'Enter a positive PKR amount within the remaining refundable balance.',
		'Payment_request_busy' => 'This order is being updated. Refresh before trying again.',
		'provider_rejected' => 'JazzCash rejected the refund.',
		'stale' => 'The refund form changed. Review the order and use the refreshed form.',
		'credentials' => 'Check your Live JazzCash credentials in the CardRaabta payment settings.',
		'local_pending' => 'JazzCash confirmed the refund. Use Save WooCommerce refund to retry recording it.',
		'review' => 'CardRaabta could not confirm the refund outcome. Check the JazzCash merchant portal before sending another refund.',
	);
}

function crj_verify_refund_access(): void {
	if ( current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$message = __( 'You are not allowed to send JazzCash refunds.', 'cardraabta-pay-with-jazzcash' );
	if ( wp_doing_ajax() ) {
		wp_send_json( array( 'success' => false, 'message' => $message, 'code' => 'HTTP 403' ), 403 );
	}
	wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
}

function crj_refund_login_required(): void {
	wp_send_json(
		array( 'success' => false, 'message' => __( 'Your WordPress session has expired. Sign in again before retrying.', 'cardraabta-pay-with-jazzcash' ), 'code' => 'HTTP 401' ),
		401
	);
}

function crj_verify_refund_nonce( string $action ): void {
	if ( ! wp_doing_ajax() ) {
		check_admin_referer( $action );
		return;
	}
	if ( ! check_ajax_referer( $action, '_wpnonce', false ) ) {
		wp_send_json(
			array( 'success' => false, 'message' => __( 'The refund form expired or changed. Refresh the Transactions page and try again.', 'cardraabta-pay-with-jazzcash' ), 'code' => 'HTTP 403', 'refresh' => true ),
			403
		);
	}
}

function crj_send_refund_modal_result( string $outcome, array $provider_details ): void {
	if ( ! wp_doing_ajax() ) {
		return;
	}
	$messages = crj_refund_messages();
	$success = in_array( $outcome, array( 'success', 'direct_success' ), true );
	$message = $messages[ $outcome ] ?? $messages['review'];
	if ( ! $success && $outcome !== 'local_pending' && ! empty( $provider_details['message'] ) ) {
		$message = $provider_details['message'];
	}
	wp_send_json(
		array(
			'success' => $success,
			'message' => $message,
			'code'    => $provider_details['code'] ?? '',
			'refresh' => $success || in_array( $outcome, array( 'review', 'stale', 'local_pending', 'direct_duplicate', 'direct_lookup', 'merchant_mpin', 'provider_rejected' ), true ),
		)
	);
}

function crj_refund_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, fixed notices from a redirect.
	$result = isset( $_GET['crj_refund_result'] ) && is_string( $_GET['crj_refund_result'] ) ? sanitize_text_field( wp_unslash( $_GET['crj_refund_result'] ) ) : '';
	$messages = crj_refund_messages();
	if ( isset( $messages[ $result ] ) ) {
		echo '<div class="notice ' . esc_attr( in_array( $result, array( 'success', 'direct_success' ), true ) ? 'notice-success' : 'notice-warning' ) . '"><p>' . esc_html( $messages[ $result ] ) . '</p></div>';
	}
}

function crj_payment_issue_message( WC_Order $order ): string {
	$code = crj_provider_code( $order->get_meta( '_crj_response_code' ) );
	if ( crj_order_awaits_confirmation( $order ) ) {
		if ( $code === '999' ) {
			return __( 'JazzCash has not confirmed your payment (code 999). If the payment prompt is still open on your phone, finish it there and then refresh this page. If the prompt closes or the status stays unchanged, check your wallet or contact the store before trying again.', 'cardraabta-pay-with-jazzcash' );
		}
		/* translators: %s: payment response code. */
		return $code !== '' ? sprintf( __( 'JazzCash has not confirmed your payment (code %s). Check your wallet or card account, or contact the store before trying to pay again.', 'cardraabta-pay-with-jazzcash' ), $code ) : __( 'JazzCash has not confirmed your payment. Check with the store before trying to pay again.', 'cardraabta-pay-with-jazzcash' );
	}
	if ( $code === '8900' ) {
		return __( 'JazzCash confirmed that this payment was cancelled (8900).', 'cardraabta-pay-with-jazzcash' );
	}
	if ( in_array( $code, array( '129', '9800', '1111', 'TMDO' ), true ) ) {
		/* translators: %s: payment response code. */
		return sprintf( __( 'JazzCash reported that this payment attempt ended without confirmation (code %s). If your wallet shows a debit, contact the store before trying again.', 'cardraabta-pay-with-jazzcash' ), $code );
	}
	if ( $code === '156' ) {
		return __( 'JazzCash declined the payment (156). The CNIC and mobile number did not match, or that number does not have a JazzCash Wallet. Check the details registered to your wallet before trying again.', 'cardraabta-pay-with-jazzcash' );
	}
	$details = (array) $order->get_meta( '_crj_provider_details' );
	if ( $code === '004' || ( $code === '405' && crj_payment_has_insufficient_funds( (string) ( $details['response_message'] ?? '' ) ) ) ) {
		/* translators: %s: payment response code. */
		return sprintf( __( 'JazzCash reported insufficient funds (code %s). Check your available balance or use another payment method.', 'cardraabta-pay-with-jazzcash' ), $code );
	}
	/* translators: %s: payment response code. */
	return $code !== '' ? sprintf( __( 'JazzCash payment was unsuccessful (code %s). Check the payment details before trying again.', 'cardraabta-pay-with-jazzcash' ), $code ) : '';
}

function crj_render_retry_notice( WC_Order $order ): void {
	if ( $order->get_payment_method() === 'crj_card' && $order->has_status( 'failed' ) && $order->get_meta( '_crj_status' ) === 'not_completed' ) {
		echo '<p class="crj-payment-notice">' . esc_html( crj_payment_issue_message( $order ) ) . '</p>';
	}
}

function crj_render_payment_notice( $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'crj_card' ) {
		return;
	}
	// WooCommerce may render the thank-you hook even when the visitor lacks the correct order key.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The WooCommerce order key authorizes this read-only view.
	$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	if ( ! crj_can_view_return( $order ) && ( $key === '' || ! hash_equals( $order->get_order_key(), $key ) ) ) {
		return;
	}
	$code = (string) $order->get_meta( '_crj_response_code' );
	if ( $order->is_paid() ) {
		echo '<p>' . esc_html__( 'CardRaabta confirmed your JazzCash payment.', 'cardraabta-pay-with-jazzcash' ) . '</p>';
	} elseif ( $code === '124' && $order->has_status( 'on-hold' ) ) {
		$details = (array) $order->get_meta( '_crj_provider_details' );
		$voucher = (string) ( $details['retrieval_reference'] ?? '' );
		echo '<p>' . esc_html__( 'Your JazzCash voucher is awaiting payment. Follow the payment instructions provided by JazzCash.', 'cardraabta-pay-with-jazzcash' ) . '</p>';
		if ( $voucher !== '' ) {
			echo '<p>' . esc_html__( 'Voucher reference', 'cardraabta-pay-with-jazzcash' ) . ' <code>' . esc_html( $voucher ) . '</code></p>';
		}
	} else {
		$message = crj_payment_issue_message( $order );
		if ( $message !== '' ) {
			$class = crj_order_awaits_confirmation( $order ) ? 'crj-payment-notice crj-payment-notice--waiting' : 'crj-payment-notice';
			echo '<p class="' . esc_attr( $class ) . '">' . esc_html( $message ) . '</p>';
		}
	}
}

function crj_needs_wallet_history( WC_Order $order ): bool {
	return $order->has_status( array( 'pending', 'failed', 'on-hold' ) )
		&& $order->get_meta( '_crj_environment' ) === 'live'
		&& (string) $order->get_meta( '_crj_response_code' ) === '999'
		&& crj_order_awaits_confirmation( $order );
}

/** Use Live merchant history only for this order's verified, unresolved wallet attempt. */
function crj_phone_history_result( WC_Order $order, array $rows, string $merchant ): array {
	$request = (string) $order->get_meta( '_crj_ref' );
	if ( ! preg_match( '/^CR[1-9][0-9]*X[A-F0-9]{10}$/D', $request ) || $merchant === '' ) {
		return array();
	}
	$attempts = array_values( array_filter( (array) $order->get_meta( '_crj_attempts' ), static fn( $attempt ) => is_array( $attempt )
		&& ( $attempt['request_ref'] ?? '' ) === $request && ! empty( $attempt['event_hash'] )
		&& ( $attempt['environment'] ?? '' ) === 'live' && ( $attempt['merchant_id'] ?? '' ) === $merchant ) );
	if ( count( $attempts ) !== 1 ) {
		return array();
	}
	$known = (string) ( $attempts[0]['provider_ref'] ?? '' );
	$amount = (string) $order->get_meta( '_crj_amount' );
	$bill = 'ORDER-' . $order->get_id();
	$successes = array();
	$terminal = array();
	$has_other_activity = false;
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$row = array_filter( $row, 'is_scalar' );
		$ref = (string) ( $row['pp_TxnRefNo'] ?? '' );
		// A hosted-link parent can be Dropped while its wallet charge succeeds.
		if ( ! str_starts_with( $ref, $request ) || ! preg_match( '/^[A-Za-z0-9]{1,8}$/D', substr( $ref, strlen( $request ) ) )
			|| ( $row['pp_BillReference'] ?? '' ) !== $bill || ( $row['pp_TxnCurrency'] ?? '' ) !== 'PKR'
			|| ltrim( (string) ( $row['pp_Amount'] ?? '' ), '0' ) !== $amount
			|| (string) ( $row['pp_MerchantID'] ?? $merchant ) !== $merchant ) {
			continue;
		}
		$type = strtoupper( trim( (string) ( $row['pp_TxnType'] ?? '' ) ) );
		if ( $type !== 'MWALLET' && ( $type !== '' || $ref !== $known || ( $attempts[0]['type'] ?? '' ) !== 'wallet' ) ) {
			continue;
		}
		$response = crj_provider_code( $row['pp_ResponseCode'] ?? '' );
		$status = crj_provider_code( $row['pp_Status'] ?? '' ) ?: $response;
		$ended = in_array( $status, array( '129', '9800', '8900', '1111', 'TMDO', '156' ), true );
		$paid = crj_payment_succeeded( $response ) && crj_payment_succeeded( $status );
		$matched = $known === $ref || $known === $request;
		if ( ! $matched || ( ! $paid && ! $ended ) ) {
			$has_other_activity = $has_other_activity || ! $ended;
			continue;
		}
		$data = array(
			'pp_TxnRefNo'      => $ref,
			'pp_BillReference' => $bill,
			'pp_MerchantID'    => $merchant,
			'pp_Amount'        => $amount,
			'pp_TxnCurrency'   => 'PKR',
			'pp_TxnType'       => 'MWALLET',
			'pp_ResponseCode'  => $paid ? $response : $status,
		);
		if ( $paid ) {
			$successes[ $ref ] = $data;
		} else {
			$terminal[ $ref . ':' . $status ] = $data;
		}
	}
	if ( count( $successes ) === 1 && ! $has_other_activity ) {
		$payment = reset( $successes );
		foreach ( $terminal as $failure ) {
			if ( $failure['pp_TxnRefNo'] === $payment['pp_TxnRefNo'] ) {
				return array();
			}
		}
		return $payment;
	}
	return ! $successes && ! $has_other_activity && count( $terminal ) === 1 ? reset( $terminal ) : array();
}
