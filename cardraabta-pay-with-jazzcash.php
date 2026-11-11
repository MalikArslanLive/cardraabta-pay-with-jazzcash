<?php
/**
 * Plugin Name: CardRaabta – Pay with JazzCash for WooCommerce
 * Description: JazzCash hosted payments for WooCommerce with signed payment verification. Sandbox integration; live payments and refunds are disabled.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Text Domain: cardraabta-pay-with-jazzcash
 * WC requires at least: 8.3
 * WC tested up to: 11.2.2
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: Malik Arslan
 * Author URI: https://malikarslan.com/
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * License: GPL-2.0-or-later
 */
defined( 'ABSPATH' ) || exit;
define( 'CRJ_ORCH_VERSION', '1.0.0' );
define( 'CRJ_ORCH_URL', plugin_dir_url( __FILE__ ) );
require_once plugin_dir_path( __FILE__ ) . 'includes/security.php';
add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( $hook === 'woocommerce_page_crj-transactions' ) {
			wp_enqueue_style( 'crj-admin', CRJ_ORCH_URL . 'assets/admin.css', array(), CRJ_ORCH_VERSION );
			wp_enqueue_script( 'crj-admin', CRJ_ORCH_URL . 'assets/admin.js', array(), CRJ_ORCH_VERSION, true );
		}}
);
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}}
);

