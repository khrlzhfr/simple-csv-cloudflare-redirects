<?php
defined( 'ABSPATH' ) || exit;

/**
 * CSV redirects: one file per site in the uploads folder (uploads/redirects/),
 * named redirects-YYYYMMDDTHHMMSSZ.csv, matched on every front-end request.
 * Every change (upload or edit in WordPress) writes a new file and removes the old one.
 */
final class RFCF_CSV {

	const STATUSES  = [ 301, 302, 303, 307, 308 ];
	const COLUMNS   = [ 'description', 'source', 'target', 'status', 'preserve_query_string', 'enabled' ];
	const MAX_BYTES = 2097152; // 2 MB
	const MAX_ROWS  = 10000;
	const TEMPLATE  = "description,source,target,status,preserve_query_string,enabled\nOld page,/old-page,/new-page,301,no,yes\nBlog moved,/blog/*,https://news.example.org/\${1},301,yes,yes\n";

	public static function init() {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_redirect' ], 1 );
	}

	/* ------------------------------------------------------------------ *
	 * Storage
	 * ------------------------------------------------------------------ */

	/** This site's folder: wp-content/uploads/redirects (uploads/sites/{ID}/redirects on multisite). */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( $uploads['basedir'] ) . '/redirects';
	}

	/** Path of this site's CSV, or null. */
	public static function current_file() {
		$files = glob( self::dir() . '/redirects-*.csv' );
		if ( ! $files ) {
			return null;
		}
		sort( $files );
		return end( $files );
	}

	/** Rows of the current file (valid rows only), and its name. */
	public static function current() {
		$file = self::current_file();
		return [
			'file' => $file ? basename( $file ) : '',
			'rows' => $file ? self::parse( (string) file_get_contents( $file ) )['rows'] : [],
		];
	}

	/** Creates the folders and blocks direct web access where the server allows it. */
	private static function prepare_dir() {
		if ( ! wp_mkdir_p( self::dir() ) ) {
			return false;
		}
		$dir = self::dir();
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n" );
		}
		return true;
	}

	/**
	 * Saves CSV content as the site's only file.
	 *
	 * @return string|WP_Error New file path.
	 */
	public static function store( $content ) {
		if ( ! self::prepare_dir() ) {
			return new WP_Error( 'rfcf_csv_dir', __( 'Could not create the redirects folder in the uploads folder. Check that the web server can write to wp-content/uploads.', 'simple-csv-cloudflare-redirects' ) );
		}

		$file = self::dir() . '/redirects-' . gmdate( 'Ymd\THis\Z' ) . '.csv';
		if ( false === file_put_contents( $file, $content ) ) {
			return new WP_Error( 'rfcf_csv_write', __( 'Could not save the CSV file.', 'simple-csv-cloudflare-redirects' ) );
		}

		foreach ( (array) glob( self::dir() . '/redirects-*.csv' ) as $old ) {
			if ( $old !== $file ) {
				wp_delete_file( $old );
			}
		}
		delete_transient( self::cache_key() );
		return $file;
	}

	/** Writes rows back as a CSV with every column. @return string|WP_Error */
	public static function save_rows( array $rows ) {
		return self::store( self::to_csv( $rows ) );
	}

	public static function to_csv( array $rows ) {
		$fh = fopen( 'php://temp', 'r+' );
		fputcsv( $fh, self::COLUMNS, ',', '"', '' );
		foreach ( $rows as $r ) {
			fputcsv(
				$fh,
				[
					$r['description'],
					$r['source'],
					$r['target'],
					(int) $r['status'],
					$r['preserve_query_string'] ? 'yes' : 'no',
					$r['enabled'] ? 'yes' : 'no',
				],
				',',
				'"',
				''
			);
		}
		rewind( $fh );
		$out = stream_get_contents( $fh );
		fclose( $fh );
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Parsing and validation
	 * ------------------------------------------------------------------ */

	/** yes/no style value → bool, '' → default, anything else → null. */
	private static function to_bool( $v, $default ) {
		$v = strtolower( trim( (string) $v ) );
		if ( '' === $v ) {
			return $default;
		}
		if ( in_array( $v, [ 'yes', 'y', 'true', '1', 'on' ], true ) ) {
			return true;
		}
		if ( in_array( $v, [ 'no', 'n', 'false', '0', 'off' ], true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * @return array{rows:array<int,array>,errors:string[]}
	 */
	public static function parse( $content ) {
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $content );
		$rows    = [];
		$errors  = [];
		$map     = null;
		$line    = 0;

		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $content );
		rewind( $fh );

		while ( false !== ( $cols = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
			$line++;
			if ( '' === trim( implode( '', array_map( 'strval', $cols ) ) ) ) {
				continue;
			}
			$cols = array_map( 'trim', array_map( 'strval', $cols ) );

			if ( null === $map ) {
				$head = array_map( 'strtolower', $cols );
				if ( ! in_array( 'source', $head, true ) || ! in_array( 'target', $head, true ) ) {
					/* translators: %d: line number */
					$errors[] = sprintf( __( 'Line %d: the first row must be a header with at least “source” and “target” columns.', 'simple-csv-cloudflare-redirects' ), $line );
					break;
				}
				$map = array_flip( $head );
				continue;
			}

			$get = static function ( $col ) use ( $map, $cols ) {
				return isset( $map[ $col ] ) ? ( $cols[ $map[ $col ] ] ?? '' ) : '';
			};

			$row = [
				'description'           => $get( 'description' ),
				'source'                => $get( 'source' ),
				'target'                => $get( 'target' ),
				'status'                => $get( 'status' ),
				'preserve_query_string' => self::to_bool( $get( 'preserve_query_string' ), false ),
				'enabled'               => self::to_bool( $get( 'enabled' ), true ),
			];

			$problem = self::validate( $row );
			if ( $problem ) {
				/* translators: 1: line number, 2: problem */
				$errors[] = sprintf( __( 'Line %1$d: %2$s', 'simple-csv-cloudflare-redirects' ), $line, $problem );
				if ( count( $errors ) >= 50 ) {
					break;
				}
				continue;
			}

			$row['status'] = '' === $row['status'] ? 301 : (int) $row['status'];
			$rows[]        = $row;

			if ( count( $rows ) > self::MAX_ROWS ) {
				/* translators: %d: maximum rows */
				$errors[] = sprintf( __( 'The file has more than %d redirects.', 'simple-csv-cloudflare-redirects' ), self::MAX_ROWS );
				break;
			}
		}
		fclose( $fh );

		if ( null === $map && ! $errors ) {
			$errors[] = __( 'The file is empty.', 'simple-csv-cloudflare-redirects' );
		}

		return [
			'rows'   => $rows,
			'errors' => $errors,
		];
	}

	/** @return string Empty when the row is fine. */
	public static function validate( array $r ) {
		$source = (string) $r['source'];
		$target = (string) $r['target'];
		$status = (string) $r['status'];

		if ( '' === $source || '/' !== $source[0] ) {
			return __( 'source must be a path starting with /.', 'simple-csv-cloudflare-redirects' );
		}
		if ( preg_match( '/[\s?#]/', $source ) ) {
			return __( 'source must be a path only, without spaces, ? or #.', 'simple-csv-cloudflare-redirects' );
		}
		if ( '' === $target || ! ( '/' === $target[0] || preg_match( '#^https?://#i', $target ) ) ) {
			return __( 'target must start with / or https://.', 'simple-csv-cloudflare-redirects' );
		}
		if ( preg_match( '/\s/', $target ) ) {
			return __( 'target cannot contain spaces.', 'simple-csv-cloudflare-redirects' );
		}
		if ( '' !== $status && ! in_array( (int) $status, self::STATUSES, true ) ) {
			return __( 'status must be 301, 302, 303, 307 or 308.', 'simple-csv-cloudflare-redirects' );
		}
		if ( preg_match_all( '/\$\{(\d+)\}/', $target, $m ) && max( array_map( 'intval', $m[1] ) ) > substr_count( $source, '*' ) ) {
			return __( 'target uses a ${n} that has no matching * in source.', 'simple-csv-cloudflare-redirects' );
		}
		if ( null === $r['preserve_query_string'] ) {
			return __( 'preserve_query_string must be yes or no.', 'simple-csv-cloudflare-redirects' );
		}
		if ( null === $r['enabled'] ) {
			return __( 'enabled must be yes or no.', 'simple-csv-cloudflare-redirects' );
		}
		return '';
	}

	/** One-line summary for the change log. */
	public static function summarize( array $r ) {
		$s = sprintf( '%s → %s (%d)', $r['source'], $r['target'], (int) $r['status'] );
		if ( ! empty( $r['preserve_query_string'] ) ) {
			$s .= ' +query';
		}
		if ( empty( $r['enabled'] ) ) {
			$s .= ' [' . __( 'disabled', 'simple-csv-cloudflare-redirects' ) . ']';
		}
		return $s;
	}

	/* ------------------------------------------------------------------ *
	 * Matching
	 * ------------------------------------------------------------------ */

	private static function cache_key() {
		return 'rfcf_csv_' . get_current_blog_id();
	}

	/** Paths get a leading slash and lose any trailing slash (except the root). */
	private static function normalize( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );
		return '/' === $path ? $path : rtrim( $path, '/' );
	}

	public static function pattern( $source ) {
		$parts = array_map(
			static function ( $p ) {
				return preg_quote( $p, '#' );
			},
			explode( '*', self::normalize( $source ) )
		);
		return '#^' . implode( '(.*)', $parts ) . '$#i';
	}

	/** Compiled enabled rules for this site, cached until the file changes. */
	public static function rules() {
		$file = self::current_file();
		if ( ! $file ) {
			return [];
		}
		$sig    = basename( $file ) . '|' . filemtime( $file );
		$cached = get_transient( self::cache_key() );
		if ( is_array( $cached ) && ( $cached['sig'] ?? '' ) === $sig ) {
			return $cached['rules'];
		}

		$rules = [];
		foreach ( self::parse( (string) file_get_contents( $file ) )['rows'] as $r ) {
			if ( $r['enabled'] ) {
				$rules[] = [ self::pattern( $r['source'] ), $r['target'], $r['status'], $r['preserve_query_string'] ];
			}
		}
		set_transient( self::cache_key(), [ 'sig' => $sig, 'rules' => $rules ], DAY_IN_SECONDS );
		return $rules;
	}

	/** A URL path relative to this site's home, or null if outside it. */
	public static function relative_path( $path ) {
		$path = rawurldecode( (string) $path );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home ) {
			if ( 0 === strcasecmp( $path, $home ) ) {
				$path = '/';
			} elseif ( 0 === stripos( $path, $home . '/' ) ) {
				$path = substr( $path, strlen( $home ) );
			} else {
				return null;
			}
		}
		return self::normalize( $path );
	}

	/** Finds the redirect for a site-relative path. @return array{url:string,status:int}|null */
	public static function match( $path, $query = '' ) {
		foreach ( self::rules() as $rule ) {
			list( $re, $target, $status, $preserve ) = $rule;
			if ( ! preg_match( $re, $path, $m ) ) {
				continue;
			}
			$url = preg_replace_callback(
				'/\$\{(\d+)\}/',
				static function ( $x ) use ( $m ) {
					return str_replace( '%2F', '/', rawurlencode( $m[ (int) $x[1] ] ?? '' ) );
				},
				$target
			);
			if ( '/' === $url[0] ) {
				$url = home_url( $url );
			}
			if ( $preserve && '' !== $query ) {
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
			}
			return [
				'url'    => $url,
				'status' => $status,
			];
		}
		return null;
	}

	public static function maybe_redirect() {
		if ( RFCF_Config::is_off() ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = self::relative_path( wp_parse_url( $uri, PHP_URL_PATH ) );
		if ( null === $path ) {
			return;
		}
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$hit   = self::match( $path, $query );
		if ( ! $hit ) {
			return;
		}

		// Don't redirect a page to itself.
		$host = wp_parse_url( $hit['url'], PHP_URL_HOST );
		if ( ! $host || 0 === strcasecmp( $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			if ( self::relative_path( wp_parse_url( $hit['url'], PHP_URL_PATH ) ) === $path ) {
				return;
			}
		}

		wp_redirect( $hit['url'], $hit['status'], 'Redirects' ); // phpcs:ignore WordPress.Security.SafeRedirect -- targets are set by site editors and may be external.
		exit;
	}
}
