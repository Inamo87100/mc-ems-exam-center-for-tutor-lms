<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MC-EMS License Functions
 *
 * Gestione verifica remota licenza + cache locale per pannello admin.
 */

if ( ! defined( 'MCEMS_LICENSE_OPTION_KEY' ) ) {
	define( 'MCEMS_LICENSE_OPTION_KEY', 'mc_ems_license_key' );
}

if ( ! defined( 'MCEMS_LICENSE_LAST_CHECK_OPTION' ) ) {
	define( 'MCEMS_LICENSE_LAST_CHECK_OPTION', 'mc_ems_license_last_check' );
}

if ( ! defined( 'MCEMS_LICENSE_TRANSIENT_KEY' ) ) {
	define( 'MCEMS_LICENSE_TRANSIENT_KEY', 'mcems_license_status_v2' );
}

if ( ! defined( 'MCEMS_LICENSE_SERVER_URL' ) ) {
	define( 'MCEMS_LICENSE_SERVER_URL', 'https://mambacoding.com/wp-json/mcems/v1/license/verify' );
}

/**
 * Normalizza un URL sito per confronto e trasmissione.
 *
 * @param string $url URL.
 * @return string
 */
function mcems_normalize_site_url( $url ) {
	$url = trim( (string) $url );

	if ( '' === $url ) {
		return '';
	}

	return trailingslashit( esc_url_raw( $url ) );
}

/**
 * Restituisce l'URL del sito corrente normalizzato.
 *
 * @return string
 */
function mcems_get_current_site_url() {
	return mcems_normalize_site_url( home_url() );
}

/**
 * Prova a recuperare il primo valore valido tra le possibili chiavi.
 *
 * @param array $array
 * @param array $keys
 * @param mixed $default
 * @return mixed
 */
function mcems_array_first_not_empty( $array, $keys, $default = '' ) {
	if ( ! is_array( $array ) ) {
		return $default;
	}

	foreach ( (array) $keys as $key ) {
		if ( isset( $array[ $key ] ) && '' !== $array[ $key ] && null !== $array[ $key ] ) {
			return $array[ $key ];
		}
	}

	return $default;
}

/**
 * Converte una data in formato leggibile per l'admin.
 *
 * @param mixed $value
 * @return string
 */
function mcems_format_license_date( $value ) {
	if ( empty( $value ) || ! is_scalar( $value ) ) {
		return '';
	}

	$timestamp = strtotime( (string) $value );
	if ( ! $timestamp ) {
		return '';
	}

	return wp_date( 'd/m/Y', $timestamp );
}

/**
 * Normalizza la risposta del server licenze.
 *
 * @param array $json JSON già decodificato.
 * @return array
 */
function mcems_normalize_license_response( $json ) {
	$status = strtolower( (string) mcems_array_first_not_empty( $json, array( 'status', 'license_status' ), 'error' ) );

	$created_at   = (string) mcems_array_first_not_empty( $json, array( 'created_at', 'creation_date', 'issued_at' ), '' );
	$activated_at = (string) mcems_array_first_not_empty( $json, array( 'activated_at', 'activation_date', 'valid_from', 'start_date' ), '' );

	if ( '' === $activated_at ) {
		$activated_at = $created_at;
	}

	$normalized = array(
		'status'         => $status,
		'message'        => (string) mcems_array_first_not_empty( $json, array( 'message', 'msg', 'detail' ), '' ),
		'reason'         => (string) mcems_array_first_not_empty( $json, array( 'reason', 'code' ), '' ),
		'license_key'    => (string) mcems_array_first_not_empty( $json, array( 'license_key', 'key' ), '' ),
		'plan'           => (string) mcems_array_first_not_empty( $json, array( 'plan', 'license_plan', 'edition' ), '' ),
		'created_at'     => $created_at,
		'activated_at'   => $activated_at,
		'expires_at'     => (string) mcems_array_first_not_empty( $json, array( 'expires_at', 'expiration_date', 'expiry_date', 'expires', 'valid_until', 'end_date' ), '' ),
		'checked_at'     => current_time( 'mysql' ),
		'site_url'       => (string) mcems_array_first_not_empty( $json, array( 'site_url' ), mcems_get_current_site_url() ),
		'raw'            => is_array( $json ) ? $json : array(),
	);

	if ( '' === $normalized['message'] ) {
		switch ( $normalized['status'] ) {
			case 'valid':
				$normalized['message'] = __( 'License is valid and active.', 'mc-ems-base' );
				break;

			case 'expired':
				$normalized['message'] = __( 'The license has expired.', 'mc-ems-base' );
				break;

			case 'inactive':
				$normalized['message'] = __( 'The license exists but is not active.', 'mc-ems-base' );
				break;

			case 'invalid':
				if ( 'site_mismatch' === $normalized['reason'] ) {
					$normalized['message'] = __( 'This license is already bound to a different domain.', 'mc-ems-base' );
				} else {
					$normalized['message'] = __( 'The entered license key is not valid.', 'mc-ems-base' );
				}
				break;

			default:
				$normalized['message'] = __( 'Unable to verify the license at the moment.', 'mc-ems-base' );
				break;
		}
	}

	return $normalized;
}