// Durable transaction ledger. Records survive WooCommerce order deletion.
function crj_ledger_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'crj_transactions';}
function crj_ledger_install(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = crj_ledger_table();
	$collate = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE $table (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 order_id bigint(20) unsigned NOT NULL,
 request_ref varchar(80) NOT NULL,
 provider_ref varchar(80) DEFAULT NULL,
 amount decimal(18,2) NOT NULL DEFAULT 0.00,
 currency varchar(8) NOT NULL DEFAULT 'PKR',
 instrument varchar(32) NOT NULL DEFAULT 'unknown',
 provider_txn_type varchar(32) NOT NULL DEFAULT '',
 response_code varchar(16) NOT NULL DEFAULT '',
 txn_status varchar(32) NOT NULL DEFAULT 'INITIATED',
 provider_details longtext NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY request_ref (request_ref),
 UNIQUE KEY provider_ref (provider_ref),
 KEY order_id (order_id),
 KEY created_at (created_at)
 ) $collate;"
	);
	update_option( 'crj_ledger_schema', '1', false );
	if ( get_option( 'crj_ledger_migration_done', null ) === null ) {
		update_option( 'crj_ledger_migration_done', '0', false );
	}
}
add_action(
	'init',
	function () {
		if ( get_option( 'crj_ledger_schema' ) !== '1' ) {
			crj_ledger_install();
		}},
	6
);
function crj_ledger_store( WC_Order $order, array $attempt ): bool {
	global $wpdb;
	$ref = (string) ( $attempt['request_ref'] ?? '' );
	if ( $ref === '' ) {
		return false;
	}
	$table    = crj_ledger_table();
	$now      = current_time( 'mysql' );
	$provider = (string) ( $attempt['provider_ref'] ?? '' );
	// Do not overwrite another attempt on a provider ID collision.
	if ( $provider !== '' ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private durable ledger has no WordPress object API; authoritative current state is required for payment reconciliation.
		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT request_ref FROM %i WHERE provider_ref=%s LIMIT 1', $table, $provider ) );
		if ( $owner !== null && ! hash_equals( (string) $owner, $ref ) ) {
			return false;
		}
	}
	$details = is_array( $attempt['provider_details'] ?? null ) ? $attempt['provider_details'] : array();
	$data    = array(
		'order_id'          => $order->get_id(),
		'request_ref'       => $ref,
		'provider_ref'      => $provider ?: null,
		'amount'            => (string) $order->get_total(),
		'currency'          => (string) $order->get_currency(),
		'instrument'        => (string) ( $attempt['type'] ?? 'unknown' ),
		'provider_txn_type' => (string) ( $attempt['provider_txn_type'] ?? '' ),
		'response_code'     => (string) ( $attempt['code'] ?? '' ),
		'txn_status'        => (string) ( $attempt['status'] ?? 'INITIATED' ),
		'provider_details'  => wp_json_encode( $details ),
		'created_at'        => (string) ( $attempt['created'] ?? $now ),
		'updated_at'        => $now,
	);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private durable ledger has no WordPress object API; authoritative current state is required for payment reconciliation.
	$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE request_ref=%s LIMIT 1', $table, $ref ) );
	if ( $exists ) {
		unset( $data['request_ref'], $data['created_at'] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private durable ledger has no WordPress object API; authoritative current state is required for payment reconciliation.
		return $wpdb->update( $table, $data, array( 'id' => (int) $exists ) ) !== false;
	}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private durable ledger has no WordPress object API; authoritative current state is required for payment reconciliation.
	return $wpdb->insert( $table, $data ) !== false;
}
function crj_ledger_import_order( WC_Order $order ): void {
	if ( ! in_array( $order->get_payment_method(), array( 'crj_card', 'crj_wallet' ), true ) ) {
		return;
	}
	$attempts = $order->get_meta( '_crj_attempts' );
	if ( ! is_array( $attempts ) || ! $attempts ) {
		$ref = (string) $order->get_meta( '_crj_ref' );
		if ( $ref === '' ) {
			return;
		}
		$attempts = array(
			array(
				'request_ref'       => $ref,
				'provider_ref'      => (string) $order->get_meta( '_crj_provider_ref' ),
				'type'              => (string) $order->get_meta( '_crj_instrument' ),
				'provider_txn_type' => (string) $order->get_meta( '_crj_provider_txn_type' ),
				'code'              => (string) $order->get_meta( '_crj_response_code' ),
				'status'            => $order->is_paid() ? 'COMPLETED' : 'UNKNOWN',
				'created'           => (string) $order->get_meta( '_crj_created_at' ),
				'provider_details'  => $order->get_meta( '_crj_provider_details' ),
			),
		);
	}
	foreach ( $attempts as $a ) {
		if ( is_array( $a ) ) {
			crj_ledger_store( $order, $a );
		}
	}
}
// Incremental import of historic orders, including HPOS. Cursor advances by page.
add_action(
	'crj_ledger_migrate',
	function () {
		if ( ! function_exists( 'wc_get_orders' ) || get_option( 'crj_ledger_migration_done' ) === '1' ) {
			return;
		}
		$page   = max( 1, (int) get_option( 'crj_ledger_migration_page', 1 ) );
		$orders = wc_get_orders(
			array(
				'limit'   => 100,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
				'status'  => array_keys( wc_get_order_statuses() ),
			)
		);
		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				crj_ledger_import_order( $order );
			}
		}
		if ( count( $orders ) < 100 ) {
			update_option( 'crj_ledger_migration_done', '1', false );
			delete_option( 'crj_ledger_migration_page' );
		} else {
			update_option( 'crj_ledger_migration_page', $page + 1, false );
		}
		if ( get_option( 'crj_ledger_migration_done' ) !== '1' ) {
			wp_schedule_single_event( time() + 30, 'crj_ledger_migrate' );
		}
	}
);
add_action(
	'init',
	function () {
		if ( get_option( 'crj_ledger_schema' ) === '1' && get_option( 'crj_ledger_migration_done' ) !== '1' && ! wp_next_scheduled( 'crj_ledger_migrate' ) ) {
			wp_schedule_single_event( time() + 10, 'crj_ledger_migrate' );
		}
	},
	20
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		class CRJ_Orchestrator_Gateway extends WC_Payment_Gateway {
			public function __construct() {
				$this->id                 = 'crj_card'; // Stable gateway identifier used by settings, orders and signed callbacks.
				$this->method_title       = 'Pay With JazzCash';
				$this->method_description = 'JazzCash hosted payments for WooCommerce by CardRaabta. Sandbox integration; live payments remain disabled.';
				$this->has_fields         = true;
				$this->supports           = array( 'products' );
				$this->init_form_fields();
				$this->init_settings();
				$this->title       = $this->get_option( 'title', 'Pay With JazzCash' );
				$this->description = 'Pay With JazzCash: hassle-free checkout in seconds.';
				$this->enabled     = $this->get_option( 'enabled', 'no' );
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			}
			public function init_form_fields() {
				$this->form_fields = array(
					'enabled'                 => array(
						'title'   => 'Enable',
						'type'    => 'checkbox',
						'label'   => 'Enable Pay With JazzCash',
						'default' => 'no',
					),
					'title'                   => array(
						'title'   => 'Checkout title',
						'type'    => 'text',
						'default' => 'Pay With JazzCash',
					),
					'environment'             => array(
						'title'       => 'Environment',
						'type'        => 'select',
						'options'     => array(
							'sandbox' => 'Sandbox',
							'live'    => 'Live (For Approved Merchants)',
						),
						'default'     => 'sandbox',
						'description' => 'Select Live after your JazzCash merchant account has been approved and your production credentials are available. Note: Live payment processing remains disabled in this release until the production API is verified.',
					),
					'merchant_id'             => array(
						'title'   => 'Merchant ID',
						'type'    => 'text',
						'default' => '',
					),
					'merchant_password'       => array(
						'title'   => 'Merchant Password',
						'type'    => 'password',
						'default' => '',
					),
					'integrity_salt'          => array(
						'title'   => 'Integrity Salt',
						'type'    => 'password',
						'default' => '',
					),
					'live_merchant_id'        => array(
						'title'   => 'Live Merchant ID',
						'type'    => 'text',
						'default' => '',
					),
					'live_merchant_password'  => array(
						'title'   => 'Live Merchant Password',
						'type'    => 'password',
						'default' => '',
					),
					'live_integrity_salt'     => array(
						'title'   => 'Live Integrity Salt',
						'type'    => 'password',
						'default' => '',
					),
					'api_version'             => array(
						'title'   => 'API Version',
						'type'    => 'select',
						'options' => array(
							'2.0' => '2.0',
							'1.1' => '1.1',
						),
						'default' => '2.0',
					),
					'merchant_callback_guide' => array(
						'title'       => 'JazzCash Merchant Portal — Callback Configuration',
						'type'        => 'title',
						'description' => $this->merchant_callback_guide_html(),
					),
				);
			}
			private function merchant_callback_guide_html(): string {
				// Display-only instructions: never modify the established callback routes.
				$return_url = home_url( '/wc-api/result/' );
				$ipn_url    = home_url( '/jazzcash_ipn_v2' );
				$rows       = array(
					array( 'Return URL', $return_url ),
					array( 'IPN URL', $ipn_url ),
					array( 'IPN Protocol', 'REST' ),
				);
				$html       = '<div style="max-width:900px"><p><strong>JazzCash Merchant Portal setup:</strong> Copy the <strong>Return URL</strong> below into the Return URL field in your JazzCash Merchant Portal. Copy the <strong>IPN URL</strong> below into the IPN URL field. For <strong>IPN Protocol</strong>, select <strong>REST</strong>.</p>';
				$html      .= '<table class="widefat striped" style="max-width:900px"><tbody>';
				foreach ( $rows as $row ) {
					$html .= '<tr><th scope="row" style="width:190px;vertical-align:middle">' . esc_html( $row[0] ) . '</th><td style="overflow-wrap:anywhere"><code style="user-select:all">' . esc_html( $row[1] ) . '</code></td></tr>';
				}
				$html .= '</tbody></table><p><strong>Return URL</strong> redirects customers back to your store after payment. <strong>IPN URL</strong> receives payment status notifications from JazzCash. The API Version dropdown above is a separate setting.</p>';
				$html .= '<p><strong>Important:</strong> Keep the existing callback configuration unchanged if your merchant portal already has these values and payments work. Security plugins or firewalls must allow JazzCash callbacks.</p></div>';
				return $html;
			}
			public function admin_options() {
				parent::admin_options();
				wp_enqueue_script( 'crj-settings', CRJ_ORCH_URL . 'assets/settings.js', array(), CRJ_ORCH_VERSION, true );}
			public function validate_merchant_password_field( $key, $value ) {
				if ( $value !== null && ( ! is_string( $value ) || strlen( $value ) > 8192 ) ) {
					throw new Exception( esc_html__( 'Invalid merchant password input.', 'cardraabta-pay-with-jazzcash' ) );
				}
				// WooCommerce reverses WordPress form slashing without corrupting literal
				// percent sequences or angle brackets in provider-issued passwords.
				$value = parent::validate_password_field( $key, $value );
				return $value === '' ? $this->get_option( $key, '' ) : $value;
			}
			public function validate_live_merchant_password_field( $key, $value ) {
				return $this->validate_merchant_password_field( $key, $value );
			}
			public function payment_fields() {
				echo '<div class="crj-payment-choice" style="padding:12px 0;line-height:1.6">';
				echo '<div style="font-size:19px;font-weight:700;margin-bottom:8px">' . esc_html( $this->description ) . '</div>';
				echo '<div style="display:grid;gap:4px"><span>💳 Debit/Credit Card</span><span>📱 Mobile Wallet</span><span>🎟️ Voucher</span><span>🏦 RaastQR</span></div>';
				echo '<small style="display:block;margin-top:10px">Available methods are determined by JazzCash on its secure hosted payment page.</small></div>';
			}
			private function credentials(): array {
				return (array) get_option( 'woocommerce_crj_card_settings', array() );}
			public function is_available() {
				$c = $this->credentials();
				return parent::is_available() && ( $c['environment'] ?? 'sandbox' ) === 'sandbox' && is_ssl() && get_woocommerce_currency() === 'PKR'
				&& ! empty( $c['merchant_id'] ) && ! empty( $c['merchant_password'] ) && ! empty( $c['integrity_salt'] );
			}
			private static function diagnostic( string $event, array $details = array() ): void {
				if ( ! function_exists( 'wc_get_logger' ) ) {
					return;
				}
				// Strict allowlist: never record payloads, credentials, customer details, or payment links.
				$allowed = array( 'order_id', 'method', 'http_status', 'wp_error_code', 'content_type', 'response_bytes', 'json_valid', 'success_type', 'success_value', 'data_type', 'link_present', 'link_type', 'link_host_allowed', 'link_path_valid', 'error_code', 'error_message', 'response_keys', 'data_keys' );
				$safe    = array();
				foreach ( $allowed as $key ) {
					if ( ! array_key_exists( $key, $details ) ) {
						continue;
					}
					$value = $details[ $key ];
					if ( in_array( $key, array( 'response_keys', 'data_keys' ), true ) ) {
						$safe[ $key ] = is_array( $value ) ? array_slice( array_values( array_filter( $value, fn( $v )=>is_string( $v ) && preg_match( '/^[A-Za-z_]{1,40}$/D', $v ) ) ), 0, 20 ) : array();
					} elseif ( $key === 'error_message' ) {
						// Provider text may contain identifiers or tokens; never log raw error messages.
						$safe[ $key ] = 'redacted (see error_code / response shape)';
					} elseif ( $key === 'error_code' ) {
						$safe[ $key ] = is_scalar( $value ) && preg_match( '/^[A-Za-z0-9_.-]{1,50}$/D', (string) $value ) ? (string) $value : 'unavailable';
					} elseif ( is_bool( $value ) || is_int( $value ) ) {
						$safe[ $key ] = $value;
					} elseif ( is_string( $value ) ) {
						$safe[ $key ] = substr( sanitize_text_field( $value ), 0, 80 );
					}
				}
				wc_get_logger()->info( 'CRJ diagnostic: ' . $event . ' ' . wp_json_encode( $safe ), array( 'source' => 'cardraabta-pay-with-jazzcash-diagnostic' ) );
			}
			private static function hash_fields( array $fields, string $salt ): string {
				$signed = array();
				foreach ( $fields as $k => $v ) {
					if ( preg_match( '/^pp_[A-Za-z0-9_]+$/D', (string) $k ) && strcasecmp( (string) $k, 'pp_SecureHash' ) !== 0 && is_scalar( $v ) && trim( (string) $v ) !== '' ) {
						$signed[ $k ] = (string) $v;
					}
				}
				ksort( $signed, SORT_STRING );
				return strtoupper( hash_hmac( 'sha256', $salt . '&' . implode( '&', $signed ), $salt ) );
			}
			private static function valid_link( $link ): bool {
				if ( ! is_string( $link ) || strlen( $link ) > 2048 || $link === '' ) {
					return false;
				}
				// Explicit character checks avoid PCRE escape ambiguity.
				for ( $i = 0, $n = strlen( $link );$i < $n;$i++ ) {
					$code = ord( $link[ $i ] );
					if ( $code <= 32 || $code === 127 || $link[ $i ] === chr( 92 ) ) {
						return false;
					}
				}
				$u = wp_parse_url( $link );
				if ( ! is_array( $u ) || strtolower( (string) ( $u['scheme'] ?? '' ) ) !== 'https' || strtolower( (string) ( $u['host'] ?? '' ) ) !== 'onlinepayments.jazzcash.com.pk' ) {
					return false;
				}
				if ( isset( $u['user'] ) || isset( $u['pass'] ) || isset( $u['port'] ) || isset( $u['fragment'] ) ) {
					return false;
				}
				$path    = (string) ( $u['path'] ?? '' );
				$decoded = rawurldecode( rawurldecode( $path ) );
				if ( preg_match( '/[\x00-\x20\x7F]/', $decoded ) ) {
					return false;
				}
				if ( $path === '' || $path === '/' || str_contains( $decoded, '..' ) || str_contains( $decoded, chr( 92 ) ) || str_contains( $decoded, '//' ) ) {
					return false;
				}
				return true;
			}
			public function process_payment( $order_id ) {
				try {
					if ( ! $this->is_available() ) {
						throw new RuntimeException( 'JazzCash sandbox credentials or PKR/HTTPS configuration is missing.' );
					}
					$order = wc_get_order( $order_id );
					if ( ! $order || ! $order->needs_payment() || $order->get_currency() !== 'PKR' || $order->get_payment_method() !== $this->id ) {
						throw new RuntimeException( 'Invalid payable order.' );
					}
					crj_security_lock( 'order:' . $order_id );
					$order = wc_get_order( $order_id );
					if ( ! $order || ! $order->needs_payment() || $order->get_date_paid() ) {
						throw new RuntimeException( 'Order_not_payable' );
					}
					$c     = $this->credentials();
					$total = (string) $order->get_total();
					if ( ! preg_match( '/^(\d+)(?:\.(\d{1,2}))?$/D', $total, $m ) ) {
						throw new RuntimeException( 'Invalid amount.' );
					}
					$amount = ltrim( $m[1] . str_pad( $m[2] ?? '', 2, '0' ), '0' );
					if ( $amount === '' || strlen( $amount ) > 12 ) {
						throw new RuntimeException( 'Invalid amount.' );
					}
					$ref                     = 'CR' . $order_id . 'X' . strtoupper( bin2hex( random_bytes( 5 ) ) );
					$now                     = new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Karachi' ) );
					$fields                  = array(
						'pp_Version'           => $c['api_version'] ?? '2.0',
						'pp_MerchantID'        => (string) $c['merchant_id'],
						'pp_Password'          => (string) $c['merchant_password'],
						'pp_Language'          => 'EN',
						'pp_TxnRefNo'          => $ref,
						'pp_Amount'            => $amount,
						'pp_TxnCurrency'       => 'PKR',
						'pp_TxnDateTime'       => $now->format( 'YmdHis' ),
						'pp_TxnExpiryDateTime' => $now->modify( '+12 hours' )->format( 'YmdHis' ),
						'pp_BillReference'     => 'ORDER-' . $order_id,
						'pp_Description'       => 'WooCommerce Order #' . $order_id,
						'pp_ReturnURL'         => home_url( '/wc-api/result/' ),
					);
					$fields['pp_SecureHash'] = self::hash_fields( $fields, (string) $c['integrity_salt'] );
					$response                = wp_remote_post(
						'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v1/transactions/link',
						array(
							'headers'             => array(
								'Content-Type' => 'application/x-www-form-urlencoded',
								'Accept'       => 'application/json',
							),
							'body'                => http_build_query( $fields, '', '&', PHP_QUERY_RFC1738 ),
							'timeout'             => 30,
							'sslverify'           => true,
							'redirection'         => 0,
							'limit_response_size' => 65536,
						)
					);
					if ( is_wp_error( $response ) ) {
								self::diagnostic(
									'transport_error',
									array(
										'order_id'      => (int) $order_id,
										'method'        => $this->id,
										'wp_error_code' => $response->get_error_code(),
									)
								);
								throw new RuntimeException( 'Could not connect securely to JazzCash.' );
					}
					$http_status    = (int) wp_remote_retrieve_response_code( $response );
					$raw_body       = (string) wp_remote_retrieve_body( $response );
					$json           = json_decode( $raw_body, true );
					$link_candidate = is_array( $json ) ? ( $json['Data']['Link'] ?? null ) : null;
					$link_parts     = is_string( $link_candidate ) ? wp_parse_url( $link_candidate ) : false;
					$diagnostic     = array(
						'order_id'          => (int) $order_id,
						'method'            => $this->id,
						'http_status'       => $http_status,
						'content_type'      => (string) wp_remote_retrieve_header( $response, 'content-type' ),
						'response_bytes'    => strlen( $raw_body ),
						'json_valid'        => json_last_error() === JSON_ERROR_NONE,
						'success_type'      => is_array( $json ) && array_key_exists( 'Success', $json ) ? gettype( $json['Success'] ) : 'missing',
						'success_value'     => is_array( $json ) && isset( $json['Success'] ) && is_bool( $json['Success'] ) ? $json['Success'] : 'not_boolean',
						'data_type'         => is_array( $json ) && array_key_exists( 'Data', $json ) ? gettype( $json['Data'] ) : 'missing',
						'link_present'      => is_string( $link_candidate ) && $link_candidate !== '',
						'link_type'         => gettype( $link_candidate ),
						'link_host_allowed' => is_array( $link_parts ) && strtolower( (string) ( $link_parts['host'] ?? '' ) ) === 'onlinepayments.jazzcash.com.pk',
						'link_path_valid'   => is_array( $link_parts ) && self::valid_link( $link_candidate ),
						'response_keys'     => is_array( $json ) ? array_keys( $json ) : array(),
						'data_keys'         => is_array( $json ) && isset( $json['Data'] ) && is_array( $json['Data'] ) ? array_keys( $json['Data'] ) : array(),
					);
					foreach ( array( 'ResponseCode', 'Code', 'ErrorCode', 'errorCode', 'code', 'error_code' ) as $key ) {
						if ( is_array( $json ) && array_key_exists( $key, $json ) ) {
							$diagnostic['error_code'] = $json[ $key ];
							break; }
					}
					self::diagnostic( 'payment_link_response', $diagnostic );
					if ( $http_status !== 200 ) {
						throw new RuntimeException( 'JazzCash rejected payment-link creation (HTTP ' . $http_status . ').' );
					}
					$link = is_array( $json ) ? ( $json['Data']['Link'] ?? '' ) : '';
					if ( ( $json['Success'] ?? false ) !== true || ! self::valid_link( $link ) ) {
						throw new RuntimeException( 'JazzCash did not return a valid hosted payment link.' );
					}
					// Preserve the history of successful payment-link requests for callback reconciliation.
					$history = $order->get_meta( '_crj_refs' );
					if ( ! is_array( $history ) ) {
						$history = array();
					}
					$history[] = $ref;
					$order->update_meta_data( '_crj_refs', array_slice( array_values( array_unique( $history ) ), -12 ) );
					$order->update_meta_data( '_crj_ref', $ref );
					$order->update_meta_data( '_crj_amount', $amount );
					$order->update_meta_data( '_crj_merchant', (string) $c['merchant_id'] );
					// Hosted describes the checkout channel, not the actual customer payment instrument.
					$order->update_meta_data( '_crj_instrument', 'unknown' );
					$attempts = $order->get_meta( '_crj_attempts' );
					if ( ! is_array( $attempts ) ) {
						$attempts = array();
					}
					$attempts[] = array(
						'request_ref'  => $ref,
						'provider_ref' => '',
						'type'         => 'unknown',
						'code'         => '',
						'status'       => 'INITIATED',
						'created'      => current_time( 'mysql' ),
					);
					$order->update_meta_data( '_crj_attempts', array_slice( $attempts, -50 ) );
					if ( WC()->session ) {
						WC()->session->set( 'crj_return_order', (int) $order_id );
						$order->update_meta_data( '_crj_session_binding', hash( 'sha256', (string) WC()->session->get_customer_id() ) );}
					$order->update_meta_data( '_crj_environment', 'sandbox' );
					$order->update_meta_data( '_crj_created_at', current_time( 'mysql' ) );
					$order->update_meta_data( '_crj_status', 'initiated' );
					$order->save();
					if ( ! crj_ledger_store( $order, end( $attempts ) ) ) {
						throw new RuntimeException( 'Transaction ledger could not store the payment attempt.' );
					}
					return array(
						'result'   => 'success',
						'redirect' => $link,
					);
				} catch ( Throwable $e ) {
					self::diagnostic(
						'payment_failed',
						array(
							'order_id' => (int) $order_id,
							'method'   => $this->id,
						)
					);
					wc_add_notice( esc_html__( 'JazzCash could not start this payment. Check the store configuration or contact the store before retrying.', 'cardraabta-pay-with-jazzcash' ), 'error' );
					return array( 'result' => 'failure' );
				}
			}
			private static function shape_report( array $payload, string $channel, string $content_type, int $bytes ): void {
				$ref             = $payload['pp_TxnRefNo'] ?? null;
				$keys            = array_slice( array_values( array_filter( array_keys( $payload ), static fn( $k )=>is_string( $k ) && preg_match( '/^[A-Za-z_]{1,40}$/D', $k ) ) ), 0, 35 );
				$reference_shape = is_string( $ref ) ? ( preg_match( '/^CR[1-9][0-9]*X[A-F0-9]{10}$/D', $ref ) ? 'generated' : ( preg_match( '/^[A-Za-z0-9_-]{1,100}$/D', $ref ) ? 'other_alphanumeric' : 'other' ) ) : 'missing';
				wc_get_logger()->info(
					'CRJ callback shape ' . wp_json_encode(
						array(
							'channel'               => $channel,
							'method'                => strtoupper( (string) sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ),
							'content_type'          => substr( $content_type, 0, 100 ),
							'bytes'                 => $bytes,
							'field_names'           => $keys,
							'reference_shape'       => $reference_shape,
							'reference_length'      => is_string( $ref ) ? strlen( $ref ) : 0,
							'hash_present'          => isset( $payload['pp_SecureHash'] ),
							'response_code_present' => isset( $payload['pp_ResponseCode'] ),
							'amount_present'        => isset( $payload['pp_Amount'] ),
							'merchant_present'      => isset( $payload['pp_MerchantID'] ),
							'currency_present'      => isset( $payload['pp_TxnCurrency'] ),
						)
					),
					array( 'source' => 'cardraabta-pay-with-jazzcash-diagnostic' )
				);
			}
			private static function parse_callback( string $raw, string $content_type ): array {
				return crj_parse_callback( $raw, $content_type );}
			private static function callback_diagnostic( string $event, array $info ): void {
				if ( ! function_exists( 'wc_get_logger' ) ) {
					return;
				}
				$allowed = array( 'channel', 'order_id', 'signature_valid', 'reference_matches', 'amount_matches', 'merchant_matches', 'currency_matches', 'bill_reference_matches', 'reference_type', 'provider_reference_reconciled', 'response_code', 'authorization_code_present', 'settlement_date_present', 'result', 'error_code', 'extra_field_count', 'field_count', 'reference_length' );
				$safe    = array();
				foreach ( $allowed as $key ) {
					if ( array_key_exists( $key, $info ) ) {
								$v = $info[ $key ];
						if ( is_bool( $v ) || is_int( $v ) ) {
							$safe[ $key ] = $v;
						} elseif ( is_string( $v ) && preg_match( '/^[A-Za-z0-9_.-]{1,60}$/D', $v ) ) {
							$safe[ $key ] = $v;
						}
					}
				}
				wc_get_logger()->info( 'CRJ callback verification ' . $event . ' ' . wp_json_encode( $safe ), array( 'source' => 'cardraabta-pay-with-jazzcash-diagnostic' ) );
			}
			private static function signed_payload( array $data ): bool {
				$hash = $data['pp_SecureHash'] ?? null;
				if ( ! is_string( $hash ) || ! preg_match( '/^[A-Fa-f0-9]{64}$/D', $hash ) ) {
					return false;
				}
				$c = (array) get_option( 'woocommerce_crj_card_settings', array() );
				if ( empty( $c['integrity_salt'] ) ) {
					return false;
				}
				return hash_equals( self::hash_fields( $data, (string) $c['integrity_salt'] ), strtoupper( $hash ) );
			}
			private static function callback_order( array $data ): ?WC_Order {
				// Prefer signed bill reference, since JazzCash may replace the request reference.
				$bill = $data['pp_BillReference'] ?? '';
				if ( ! is_string( $bill ) || ! preg_match( '/^ORDER-([1-9][0-9]*)$/D', $bill, $m ) ) {
					return null;
				}
				$order = wc_get_order( (int) $m[1] );
				return $order instanceof WC_Order ? $order : null;
			}
			public static function process_callback_data( array $data ): array {
				$report = array(
					'channel'                => 'return',
					'order_id'               => 0,
					'signature_valid'        => false,
					'reference_matches'      => false,
					'amount_matches'         => false,
					'merchant_matches'       => false,
					'currency_matches'       => false,
					'bill_reference_matches' => false,
				);
				if ( count( $data ) > 64 ) {
					throw new RuntimeException( 'Invalid_field_count' );
				}
				self::shape_report( $data, 'return_post', 'validated', strlen( wp_json_encode( $data ) ) );
				$report['field_count'] = count( $data );
				crj_validate_callback_fields( $data );
				// Provider may add response fields; reject malformed keys/arrays, but do not reject legitimate extras.
				foreach ( $data as $k => $v ) {
					if ( ! is_string( $k ) || ! is_string( $v ) || strlen( $k ) > 100 || strlen( $v ) > 8192 ) {
						throw new RuntimeException( 'Malformed_fields' );
					}
				}
				$report['extra_field_count'] = count( array_filter( array_keys( $data ), static fn( $k )=> ! preg_match( '/^pp_[A-Za-z0-9_]+$/D', (string) $k ) ) );
				$report['signature_valid']   = self::signed_payload( $data );
				if ( ! $report['signature_valid'] ) {
					throw new RuntimeException( 'Signature_mismatch' );
				}
				$signed_details                       = crj_signed_provider_details( $data );
				$report['authorization_code_present'] = isset( $signed_details['authorization_code'] );
				$report['settlement_date_present']    = isset( $signed_details['settlement_date'] );
				$order                                = self::callback_order( $data );
				if ( ! $order || ! in_array( $order->get_payment_method(), array( 'crj_card', 'crj_wallet' ), true ) ) {
					throw new RuntimeException( 'Order_not_found' );
				}
				crj_security_lock( 'order:' . $order->get_id() );
				$order = wc_get_order( $order->get_id() );
				if ( (string) $order->get_meta( '_crj_environment' ) !== 'sandbox' ) {
					throw new RuntimeException( 'Environment_mismatch' );
				}
				if ( ! in_array( $order->get_status(), array( 'pending', 'failed', 'on-hold', 'processing', 'completed' ), true ) ) {
					throw new RuntimeException( 'Ineligible_order_state' );
				}
				$report['order_id']               = $order->get_id();
				$ref                              = (string) ( $data['pp_TxnRefNo'] ?? '' );
				$latest                           = (string) $order->get_meta( '_crj_ref' );
				$report['reference_length']       = strlen( $ref );
				$report['bill_reference_matches'] = hash_equals( 'ORDER-' . $order->get_id(), (string) ( $data['pp_BillReference'] ?? '' ) );
				$report['amount_matches']         = hash_equals( (string) $order->get_meta( '_crj_amount' ), (string) ( $data['pp_Amount'] ?? '' ) );
				$report['merchant_matches']       = hash_equals( (string) $order->get_meta( '_crj_merchant' ), (string) ( $data['pp_MerchantID'] ?? '' ) );
				$report['currency_matches']       = ( $data['pp_TxnCurrency'] ?? '' ) === 'PKR' && $order->get_currency() === 'PKR';
				if ( ! hash_equals( crj_minor_amount( $order->get_total() ), (string) ( $data['pp_Amount'] ?? '' ) ) ) {
					throw new RuntimeException( 'Current_order_amount_mismatch' );
				}
				if ( ! $report['bill_reference_matches'] || ! $report['amount_matches'] || ! $report['merchant_matches'] || ! $report['currency_matches'] ) {
					throw new RuntimeException( 'Transaction_details_mismatch' );
				}
				if ( ! preg_match( '/^[A-Za-z0-9_-]{12,64}$/D', $ref ) ) {
					throw new RuntimeException( 'Invalid_provider_reference' );
				}
				$attempts = $order->get_meta( '_crj_attempts' );
				if ( ! is_array( $attempts ) ) {
					$attempts = array();
				}
				// JazzCash sandbox appends a short alphanumeric suffix to the merchant request reference.
				// Match the signed provider ID to one specific attempt, not the order's latest attempt.
				// Do not allow an arbitrary provider ID to be attached using only the bill reference.
				$matched_index = null;
				foreach ( $attempts as $i => $attempt ) {
					if ( ! is_array( $attempt ) ) {
						continue;
					}
					$request = (string) ( $attempt['request_ref'] ?? '' );
					if ( $request === '' || ! preg_match( '/^CR[1-9][0-9]*X[A-F0-9]{10}$/D', $request ) ) {
						continue;
					}
					if ( $ref !== $request && ! ( str_starts_with( $ref, $request ) && preg_match( '/^[A-Za-z0-9]{1,8}$/D', substr( $ref, strlen( $request ) ) ) ) ) {
						continue;
					}
					if ( $matched_index !== null ) {
						throw new RuntimeException( 'Ambiguous_payment_attempt' );
					}
					$matched_index = $i;
				}
				if ( $matched_index === null ) {
					throw new RuntimeException( 'Unmatched_payment_attempt' );
				}
				$original   = (string) $attempts[ $matched_index ]['request_ref'];
				// Fingerprint the authenticated fields only. Unsigned extras and hash casing
				// must not turn a replay into a new financial event or another order note.
				$event_data = array();
				foreach ( $data as $key => $value ) {
					if ( preg_match( '/^pp_[A-Za-z0-9_]+$/D', $key ) && strcasecmp( $key, 'pp_SecureHash' ) !== 0 && trim( $value ) !== '' ) {
						$event_data[ $key ] = $value;
					}
				}
				ksort( $event_data, SORT_STRING );
				$event_hash = hash( 'sha256', wp_json_encode( $event_data ) );
				if ( ( $attempts[ $matched_index ]['event_hash'] ?? '' ) === $event_hash && ( $attempts[ $matched_index ]['status'] ?? '' ) !== 'PROCESSING' ) {
					$report['result'] = 'duplicate_ignored';
					return array(
						'order'    => $order,
						'report'   => $report,
						'can_view' => crj_can_view_return( $order ),
					);}
				$report['reference_matches'] = hash_equals( $original, $ref );
				$report['reference_type']    = $report['reference_matches'] ? 'original' : 'provider_assigned';
				$existing_attempt_ref        = (string) ( $attempts[ $matched_index ]['provider_ref'] ?? '' );
				if ( $existing_attempt_ref !== '' && ! hash_equals( $existing_attempt_ref, $ref ) ) {
					throw new RuntimeException( 'Different_provider_reference_on_attempt' );
				}
				// An ID cannot be reused across orders, nor on another attempt of this order.
			   // phpcs:ignore WordPress.DB.SlowDBQuery -- Bounded two-record compatibility check for older orders not yet imported into the indexed ledger.
				$previous = wc_get_orders(
					array(
						'limit'      => 2,
						'return'     => 'ids',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded legacy order collision lookup until ledger migration finishes.
						'meta_key'   => '_crj_provider_ref',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Legacy compatibility collision check, limited to two records.
						'meta_value' => $ref,
					)
				);
				foreach ( $previous as $prior_id ) {
					if ( (int) $prior_id !== $order->get_id() ) {
						throw new RuntimeException( 'Provider_reference_reused' );
					}
				}
				foreach ( $attempts as $i => $attempt ) {
					if ( $i !== $matched_index && is_array( $attempt ) && (string) ( $attempt['provider_ref'] ?? '' ) === $ref ) {
						throw new RuntimeException( 'Provider_reference_reused_on_attempt' );
					}
				}
				global $wpdb;
				crj_security_lock( 'provider-ledger' );
				$ledger_table = crj_ledger_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private durable ledger has no WordPress object API; authoritative current state is required for payment reconciliation.
				$ledger_attempt = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id,amount,currency FROM %i WHERE request_ref=%s', $ledger_table, $original ) );
				if ( ! $ledger_attempt || (int) $ledger_attempt->order_id !== $order->get_id() || $ledger_attempt->currency !== 'PKR' || ! hash_equals( crj_minor_amount( $ledger_attempt->amount ), (string) $data['pp_Amount'] ) ) {
					throw new RuntimeException( 'Ledger_attempt_mismatch' );
				}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private durable ledger has no WordPress object API; authoritative current state is required for payment reconciliation.
				$ledger_owner = $wpdb->get_var( $wpdb->prepare( 'SELECT request_ref FROM %i WHERE provider_ref=%s LIMIT 1', $ledger_table, $ref ) );
				if ( $ledger_owner !== null && ! hash_equals( (string) $ledger_owner, $original ) ) {
					throw new RuntimeException( 'Provider_reference_reused_in_ledger' );
				}
				// Once paid, never let a late failed callback overwrite the successful payment.
				$code = (string) ( $data['pp_ResponseCode'] ?? '' );
				if ( ! preg_match( '/^[0-9]{3}$/D', $code ) ) {
					throw new RuntimeException( 'Invalid_response_code' );
				}
				if ( $order->is_paid() && ( ! in_array( $code, array( '000', '121' ), true ) || ! hash_equals( (string) $order->get_transaction_id(), $ref ) ) ) {
					throw new RuntimeException( 'Order_already_paid' );
				}
				$report['provider_reference_reconciled'] = true;
				// Capture only signed provider-supplied payment type. Never infer a payment
				// method from the gateway ID or from the hosted payment-link request.
				$provider_txn_type = isset( $data['pp_TxnType'] ) ? strtoupper( trim( (string) $data['pp_TxnType'] ) ) : '';
				$reported_type     = 'unknown';
				$type_candidates   = array();
				foreach ( array( 'pp_TxnType', 'pp_PaymentMethod', 'pp_PaymentType', 'pp_Instrument', 'pp_PaymentMode' ) as $type_key ) {
					if ( isset( $data[ $type_key ] ) ) {
						$type_candidates[] = strtoupper( trim( (string) $data[ $type_key ] ) );
					}
				}
				foreach ( $type_candidates as $v ) {
					if ( in_array( $v, array( 'MWALLET', 'WALLET', 'MOBILE WALLET', 'MOBILE_WALLET', 'MW' ), true ) ) {
						$reported_type = 'wallet';
					} elseif ( in_array( $v, array( 'MPAY', 'CARD', 'CREDIT CARD', 'DEBIT CARD', 'CREDIT/DEBIT CARD', 'CREDIT / DEBIT CARD' ), true ) ) {
						$reported_type = 'card';
					} elseif ( $v === 'VOUCHER' ) {
						$reported_type = 'voucher';
					} elseif ( in_array( $v, array( 'RAAST', 'RAASTQR', 'RAAST QR' ), true ) ) {
						$reported_type = 'raastqr';
					}
					if ( $reported_type !== 'unknown' ) {
						break;
					}
				}
				$attempt                      =&$attempts[ $matched_index ];
				$attempt['event_hash']        = $event_hash;
				$attempt['provider_ref']      = $ref;
				$attempt['type']              = $reported_type;
				$attempt['provider_txn_type'] = $provider_txn_type;
				$attempt['code']              = $code;
				$attempt['status']            = in_array( $code, array( '000', '121' ), true ) ? ( $order->is_paid() ? 'COMPLETED' : 'PROCESSING' ) : ( in_array( $code, array( '124', '157', '210' ), true ) ? 'PENDING' : 'NOT COMPLETED' );
				unset( $attempt );
				if ( ! crj_ledger_store( $order, $attempts[ $matched_index ] ) ) {
					throw new RuntimeException( 'Transaction_ledger_write_failed' );
				}
				$order->update_meta_data( '_crj_attempts', array_slice( $attempts, -50 ) );
				if ( ! $order->is_paid() ) {
						$order->update_meta_data( '_crj_instrument', $reported_type );
						$order->update_meta_data( '_crj_provider_txn_type', $provider_txn_type );
				}
				$order->save();
				$code                    = (string) ( $data['pp_ResponseCode'] ?? '' );
				$report['response_code'] = preg_match( '/^[0-9]{3}$/D', $code ) ? $code : 'unavailable';
				// Store only allowlisted non-secret fields from the VERIFIED signed return.
				// Never store pp_Password, pp_SecureHash, customer card or tokenization data.
				$provider_details = crj_signed_provider_details( $data, is_array( $attempts[ $matched_index ]['provider_details'] ?? null ) ? $attempts[ $matched_index ]['provider_details'] : array() );
				$attempts         = $order->get_meta( '_crj_attempts' );
				if ( ! is_array( $attempts ) ) {
					$attempts = array();
				}
				foreach ( $attempts as &$attempt ) {
					if ( ! is_array( $attempt ) || ( $attempt['request_ref'] ?? '' ) !== $original || ( $attempt['provider_ref'] ?? '' ) !== $ref ) {
						continue;
					}
					$attempt['provider_details'] = $provider_details;
					break;
				}
				unset( $attempt );
				$order->update_meta_data( '_crj_attempts', $attempts );
				if ( ! $order->is_paid() ) {
					$order->update_meta_data( '_crj_provider_details', $provider_details );
				}
				// Explicitly persist the JazzCash-assigned ID independently of request ref.
				if ( ! $order->is_paid() ) {
					$order->update_meta_data( '_crj_provider_ref', $ref );
				}
				$order->save();
				if ( ! crj_ledger_store( $order, $attempts[ $matched_index ] ) ) {
					throw new RuntimeException( 'Transaction_ledger_write_failed' );
				}
				// A confirmed successful JazzCash return carries pp_ResponseCode 000 or 121.
				// A failure or pending response must not complete the order.
				if ( in_array( $code, array( '000', '121' ), true ) ) {
					if ( ! $order->is_paid() ) {
						$order->update_meta_data( '_crj_provider_ref', $ref );
						$order->update_meta_data( '_crj_status', 'processing' );
						$order->update_meta_data( '_crj_response_code', $code );
						$order->save();
						$order->payment_complete( $ref );
						$order = wc_get_order( $order->get_id() );
						if ( ! $order->is_paid() || ! hash_equals( (string) $order->get_transaction_id(), $ref ) ) {
							throw new RuntimeException( 'Completion_requires_reconciliation' );
						}
						$order->update_meta_data( '_crj_status', 'completed' );
						$order->save();
						$attempts[ $matched_index ]['status'] = 'COMPLETED';
						$order->update_meta_data( '_crj_attempts', $attempts );
						$order->save();
						if ( ! crj_ledger_store( $order, $attempts[ $matched_index ] ) ) {
							throw new RuntimeException( 'Completion_ledger_requires_reconciliation' );
						}
						$order->add_order_note( 'CardRaabta sandbox: JazzCash signed successful return verified (bill reference, amount, merchant, currency and provider transaction ID).' );
						$report['result'] = 'payment_completed';
					} else {
						$report['result'] = 'already_paid';
					}
				} else {
					$report['result'] = 'non_success_payment_not_completed';
					$order->update_meta_data( '_crj_status', 'not_completed' );
					$order->update_meta_data( '_crj_response_code', $code );
					$order->save();
					$order->add_order_note( 'CardRaabta sandbox: signed JazzCash return received, but payment status was not successful.' );
				}
				return array(
					'order'    => $order,
					'report'   => $report,
					'can_view' => crj_can_view_return( $order ),
				);
			}
			public static function handle_return_request( string $raw, string $content_type, string $method ): array {
				if ( strtoupper( $method ) !== 'POST' ) {
					throw new RuntimeException( 'POST_required' );
				}
				return self::process_callback_data( self::parse_callback( $raw, $content_type ) );
			}
			public static function callback(): void {
				crj_no_cache_response();
				// A cross-site POST can omit Lax cookies. Restore them with a signed GET
				// continuation before authorizing disclosure of WooCommerce's private order key.
				$request_method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) );
				if ( $request_method === 'GET' ) {
					nocache_headers();
					header( 'Referrer-Policy: no-referrer' );
					header( 'X-Content-Type-Options: nosniff' );
				 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- HMAC ticket plus independent session/customer authorization; read-only redirect.
					$destination = crj_return_destination( wp_unslash( $_GET ) );
					if ( $destination !== '' ) {
						wp_safe_redirect( $destination, 303 );
						exit;}
					status_header( 200 );
					header( 'Content-Type: text/plain; charset=utf-8' );
					echo 'Payment response received. Open your original order link or sign in to view its status.';
					exit;
				}
				try {
					$raw          = (string) file_get_contents( 'php://input', false, null, 0, 32769 );
					$method       = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) );
					$content_type = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ?? '' ) );
					$result       = self::handle_return_request( $raw, $content_type, $method );
					self::callback_diagnostic( 'return', $result['report'] );
					nocache_headers();
					header( 'Referrer-Policy: no-referrer' );
					if ( $result['can_view'] ) {
						wp_safe_redirect( $result['order']->get_checkout_order_received_url(), 303 );
						exit;}
					wp_safe_redirect( crj_return_continuation_url( $result['order'] ), 303 );
					exit;
				} catch ( Throwable $error ) {
					self::callback_diagnostic( 'return_rejected', array( 'error_code' => preg_replace( '/[^A-Za-z0-9_.-]/', '_', substr( $error->getMessage(), 0, 50 ) ) ) );
					$busy = $error->getMessage() === 'Payment_request_busy';
					status_header( $busy ? 503 : 400 );
					header( 'Cache-Control: no-store' );
					if ( $busy ) {
						header( 'Retry-After: 3' );
					}
					echo 'Payment response could not be verified. Contact the store before retrying.';
					exit;
				}
			}
		}
		add_filter(
			'woocommerce_payment_gateways',
			function ( $methods ) {
				$methods[] = 'CRJ_Orchestrator_Gateway';
				return $methods;
			}
		);
		// Registered merchant return route: handle only CardRaabta orders; no silent success on an unverified response.
		add_action( 'woocommerce_api_result', array( 'CRJ_Orchestrator_Gateway', 'callback' ) );
		add_action( 'woocommerce_api_crj_orchestrator_return', array( 'CRJ_Orchestrator_Gateway', 'callback' ) );
		// Merchant IPN V1 REST endpoint. Accepts JSON/form requests; fail-closed
		// until the actual provider notification contract is confirmed.
		add_action(
			'init',
			function () {
				add_rewrite_rule( '^jazzcash_ipn_v2/?$', 'index.php?crj_jazzcash_ipn=1', 'top' );
			}
		);
		add_filter(
			'query_vars',
			function ( $vars ) {
				$vars[] = 'crj_jazzcash_ipn';
				return $vars;
			}
		);
		add_action(
			'template_redirect',
			function () {
				if ( ! get_query_var( 'crj_jazzcash_ipn' ) ) {
					return;
				}
				crj_no_cache_response();
				if ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) !== 'POST' ) {
					status_header( 405 );
					header( 'Allow: POST' );
					exit;
				}
				$raw = file_get_contents( 'php://input', false, null, 0, 32769 );
				if ( ! is_string( $raw ) || strlen( $raw ) > 32768 ) {
					status_header( 413 );
					exit;}
				$content_type = strtolower( (string) sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ?? '' ) ) );
				$is_json      = str_contains( $content_type, 'application/json' );
				$is_form      = str_contains( $content_type, 'application/x-www-form-urlencoded' );
				if ( ! $is_json && ! $is_form ) {
					status_header( 415 );
						exit;}
				$payload = array();
				try {
					$payload = crj_parse_callback( $raw, $content_type );
				} catch ( Throwable $error ) {
					status_header( 400 );
					exit;}
				if ( ! is_array( $payload ) || ! $payload ) {
					status_header( 400 );
						exit;}
				// Sandbox verification only: REST IPN V1 does not contain amount/currency
				// in observed notifications, so never settle orders from IPN alone.
				$safe_keys         = array_slice(
					array_values(
						array_filter(
							array_keys( $payload ),
							static fn( $k )=>is_string( $k ) && preg_match( '/^[A-Za-z_]{1,40}$/D', $k )
						)
					),
					0,
					25
				);
				$result            = 'malformed';
				$order_id          = 0;
				$signature_valid   = false;
				$reference_matches = false;
				$response_code     = 'unavailable';
				$status            = 400;
				try {
					foreach ( $payload as $k => $v ) {
						if ( ! is_string( $k ) || ! preg_match( '/^pp_[A-Za-z0-9_]+$/D', $k ) || ! is_scalar( $v ) ) {
							throw new RuntimeException( 'Malformed IPN fields' );
						}
					}
					// Safe field-shape diagnostics only; class-private helper is not callable from this route.
					wc_get_logger()->info(
						'CRJ IPN shape ' . wp_json_encode(
							array(
								'field_count' => count( $payload ),
								'bytes'       => strlen( $raw ),
							)
						),
						array( 'source' => 'cardraabta-pay-with-jazzcash-diagnostic' )
					);
					$salt     = (string) ( ( (array) get_option( 'woocommerce_crj_card_settings', array() ) )['integrity_salt'] ?? '' );
					$received = $payload['pp_SecureHash'] ?? null;
					if ( ! is_string( $received ) || ! preg_match( '/^[A-Fa-f0-9]{64}$/D', $received ) || $salt === '' ) {
						throw new RuntimeException( 'IPN signature missing' );
					}
					$signed = array();
					foreach ( $payload as $k => $v ) {
						if ( preg_match( '/^pp_[A-Za-z0-9_]+$/D', (string) $k ) && strcasecmp( (string) $k, 'pp_SecureHash' ) !== 0 && is_scalar( $v ) && trim( (string) $v ) !== '' ) {
							$signed[ $k ] = (string) $v;
						}
					}
					ksort( $signed, SORT_STRING );
					$signature_valid = hash_equals( strtoupper( hash_hmac( 'sha256', $salt . '&' . implode( '&', $signed ), $salt ) ), strtoupper( $received ) );
					if ( ! $signature_valid ) {
						throw new RuntimeException( 'IPN signature mismatch' );
					}
					$ref = (string) ( $payload['pp_TxnRefNo'] ?? '' );
					if ( preg_match( '/^CR([1-9][0-9]*)X[A-F0-9]{10}$/D', $ref, $m ) ) {
						$order = wc_get_order( (int) $m[1] );
						if ( $order && in_array( $order->get_payment_method(), array( 'crj_card', 'crj_wallet' ), true ) ) {
							$order_id          = $order->get_id();
							$reference_matches = hash_equals( (string) $order->get_meta( '_crj_ref' ), $ref );
						}
					}
					$code          = (string) ( $payload['pp_ResponseCode'] ?? '' );
					$response_code = preg_match( '/^[0-9]{3}$/D', $code ) ? $code : 'unavailable';
					$result        = $reference_matches ? 'signed_original_ref_pending_amount_confirmation' : 'signed_provider_ref_pending_reconciliation';
					$status        = 200;
				} catch ( Throwable $e ) {
					$result = 'verification_failed';
				}
				wc_get_logger()->info(
					'CRJ REST IPN V1 verification ' . wp_json_encode(
						array(
							'content_type'      => $is_json ? 'json' : 'form',
							'bytes'             => strlen( $raw ),
							'field_names'       => $safe_keys,
							'order_id'          => $order_id,
							'reference_matches' => $reference_matches,
							'signature_valid'   => $signature_valid,
							'response_code'     => $response_code,
							'result'            => $result,
						)
					),
					array( 'source' => 'cardraabta-pay-with-jazzcash-diagnostic' )
				);
				status_header( $status );
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Cache-Control: no-store' );
				echo wp_json_encode(
					array(
						'success' => $status === 200,
						'message' => $status === 200 ? 'Notification authenticated; payment confirmation pending' : 'Notification verification failed',
					)
				);
				exit;
			}
		);
		add_action(
			'admin_menu',
			function () {
				add_submenu_page( 'woocommerce', 'CardRaabta Transactions', 'CardRaabta Transactions', 'manage_woocommerce', 'crj-transactions', 'crj_render_transactions' );
			}
		);
		// Export from the durable ledger, including transactions whose orders were deleted.
		// Restrict access to WooCommerce managers and require a dedicated CSRF nonce.
		add_action(
			'admin_post_crj_export_transactions',
			function () {
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( esc_html__( 'You are not allowed to export transactions.', 'cardraabta-pay-with-jazzcash' ), 403 );
				}
				check_admin_referer( 'crj_export_transactions' );
				global $wpdb;
				$table  = crj_ledger_table();
				$search = isset( $_GET['crj_search'] ) ? sanitize_text_field( wp_unslash( $_GET['crj_search'] ) ) : '';
				$where  = '';
				$args   = array();
				if ( $search !== '' ) {
					$like  = '%' . $wpdb->esc_like( $search ) . '%';
					$where = ' WHERE request_ref LIKE %s OR provider_ref LIKE %s OR CONCAT(\'ORDER-\',order_id) LIKE %s';
					$args  = array( $like, $like, $like );
				}
				if ( headers_sent() ) {
					wp_die( 'Cannot send CSV headers.' );
				}
				nocache_headers();
				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . crj_transaction_csv_filename() . '"' );
				header( 'X-Content-Type-Options: nosniff' );
				$out = fopen( 'php://output', 'w' );
				if ( ! $out ) {
					exit;
				}
				// UTF-8 BOM improves compatibility with Microsoft Excel.
         // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write the UTF-8 BOM to php://output, not a filesystem file.
				fwrite( $out, "\xEF\xBB\xBF" );
				fputcsv( $out, array_values( crj_transaction_csv_columns() ), ',', '"', '' );
				$last = PHP_INT_MAX;
				do {
						$records = crj_ledger_export_page( $search, $last );
					if ( ! is_array( $records ) || ! $records ) {
						break;
					}
					foreach ( $records as $record ) {
						$last  = (int) $record['id'];
						$cells = crj_transaction_csv_cells( $record );
						fputcsv( $out, $cells, ',', '"', '' );
					}
					if ( function_exists( 'flush' ) ) {
						flush();
					}
				} while ( count( $records ) === 500 );
         // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV HTTP response stream.
				fclose( $out );
				exit;
			}
		);
		function crj_render_transactions() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Not permitted' );
			}
		 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only manager table search; export has a separate nonce.
			$query = isset( $_GET['crj_search'] ) ? sanitize_text_field( wp_unslash( $_GET['crj_search'] ) ) : '';
			global $wpdb;
			$table = crj_ledger_table();
			$rows  = array();
			// Database is the source of truth; orders are optional links only.
			if ( $query !== '' ) {
				$like = '%' . $wpdb->esc_like( $query ) . '%';
	   // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private durable ledger has no WordPress object API; authoritative current state is required for payment reconciliation.
				$records = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE request_ref LIKE %s OR provider_ref LIKE %s OR CONCAT('ORDER-',order_id) LIKE %s ORDER BY created_at DESC,id DESC LIMIT 250", $table, $like, $like, $like ), ARRAY_A );
	   // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private durable ledger has no WordPress object API; authoritative current state is required for payment reconciliation.
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private ledger has no WordPress object API; bounded manager view requires current records.
				$records = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC,id DESC LIMIT 250', $table ), ARRAY_A );
			}
			foreach ( (array) $records as $a ) {
				$order   = wc_get_order( (int) $a['order_id'] );
				$details = crj_transaction_details( $a, $order instanceof WC_Order ? $order : null );
				$rows[]  = array(
					'order'             => $order instanceof WC_Order ? $order : null,
					'id'                => (int) $a['order_id'],
					'provider'          => (string) ( $a['provider_ref'] ?? '' ),
					'request'           => (string) $a['request_ref'],
					'type'              => (string) $a['instrument'],
					'provider_txn_type' => (string) $a['provider_txn_type'],
					'code'              => (string) $a['response_code'],
					'status'            => (string) $a['txn_status'],
					'created'           => (string) $a['created_at'],
					'provider_details'  => is_array( $details ) ? $details : array(),
					'amount'            => (string) $a['amount'],
					'currency'          => (string) $a['currency'],
				);
			}
			usort( $rows, static fn( $a, $b )=>strcmp( $b['created'], $a['created'] ) );
			echo '<div class="wrap crj-history">';
			echo '<div class="crj-banner"><span class="crj-mark">CR</span><div><h1>CardRaabta <span style="color:#b5480b">JazzCash</span> Transaction History</h1><p>CardRaabta payments only · Independent transaction ledger (preserved after order deletion)</p></div></div>';
			$export_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'     => 'crj_export_transactions',
						'crj_search' => $query,
					),
					admin_url( 'admin-post.php' )
				),
				'crj_export_transactions'
			);
			echo '<div class="crj-panel"><div class="crj-toolbar"><h2>📊 Transaction History</h2><div class="crj-toolbar-actions"><form method="get" class="crj-search"><input type="hidden" name="page" value="crj-transactions"><input type="search" name="crj_search" aria-label="Search transaction ID or order number" value="' . esc_attr( $query ) . '" placeholder="Search transaction ID or order number"><button class="crj-btn">Search</button><a class="crj-btn secondary" href="' . esc_url( admin_url( 'admin.php?page=crj-transactions' ) ) . '">Clear</a></form><a class="crj-btn crj-export" href="' . esc_url( $export_url ) . '">Export CSV</a></div><p class="crj-muted">' . count( $rows ) . ' matching transaction attempts · Payment method is shown only when confirmed by provider data · CSV includes all matching transactions</p></div>';
			echo '<div class="crj-table-wrap" role="region" aria-label="CardRaabta transactions" tabindex="0"><table><thead><tr><th>Transaction ID</th><th>Order Reference</th><th>TXN Type</th><th>Amount</th><th>Response Code</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
			foreach ( array_slice( $rows, 0, 250 ) as $row_index => $r ) {
				$order         = $r['order'];
				$id            = $r['id'];
				$type          = strtolower( $r['type'] );
				$provider_type = strtoupper( trim( $r['provider_txn_type'] ) );
				$label         = array(
					'wallet'  => 'MWALLET',
					'card'    => 'MPAY',
					'voucher' => 'VOUCHER',
					'raastqr' => 'RAAST QR',
				)[ $type ] ?? 'UNCONFIRMED';
				// Older records may already contain the exact signed provider code.
				if ( $label === 'UNCONFIRMED' && in_array( $provider_type, array( 'MWALLET', 'MPAY', 'VOUCHER', 'RAASTQR' ), true ) ) {
					$label = $provider_type;
				}
				$status = strtoupper( $r['status'] );
				if ( $status === 'INITIATED' && $r['provider'] === '' ) {
					$status = 'AWAITING CONFIRMATION';
				}$ok          = $status === 'COMPLETED';
				$pending      = in_array( $status, array( 'INITIATED', 'AWAITING CONFIRMATION', 'PENDING', 'PROCESSING', 'ON-HOLD', 'UNKNOWN' ), true );
				$status_class = $ok ? 'ok' : ( $pending ? 'wait' : 'bad' );
				$code         = $r['code'];
				$display      = $r['provider'] !== '' ? $r['provider'] : 'Awaiting JazzCash Transaction ID';
				echo '<tr><td><span class="crj-ref" title="' . esc_attr( $r['provider'] !== '' ? 'Provider transaction ID' : 'Request reference (provider ID not yet received)' ) . '">' . esc_html( $display ) . '</span>' . ( $r['request'] !== '' ? '<div class="crj-muted">Merchant ref: ' . esc_html( $r['request'] ) . '</div>' : '' ) . ( $r['provider'] === '' ? '<div class="crj-muted">Provider ID not yet received</div>' : '' ) . '</td>';
				echo '<td>' . ( $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">ORDER-' . esc_html( (string) $id ) . '</a>' : 'ORDER-' . esc_html( (string) $id ) . ' <span class="crj-muted">(Order Deleted)</span>' ) . '</td><td><span class="crj-pill type">' . esc_html( $label ) . '</span>' . ( $provider_type !== '' ? '<div class="crj-muted">Provider: ' . esc_html( $provider_type ) . '</div>' : '' ) . '</td>';
				echo '<td>' . wp_kses_post( wc_price( $r['amount'], array( 'currency' => $r['currency'] ) ) ) . '</td><td><span class="crj-pill ' . ( $code === '000' ? 'codeok' : 'codeno' ) . '">' . esc_html( $code ?: '—' ) . '</span></td>';
				echo '<td><span class="crj-pill ' . esc_attr( $status_class ) . '">' . esc_html( $status ) . '</span></td><td>' . esc_html( $r['created'] ?: '—' ) . '</td><td>';
				$details = $r['provider_details'];
				if ( ! $details && $order && $r['provider'] !== '' && (string) $order->get_meta( '_crj_provider_ref' ) === $r['provider'] ) {
					$stored = $order->get_meta( '_crj_provider_details' );
					if ( is_array( $stored ) ) {
						$details = $stored;
					}
				}
				$detail_html = '';
				if ( $details ) {
					$labels      = crj_provider_detail_labels();
					$detail_html = '<div class="crj-detail-grid">';
					foreach ( $labels as $key => $label ) {
						$detail_html .= '<div class="crj-detail-item"><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( isset( $details[ $key ] ) && $details[ $key ] !== '' ? (string) $details[ $key ] : 'Not available in verified response' ) . '</span></div>';
					}
					$detail_html .= '<div class="crj-detail-item"><strong>Merchant Reference</strong><span>' . esc_html( $r['request'] ) . '</span></div></div>';
					echo '<button type="button" class="button crj-view-details" aria-expanded="false" aria-controls="crj-detail-' . esc_attr( (string) $row_index ) . '">View Details</button><br>';
				}
				if ( $ok ) {
					echo '<button type="button" class="button" disabled title="Refund requires verified JazzCash Orchestrator API">Refund (API pending)</button>';
				} else {
					echo '—';
				}
				echo '</td></tr>';
				if ( $detail_html !== '' ) {
					echo '<tr id="crj-detail-' . esc_attr( (string) $row_index ) . '" class="crj-detail-row" hidden><td colspan="8">' . wp_kses_post( $detail_html ) . '</td></tr>';
				}
			}
			if ( ! $rows ) {
				echo '<tr><td colspan="8">No CardRaabta transactions found in the independent ledger.</td></tr>';
			}
			echo '</tbody></table></div><div class="crj-foot">Only CardRaabta-generated attempts are listed. Historical order import runs in the background. Deleted orders retain their transaction history. Refund is disabled until a verified provider API is available; no funds are refunded by changing an order status.</div></div></div>';
		}
	}
);

