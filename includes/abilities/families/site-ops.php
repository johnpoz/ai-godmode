<?php
/**
 * Site operations: the three things that were being typed into run-php over
 * and over because no ability covered them.
 *
 *  http-fetch       a GET or HEAD of this site's own URLs from the server
 *                   itself, for "does the page render" checks when the
 *                   caller's own network cannot reach the site (bot captchas,
 *                   browser integrity checks). Same-host only.
 *  cache-purge      flush the object cache and whichever page caches are
 *                   active, in one call, reporting what was found.
 *  permalinks-flush rebuild the rewrite rules, needed after any post type,
 *                   taxonomy or permalink change.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

use AIGodmode\Scrub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Http_Fetch extends Base {
	public const MAX_BODY = 200000;

	public static function name(): string {
		return 'godmode/http-fetch';
	}

	public static function definition(): array {
		return self::make(
			'site',
			'Fetch Site URL',
			'GET or HEAD one of this site\'s own URLs from the server itself and return status, headers, timing and a scrubbed body excerpt. For checking that a page renders, a redirect lands, or a REST route answers, when your own network cannot reach the site. Same host only; no cookies; follows up to 3 redirects.',
			self::obj(
				array(
					'url'       => self::str( 2000, 1 ),
					'method'    => array( 'type' => 'string', 'enum' => array( 'GET', 'HEAD' ), 'default' => 'GET' ),
					'max_bytes' => self::int( 1, self::MAX_BODY, 20000 ),
					'headers'   => self::map(),
					'timeout'   => self::int( 1, 60, 20 ),
				),
				array( 'url' )
			),
			self::obj(
				array(
					'url'        => self::str(),
					'status'     => array( 'type' => 'integer' ),
					'headers'    => self::map(),
					'body'       => self::str(),
					'body_bytes' => array( 'type' => 'integer' ),
					'truncated'  => self::bool(),
					'seconds'    => array( 'type' => 'number' ),
					'redirected' => self::nullable( self::str() ),
				),
				array( 'url', 'status', 'headers', 'body', 'body_bytes', 'truncated', 'seconds' )
			),
			false
		);
	}

	public function execute( $input ) {
		$raw = trim( (string) $input['url'] );
		// A path is relative to the site's home URL.
		if ( 0 === strpos( $raw, '/' ) ) {
			$raw = home_url( $raw );
		}
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( (string) ( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return self::err( 'godmode_bad_url', 'url must be an http(s) URL on this site, or a path starting with /.' );
		}
		$allowed = array_unique( array_filter( array(
			strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
		) ) );
		if ( ! in_array( strtolower( (string) $parts['host'] ), $allowed, true ) ) {
			return self::err( 'godmode_foreign_host', 'http-fetch reaches this site only (' . implode( ', ', $allowed ) . '). Use run-php for anything else, deliberately.', 403 );
		}
		$method  = strtoupper( (string) ( $input['method'] ?? 'GET' ) );
		$max     = (int) ( $input['max_bytes'] ?? 20000 );
		$headers = array( 'User-Agent' => 'AI Godmode http-fetch/' . GODMODE_VERSION );
		foreach ( (array) ( $input['headers'] ?? array() ) as $k => $v ) {
			$k = (string) $k;
			if ( in_array( strtolower( $k ), array( 'cookie', 'authorization', 'host' ), true ) ) {
				return self::err( 'godmode_header_refused', 'The ' . $k . ' header cannot be set through http-fetch.', 403 );
			}
			$headers[ $k ] = (string) $v;
		}
		$start = microtime( true );
		$r     = wp_remote_request(
			$raw,
			array(
				'method'      => $method,
				'timeout'     => (int) ( $input['timeout'] ?? 20 ),
				'redirection' => 3,
				'headers'     => $headers,
				'cookies'     => array(),
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter for loopback requests.
			)
		);
		$seconds = round( microtime( true ) - $start, 3 );
		if ( is_wp_error( $r ) ) {
			return self::err( 'godmode_fetch_failed', 'Request failed: ' . $r->get_error_message(), 502 );
		}
		$out_headers = array();
		$hdrs        = wp_remote_retrieve_headers( $r );
		// A CaseInsensitiveDictionary cast to array yields its protected
		// property, not the headers; ask it for the map instead.
		if ( is_object( $hdrs ) && method_exists( $hdrs, 'getAll' ) ) {
			$hdrs = $hdrs->getAll();
		}
		foreach ( (array) $hdrs as $k => $v ) {
			$out_headers[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
		}
		unset( $out_headers['set-cookie'] );
		$body = 'HEAD' === $method ? '' : (string) wp_remote_retrieve_body( $r );
		$cap  = Scrub::cap( Scrub::text( $body ), $max );
		$final = isset( $r['http_response'] ) && is_object( $r['http_response'] ) && method_exists( $r['http_response'], 'get_response_object' ) ? (string) $r['http_response']->get_response_object()->url : $raw;
		return array(
			'url'        => $raw,
			'status'     => (int) wp_remote_retrieve_response_code( $r ),
			'headers'    => $out_headers,
			'body'       => $cap['text'],
			'body_bytes' => strlen( $body ),
			'truncated'  => $cap['truncated'],
			'seconds'    => $seconds,
			'redirected' => $final !== $raw ? $final : null,
		);
	}
}

final class Cache_Purge extends Base {
	public static function name(): string {
		return 'godmode/cache-purge';
	}

	public static function definition(): array {
		return self::make(
			'site',
			'Purge Caches',
			'Flush the WordPress object cache and every page cache this ability recognises and finds active (SiteGround Speed Optimizer, LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache, Breeze, Cache Enabler, Hummingbird, Autoptimize, Kinsta, WP Engine, Elementor CSS), then fire the generic purge actions other caches listen for. Optionally reset the PHP opcode cache. Reports what was purged and what was not present.',
			self::obj( array( 'opcache' => self::bool( false ) ) + self::ack() ),
			self::obj( array( 'purged' => self::list_of( self::str() ), 'absent' => self::list_of( self::str() ), 'object_cache' => self::bool() ), array( 'purged', 'absent', 'object_cache' ) ),
			true
		);
	}

	public function execute( $input ) {
		$purged = array();
		$absent = array();
		$object = function_exists( 'wp_cache_flush' ) ? (bool) wp_cache_flush() : false;

		$targets = array(
			'SiteGround Speed Optimizer' => function () {
				// The public hook SiteGround documents for third parties.
				if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
					sg_cachepress_purge_cache();
					return true;
				}
				// purge_everything() is an instance method in current releases; a
				// static call throws, so instantiate.
				if ( class_exists( '\\SiteGround_Optimizer\\Supercacher\\Supercacher' ) && method_exists( '\\SiteGround_Optimizer\\Supercacher\\Supercacher', 'purge_everything' ) ) {
					$sg = new \SiteGround_Optimizer\Supercacher\Supercacher();
					$sg->purge_everything();
					return true;
				}
				return false;
			},
			'LiteSpeed Cache'            => function () {
				if ( ! defined( 'LSCWP_V' ) && ! class_exists( '\\LiteSpeed\\Purge' ) ) {
					return false;
				}
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
				return true;
			},
			'WP Rocket'                  => function () {
				if ( ! function_exists( 'rocket_clean_domain' ) ) {
					return false;
				}
				rocket_clean_domain();
				if ( function_exists( 'rocket_clean_minify' ) ) {
					rocket_clean_minify();
				}
				return true;
			},
			'W3 Total Cache'             => function () {
				if ( ! function_exists( 'w3tc_flush_all' ) ) {
					return false;
				}
				w3tc_flush_all();
				return true;
			},
			'WP Super Cache'             => function () {
				if ( ! function_exists( 'wp_cache_clear_cache' ) ) {
					return false;
				}
				wp_cache_clear_cache();
				return true;
			},
			'WP Fastest Cache'           => function () {
				if ( ! isset( $GLOBALS['wp_fastest_cache'] ) || ! method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
					return false;
				}
				$GLOBALS['wp_fastest_cache']->deleteCache( true );
				return true;
			},
			'Breeze'                     => function () {
				if ( ! class_exists( 'Breeze_PurgeCache' ) && ! defined( 'BREEZE_VERSION' ) ) {
					return false;
				}
				do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's own hook.
				return true;
			},
			'Cache Enabler'              => function () {
				if ( ! class_exists( 'Cache_Enabler' ) ) {
					return false;
				}
				do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's own hook.
				return true;
			},
			'Hummingbird'                => function () {
				if ( ! defined( 'WPHB_VERSION' ) ) {
					return false;
				}
				do_action( 'wphb_clear_page_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hummingbird's own hook.
				return true;
			},
			'Autoptimize'                => function () {
				if ( ! class_exists( 'autoptimizeCache' ) || ! method_exists( 'autoptimizeCache', 'clearall' ) ) {
					return false;
				}
				\autoptimizeCache::clearall();
				return true;
			},
			'Kinsta'                     => function () {
				if ( ! class_exists( '\\Kinsta\\Cache' ) ) {
					return false;
				}
				do_action( 'kinsta_cache_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Kinsta's own hook.
				return true;
			},
			'WP Engine'                  => function () {
				if ( ! class_exists( 'WpeCommon' ) ) {
					return false;
				}
				if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
					\WpeCommon::purge_memcached();
				}
				if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
					\WpeCommon::purge_varnish_cache();
				}
				return true;
			},
			'Elementor CSS'              => function () {
				if ( ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance->files_manager ) ) {
					return false;
				}
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				return true;
			},
		);
		foreach ( $targets as $label => $fn ) {
			try {
				if ( $fn() ) {
					$purged[] = $label;
				} else {
					$absent[] = $label;
				}
			} catch ( \Throwable $e ) {
				$absent[] = $label . ' (threw: ' . $e->getMessage() . ')';
			}
		}
		// Generic hooks that several hosts and plugins listen for.
		do_action( 'godmode_cache_purge' );
		if ( ! empty( $input['opcache'] ) && function_exists( 'opcache_reset' ) ) {
			$purged[] = @opcache_reset() ? 'PHP opcache' : 'PHP opcache (reset refused)';
		}
		return array( 'purged' => $purged, 'absent' => $absent, 'object_cache' => $object );
	}
}

final class Permalinks_Flush extends Base {
	public static function name(): string {
		return 'godmode/permalinks-flush';
	}

	public static function definition(): array {
		return self::make(
			'site',
			'Flush Permalinks',
			'Rebuild the rewrite rules, the same as saving Settings then Permalinks. Needed after registering a post type or taxonomy, or changing the permalink structure, when pages answer 404. hard also rewrites the root .htaccess or web.config; the default soft flush touches the database only.',
			self::obj( array( 'hard' => self::bool( false ) ) + self::ack() ),
			self::obj( array( 'rules' => array( 'type' => 'integer' ), 'structure' => self::str(), 'hard' => self::bool() ), array( 'rules', 'structure', 'hard' ) ),
			true
		);
	}

	public function execute( $input ) {
		$hard = ! empty( $input['hard'] );
		flush_rewrite_rules( $hard );
		$rules = get_option( 'rewrite_rules' );
		return array(
			'rules'     => is_array( $rules ) ? count( $rules ) : 0,
			'structure' => (string) get_option( 'permalink_structure' ),
			'hard'      => $hard,
		);
	}
}
