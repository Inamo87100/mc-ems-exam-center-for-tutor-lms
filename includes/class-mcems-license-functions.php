<?php
if (!defined('ABSPATH')) exit;

/**
 * MC-EMS License Functions
 *
 * Funzioni per la verifica remota della licenza premium.
 */

/**
 * Checks the license status by sending a POST request to the verification endpoint.
 *
 * @param bool $force Se true, forza una nuova richiesta al server ignorando la cache locale.
 * @return array        Array associativo con almeno ['status' => valid|expired|invalid|error, ... ]
 */
function mcems_check_license($force = false) {
    $license_key = get_option('mc_ems_license_key', '');
    if (!$license_key) {
        return ['status' => 'invalid', 'message' => 'No license key configured.'];
    }

    $transient_key = 'mcems_license_status_v2';

    // Usa la cache, a meno che $force sia true
    if (!$force) {
        $cached = get_transient($transient_key);
        if ($cached && is_array($cached)) {
            return $cached;
        }
    }

    $api_url = home_url('/wp-json/mcems/v1/license/verify');

    $response = wp_remote_post($api_url, [
        'timeout' => 10,
        'body'    => [
            'license_key' => $license_key,
            'site_url'    => home_url(),
        ]
    ]);

    if (is_wp_error($response)) {
        return [
            'status' => 'error',
            'message' => $response->get_error_message(),
        ];
    }

    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);

    if (empty($json) || !isset($json['status'])) {
        return [
            'status' => 'error',
            'message' => 'Invalid response from license server.',
        ];
    }

    // Cache result for 12 hours
    set_transient($transient_key, $json, HOUR_IN_SECONDS * 12);

    return $json;
}

/**
 * Returns true if the license is valid, false otherwise.
 *
 * @param bool $force Se true, forza una richiesta al server.
 * @return bool
 */
function mcems_is_license_valid($force = false) {
    $check = mcems_check_license($force);
    return isset($check['status']) && $check['status'] === 'valid';
}
