<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reads RFCF_CONFIG from wp-config.php.
 *
 * Top-level string keys are the defaults (and the whole config on a single
 * site). On multisite, an entry keyed by site ID replaces any of those keys it
 * sets; `false` switches the plugin off for that site.
 */
final class RFCF_Config {

	const DEFAULT_ROLES = [ 'administrator', 'editor' ];

	private static $cache = [];

	private static function raw() {
		return ( defined( 'RFCF_CONFIG' ) && is_array( RFCF_CONFIG ) ) ? RFCF_CONFIG : [];
	}

	/** Merged settings for the current site, or null if switched off. */
	public static function site() {
		$blog = get_current_blog_id();
		if ( array_key_exists( $blog, self::$cache ) ) {
			return self::$cache[ $blog ];
		}

		$raw = self::raw();
		$c   = array_filter( $raw, 'is_string', ARRAY_FILTER_USE_KEY );

		if ( is_multisite() && array_key_exists( $blog, $raw ) ) {
			if ( false === $raw[ $blog ] ) {
				self::$cache[ $blog ] = null;
				return null;
			}
			if ( is_array( $raw[ $blog ] ) ) {
				$c = array_merge( $c, array_filter( $raw[ $blog ], 'is_string', ARRAY_FILTER_USE_KEY ) );
			}
		}

		self::$cache[ $blog ] = $c;
		return $c;
	}

	public static function is_off() {
		return null === self::site();
	}

	/** Roles allowed to use Tools → Redirects. */
	public static function roles() {
		$c = self::site();
		return isset( $c['roles'] ) ? array_values( array_filter( (array) $c['roles'], 'is_string' ) ) : self::DEFAULT_ROLES;
	}

	/** @return array{token:string,domains:string[]}|null Null when Cloudflare isn't configured. */
	public static function cloudflare() {
		$c = self::site();
		if ( ! $c ) {
			return null;
		}
		$token   = trim( (string) ( $c['token'] ?? '' ) );
		$domains = array_values( array_filter( array_map( 'strtolower', array_map( 'trim', (array) ( $c['domains'] ?? [] ) ) ) ) );
		return ( '' === $token || ! $domains ) ? null : [
			'token'   => $token,
			'domains' => $domains,
		];
	}

	/** Super admins, plus users holding one of the configured roles. */
	public static function user_can_manage() {
		if ( self::is_off() || ! is_user_logged_in() ) {
			return false;
		}
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}
		return (bool) array_intersect( (array) wp_get_current_user()->roles, self::roles() );
	}
}
