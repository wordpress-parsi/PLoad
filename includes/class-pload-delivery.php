<?php
/**
 * Shows WebP to visitors by wrapping images in <picture>:
 *
 *   <picture class="pload">
 *     <source type="image/webp" srcset="a.jpg.webp 300w, b.jpg.webp 768w" sizes="...">
 *     <img src="a.jpg" srcset="a.jpg 300w, b.jpg 768w" ...>   (unchanged)
 *   </picture>
 *
 * Why <picture> and not server rewrites: it works on every server (Apache,
 * LiteSpeed, nginx), is safe with page caches and CDNs, needs no .htaccess,
 * and browsers without WebP simply use the original <img>.
 *
 * No file system checks while rendering: which files have a WebP copy is
 * read from the attachment's meta, which WordPress has usually cached.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Delivery {

	const OPTION = 'pload_deliver';

	/** @var array attachment ID => [basename => true] */
	private static $cache = array();

	/** @var array Counters for the debug comment. */
	private static $stats = array(
		'wp'       => 0,   // got WebP through WordPress image functions
		'compat'   => 0,   // got WebP through compatibility mode
		'lazy'     => 0,   // of those, handled through lazy-load attributes
		'pending'  => 0,   // not converted yet
		'nogain'   => 0,   // converted, but no WebP for the sizes shown (not smaller, too big)
		'lazy_off' => 0,   // lazy-loaded by a script while lazy-load support is off
		'excluded' => 0,   // excluded by class or per image
		'external' => 0,   // compatibility mode: image from another site, inline, or outside uploads
		'local'    => 0,   // compatibility mode: file of this site that has no WebP copy yet
		'ms'       => 0.0,
	);

	/** @var bool True while the compatibility pass is running. */
	private static $in_compat = false;

	public static function enabled() {
		return (bool) get_option( self::OPTION, false );
	}

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_content_img' ), 20, 3 );
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'filter_attachment_image' ), 20, 2 );
		add_filter( 'render_block_core/image', array( __CLASS__, 'filter_image_block' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );

		if ( Pload_Settings::compat() || Pload_Settings::debug() ) {
			// Late, so our buffer sits inside any page-cache buffer: the cache
			// stores the processed HTML and the work is done once per page.
			add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 10000 );
		}
	}

	/**
	 * Image block: the "Additional CSS class" goes on <figure>, not <img>.
	 * Copy the skip marker down so class exclusions work for blocks too.
	 */
	public static function filter_image_block( $html, $block ) {
		$class = isset( $block['attrs']['className'] ) ? ' ' . $block['attrs']['className'] . ' ' : '';
		if ( '  ' === $class || '' === $class ) {
			return $html;
		}
		foreach ( Pload_Settings::skip_classes() as $c ) {
			if ( false !== strpos( $class, ' ' . $c . ' ' ) ) {
				return self::add_skip_class( $html );
			}
		}
		return $html;
	}

	private static function add_skip_class( $html ) {
		if ( preg_match( '/<img\b[^>]*\sclass\s*=\s*(["\'])/i', $html ) ) {
			return preg_replace( '/(<img\b[^>]*\sclass\s*=\s*(["\']))/i', '$1pload-skip ', $html, 1 );
		}
		return preg_replace( '/<img\b/i', '<img class="pload-skip"', $html, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Compatibility mode: process the final HTML of the page, so images
	 * printed without WordPress image functions are covered too.
	 * ------------------------------------------------------------------- */

	public static function start_buffer() {
		if ( self::active() && ! is_customize_preview() ) {
			ob_start( array( __CLASS__, 'buffer' ) );
		}
	}

	/** @var bool Set by file_has_webp() when the current image is a local upload. */
	private static $local_seen = false;

	/** @var string Error from the last HTML check, for the debug comment. */
	private static $compat_error = '';

	/**
	 * Output handler. Themes and plugins may flush output early, which would
	 * hand us the page in pieces; pieces are collected and the whole page is
	 * processed once, at the end.
	 */
	public static function buffer( $chunk, $phase ) {
		static $acc = '';
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			$acc = ''; // Someone discarded the buffer.
			return '';
		}
		$acc .= $chunk;
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return '';
		}
		$html = $acc;
		$acc  = '';
		return self::filter_html( $html );
	}

	public static function filter_html( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<html' ) ) {
			return $html;
		}
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return $html;
			}
		}

		if ( Pload_Settings::compat() && false !== stripos( $html, '<img' ) ) {
			$start    = microtime( true );
			$original = $html;
			try {
				self::$in_compat = true;
				$html            = self::scan( $html );
			} catch ( Throwable $e ) {
				// Never break the page: send it unchanged and report why.
				$html               = $original;
				self::$compat_error = $e->getMessage();
				if ( ! get_transient( 'pload_compat_error' ) ) {
					set_transient( 'pload_compat_error', 1, HOUR_IN_SECONDS );
					Pload_Logger::log( 'error', 'Compatibility mode failed on ' . ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '?' ) . ': ' . self::$compat_error );
				}
			}
			self::$in_compat   = false;
			self::$stats['ms'] = ( microtime( true ) - $start ) * 1000;
		}

		if ( Pload_Settings::debug() ) {
			$html .= self::debug_comment();
		}
		return $html;
	}

	/**
	 * One linear pass over the page. Blocks whose images must not be touched
	 * (<picture>, <script>, <style>, <textarea>, <noscript>, <template>) are
	 * copied as they are; every other <img> is processed. No backtracking
	 * regular expression, so page size and large inline scripts are no risk.
	 */
	private static function scan( $html ) {
		$out = '';
		$pos = 0;
		$len = strlen( $html );

		while ( $pos < $len && preg_match( '/<(img|picture|script|style|textarea|noscript|template)\b/i', $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$start = $m[0][1];
			$tag   = strtolower( $m[1][0] );
			$out  .= substr( $html, $pos, $start - $pos );
			$pos   = $start;

			if ( 'img' === $tag ) {
				$end = strpos( $html, '>', $start );
				if ( false === $end ) {
					break;
				}
				$out .= self::process_img( substr( $html, $start, $end - $start + 1 ) );
				$pos  = $end + 1;
				continue;
			}

			$close = stripos( $html, '</' . $tag, $start + 1 );
			$end   = false === $close ? false : strpos( $html, '>', $close );
			if ( false === $end ) {
				break; // Unclosed block: keep the rest unchanged.
			}
			$out .= substr( $html, $start, $end - $start + 1 );
			$pos  = $end + 1;
		}

		return $out . substr( $html, $pos );
	}

	private static function process_img( $tag ) {
		if ( false !== strpos( $tag, 'data-pload' ) ) {
			return $tag;
		}
		if ( self::skipped_by_class( $tag ) ) {
			self::$stats['excluded']++;
			return $tag;
		}
		self::$local_seen = false;
		$before           = self::$stats['external'];
		$tag              = self::build( $tag, array( __CLASS__, 'file_has_webp' ), 'external' );
		// A local file without WebP is only waiting; tell it apart from
		// images that can never get one (other sites, inline images).
		if ( self::$stats['external'] > $before && self::$local_seen ) {
			self::$stats['external']--;
			self::$stats['local']++;
		}
		return $tag;
	}

	/** Plain English on purpose: it is read in page source by developers. */
	private static function debug_comment() {
		$s = self::$stats;
		$c = sprintf(
			'Pload %s: %d images with WebP (%d via WordPress image functions, %d via compatibility mode; %d of them lazy-loaded by a script).',
			PLOAD_VERSION,
			$s['wp'] + $s['compat'],
			$s['wp'],
			$s['compat'],
			$s['lazy']
		);
		$c .= sprintf(
			' Without WebP: %d not converted yet, %d converted but no smaller WebP for the size shown, %d excluded',
			$s['pending'],
			$s['nogain'],
			$s['excluded']
		);
		if ( $s['lazy_off'] ) {
			$c .= sprintf( ', %d lazy-loaded by a script while lazy-load support is off', $s['lazy_off'] );
		}
		if ( Pload_Settings::compat() ) {
			$c .= sprintf( ', %d files of this site without a WebP copy yet, %d from other sites, inline or missing files (compatibility mode).', $s['local'], $s['external'] );
			$c .= '' !== self::$compat_error
				? ' HTML check FAILED, page sent unchanged: ' . str_replace( '--', '- -', self::$compat_error )
				: sprintf( ' HTML check %.1f ms.', $s['ms'] );
		} else {
			$c .= '. Compatibility mode off.';
		}
		return "\n<!-- " . $c . ' -->';
	}

	/**
	 * For images found in the HTML there is no attachment ID, so the file
	 * system is checked: the WebP must exist and be newer than its source.
	 * Only local uploads URLs are considered.
	 */
	public static function file_has_webp( $clean_url ) {
		static $cache = array();
		static $base  = null;
		if ( isset( $cache[ $clean_url ] ) ) {
			if ( $cache[ $clean_url ]['local'] ) {
				self::$local_seen = true;
			}
			return $cache[ $clean_url ]['ok'];
		}
		if ( null === $base ) {
			$up   = wp_get_upload_dir();
			$base = array(
				'host' => strtolower( (string) wp_parse_url( $up['baseurl'], PHP_URL_HOST ) ),
				'path' => untrailingslashit( (string) wp_parse_url( $up['baseurl'], PHP_URL_PATH ) ),
				'dir'  => untrailingslashit( $up['basedir'] ),
			);
		}

		$ok   = false;
		$p    = wp_parse_url( $clean_url );
		$host = isset( $p['host'] ) ? strtolower( $p['host'] ) : $base['host'];
		$path = isset( $p['path'] ) ? rawurldecode( $p['path'] ) : '';

		if ( $host === $base['host'] && 0 === strpos( $path, $base['path'] . '/' ) && false === strpos( $path, '..' )
			&& preg_match( '/\.(jpe?g|png)$/i', $path ) ) {
			$file = $base['dir'] . substr( $path, strlen( $base['path'] ) );
			$webp = $file . '.webp';
			if ( is_file( $file ) ) {
				self::$local_seen = true;
				$ok               = is_file( $webp ) && filemtime( $webp ) >= filemtime( $file );
			}
		}

		$cache[ $clean_url ] = array( 'ok' => $ok, 'local' => self::$local_seen );
		return $ok;
	}

	/**
	 * display:contents keeps <picture> out of the layout, so flex/grid
	 * containers and most theme CSS treat the <img> as before.
	 */
	public static function styles() {
		wp_register_style( 'pload', false, array(), PLOAD_VERSION );
		wp_enqueue_style( 'pload' );
		wp_add_inline_style( 'pload', 'picture.pload{display:contents}' );
	}

	/** Images inside post content and blocks. */
	public static function filter_content_img( $img, $context, $id ) {
		return self::wrap( $img, (int) $id );
	}

	/** Images output by themes and plugins (featured images, WooCommerce). */
	public static function filter_attachment_image( $html, $id ) {
		return self::wrap( $html, (int) $id );
	}

	private static function active() {
		if ( is_admin() || wp_doing_ajax() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return false;
		}
		static $url_ok = null;
		if ( null === $url_ok ) {
			$url_ok = ! self::url_excluded();
		}
		if ( ! $url_ok ) {
			return false;
		}
		/** Lets themes or other code turn delivery off for a request. */
		return (bool) apply_filters( 'pload_deliver', true );
	}

	/** The current address contains one of the "don't show WebP on" lines. */
	private static function url_excluded() {
		// esc_url_raw keeps percent-encoding, so non-Latin addresses still decode correctly.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '';
		if ( '' === $uri ) {
			return false;
		}
		foreach ( Pload_Settings::skip_urls() as $needle ) {
			// Full URLs are reduced to their path so either form works.
			$path = wp_parse_url( $needle, PHP_URL_PATH );
			$path = $path ? $path : $needle;
			if ( '/' === $path ? '/' === strtok( $uri, '?' ) : false !== stripos( $uri, $path ) ) {
				return true;
			}
		}
		return false;
	}

	private static function skipped_by_class( $img ) {
		static $classes = null;
		if ( null === $classes ) {
			$classes = Pload_Settings::skip_classes();
		}
		$attr = ' ' . self::attr( $img, 'class' ) . ' ';
		foreach ( $classes as $c ) {
			if ( false !== strpos( $attr, ' ' . $c . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $img Markup containing one <img>.
	 * @param int    $id  Attachment ID.
	 */
	public static function wrap( $img, $id ) {
		if ( ! $id || ! is_string( $img ) || false === stripos( $img, '<img' ) ) {
			return $img;
		}
		// data-pload marks images already handled: block themes run the whole
		// template through the content filter, featured images included.
		if ( false !== strpos( $img, 'data-pload' ) || false !== stripos( $img, '<picture' ) ) {
			return $img;
		}
		if ( ! self::active() ) {
			return $img;
		}
		if ( self::skipped_by_class( $img ) ) {
			self::$stats['excluded']++;
			return $img;
		}

		$have = self::webp_files( $id );
		if ( ! $have ) {
			self::$stats[ self::reason_for( $id ) ]++;
			return $img;
		}

		return self::build(
			$img,
			function ( $clean ) use ( $have ) {
				return isset( $have[ wp_basename( (string) wp_parse_url( $clean, PHP_URL_PATH ) ) ] );
			},
			'nogain'
		);
	}

	/** Why an attachment has no WebP files at all. */
	private static function reason_for( $id ) {
		if ( get_post_meta( $id, Pload_Converter::EXCLUDE, true ) ) {
			return 'excluded';
		}
		$status = get_post_meta( $id, Pload_Converter::STATUS, true );
		return ( 'done' === $status || 'failed' === $status ) ? 'nogain' : 'pending';
	}

	/** Attributes lazy-load scripts (themes, plugins) keep the real image in. */
	const LAZY_ATTRS = array( 'data-src', 'data-srcset', 'data-lazy-src', 'data-lazy-srcset', 'data-original' );

	/** Every attribute that may hold an image URL, for the lazy-load path. */
	const URL_ATTRS = array( 'src', 'srcset', 'data-src', 'data-srcset', 'data-lazy-src', 'data-lazy-srcset', 'data-original', 'data-large_image' );

	/**
	 * Gives one <img> its WebP version.
	 *
	 * Normal images are wrapped in <picture> with a WebP <source>, keeping the
	 * original as fallback. Images handled by a lazy-load script (real URL in
	 * data-src / data-srcset, a placeholder in src) cannot be wrapped: the
	 * <source> would either load at once, defeating lazy loading, or pin the
	 * placeholder after the script swaps the <img>. For those the URLs in the
	 * attributes are replaced with their WebP copies instead, so the script
	 * loads WebP. Every current browser supports WebP.
	 *
	 * @param string   $img  Markup of one <img>.
	 * @param callable $has  Receives a URL without query string; returns
	 *                       whether a WebP copy (URL . '.webp') exists.
	 * @param string   $miss Counter to raise when nothing could be converted.
	 */
	private static function build( $img, $has, $miss ) {
		foreach ( self::LAZY_ATTRS as $a ) {
			if ( '' !== self::attr( $img, $a ) ) {
				return self::build_lazy( $img, $has, $miss );
			}
		}

		$srcset = self::attr( $img, 'srcset' );
		$list   = $srcset ? self::rewrite_list( $srcset, $has, $hits ) : self::rewrite_list( self::attr( $img, 'src' ), $has, $hits );

		if ( ! $hits ) {
			self::$stats[ $miss ]++;
			return $img;
		}
		self::$stats[ self::$in_compat ? 'compat' : 'wp' ]++;

		$source = '<source type="image/webp" srcset="' . esc_attr( $list ) . '"';
		$sizes  = self::attr( $img, 'sizes' );
		if ( $sizes ) {
			// "auto" is defined for <img loading="lazy"> only.
			$sizes = trim( preg_replace( '/^auto\s*,?\s*/i', '', $sizes ) );
			if ( '' !== $sizes ) {
				$source .= ' sizes="' . esc_attr( $sizes ) . '"';
			}
		}
		$source .= '>';

		$img = preg_replace( '/<img\b/i', '<img data-pload', $img, 1 );

		return '<picture class="pload">' . $source . $img . '</picture>';
	}

	private static function build_lazy( $img, $has, $miss ) {
		if ( ! Pload_Settings::lazy_compat() ) {
			self::$stats['lazy_off']++;
			return $img;
		}
		$total = 0;
		foreach ( self::URL_ATTRS as $name ) {
			$value = self::attr( $img, $name );
			if ( '' === $value ) {
				continue;
			}
			$hits = 0;
			$new  = self::rewrite_list( $value, $has, $hits );
			if ( $hits ) {
				$total += $hits;
				$img    = preg_replace_callback(
					'/(\s' . preg_quote( $name, '/' ) . '\s*=\s*)(["\'])(.*?)\2/is',
					function ( $m ) use ( $new ) {
						return $m[1] . '"' . esc_attr( $new ) . '"';
					},
					$img,
					1
				);
			}
		}
		if ( ! $total ) {
			self::$stats[ $miss ]++;
			return $img;
		}
		self::$stats[ self::$in_compat ? 'compat' : 'wp' ]++;
		self::$stats['lazy']++;
		return preg_replace( '/<img\b/i', '<img data-pload', $img, 1 );
	}

	/**
	 * Replaces URLs that have a WebP copy in a src or srcset value.
	 *
	 * @param string   $value src (one URL) or srcset ("url 300w, url 600w").
	 * @param callable $has
	 * @param int      $hits  Set to the number of URLs replaced.
	 * @return string
	 */
	private static function rewrite_list( $value, $has, &$hits ) {
		$hits = 0;
		$out  = array();
		foreach ( preg_split( '/,\s+/', trim( (string) $value ) ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $candidate, 2 );
			$url   = $parts[0];
			$desc  = isset( $parts[1] ) ? ' ' . $parts[1] : '';
			$clean = preg_replace( '/[?#].*$/', '', $url );

			if ( '' !== $clean && 0 !== strpos( $clean, 'data:' ) && call_user_func( $has, $clean ) ) {
				$out[] = self::cdn( $clean ) . '.webp' . $desc;
				$hits++;
			} else {
				$out[] = $url . $desc; // No WebP for this size: keep the original.
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * Points a local address at the CDN set in the settings. Only the WebP
	 * sources are changed; the original <img> is left to the site's own
	 * CDN or cache plugin.
	 */
	private static function cdn( $url ) {
		static $base = null, $host = null;
		if ( null === $base ) {
			$base = Pload_Settings::cdn_url();
			$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
		if ( '' === $base ) {
			return $url;
		}
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			return $base . $url; // Root-relative address.
		}
		$p = wp_parse_url( $url );
		if ( ! empty( $p['host'] ) && strtolower( $p['host'] ) === $host && isset( $p['path'] ) ) {
			return $base . $p['path'];
		}
		return $url;
	}

	/**
	 * File names of this attachment that have a WebP copy.
	 *
	 * @return array basename => true
	 */
	private static function webp_files( $id ) {
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}
		$have = array();

		// Right after a regenerate the status is cleared until sizes are
		// converted again, so stale copies are never served.
		$status = get_post_meta( $id, Pload_Converter::STATUS, true );
		if ( 'done' === $status || 'failed' === $status ) {
			$detail = get_post_meta( $id, Pload_Converter::META, true );
			if ( is_array( $detail ) && ! empty( $detail['sizes'] ) ) {
				foreach ( $detail['sizes'] as $rec ) {
					if ( isset( $rec['s'], $rec['f'] ) && 'ok' === $rec['s'] ) {
						$have[ $rec['f'] ] = true;
					}
				}
			}
		}

		self::$cache[ $id ] = $have;
		return $have;
	}

	private static function attr( $html, $name ) {
		if ( preg_match( '/\s' . $name . '\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
			return html_entity_decode( $m[2], ENT_QUOTES );
		}
		return '';
	}
}
