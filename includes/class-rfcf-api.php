<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the Cloudflare v4 API (Rulesets, phase http_request_dynamic_redirect).
 */
final class RFCF_API {

	const BASE  = 'https://api.cloudflare.com/client/v4';
	const PHASE = 'http_request_dynamic_redirect';

	private $token;

	public function __construct( $token ) {
		$this->token = (string) $token;
	}

	/**
	 * @return array|WP_Error Full decoded response on success.
	 */
	public function request( $method, $path, $body = null, $query = [] ) {
		$url = self::BASE . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}
		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( ! is_array( $data ) ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'rfcf_bad_response', sprintf( __( 'Unexpected response from Cloudflare (HTTP %d).', 'simple-csv-redirects-with-cloudflare' ), $code ), [ 'status' => $code ] );
		}

		if ( empty( $data['success'] ) ) {
			$msgs = [];
			foreach ( (array) ( $data['errors'] ?? [] ) as $e ) {
				$msgs[] = ( isset( $e['code'] ) ? $e['code'] . ': ' : '' ) . ( $e['message'] ?? '' );
			}
			return new WP_Error( 'rfcf_api_error', $msgs ? implode( '; ', $msgs ) : sprintf( 'HTTP %d', $code ), [ 'status' => $code ] );
		}

		return $data;
	}

	/** All zones the token can see (needs Zone → Zone → Read). */
	public function list_zones() {
		$zones = [];
		$page  = 1;
		do {
			$res = $this->request( 'GET', '/zones', null, [ 'per_page' => 50, 'page' => $page ] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			foreach ( (array) $res['result'] as $z ) {
				$zones[ $z['id'] ] = $z['name'];
			}
			$total = (int) ( $res['result_info']['total_pages'] ?? 1 );
			$page++;
		} while ( $page <= $total && $page <= 20 );

		asort( $zones );
		return $zones;
	}

	public function get_zone( $zone_id ) {
		$res = $this->request( 'GET', '/zones/' . rawurlencode( $zone_id ) );
		return is_wp_error( $res ) ? $res : $res['result'];
	}

	/** @return array|WP_Error Zone object for a domain name. */
	public function find_zone( $name ) {
		$res = $this->request( 'GET', '/zones', null, [ 'name' => $name ] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['result'][0]['id'] ) ) {
			/* translators: %s: domain */
			return new WP_Error( 'rfcf_no_zone', sprintf( __( 'No zone named %s is visible to this token.', 'simple-csv-redirects-with-cloudflare' ), $name ) );
		}
		return $res['result'][0];
	}

	/** @return array|null|WP_Error Null when the zone has no redirect ruleset yet. */
	public function get_entrypoint( $zone_id ) {
		$res = $this->request( 'GET', '/zones/' . rawurlencode( $zone_id ) . '/rulesets/phases/' . self::PHASE . '/entrypoint' );
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			if ( isset( $data['status'] ) && 404 === (int) $data['status'] ) {
				return null;
			}
			return $res;
		}
		return $res['result'];
	}

	/** Creates the entrypoint ruleset. Only call when none exists — PUT replaces all rules. */
	public function create_entrypoint( $zone_id, array $rule ) {
		unset( $rule['position'] );
		return $this->request( 'PUT', '/zones/' . rawurlencode( $zone_id ) . '/rulesets/phases/' . self::PHASE . '/entrypoint', [ 'rules' => [ $rule ] ] );
	}

	public function add_rule( $zone_id, $ruleset_id, array $rule ) {
		return $this->request( 'POST', '/zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules', $rule );
	}

	public function update_rule( $zone_id, $ruleset_id, $rule_id, array $rule ) {
		return $this->request( 'PATCH', '/zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules/' . rawurlencode( $rule_id ), $rule );
	}

	public function delete_rule( $zone_id, $ruleset_id, $rule_id ) {
		return $this->request( 'DELETE', '/zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules/' . rawurlencode( $rule_id ) );
	}
}
