<?php
/**
 * Plugin Name: CardRaabta – Pay with JazzCash for WooCommerce
 * Plugin URI: https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash
 * Description: JazzCash hosted payments for WooCommerce with signed payment verification.
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
require_once plugin_dir_path( __FILE__ ) . 'includes/provider.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/order-tools.php';

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		$cardraabta_links = array();
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$cardraabta_links['transactions'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=crj-transactions' ) ),
				esc_html__( 'Transaction History', 'cardraabta-pay-with-jazzcash' )
			);
		}
		$cardraabta_links['documentation'] = sprintf(
			'<a class="crj-plugin-documentation" href="%s">%s</a>',
			esc_url( 'https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash/blob/main/docs/SETUP.md' ),
			esc_html__( 'Documentation', 'cardraabta-pay-with-jazzcash' )
		);
		if ( isset( $links['deactivate'] ) ) {
			$deactivate = $links['deactivate'];
			unset( $links['deactivate'] );
			$links['deactivate'] = $deactivate;
		}
		return $cardraabta_links + $links;
	}
);
add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( 'plugins.php' === $hook ) {
			wp_add_inline_style( 'common', '.row-actions a.crj-plugin-documentation { color: #166534; font-weight: 600; } .row-actions a.crj-plugin-documentation:hover, .row-actions a.crj-plugin-documentation:focus { color: #14532d; text-decoration: underline; }' );
		}
		if ( $hook === 'woocommerce_page_crj-transactions' ) {
			wp_enqueue_style( 'crj-admin', CRJ_ORCH_URL . 'assets/admin.css', array(), CRJ_ORCH_VERSION . '.' . filemtime( plugin_dir_path( __FILE__ ) . 'assets/admin.css' ) );
			wp_enqueue_script( 'crj-refund', CRJ_ORCH_URL . 'assets/refund.js', array(), CRJ_ORCH_VERSION . '.' . filemtime( plugin_dir_path( __FILE__ ) . 'assets/refund.js' ), true );
		}}
);
add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			wp_enqueue_style( 'crj-checkout', CRJ_ORCH_URL . 'assets/checkout.css', array(), (string) filemtime( plugin_dir_path( __FILE__ ) . 'assets/checkout.css' ) );
		}
	}
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
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		class CRJ_Orchestrator_Gateway extends WC_Payment_Gateway {
			private const HOSTED_LINK_URL = 'https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v1/transactions/link';
			public function __construct() {
				$this->id                 = 'crj_card'; // Stable gateway identifier used by settings, orders and signed callbacks.
				$this->method_title       = 'Pay With JazzCash';
				$this->method_description = 'JazzCash hosted payments for WooCommerce by CardRaabta.';
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
						'default'     => 'live',
						'description' => 'Select Live with approved JazzCash production credentials for hosted payment links.',
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
						'default' => '1.1',
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
				echo '<div class="crj-payment-choice">';
				echo '<img class="crj-checkout-logo" src="' . esc_url( CRJ_ORCH_URL . 'assets/jazzcash.png' ) . '" alt="JazzCash" width="130" height="38">';
				echo '<strong class="crj-checkout-heading">' . esc_html__( 'Secure checkout with JazzCash', 'cardraabta-pay-with-jazzcash' ) . '</strong>';
				echo '<ul class="crj-checkout-methods">';
				foreach ( crj_checkout_methods() as $method ) {
					echo '<li><span class="crj-checkout-method-icon" aria-hidden="true">' . esc_html( $method['icon'] ) . '</span>' . esc_html( $method['label'] ) . '</li>';
				}
				echo '</ul></div>';
			}
			private static function credential_prefix( string $environment ): string {
				return $environment === 'live' ? 'live_' : '';
			}
			private function credentials(): array {
				$settings    = (array) get_option( 'woocommerce_crj_card_settings', array() );
				$environment = (string) $this->get_option( 'environment', 'live' );
				$prefix      = self::credential_prefix( $environment );
				return array(
					'environment'       => $environment,
					'merchant_id'       => $settings[ $prefix . 'merchant_id' ] ?? '',
					'merchant_password' => $settings[ $prefix . 'merchant_password' ] ?? '',
					'integrity_salt'    => $settings[ $prefix . 'integrity_salt' ] ?? '',
					'api_version'       => $this->get_option( 'api_version', '1.1' ),
				);
			}
			public function is_available() {
				$c = $this->credentials();
				return parent::is_available() && in_array( $c['environment'], array( 'sandbox', 'live' ), true )
					&& is_ssl() && get_woocommerce_currency() === 'PKR'
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
				// JazzCash can return a result-page URL instead of a payable checkout link.
				if ( stripos( $decoded, '/payment-frontend/result/' ) !== false || preg_match( '/[\x00-\x20\x7F]/', $decoded ) ) {
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
						throw new RuntimeException( 'JazzCash credentials or PKR/HTTPS configuration is missing.' );
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
						'pp_Version'           => $c['api_version'],
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
						self::HOSTED_LINK_URL,
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
					$data = $json['Data'] ?? null;
					if ( ( $json['Success'] ?? false ) !== true || ! is_array( $data ) ) {
						throw new RuntimeException( 'JazzCash did not return a successful checkout response.' );
					}
					$link = $data['Link'] ?? '';
					$initial_return = array();
					if ( $link === '' ) {
						foreach ( $data as $key => $value ) {
							if ( is_string( $key ) && str_starts_with( $key, 'pp_' ) && is_scalar( $value ) ) {
								$initial_return[ $key ] = (string) $value;
							}
						}
						$initial_code = $initial_return['pp_ResponseCode'] ?? '';
						$initial_message = $initial_return['pp_ResponseMessage'] ?? '';
						if ( ( ! crj_payment_succeeded( $initial_code ) && ! crj_payment_awaits_confirmation( $initial_code, $initial_message ) && ! crj_payment_declined( $initial_code, $initial_message ) )
							|| ! self::signed_payload( $initial_return, $c['environment'] )
							|| ( $initial_return['pp_BillReference'] ?? '' ) !== $fields['pp_BillReference'] ) {
							throw new RuntimeException( 'JazzCash did not return a verified payment result.' );
						}
					} elseif ( ! self::valid_link( $link ) ) {
						throw new RuntimeException( 'JazzCash did not return a valid hosted payment link.' );
					}
					// Register the attempt before reconciling a direct signed result or sending the customer away.
					$history = $order->get_meta( '_crj_refs' );
					if ( ! is_array( $history ) ) {
						$history = array();
					}
					$history[] = $ref;
					$order->update_meta_data( '_crj_refs', array_slice( array_values( array_unique( $history ) ), -12 ) );
					$order->update_meta_data( '_crj_ref', $ref );
					$order->update_meta_data( '_crj_amount', $amount );
					// Hosted checkout may offer several payment instruments.
					$order->update_meta_data( '_crj_instrument', 'unknown' );
					$attempts = $order->get_meta( '_crj_attempts' );
					if ( ! is_array( $attempts ) ) {
						$attempts = array();
					}
					foreach ( $attempts as &$attempt ) {
						if ( is_array( $attempt ) && ! isset( $attempt['environment'] ) ) {
							$attempt['environment'] = (string) $order->get_meta( '_crj_environment' );
							$attempt['merchant_id'] = (string) $order->get_meta( '_crj_merchant' );
						}
					}
					unset( $attempt );
					$order->update_meta_data( '_crj_merchant', (string) $c['merchant_id'] );
					$attempts[] = array(
						'request_ref'  => $ref,
						'environment'  => $c['environment'],
						'merchant_id'  => (string) $c['merchant_id'],
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
					$order->update_meta_data( '_crj_environment', $c['environment'] );
					$order->update_meta_data( '_crj_created_at', current_time( 'mysql' ) );
					$order->update_meta_data( '_crj_status', 'initiated' );
					$order->save();
					if ( ! crj_ledger_store( $order, end( $attempts ) ) ) {
						throw new RuntimeException( 'Transaction ledger could not store the payment attempt.' );
					}
					if ( $initial_return ) {
						$result = self::process_callback_data( $initial_return );
						$return_order = $result['order'];
						$link = crj_checkout_return_url( $return_order );
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
				$allowed = array( 'channel', 'order_id', 'signature_valid', 'reference_matches', 'amount_matches', 'merchant_matches', 'currency_matches', 'bill_reference_matches', 'reference_type', 'provider_reference_reconciled', 'response_code', 'authorization_code_present', 'settlement_date_present', 'result', 'error_code', 'extra_field_count', 'field_count', 'reference_length', 'duration_ms' );
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
			public static function signed_payload( array $data, string $environment ): bool {
				$hash = $data['pp_SecureHash'] ?? null;
				if ( ! is_string( $hash ) || ! preg_match( '/^[A-Fa-f0-9]{64}$/D', $hash ) ) {
					return false;
				}
				if ( ! in_array( $environment, array( 'sandbox', 'live' ), true ) ) {
					return false;
				}
				$c    = (array) get_option( 'woocommerce_crj_card_settings', array() );
				$salt = (string) ( $c[ self::credential_prefix( $environment ) . 'integrity_salt' ] ?? '' );
				return $salt !== '' && hash_equals( self::hash_fields( $data, $salt ), strtoupper( $hash ) );
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
				return self::reconcile_payment_data( $data );
			}

			public static function check_wallet_history( int $id, string $reference, int $check ): void {
				$order = wc_get_order( $id );
				if ( ! $order || $order->get_meta( '_crj_ref' ) !== $reference || ! crj_needs_wallet_history( $order ) ) {
					return;
				}
				try {
					$order = self::reconcile_phone_history( $order );
				} catch ( Throwable $error ) {
					// A callback can hold the same order lock. Leave the verified state untouched.
					$order = wc_get_order( $id ) ?: $order;
				}
				if ( $check < 6 && $order->get_meta( '_crj_ref' ) === $reference && crj_needs_wallet_history( $order ) ) {
					wp_schedule_single_event( time() + 60, 'crj_check_wallet_history', array( $id, $reference, $check + 1 ) );
				}
			}

			public static function check_order_history( WC_Order $order, array $rows ): WC_Order {
				$settings = (array) get_option( 'woocommerce_crj_card_settings', array() );
				$merchant = (string) ( $settings['live_merchant_id'] ?? '' );
				$request = (string) $order->get_meta( '_crj_ref' );
				if ( $merchant === '' || $request === '' || $order->get_meta( '_crj_environment' ) !== 'live'
					|| $order->get_meta( '_crj_merchant' ) !== $merchant || $order->get_date_paid()
					|| ! $order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
					return $order;
				}
				$data = crj_needs_wallet_history( $order ) ? crj_phone_history_result( $order, $rows, $merchant ) : array();
				if ( $data ) {
					return self::reconcile_payment_data( $data, $merchant )['order'];
				}
				$matches = array();
				$references = array();
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$row = array_filter( $row, 'is_scalar' );
					$ref = (string) ( $row['pp_TxnRefNo'] ?? '' );
					$references[ $ref ] = ( $references[ $ref ] ?? 0 ) + 1;
					$code = crj_provider_code( $row['pp_ResponseCode'] ?? '' );
					$status = crj_provider_code( $row['pp_Status'] ?? '' ) ?: $code;
					$type = (string) ( $row['pp_TxnType'] ?? '' );
					if ( ! str_starts_with( $ref, $request ) || ! crj_payment_succeeded( $code ) || ! crj_payment_succeeded( $status )
						|| ! in_array( $type, array( 'MPAY', 'AUTH', 'MWALLET', 'BNPL' ), true )
						|| ( $row['pp_BillReference'] ?? '' ) !== 'ORDER-' . $order->get_id()
						|| ( $row['pp_TxnCurrency'] ?? '' ) !== 'PKR' || ( $row['pp_MerchantID'] ?? $merchant ) !== $merchant
						|| ltrim( (string) ( $row['pp_Amount'] ?? '' ), '0' ) !== $order->get_meta( '_crj_amount' ) ) {
						continue;
					}
					$matches[] = array(
						'pp_TxnRefNo'      => $ref,
						'pp_BillReference' => $row['pp_BillReference'],
						'pp_TxnCurrency'   => 'PKR',
						'pp_MerchantID'    => $merchant,
						'pp_Amount'        => (string) $order->get_meta( '_crj_amount' ),
						'pp_TxnType'       => $type,
						'pp_ResponseCode'  => $code,
					);
				}
				if ( count( $matches ) !== 1 || $references[ $matches[0]['pp_TxnRefNo'] ] !== 1 ) {
					return $order;
				}
				return self::reconcile_payment_data( $matches[0], $merchant, true )['order'];
			}

			private static function reconcile_phone_history( WC_Order $order ): WC_Order {
				$lookup = 'crj_phone_check_' . $order->get_id();
				if ( get_transient( $lookup ) ) {
					return $order;
				}
				crj_security_lock( 'phone-history:' . $order->get_id() );
				if ( get_transient( $lookup ) ) {
					return $order;
				}
				set_transient( $lookup, '1', 15 );
				$settings = (array) get_option( 'woocommerce_crj_card_settings', array() );
				$merchant = (string) ( $settings['live_merchant_id'] ?? '' );
				if ( $merchant === '' || (string) $order->get_meta( '_crj_environment' ) !== 'live'
					|| (string) $order->get_meta( '_crj_merchant' ) !== $merchant ) {
					return $order;
				}
				$history = crj_provider_request( 'history', array(), array( 'PageNumber' => 1, 'PageSize' => 50, 'SortOrder' => 'DESC', 'SortBy' => 'pp_TxnDateTime' ), $settings );
				if ( ! is_array( $history ) || (string) ( $history['pp_ResponseCode'] ?? '' ) !== '000' || ! is_array( $history['pp_Items'] ?? null ) ) {
					return $order;
				}
				$data = crj_phone_history_result( $order, $history['pp_Items'], $merchant );
				if ( ! $data ) {
					return $order;
				}
				return self::reconcile_payment_data( $data, $merchant )['order'];
			}

			private static function reconcile_payment_data( array $data, string $history_merchant = '', bool $confirmed_history_payment = false ): array {
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
				$from_history = $history_merchant !== '';
				self::shape_report( $data, $from_history ? 'merchant_history' : 'return_post', 'validated', strlen( wp_json_encode( $data ) ) );
				$report['field_count'] = count( $data );
				crj_validate_callback_fields( $data );
				// Provider may add response fields; reject malformed keys/arrays, but do not reject legitimate extras.
				foreach ( $data as $k => $v ) {
					if ( ! is_string( $k ) || ! is_string( $v ) || strlen( $k ) > 100 || strlen( $v ) > 8192 ) {
						throw new RuntimeException( 'Malformed_fields' );
					}
				}
				$report['extra_field_count'] = count( array_filter( array_keys( $data ), static fn( $k )=> ! preg_match( '/^pp_[A-Za-z0-9_]+$/D', (string) $k ) ) );
				$verified_environments = $from_history ? array( 'live' ) : array();
				if ( ! $from_history ) {
					foreach ( array( 'live', 'sandbox' ) as $environment ) {
						if ( self::signed_payload( $data, $environment ) ) {
							$verified_environments[] = $environment;
						}
					}
				}
				if ( ! $verified_environments ) {
					throw new RuntimeException( 'Signature_mismatch' );
				}
				$order = self::callback_order( $data );
				if ( ! $order || 'crj_card' !== $order->get_payment_method() ) {
					throw new RuntimeException( 'Order_not_found' );
				}
				crj_security_lock( 'order:' . $order->get_id() );
				$order = wc_get_order( $order->get_id() );
				if ( ! in_array( $order->get_status(), array( 'pending', 'failed', 'on-hold', 'processing', 'completed' ), true ) ) {
					throw new RuntimeException( 'Ineligible_order_state' );
				}
				$report['order_id']               = $order->get_id();
				$ref                              = (string) ( $data['pp_TxnRefNo'] ?? '' );
				$report['reference_length']       = strlen( $ref );
				$report['bill_reference_matches'] = hash_equals( 'ORDER-' . $order->get_id(), (string) ( $data['pp_BillReference'] ?? '' ) );
				$report['amount_matches']         = hash_equals( (string) $order->get_meta( '_crj_amount' ), (string) ( $data['pp_Amount'] ?? '' ) );
				$report['currency_matches']       = ( $data['pp_TxnCurrency'] ?? '' ) === 'PKR' && $order->get_currency() === 'PKR';
				if ( ! hash_equals( crj_minor_amount( $order->get_total() ), (string) ( $data['pp_Amount'] ?? '' ) ) ) {
					throw new RuntimeException( 'Current_order_amount_mismatch' );
				}
				if ( ! $report['bill_reference_matches'] || ! $report['amount_matches'] || ! $report['currency_matches'] ) {
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
				$matched      = $attempts[ $matched_index ];
				$environment = (string) ( $matched['environment'] ?? $order->get_meta( '_crj_environment' ) );
				$merchant     = (string) ( $matched['merchant_id'] ?? $order->get_meta( '_crj_merchant' ) );
				$report['signature_valid'] = ! $from_history && in_array( $environment, $verified_environments, true );
				if ( $from_history ) {
					$eligible = $confirmed_history_payment
						? ( ! $order->get_date_paid() && $order->has_status( array( 'pending', 'on-hold', 'failed' ) )
							&& crj_payment_succeeded( (string) ( $data['pp_ResponseCode'] ?? '' ) ) )
						: ( crj_needs_wallet_history( $order ) && ! empty( $matched['event_hash'] ) );
					if ( ! $eligible || $environment !== 'live' || $merchant !== $history_merchant
						|| ( $matched['request_ref'] ?? '' ) !== $order->get_meta( '_crj_ref' ) ) {
						throw new RuntimeException( 'History_attempt_changed' );
					}
				} elseif ( ! $report['signature_valid'] ) {
					throw new RuntimeException( 'Signature_mismatch' );
				}
				$report['merchant_matches'] = $merchant !== '' && hash_equals( $merchant, (string) ( $data['pp_MerchantID'] ?? '' ) );
				if ( ! $report['merchant_matches'] ) {
					throw new RuntimeException( 'Transaction_details_mismatch' );
				}
				$signed_details                       = crj_signed_provider_details( $data );
				$report['authorization_code_present'] = isset( $signed_details['authorization_code'] );
				$report['settlement_date_present']    = isset( $signed_details['settlement_date'] );
				$original = (string) $matched['request_ref'];
				// Fingerprint the authenticated fields only. Unsigned extras and hash casing
				// must not turn a replay into a new financial event or another order note.
				$event_data = array();
				foreach ( $data as $key => $value ) {
					if ( preg_match( '/^pp_[A-Za-z0-9_]+$/D', $key ) && strcasecmp( $key, 'pp_SecureHash' ) !== 0 && trim( $value ) !== '' ) {
						$event_data[ $key ] = $value;
					}
				}
				ksort( $event_data, SORT_STRING );
				$event_hash = hash( 'sha256', ( $from_history ? 'merchant-history|' : '' ) . wp_json_encode( $event_data ) );
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
				// A verified final result can identify the wallet charge under its hosted-link parent.
				$confirmed_suffix = $existing_attempt_ref === $original
					&& in_array( $matched['status'] ?? '', array( 'PENDING', 'NOT COMPLETED' ), true )
					&& ( $from_history || crj_payment_succeeded( (string) ( $data['pp_ResponseCode'] ?? '' ) ) );
				if ( $existing_attempt_ref !== '' && ! hash_equals( $existing_attempt_ref, $ref ) && ! $confirmed_suffix ) {
					throw new RuntimeException( 'Different_provider_reference_on_attempt' );
				}
				// An ID cannot be reused across orders, nor on another attempt of this order.
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
				if ( crj_provider_code( $code ) === '' || in_array( $code, array( 'RF00', 'PRF0' ), true ) ) {
					throw new RuntimeException( 'Invalid_response_code' );
				}
				$successful = crj_payment_succeeded( $code );
				$response_message = (string) ( $data['pp_ResponseMessage'] ?? '' );
				$pending = crj_payment_awaits_confirmation( $code, $response_message );
				$provider_txn_type = strtoupper( trim( (string) ( $data['pp_TxnType'] ?? '' ) ) );
				if ( in_array( $provider_txn_type, array( 'REF', 'REFMWALLET', 'REFBNPL' ), true ) ) {
					throw new RuntimeException( 'Refund_notification_requires_reconciliation' );
				}
				if ( $order->is_paid() && ( ! $successful || ! hash_equals( (string) $order->get_transaction_id(), $ref ) ) ) {
					throw new RuntimeException( 'Order_already_paid' );
				}
				$report['provider_reference_reconciled'] = true;
				// Capture only signed provider-supplied payment type. Never infer a payment
				// method from the gateway ID or from the hosted payment-link request.
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
				// A later callback may omit the payment method. Only retain it for the same verified charge ID.
				if ( $existing_attempt_ref === $ref && ! empty( $matched['event_hash'] ) ) {
					$previous_type = (string) ( $matched['type'] ?? 'unknown' );
					if ( $reported_type === 'unknown' ) {
						$reported_type = $previous_type;
					}
					if ( $provider_txn_type === '' && $reported_type === $previous_type ) {
						$provider_txn_type = (string) ( $matched['provider_txn_type'] ?? '' );
					}
				}
				$attempt                      =&$attempts[ $matched_index ];
				if ( $from_history ) {
					$attempt['signed_return_code'] = $attempt['code'] ?? '';
				} else {
					$attempt['signed_return_code'] = $code;
				}
				$attempt['event_hash']        = $event_hash;
				$attempt['provider_ref']      = $ref;
				$attempt['type']              = $reported_type;
				$attempt['provider_txn_type'] = $provider_txn_type;
				$attempt['code']              = $code;
				$attempt['updated']           = current_time( 'mysql' );
				$attempt['status']            = $successful ? ( $order->is_paid() ? 'COMPLETED' : 'PROCESSING' ) : ( $pending ? 'PENDING' : 'NOT COMPLETED' );
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
				$report['response_code'] = $code;
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
				// Only confirmed success codes can complete the order.
				// A failure or pending response must not complete the order.
				if ( $successful ) {
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
						$order->add_order_note( $from_history ? 'CardRaabta: JazzCash payment verified through Live merchant history.' : 'CardRaabta: JazzCash signed successful return verified (bill reference, amount, merchant, currency and provider transaction ID).' );
						$report['result'] = 'payment_completed';
						if ( ! $from_history && $environment === 'live' ) {
							update_option( 'crj_last_verified_live_payment_callback', time(), false );
						}
					} else {
						$report['result'] = 'already_paid';
					}
				} else {
					$report['result'] = 'non_success_payment_not_completed';
					// Only the latest attempt can change the unpaid order's customer-facing state.
					if ( hash_equals( (string) $order->get_meta( '_crj_ref' ), $original ) ) {
						$order->update_meta_data( '_crj_status', $pending ? 'pending' : 'not_completed' );
						$order->update_meta_data( '_crj_response_code', $code );
						$order->save();
						$history_args = array( $order->get_id(), $original, 1 );
						if ( crj_needs_wallet_history( $order ) && ! wp_next_scheduled( 'crj_check_wallet_history', $history_args ) ) {
							wp_schedule_single_event( time() + 20, 'crj_check_wallet_history', $history_args );
						}
						$note = 'CardRaabta JazzCash: ' . crj_provider_status_label( $code ) . ' (' . $code . ').';
						if ( $pending && $order->has_status( array( 'pending', 'failed' ) ) ) {
							$order->update_status( 'on-hold', $note );
						} elseif ( crj_payment_declined( $code, $response_message ) && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
							$order->update_status( 'failed', $note );
						} else {
							$order->add_order_note( $note );
						}
					}
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
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- HMAC ticket plus independent session/customer authorization.
					$query = wp_unslash( $_GET );
					$return_order = crj_authorized_return_order( $query );
					if ( $return_order ) {
						wp_safe_redirect( crj_checkout_return_url( $return_order ), 303 );
						exit;
					}
					status_header( 200 );
					header( 'Content-Type: text/plain; charset=utf-8' );
					echo 'Payment response received. Open your original order link or sign in to view its status.';
					exit;
				}
				$started = hrtime( true );
				try {
					$raw          = (string) file_get_contents( 'php://input', false, null, 0, 32769 );
					$method       = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) );
					$content_type = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ?? '' ) );
					$result       = self::handle_return_request( $raw, $content_type, $method );
					self::callback_diagnostic( 'return', $result['report'] + array( 'duration_ms' => (int) ( ( hrtime( true ) - $started ) / 1000000 ) ) );
					nocache_headers();
					header( 'Referrer-Policy: no-referrer' );
					if ( $result['can_view'] ) {
						wp_safe_redirect( crj_checkout_return_url( $result['order'] ), 303 );
						exit;}
					wp_safe_redirect( crj_return_continuation_url( $result['order'] ), 303 );
					exit;
				} catch ( Throwable $error ) {
					self::callback_diagnostic( 'return_rejected', array( 'error_code' => preg_replace( '/[^A-Za-z0-9_.-]/', '_', substr( $error->getMessage(), 0, 50 ) ), 'duration_ms' => (int) ( ( hrtime( true ) - $started ) / 1000000 ) ) );
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
		add_action( 'crj_check_wallet_history', array( 'CRJ_Orchestrator_Gateway', 'check_wallet_history' ), 10, 3 );
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
				// Some provider notifications omit payment identities or amount/currency.
				// Only a complete signed success can use the same settlement path as the browser return.
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
					// Either account can notify this shared endpoint.
					$signature_valid = CRJ_Orchestrator_Gateway::signed_payload( $payload, 'live' )
						|| CRJ_Orchestrator_Gateway::signed_payload( $payload, 'sandbox' );
					if ( ! $signature_valid ) {
						throw new RuntimeException( 'IPN signature mismatch' );
					}
					$ref = (string) ( $payload['pp_TxnRefNo'] ?? '' );
					if ( preg_match( '/^CR([1-9][0-9]*)X[A-F0-9]{10}$/D', $ref, $m ) ) {
						$order = wc_get_order( (int) $m[1] );
						if ( $order && 'crj_card' === $order->get_payment_method() ) {
							$order_id          = $order->get_id();
							$reference_matches = hash_equals( (string) $order->get_meta( '_crj_ref' ), $ref );
						}
					}
					$code          = (string) ( $payload['pp_ResponseCode'] ?? '' );
					$response_code = crj_provider_code( $code ) ?: 'unavailable';
					$result        = $reference_matches ? 'signed_original_ref_pending_amount_confirmation' : 'signed_provider_ref_pending_reconciliation';
					if ( crj_payment_succeeded( $code ) && isset( $payload['pp_BillReference'], $payload['pp_MerchantID'], $payload['pp_Amount'], $payload['pp_TxnCurrency'] ) ) {
						$settlement = CRJ_Orchestrator_Gateway::process_callback_data( $payload );
						$order_id = $settlement['order']->get_id();
						$result = $settlement['report']['result'];
					}
					$status        = 200;
				} catch ( Throwable $e ) {
					$result = 'verification_failed';
					if ( $e->getMessage() === 'Payment_request_busy' ) {
						$status = 503;
						header( 'Retry-After: 3' );
					}
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
						'message' => $status === 200 ? 'Notification authenticated' : 'Notification verification failed',
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
		add_action( 'admin_post_crj_refund', 'crj_handle_refund' );
		add_action( 'admin_post_crj_direct_refund', 'crj_handle_direct_refund' );
		add_action( 'wp_ajax_crj_refund', 'crj_handle_refund' );
		add_action( 'wp_ajax_crj_direct_refund', 'crj_handle_direct_refund' );
		add_action( 'wp_ajax_nopriv_crj_refund', 'crj_refund_login_required' );
		add_action( 'wp_ajax_nopriv_crj_direct_refund', 'crj_refund_login_required' );
		add_action( 'woocommerce_thankyou_crj_card', 'crj_render_payment_notice' );
		add_action( 'before_woocommerce_pay_form', 'crj_render_retry_notice' );
		add_filter(
			'woocommerce_thankyou_order_received_text',
			static function ( $text, $order ) {
				return $order instanceof WC_Order && crj_order_awaits_confirmation( $order )
					? __( 'Order received. JazzCash payment confirmation is pending.', 'cardraabta-pay-with-jazzcash' )
					: $text;
			},
			10,
			2
		);
		add_filter(
			'woocommerce_order_needs_payment',
			static function ( $needs_payment, $order ) {
				// Earlier builds saved this marker without putting the WooCommerce order on hold.
				return $needs_payment && ! crj_order_awaits_confirmation( $order );
			},
			10,
			2
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
				wp_register_script( 'crj-checkout-blocks', plugins_url( 'blocks.js', __FILE__ ), array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), (string) filemtime( plugin_dir_path( __FILE__ ) . 'blocks.js' ), true );
				return array( 'crj-checkout-blocks' );
			}
			public function get_payment_method_data() {
				$g = WC()->payment_gateways()->payment_gateways()[ $this->name ] ?? null;
				return array(
					'title'       => $g ? $g->get_title() : $this->name,
					'logo'        => CRJ_ORCH_URL . 'assets/jazzcash.png',
					'methods'     => crj_checkout_methods(),
					'heading'     => __( 'Secure checkout with JazzCash', 'cardraabta-pay-with-jazzcash' ),
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
register_deactivation_hook( __FILE__, static function () {
	wp_clear_scheduled_hook( 'crj_sync_merchant_refunds' );
	wp_unschedule_hook( 'crj_continue_order_history' );
	flush_rewrite_rules();
} );