/**
 * Salva l'ultimo esito licenza in option.
 *
 * @param array $data
 * @return void
 */
function mcems_store_last_license_check( $data ) {
	if ( is_array( $data ) ) {
		update_option( MCEMS_LICENSE_LAST_CHECK_OPTION, $data, false );
	}
}

/**
 * Restituisce l'ultimo check disponibile.
 *
 * @return array
 */
function mcems_get_last_license_check() {
	$data = get_option( MCEMS_LICENSE_LAST_CHECK_OPTION, array() );
	return is_array( $data ) ? $data : array();
}

/**
 * Resetta cache e ultimo check licenza.
 *
 * @return void
 */
function mcems_clear_license_cache() {
	delete_transient( MCEMS_LICENSE_TRANSIENT_KEY );
	delete_option( MCEMS_LICENSE_LAST_CHECK_OPTION );
}

/**
 * Costruisce una risposta standard di errore.
 *
 * @param string $license_key Chiave.
 * @param string $message Messaggio.
 * @param string $reason Reason machine-readable.
 * @return array
 */
function mcems_build_license_error_result( $license_key, $message, $reason = 'error' ) {
	return array(
		'status'       => 'error',
		'message'      => (string) $message,
		'reason'       => (string) $reason,
		'license_key'  => (string) $license_key,
		'plan'         => '',
		'created_at'   => '',
		'activated_at' => '',
		'expires_at'   => '',
		'checked_at'   => current_time( 'mysql' ),
		'site_url'     => mcems_get_current_site_url(),
		'raw'          => array(),
	);
}

/**
 * Checks the license status by sending a POST request to the verification endpoint.
 *
 * @param bool $force Se true, forza una nuova richiesta al server ignorando la cache locale.
 * @return array
 */
function mcems_check_license( $force = false ) {
	$license_key = trim( (string) get_option( MCEMS_LICENSE_OPTION_KEY, '' ) );

	if ( '' === $license_key ) {
		$result = array(
			'status'       => 'invalid',
			'message'      => __( 'No license key configured.', 'mc-ems-base' ),
			'reason'       => 'missing_key',
			'license_key'  => '',
			'plan'         => '',
			'created_at'   => '',
			'activated_at' => '',
			'expires_at'   => '',
			'checked_at'   => current_time( 'mysql' ),
			'site_url'     => mcems_get_current_site_url(),
			'raw'          => array(),
		);

		mcems_store_last_license_check( $result );
		return $result;
	}

	if ( ! $force ) {
		$cached = get_transient( MCEMS_LICENSE_TRANSIENT_KEY );
		if ( $cached && is_array( $cached ) ) {
			return $cached;
		}
	}

	$response = wp_remote_post(
		MCEMS_LICENSE_SERVER_URL,
		array(
			'timeout' => 20,
			'headers' => array(
				'Accept' => 'application/json',
			),
			'body'    => array(
				'license_key' => $license_key,
				'site_url'    => mcems_get_current_site_url(),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		$result = mcems_build_license_error_result(
			$license_key,
			$response->get_error_message(),
			'request_error'
		);

		mcems_store_last_license_check( $result );
		return $result;
	}

	$http_code = (int) wp_remote_retrieve_response_code( $response );
	$body      = wp_remote_retrieve_body( $response );
	$json      = json_decode( $body, true );

	if ( $http_code < 200 || $http_code >= 300 ) {
		$result = mcems_build_license_error_result(
			$license_key,
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'License server returned HTTP %d.', 'mc-ems-base' ),
				$http_code
			),
			'http_error'
		);
		$result['raw'] = is_array( $json ) ? $json : array();

		mcems_store_last_license_check( $result );
		return $result;
	}

	if ( empty( $json ) || ! is_array( $json ) ) {
		$result = mcems_build_license_error_result(
			$license_key,
			__( 'Invalid response from license server.', 'mc-ems-base' ),
			'invalid_json'
		);
		$result['raw_body'] = is_string( $body ) ? $body : '';

		mcems_store_last_license_check( $result );
		return $result;
	}

	$result = mcems_normalize_license_response( $json );

	if ( '' === $result['license_key'] ) {
		$result['license_key'] = $license_key;
	}

	$cache_ttl = ( 'valid' === $result['status'] ) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS;

	set_transient( MCEMS_LICENSE_TRANSIENT_KEY, $result, $cache_ttl );
	mcems_store_last_license_check( $result );

	return $result;
}

/**
 * Returns true if the license is valid, false otherwise.
 *
 * @param bool $force Se true, forza una richiesta al server.
 * @return bool
 */
function mcems_is_license_valid( $force = false ) {
	$check = mcems_check_license( $force );
	return isset( $check['status'] ) && 'valid' === $check['status'];
}
