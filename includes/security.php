<?php
/** Payment security helpers; no live-payment or refund API is enabled. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
function crj_minor_amount( $value ): string {
	$value = (string) $value;
	if ( ! preg_match( '/^(\d+)(?:\.(\d{1,2}))?$/D', $value, $match ) ) {
		throw new RuntimeException( 'Invalid_amount' );
	}
	$minor = ltrim( $match[1] . str_pad( $match[2] ?? '', 2, '0' ), '0' );
	if ( $minor === '' || strlen( $minor ) > 12 ) {
		throw new RuntimeException( 'Invalid_amount' );
	}
	return $minor;
}
/** Named locks are store-local and connection-scoped. Unsupported databases fail closed. */
function crj_security_lock( string $scope ): void {
	global $wpdb;
	static $held = array();
	$name        = 'crj_' . hash( 'sha256', $wpdb->prefix . '|' . $scope );
	$name        = substr( $name, 0, 64 );
	if ( isset( $held[ $name ] ) ) {
		return;
	}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection locking requires an uncached database function call.
	if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) !== '1' ) {
		throw new RuntimeException( 'Payment_request_busy' );
	}
	$held[ $name ] = true;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release only this connection's named lock at request shutdown.
	register_shutdown_function(
		static function () use ( $name ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release this connection-scoped named lock; caching would prevent its release.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	);
}
/** Reject duplicated keys, nested data, excessive fields and ambiguous callback structures. */
function crj_parse_callback( string $raw, string $content_type ): array {
	if ( $raw === '' || strlen( $raw ) > 32768 ) {
		throw new RuntimeException( 'Empty_or_oversized_payload' );
	}
	$mime    = strtolower( trim( explode( ';', $content_type, 2 )[0] ) );
	$payload = array();
	if ( $mime === 'application/json' ) {
		$payload = json_decode( $raw, true, 4, JSON_THROW_ON_ERROR );
		if ( ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new RuntimeException( 'Invalid_JSON_callback' );
		}
		preg_match_all( '/[,{]\s*("(?:\\\\.|[^"\\\\])*")\s*:/s', $raw, $keys );
		$seen = array();
		foreach ( $keys[1] as $encoded ) {
			$key = json_decode( $encoded, true, 2, JSON_THROW_ON_ERROR );
			if ( ! is_string( $key ) || isset( $seen[ $key ] ) ) {
				throw new RuntimeException( 'Duplicate_or_invalid_key' );
			}$seen[ $key ] = true;}
		if ( count( $seen ) !== count( $payload ) ) {
			throw new RuntimeException( 'Ambiguous_JSON_callback' );
		}
	} elseif ( $mime === 'application/x-www-form-urlencoded' ) {
		foreach ( explode( '&', $raw ) as $pair ) {
			$parts = explode( '=', $pair, 2 );
			$key   = urldecode( $parts[0] );
			if ( $key === '' || array_key_exists( $key, $payload ) ) {
				throw new RuntimeException( 'Duplicate_or_empty_key' );
			}$payload[ $key ] = urldecode( $parts[1] ?? '' );}
	} else {
		throw new RuntimeException( 'Unsupported_content_type' );
	}
	crj_validate_callback_fields( $payload );
	foreach ( $payload as $key => $value ) {
		if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,99}$/D', $key ) || ! is_scalar( $value ) || strlen( (string) $value ) > 8192 ) {
			throw new RuntimeException( 'Malformed_fields' );
		}$payload[ $key ] = (string) $value;}
	return $payload;
}
/** Reject case aliases: downstream provider labels normalize field names. */
function crj_validate_callback_fields( array $payload ): void {
	if ( ! $payload || count( $payload ) > 64 ) {
		throw new RuntimeException( 'Invalid_field_count' );
	}
	$seen = array();
	foreach ( $payload as $key => $value ) {
		if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,99}$/D', $key ) || ! is_scalar( $value ) || strlen( (string) $value ) > 8192 ) {
			throw new RuntimeException( 'Malformed_fields' );
		}
		$canonical = strtolower( $key );
		if ( isset( $seen[ $canonical ] ) ) {
			throw new RuntimeException( 'Ambiguous_field_case' );
		}
		$seen[ $canonical ] = true;
		if ( str_starts_with( $canonical, 'pp_' ) && ! str_starts_with( $key, 'pp_' ) ) {
			throw new RuntimeException( 'Unsigned_provider_field' );
		}
	}
}
/** Binding determines whether a browser may receive an order-key-bearing redirect, never payment authenticity. */
function crj_can_view_return( WC_Order $order ): bool {
	if ( get_current_user_id() > 0 && (int) $order->get_customer_id() === get_current_user_id() ) {
		return true;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return false;
	}
	$session = WC()->session;
	$binding = (string) $order->get_meta( '_crj_session_binding' );
	if ( $binding !== '' ) {
		return hash_equals( $binding, hash( 'sha256', (string) $session->get_customer_id() ) );
	}
	// Existing orders may predate the binding metadata; use WooCommerce's private checkout session.
	return $binding === '' && (int) $order->get_customer_id() === 0 && (int) $session->get( 'order_awaiting_payment' ) === $order->get_id();
}
/** Encrypt option secrets with authenticated encryption; the key derives from site salts in wp-config. */
function crj_protect_secret( string $value ): string {
	if ( $value === '' || str_starts_with( $value, 'crjenc1:' ) ) {
		return $value;
	}
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		throw new RuntimeException( 'OpenSSL_required' );
	}
	$iv     = random_bytes( 12 );
	$tag    = '';
	$key    = hash( 'sha256', wp_salt( 'auth' ) . '|crj-options-v1', true );
	$cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'crj-options-v1' );
	if ( $cipher === false ) {
		throw new RuntimeException( 'Credential_encryption_failed' );
	}
	return 'crjenc1:' . base64_encode( $iv . $tag . $cipher );
}
function crj_unprotect_secret( string $value ): string {
	if ( ! str_starts_with( $value, 'crjenc1:' ) ) {
		return $value;
	}
	if ( ! function_exists( 'openssl_decrypt' ) ) {
		return '';
	}
	$raw = base64_decode( substr( $value, 8 ), true );
	if ( $raw === false || strlen( $raw ) < 29 ) {
		return '';
	}
	$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ) . '|crj-options-v1', true ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'crj-options-v1' );
	return is_string( $plain ) ? $plain : '';
}
add_filter(
	'pre_update_option_woocommerce_crj_card_settings',
	static function ( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array();
		}
		foreach ( array( 'merchant_password', 'integrity_salt', 'live_merchant_password', 'live_integrity_salt' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$settings[ $key ] = crj_protect_secret( is_scalar( $settings[ $key ] ) ? (string) $settings[ $key ] : '' );
			}
		}
		return $settings;
	}
);
add_filter(
	'option_woocommerce_crj_card_settings',
	static function ( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array();
		}
		foreach ( array( 'merchant_password', 'integrity_salt', 'live_merchant_password', 'live_integrity_salt' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$settings[ $key ] = crj_unprotect_secret( is_string( $settings[ $key ] ) ? $settings[ $key ] : '' );
			}
		}
		return $settings;
	}
);
add_action(
	'admin_init',
	static function () {
		if ( current_user_can( 'manage_woocommerce' ) && get_option( 'crj_secret_storage_version' ) !== '1' ) {
			update_option( 'woocommerce_crj_card_settings', get_option( 'woocommerce_crj_card_settings', array() ), false );
			update_option( 'crj_secret_storage_version', '1', false );
		}}
);
/** The cursor applies to the entire search disjunction, preventing repeated export pages. */
function crj_ledger_export_page( string $search, int $last ): array {
	global $wpdb;
	$like = '%' . $wpdb->esc_like( $search ) . '%';
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private ledger export must stream current records with a bounded keyset cursor.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,order_id,provider_ref,request_ref,instrument,provider_txn_type,amount,currency,response_code,txn_status,provider_details,created_at,updated_at FROM %i WHERE id<%d AND (%s='' OR request_ref LIKE %s OR provider_ref LIKE %s OR CONCAT('ORDER-',order_id) LIKE %s) ORDER BY id DESC LIMIT 500", crj_ledger_table(), $last, $search, $like, $like, $like ), ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}

/** Signed continuation restores SameSite=Lax cookies on a top-level same-site GET.
 * This ticket never authorizes order disclosure; the browser ownership check still does.
 */
function crj_return_continuation_url( WC_Order $order ): string {
	$expires   = time() + 600;
	$id        = $order->get_id();
	$signature = hash_hmac( 'sha256', $id . '|' . $expires, wp_salt( 'nonce' ) . '|crj-return-v1' );
	return add_query_arg(
		array(
			'crj_return_order'     => $id,
			'crj_return_expires'   => $expires,
			'crj_return_signature' => $signature,
		),
		home_url( '/wc-api/result/' )
	);
}
function crj_return_destination( array $query ): string {
	foreach ( array( 'crj_return_order', 'crj_return_expires', 'crj_return_signature' ) as $key ) {
		if ( ! isset( $query[ $key ] ) || ! is_scalar( $query[ $key ] ) ) {
			return '';
		}
	}
	$id     = (string) $query['crj_return_order'];
	$expiry = (string) $query['crj_return_expires'];
	$sig    = (string) $query['crj_return_signature'];
	if ( ! preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) || ! preg_match( '/^[0-9]{10}$/D', $expiry ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sig ) ) {
		return '';
	}
	if ( (int) $expiry < time() || (int) $expiry > time() + 600 ) {
		return '';
	}
	if ( ! hash_equals( hash_hmac( 'sha256', $id . '|' . $expiry, wp_salt( 'nonce' ) . '|crj-return-v1' ), $sig ) ) {
		return '';
	}
	if ( function_exists( 'WC' ) && ! WC()->session && method_exists( WC(), 'initialize_session' ) ) {
		WC()->initialize_session();
	}
	$order = wc_get_order( (int) $id );
	if ( ! $order instanceof WC_Order || ! in_array( $order->get_payment_method(), array( 'crj_card', 'crj_wallet' ), true ) || ! crj_can_view_return( $order ) ) {
		return '';
	}
	return $order->get_checkout_order_received_url();
}
/** Only non-secret fields from a verified signed response may enter this schema. */
function crj_provider_detail_labels(): array {
	return array(
		'transaction_id'       => 'JazzCash Transaction ID',
		'transaction_type'     => 'TXN Type',
		'response_code'        => 'Response Code',
		'response_message'     => 'Response Message',
		'transaction_datetime' => 'Transaction Date/Time',
		'settlement_date'      => 'Settlement Date',
		'settlement_expiry'    => 'Settlement Expiry',
		'retrieval_reference'  => 'Retrieval Reference',
		'authorization_code'   => 'Authorization Code',
		'bank_id'              => 'Bank ID',
		'merchant_id'          => 'Merchant ID',
		'submerchant_id'       => 'Submerchant ID',
		'product_id'           => 'Product ID',
		'api_version'          => 'API Version',
		'language'             => 'Language',
	);
}
function crj_filter_provider_details( array $details ): array {
	$safe = array();
	foreach ( crj_provider_detail_labels() as $key => $label ) {
		if ( isset( $details[ $key ] ) && is_scalar( $details[ $key ] ) && trim( (string) $details[ $key ] ) !== '' ) {
			$safe[ $key ] = sanitize_text_field( substr( (string) $details[ $key ], 0, $key === 'response_message' ? 8192 : 256 ) );
		}
	}
	return $safe;
}
function crj_signed_provider_details( array $data, array $existing = array() ): array {
	$fields  = array(
		'pp_txnrefno'             => 'transaction_id',
		'pp_txntype'              => 'transaction_type',
		'pp_responsecode'         => 'response_code',
		'pp_responsemessage'      => 'response_message',
		'pp_txndatetime'          => 'transaction_datetime',
		'pp_settlementdate'       => 'settlement_date',
		'pp_settlementexpiry'     => 'settlement_expiry',
		'pp_retreivalreferenceno' => 'retrieval_reference',
		'pp_retrievalreferenceno' => 'retrieval_reference',
		'pp_authcode'             => 'authorization_code',
		'pp_authorizationcode'    => 'authorization_code',
		'pp_authorisationcode'    => 'authorization_code',
		'pp_bankid'               => 'bank_id',
		'pp_merchantid'           => 'merchant_id',
		'pp_submerchantid'        => 'submerchant_id',
		'pp_productid'            => 'product_id',
		'pp_version'              => 'api_version',
		'pp_language'             => 'language',
	);
	$details = crj_filter_provider_details( $existing );
	foreach ( $data as $field => $value ) {
		if ( ! str_starts_with( (string) $field, 'pp_' ) ) {
			continue;
		}$key = $fields[ strtolower( (string) $field ) ] ?? '';
		if ( $key !== '' && is_string( $value ) && trim( $value ) !== '' ) {
			$details[ $key ] = $value;
		}
	}
	return crj_filter_provider_details( $details );
}
/** Read exactly the matching attempt; never borrow details from a different payment. */
function crj_transaction_details( array $record, ?WC_Order $order = null ): array {
	$details  = json_decode( (string) ( $record['provider_details'] ?? '' ), true );
	$details  = is_array( $details ) ? crj_filter_provider_details( $details ) : array();
	$provider = (string) ( $record['provider_ref'] ?? '' );
	$request  = (string) ( $record['request_ref'] ?? '' );
	if ( ! $order && isset( $record['order_id'] ) ) {
		$order = wc_get_order( (int) $record['order_id'] );
	}
	$fallback = array();
	if ( $order instanceof WC_Order ) {
		$attempts = $order->get_meta( '_crj_attempts' );
		foreach ( is_array( $attempts ) ? $attempts : array() as $attempt ) {
			if ( is_array( $attempt ) && $request !== '' && ( $attempt['request_ref'] ?? '' ) === $request && (string) ( $attempt['provider_ref'] ?? '' ) === $provider ) {
				$fallback = is_array( $attempt['provider_details'] ?? null ) ? $attempt['provider_details'] : array();
				break;}
		}
		if ( $provider !== '' && (string) $order->get_meta( '_crj_provider_ref' ) === $provider && (string) $order->get_meta( '_crj_ref' ) === $request ) {
			$latest = $order->get_meta( '_crj_provider_details' );
			if ( is_array( $latest ) ) {
				$fallback = array_merge( $latest, $fallback );
			}
		}
	}
	return array_merge( crj_filter_provider_details( $fallback ), $details );
}
/** One CSV column per displayed datum, including all allowlisted detail fields. */
function crj_transaction_csv_columns(): array {
	return array(
		'id'                   => 'Ledger ID',
		'order_reference'      => 'Order Reference',
		'provider_ref'         => 'JazzCash Transaction ID',
		'request_ref'          => 'Merchant Reference',
		'txn_type'             => 'TXN Type',
		'amount'               => 'Amount',
		'currency'             => 'Currency',
		'response_code'        => 'Response Code',
		'response_message'     => 'Response Message',
		'txn_status'           => 'Status',
		'created_at'           => 'Created At',
		'updated_at'           => 'Updated At',
		'transaction_datetime' => 'Transaction Date/Time',
		'settlement_date'      => 'Settlement Date',
		'settlement_expiry'    => 'Settlement Expiry',
		'retrieval_reference'  => 'Retrieval Reference',
		'authorization_code'   => 'Authorization Code',
		'bank_id'              => 'Bank ID',
		'merchant_id'          => 'Merchant ID',
		'submerchant_id'       => 'Submerchant ID',
		'product_id'           => 'Product ID',
		'api_version'          => 'API Version',
		'language'             => 'Language',
	);
}
function crj_transaction_csv_cells( array $record ): array {
	$details                = crj_transaction_details( $record );
	$row                    = array_merge( $details, $record );
	$row['order_reference'] = 'ORDER-' . (int) $record['order_id'];
	$row['txn_type']        = (string) ( $record['provider_txn_type'] ?: ( $details['transaction_type'] ?? '' ) );
	if ( $row['txn_type'] === '' ) {
		$row['txn_type'] = array(
			'wallet'  => 'MWALLET',
			'card'    => 'MPAY',
			'voucher' => 'VOUCHER',
			'raastqr' => 'RAAST QR',
		)[ $record['instrument'] ] ?? 'UNCONFIRMED';
	}
	if ( empty( $row['provider_ref'] ) ) {
		$row['provider_ref'] = $details['transaction_id'] ?? '';
	}
	if ( (string) ( $row['response_code'] ?? '' ) === '' ) {
		$row['response_code'] = $details['response_code'] ?? '';
	}
	$cells = array();
	foreach ( crj_transaction_csv_columns() as $key => $label ) {
		$value = (string) ( $row[ $key ] ?? '' );
		if ( $value === '' && in_array( $key, array( 'authorization_code', 'settlement_date', 'settlement_expiry', 'retrieval_reference', 'bank_id', 'submerchant_id', 'product_id', 'api_version', 'language', 'response_message', 'transaction_datetime', 'merchant_id' ), true ) ) {
			$value = 'Not available in verified response';
		}
		// Only this strictly numeric, fixed-shape expression may be evaluated by Excel.
		if ( $key === 'response_code' && preg_match( '/^[0-9]{3}$/D', $value ) ) {
			$value = '="' . $value . '"';
		} elseif ( preg_match( '/^[\x00-\x20]*[=+@\-]/u', $value ) ) {
			$value = "'" . $value;
		}
		$cells[] = $value;
	}
	return $cells;
}
function crj_transaction_csv_filename(): string {
	return 'cardraabta-pay-with-jazzcash-transactions-' . gmdate( 'Y-m-d' ) . '.csv';}
