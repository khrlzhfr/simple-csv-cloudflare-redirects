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
			return new WP_Error( 'rfcf_csv_dir', __( 'Could not create the redirects folder in the uploads folder. Check that the web server can write to wp-content/uploads.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ) );
		}

		$file = self::dir() . '/redirects-' . gmdate( 'Ymd\THis\Z' ) . '.csv';
		if ( false === file_put_contents( $file, $content ) ) {
			return new WP_Error( 'rfcf_csv_write', __( 'Could not save the CSV file.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ) );
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

	/**
	 * Where to download a CSV from. Google Sheets and Google Drive sharing links
	 * become their CSV download links (keeping the sheet tab), Dropbox links
	 * their direct download. Anything else is used as it is.
	 */
	public static function download_url( $url ) {
		$url = trim( (string) $url );
		if ( preg_match( '#^https://docs\.google\.com/spreadsheets/d/(e/)?([A-Za-z0-9_-]+)#', $url, $m ) ) {
			$gid = preg_match( '/[#&?]gid=([0-9]+)/', $url, $g ) ? $g[1] : '';
			if ( $m[1] ) { // "Publish to the web" link.
				return 'https://docs.google.com/spreadsheets/d/e/' . $m[2] . '/pub?output=csv' . ( '' !== $gid ? '&single=true&gid=' . $gid : '' );
			}
			return 'https://docs.google.com/spreadsheets/d/' . $m[2] . '/export?format=csv' . ( '' !== $gid ? '&gid=' . $gid : '' );
		}
		if ( preg_match( '~^https://drive\.google\.com/(?:file/d/|open\?id=|uc\?(?:[^#]*&)?id=)([A-Za-z0-9_-]+)~', $url, $m ) ) {
			return 'https://drive.google.com/uc?export=download&id=' . $m[1];
		}
		if ( preg_match( '#^https://(?:www\.)?dropbox\.com/#', $url ) ) {
			return add_query_arg( 'dl', '1', $url );
		}
		return $url;
	}

	/**
	 * Downloads a CSV from a public web address (never the server's own network).
	 *
	 * @return string|WP_Error The CSV text.
	 */
	public static function fetch( $url ) {
		if ( ! preg_match( '#^https?://#i', (string) $url ) || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'rfcf_url', __( 'Enter a full web address, starting with https://', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ) );
		}
		$res = wp_safe_remote_get(
			self::download_url( $url ),
			[
				'timeout'             => 20,
				'redirection'         => 5,
				'limit_response_size' => self::MAX_BYTES + 1,
			]
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'rfcf_url', __( 'The CSV could not be fetched:', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ) . ' ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		$type = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'rfcf_url', sprintf( __( 'The address answered with HTTP %d. Check the address, and that the file is shared so anyone with the link can view it.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ), $code ) );
		}
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error( 'rfcf_url', __( 'The file is larger than 2 MB.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ) );
		}
		if ( false !== strpos( $type, 'text/html' ) || preg_match( '/^\s*<(?:!doctype|html)/i', $body ) ) {
			return new WP_Error( 'rfcf_url', __( 'The address returned a web page, not a CSV. For Google Sheets or Google Drive, share the file so anyone with the link can view it.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ) );
		}
		return $body;
	}

	/**
	 * A description without leading =, +, -, @, tab or carriage return, so a
	 * spreadsheet opening the downloaded CSV can't treat it as a formula.
	 * Descriptions are the only free-text column; the others are validated.
	 */
	public static function clean_description( $text ) {
		return trim( ltrim( (string) $text, "=+-@\t\r " ) );
	}

	public static function to_csv( array $rows ) {
		$lines = [ self::csv_line( self::COLUMNS ) ];
		foreach ( $rows as $r ) {
			$lines[] = self::csv_line(
				[
					self::clean_description( $r['description'] ),
					$r['source'],
					$r['target'],
					(int) $r['status'],
					$r['preserve_query_string'] ? 'yes' : 'no',
					$r['enabled'] ? 'yes' : 'no',
				]
			);
		}
		return implode( "\n", $lines ) . "\n";
	}

	/** One CSV line (RFC 4180): fields with commas, quotes, line breaks or edge spaces are quoted. */
	private static function csv_line( array $fields ) {
		$out = [];
		foreach ( $fields as $v ) {
			$v     = (string) $v;
			$out[] = preg_match( '/[",\r\n]|^\s|\s$/', $v ) ? '"' . str_replace( '"', '""', $v ) . '"' : $v;
		}
		return implode( ',', $out );
	}

	/**
	 * Splits CSV text into records (RFC 4180: quoted fields may hold commas, quotes and line breaks).
	 *
	 * @return array<int,array{0:string[],1:int}> Each record's fields and the line it starts on.
	 */
	private static function csv_records( $text ) {
		$records = [];
		$row     = [];
		$field   = '';
		$quoted  = false;
		$line    = 1;
		$start   = 1;
		$len     = strlen( $text );

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $text[ $i ];
			if ( $quoted ) {
				if ( '"' === $c ) {
					if ( $i + 1 < $len && '"' === $text[ $i + 1 ] ) {
						$field .= '"';
						$i++;
					} else {
						$quoted = false;
					}
				} else {
					if ( "\n" === $c ) {
						$line++;
					}
					$field .= $c;
				}
			} elseif ( '"' === $c && '' === $field ) {
				$quoted = true;
			} elseif ( ',' === $c ) {
				$row[] = $field;
				$field = '';
			} elseif ( "\n" === $c || "\r" === $c ) {
				if ( "\r" === $c && $i + 1 < $len && "\n" === $text[ $i + 1 ] ) {
					$i++;
				}
				$row[]     = $field;
				$records[] = [ $row, $start ];
				$row       = [];
				$field     = '';
				$line++;
				$start = $line;
			} else {
				$field .= $c;
			}
		}
		if ( '' !== $field || $row ) {
			$row[]     = $field;
			$records[] = [ $row, $start ];
		}
		return $records;
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

		foreach ( self::csv_records( $content ) as $record ) {
			list( $cols, $line ) = $record;
			if ( '' === trim( implode( '', array_map( 'strval', $cols ) ) ) ) {
				continue;
			}
			$cols = array_map( 'trim', array_map( 'strval', $cols ) );

			if ( null === $map ) {
				$head = array_map( 'strtolower', $cols );
				if ( ! in_array( 'source', $head, true ) || ! in_array( 'target', $head, true ) ) {
					/* translators: %d: line number */
					$errors[] = sprintf( __( 'Line %d: the first row must be a header with at least “source” and “target” columns.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ), $line );
					break;
				}
				$map = array_flip( $head );
				continue;
			}

			$get = static function ( $col ) use ( $map, $cols ) {
				return isset( $map[ $col ] ) ? ( $cols[ $map[ $col ] ] ?? '' ) : '';
			};

			$row = [
				'description'           => self::clean_description( $get( 'description' ) ),
				'source'                => $get( 'source' ),
				'target'                => $get( 'target' ),
				'status'                => $get( 'status' ),
				'preserve_query_string' => self::to_bool( $get( 'preserve_query_string' ), false ),
				'enabled'               => self::to_bool( $get( 'enabled' ), true ),
			];

			$problem = self::validate( $row );
			if ( $problem ) {
				/* translators: 1: line number, 2: problem */
				$errors[] = sprintf( __( 'Line %1$d: %2$s', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ), $line, $problem );
				if ( count( $errors ) >= 50 ) {
					break;
				}
				continue;
			}

			$row['status'] = '' === $row['status'] ? 301 : (int) $row['status'];
			$rows[]        = $row;

			if ( count( $rows ) > self::MAX_ROWS ) {
				/* translators: %d: maximum rows */
				$errors[] = sprintf( __( 'The file has more than %d redirects.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ), self::MAX_ROWS );
				break;
			}
		}

		if ( null === $map && ! $errors ) {
			$errors[] = __( 'The file is empty.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' );
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
			return __( 'source must be a path starting with /.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' );
		}
		if ( preg_match( '/[\s?#]/', $source ) ) {
			return __( 'source must be a path only, without spaces, ? or #.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' );
		}
		if ( '' === $target || ! ( '/' === $target[0] || preg_match( '#^https?://#i', $target ) ) ) {
			return __( 'target must start with / or https://.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' );
		}
		if ( preg_match( '/\s/', $target ) ) {
			return __( 'target cannot contain spaces.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' );
		}
		if ( '' !== $status && ! in_array( (int) $status, self::STATUSES, true ) ) {
			return __( 'status must be 301, 302, 303, 307 or 308.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' );
		}
		if ( preg_match_all( '/\$\{(\d+)\}/', $target, $m ) && max( array_map( 'intval', $m[1] ) ) > substr_count( $source, '*' ) ) {
			return __( 'target uses a ${n} that has no matching * in source.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' );
		}
		if ( null === $r['preserve_query_string'] ) {
			return __( 'preserve_query_string must be yes or no.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' );
		}
		if ( null === $r['enabled'] ) {
			return __( 'enabled must be yes or no.', 'khrlzhfr-simple-csv-redirects-with-cloudflare' );
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
			$s .= ' [' . __( 'disabled', 'khrlzhfr-simple-csv-redirects-with-cloudflare' ) . ']';
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
				// The query string goes before any #fragment in the target.
				$hash = strpos( $url, '#' );
				$frag = false === $hash ? '' : substr( $url, $hash );
				$url  = false === $hash ? $url : substr( $url, 0, $hash );
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query . $frag;
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

		wp_redirect( $hit['url'], $hit['status'], 'Simple CSV Redirects with Cloudflare' ); // phpcs:ignore WordPress.Security.SafeRedirect -- targets are set by site editors and may be external.
		exit;
	}
}