add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		class CRJ_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
			protected $name = 'crj_card';
			public function __construct( string $id = 'crj_card' ) {
				$this->name = $id;}
			public function initialize() {
				$this->settings = (array) get_option( 'woocommerce_' . $this->name . '_settings', array() );}
			public function is_active() {
				$g = WC()->payment_gateways()->payment_gateways()[ $this->name ] ?? null;
				return $g && $g->is_available(); }
			public function get_payment_method_script_handles() {
				wp_register_script( 'crj-sandbox-blocks', plugins_url( 'blocks.js', __FILE__ ), array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), '1.0.0', true );
				return array( 'crj-sandbox-blocks' );
			}
			public function get_payment_method_data() {
				$g = WC()->payment_gateways()->payment_gateways()[ $this->name ] ?? null;
				return array(
					'title'       => $g ? $g->get_title() : $this->name,
					'description' => $g ? $g->get_description() : '',
					'supports'    => array( 'products' ),
				);
			}
		}
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				$registry->register( new CRJ_Blocks( 'crj_card' ) );
			}
		);
	}
);

register_activation_hook(
	__FILE__,
	function () {
		crj_ledger_install();
		add_rewrite_rule( '^jazzcash_ipn_v2/?$', 'index.php?crj_jazzcash_ipn=1', 'top' );
		flush_rewrite_rules();
	}
);
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
