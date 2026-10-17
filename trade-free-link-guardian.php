<?php
/**
 * Plugin Name:       Trade-Free Link Guardian
 * Plugin URI:        https://trom.tf/
 * Description:       Finds broken links across your posts, pages, menus and widgets, then fixes what can be fixed deterministically (protocol/www swaps, permanent redirects, renamed slugs) and gives you a one-click path to resolve the rest. Runs entirely on this server: no SaaS account, no phone-home, no ads, no premium tier.
 * Version:           1.33.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * Author:            Alexandru-Ionuț Chiuță
 * Author URI:        https://trom.tf/
 * License:           MIT
 * License URI:       https://opensource.org/license/mit
 * Text Domain:       trade-free-link-guardian
 *
 * Trade-free, by design:
 *   - No external service is ever contacted to "check" a link — every HTTP
 *     request this plugin makes is one YOU asked for (checking a URL your
 *     own content links to), made directly from your own server.
 *   - The one optional exception is the Wayback Machine lookup, which is
 *     off by default, clearly labelled, and never applied automatically.
 *   - No telemetry, no usage tracking, no update-nag screens, no upsell.
 *   - Uninstalling (not just deactivating) removes every table and option
 *     this plugin created. Nothing is left behind.
 *
 * @package TradeFreeLinkGuardian
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access is not allowed.
}

/* =========================================================================
 * CONSTANTS
 * ========================================================================= */

define( 'TFLG_VERSION', '1.33.1' );
define( 'TFLG_DB_VERSION', '8' );
define( 'TFLG_PLUGIN_FILE', __FILE__ );
define( 'TFLG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TFLG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TFLG_CAPABILITY', apply_filters( 'tflg_capability', 'manage_options' ) );

/* =========================================================================
 * TABLE OF CONTENTS -- this file is deliberately single-file (see the
 * project's own "without borders" philosophy: no build step, no external
 * dependency, works by being copied to one place). That trade-off means
 * there's no folder tree to browse by filename, so this stands in for
 * one: search this file for the exact marker text on the right (e.g.
 * "SECTION: TFLG_Checker") to jump straight to that class, in any editor.
 * Kept as searchable text rather than line numbers on purpose -- a line
 * number goes stale the moment anything above it changes; this doesn't.
 *
 *   TFLG_Util           -  "SECTION: TFLG_Util"            -  stateless helpers shared by every other class (URL parsing, escaping, settings)
 *   TFLG_DB             -  "SECTION: TFLG_DB"              -  schema, table creation/upgrades, every direct database query
 *   TFLG_Scanner        -  "SECTION: TFLG_Scanner"         -  walks posts/pages/menus/widgets, extracts every link it finds
 *   TFLG_Checker        -  "SECTION: TFLG_Checker"         -  makes the actual HTTP request that decides if a URL is alive
 *   TFLG_Fixer          -  "SECTION: TFLG_Fixer"           -  the six deterministic auto-fixes, and applying/reverting a fix
 *   TFLG_List_Table     -  "SECTION: TFLG_List_Table"      -  the Links screen's table (admin-only; declared inside is_admin())
 *   TFLG_Admin          -  "SECTION: TFLG_Admin"           -  every admin page's HTML, the menu, and the enqueued CSS/JS
 *   TFLG_Ajax           -  "SECTION: TFLG_Ajax"            -  every wp_ajax_ endpoint the admin screens call
 *   TFLG_Network_Admin  -  "SECTION: TFLG_Network_Admin"   -  the network-wide rollup, multisite only
 *   TFLG_Editor_Check   -  "SECTION: TFLG_Editor_Check"    -  the save_post warning for already-known-broken links
 *   TFLG_404_Monitor    -  "SECTION: TFLG_404_Monitor"     -  passive logging of real front-end 404 hits
 *   TFLG_Cron           -  "SECTION: TFLG_Cron"            -  the optional scheduled scan
 *   TFLG_Plugin         -  "SECTION: TFLG_Plugin"          -  activation, deactivation, uninstall, the constructor that wires everything else together
 * ========================================================================= */

/* =========================================================================
 * SECTION: TFLG_Util — small stateless helpers shared by every other class
 * ========================================================================= */

class TFLG_Util {

	/**
	 * Default settings. Merged with saved settings so new options added in
	 * future versions always have a sane value.
	 */
	public static function default_settings() {
		return array(
			'post_types'               => array( 'post', 'page' ),
			'check_menus'               => true,
			'check_widgets'             => true,
			'check_images'              => false,
			'verify_internal_via_http'  => true,
			'http_timeout'              => 8,
			'check_concurrency'         => 20,
			'recheck_after_hours'       => 12,
			'autofix_protocol'          => true,
			'autofix_www'               => true,
			'autofix_redirect'          => true,
			'autofix_old_slug'          => true,
			'autofix_unmangle'          => true,
			'autofix_www_normalize'     => true,
			'autofix_scheme_typo'       => true,
			'user_agent'                => self::preset_user_agents()['chrome_windows'],
			'exclude_patterns'          => '',
			'schedule'                  => 'weekly',
			'email_notify'              => true,
			'notify_email'              => '',
			'enable_drift_detection'    => false,
			'drift_check_interval_days' => 30,
			'enable_wayback'            => false,
			'enable_archive_today'      => false,
			'enable_memento'            => false,
			'enable_404_log'            => true,
			'batch_size'                => 20,
		);
	}

	/**
	 * Get plugin settings merged with defaults.
	 */
	public static function get_settings() {
		$saved = get_option( 'tflg_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::default_settings(), $saved );
	}

	public static function get_setting( $key ) {
		$settings = self::get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Default, honest User-Agent string identifying this plugin and site.
	 */
	public static function default_user_agent() {
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		return sprintf(
			'TradeFreeLinkGuardian/%s (+%s; WordPress link checker)',
			TFLG_VERSION,
			is_string( $site ) ? $site : home_url()
		);
	}

	/**
	 * A short list of current, genuinely common browser User-Agent
	 * strings, for the Settings dropdown — Chrome first, since it's by
	 * far the single most common combination on the web and, precisely
	 * because it's so ordinary, the one least likely to be singled out
	 * by a site's bot-blocking on User-Agent alone. Chrome and Edge both
	 * use "0.0.0" for the minor/build/patch segments deliberately —
	 * that's Chromium's actual current format (User-Agent Reduction),
	 * not a placeholder; a fabricated full build number would look
	 * less current, not more. Browser version numbers here reflect
	 * what was current as of September 2026 and will drift out of
	 * date over time the same way a real browser's version would.
	 */
	public static function preset_user_agents() {
		return array(
			'chrome_windows'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
			'safari_mac'      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6 Safari/605.1.15',
			'edge_windows'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36 Edg/152.0.0.0',
			'firefox_windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:154.0) Gecko/20100101 Firefox/154.0',
		);
	}

	public static function preset_user_agent_labels() {
		return array(
			'chrome_windows'  => __( 'Chrome on Windows — most common, least likely to be blocked', 'trade-free-link-guardian' ),
			'safari_mac'      => __( 'Safari on macOS', 'trade-free-link-guardian' ),
			'edge_windows'    => __( 'Edge on Windows', 'trade-free-link-guardian' ),
			'firefox_windows' => __( 'Firefox on Windows', 'trade-free-link-guardian' ),
		);
	}

	public static function user_agent() {
		$ua = trim( (string) self::get_setting( 'user_agent' ) );
		return '' !== $ua ? $ua : self::default_user_agent();
	}

	/**
	 * A direct link to archive.today's own lookup for a URL — its
	 * newest snapshot if one exists, or an offer to create one if not.
	 * This is a plain link to a well-documented, public URL pattern,
	 * not a programmatic "does an archive exist" check: archive.today
	 * (also archive.ph / archive.is / archive.today's several other
	 * mirror domains) has no official, stable API the way the Wayback
	 * Machine does, only unofficial, reverse-engineered ones — building
	 * on those would mean this could break without warning whenever
	 * archive.today changes something internal, so this deliberately
	 * only ever does what a plain link can: send the person there to
	 * look for themselves. The target URL is appended as-is (not
	 * percent-encoded) — that's the format archive.today's own lookup
	 * path expects.
	 */
	public static function archive_today_url( $url ) {
		return 'https://archive.ph/newest/' . $url;
	}

	/**
	 * A direct link into the Memento aggregator's own public redirect
	 * service — a genuinely official, documented API (unlike
	 * archive.today's), maintained by the same research effort behind
	 * the Memento web standard (RFC 7089) and still in active use by
	 * other real tools today. A single lookup here checks across
	 * several archives at once — the Wayback Machine, archive.today
	 * where it participates, and others — rather than just one,
	 * redirecting straight to whichever holds the memento closest to
	 * right now (in practice, the most recent one available anywhere).
	 */
	public static function memento_url( $url ) {
		return 'https://timetravel.mementoweb.org/memento/' . gmdate( 'YmdHis' ) . '/' . $url;
	}

	/**
	 * A single "how concerning is this page's link health" number, 0 to
	 * 100 — confirmed-broken links count fully, unclear ones count at
	 * half weight (worth flagging, but not as certain a problem as a
	 * confirmed break), everything else contributes nothing. A page
	 * with no links at all reads as perfectly healthy (0) rather than
	 * dividing by zero.
	 */
	public static function health_percent( $broken, $unknown, $total ) {
		$total = (int) $total;
		if ( $total <= 0 ) {
			return 0.0;
		}
		$weighted = ( (int) $broken ) + ( (int) $unknown * 0.5 );
		return max( 0.0, min( 100.0, ( $weighted / $total ) * 100 ) );
	}

	/**
	 * Maps a 0-100 concern score to a color along a green -> amber ->
	 * red gradient — the same green, amber, and red defined once, as
	 * --tflg-ok / --tflg-warn / --tflg-danger, in this plugin's own
	 * CSS custom-property token set (see css()), so the Health Map
	 * reads as part of the same visual language rather than a new
	 * one. PHP can't reference a CSS variable directly, so the three
	 * endpoints are restated here as plain hex — kept deliberately in
	 * sync with those tokens, not just coincidentally the same.
	 */
	public static function health_color( $broken_pct ) {
		$broken_pct = max( 0.0, min( 100.0, (float) $broken_pct ) );
		$stops      = array(
			array( 0, 163, 42 ),   // 0%   -- #00a32a
			array( 219, 166, 23 ), // 50%  -- #dba617
			array( 214, 54, 56 ),  // 100% -- #d63638
		);
		if ( $broken_pct <= 50 ) {
			$from = $stops[0];
			$to   = $stops[1];
			$t    = $broken_pct / 50;
		} else {
			$from = $stops[1];
			$to   = $stops[2];
			$t    = ( $broken_pct - 50 ) / 50;
		}
		$r = (int) round( $from[0] + ( $to[0] - $from[0] ) * $t );
		$g = (int) round( $from[1] + ( $to[1] - $from[1] ) * $t );
		$b = (int) round( $from[2] + ( $to[2] - $from[2] ) * $t );
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	/**
	 * Groups a flat list of link rows (url, check_result) by their
	 * destination host — where every other view in this plugin organizes
	 * by the page a link was FOUND on, this is the one place that
	 * organizes by where a link actually points, which is what makes a
	 * pattern like "everything on this one external site broke at once"
	 * visible as a single row instead of scattered across dozens of
	 * unrelated pages. www. is folded together with the bare domain, the
	 * same normalization already used for deciding whether a link is
	 * this site's own; a link whose host can't be parsed at all is
	 * skipped rather than guessed at. Returned unsorted — sorting is the
	 * caller's choice, not baked in here.
	 */
	public static function group_links_by_domain( $links ) {
		$groups = array();
		foreach ( $links as $link ) {
			$host = wp_parse_url( $link->url, PHP_URL_HOST );
			if ( ! $host ) {
				continue;
			}
			$host = preg_replace( '#^www\.#i', '', strtolower( $host ) );
			if ( ! isset( $groups[ $host ] ) ) {
				$groups[ $host ] = array(
					'domain'  => $host,
					'total'   => 0,
					'broken'  => 0,
					'unknown' => 0,
				);
			}
			++$groups[ $host ]['total'];
			if ( 'broken' === $link->check_result ) {
				++$groups[ $host ]['broken'];
			} elseif ( 'unknown' === $link->check_result ) {
				++$groups[ $host ]['unknown'];
			}
		}
		return array_values( $groups );
	}

	/**
	 * A page's own permalink and a stored internal link's URL can differ
	 * by nothing more than a trailing slash and still mean the exact
	 * same page -- this is the one normalization step that matters for
	 * comparing the two reliably.
	 */
	public static function normalize_url_for_comparison( $url ) {
		return untrailingslashit( trim( (string) $url ) );
	}

	/**
	 * A heuristic, not a certainty: returns the first path segment if
	 * it looks like a language code (en, fr, en-us, pt-br — the
	 * convention most manually-built language sections and
	 * multilingual plugins alike use for a path-based setup), or null
	 * if the path doesn't start with anything matching that shape. A
	 * short slug that happens to look like a language code (a real
	 * site section literally named "/ro/" for some other reason) is
	 * indistinguishable from an actual one this way -- there's no way
	 * to know for certain without a multilingual plugin's own data,
	 * which this deliberately doesn't integrate with.
	 */
	public static function detect_language_prefix( $path ) {
		$path = trim( (string) $path, '/' );
		if ( '' === $path ) {
			return null;
		}
		$segments = explode( '/', $path );
		$first    = strtolower( $segments[0] );
		if ( preg_match( '/^[a-z]{2}(-[a-z]{2})?$/', $first ) ) {
			return $first;
		}
		return null;
	}

	/**
	 * Turns a flat list of pages (each with id, title, path, and
	 * post_parent) into one hierarchy per detected language group.
	 * Each item needs: 'id', 'title', 'path', 'parent' (post_parent,
	 * 0 for none). A page whose own parent isn't in the same language
	 * group (or doesn't exist at all — a parent since trashed, say)
	 * becomes a root of its own group rather than being dropped.
	 * Recursion depth is capped as a safety net against a corrupted,
	 * circular post_parent chain, which should never happen but would
	 * otherwise recurse forever if it somehow did.
	 */
	public static function build_page_trees( $pages ) {
		$groups = array();
		foreach ( $pages as $page ) {
			$lang = self::detect_language_prefix( $page['path'] );
			$key  = $lang ? $lang : '';
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array();
			}
			$groups[ $key ][ $page['id'] ] = array(
				'id'       => $page['id'],
				'title'    => $page['title'],
				'path'     => $page['path'],
				'parent'   => (int) $page['parent'],
				'children' => array(),
			);
		}

		$trees = array();
		foreach ( $groups as $key => $nodes_by_id ) {
			$roots = array();
			foreach ( $nodes_by_id as $id => $node ) {
				$parent_id = $node['parent'];
				if ( $parent_id && $parent_id !== $id && isset( $nodes_by_id[ $parent_id ] ) ) {
					$nodes_by_id[ $parent_id ]['children'][] = $id;
				} else {
					$roots[] = $id;
				}
			}

			$build = function ( $id, $depth ) use ( &$build, &$nodes_by_id ) {
				if ( $depth > 50 ) {
					return null;
				}
				$node               = $nodes_by_id[ $id ];
				$child_ids          = $node['children'];
				$node['children']   = array();
				foreach ( $child_ids as $child_id ) {
					$child = $build( $child_id, $depth + 1 );
					if ( null !== $child ) {
						$node['children'][] = $child;
					}
				}
				usort(
					$node['children'],
					function ( $a, $b ) {
						return strcasecmp( $a['title'], $b['title'] );
					}
				);
				return $node;
			};

			$tree = array();
			foreach ( $roots as $root_id ) {
				$built = $build( $root_id, 0 );
				if ( null !== $built ) {
					$tree[] = $built;
				}
			}
			usort(
				$tree,
				function ( $a, $b ) {
					return strcasecmp( $a['title'], $b['title'] );
				}
			);
			$trees[ $key ] = $tree;
		}

		return $trees;
	}

	/**
	 * Walks an already-built tree (or forest of trees, one per
	 * language group) and adds each node's own link-health numbers —
	 * total tracked links and how many are broken — looked up by page
	 * id from a flat map built elsewhere (see
	 * TFLG_DB::get_source_health_summary(), the same data the Health
	 * Map itself is built from). A page with no entry in the map has
	 * simply never had any links recorded for it, which is a
	 * perfectly normal, healthy state, not a gap to treat as broken.
	 */
	public static function attach_health_to_tree( $tree, $health_map ) {
		foreach ( $tree as &$node ) {
			$health               = isset( $health_map[ $node['id'] ] ) ? $health_map[ $node['id'] ] : array(
				'total'        => 0,
				'broken_count' => 0,
			);
			$node['total']        = (int) $health['total'];
			$node['broken_count'] = (int) $health['broken_count'];
			if ( ! empty( $node['children'] ) ) {
				$node['children'] = self::attach_health_to_tree( $node['children'], $health_map );
			}
		}
		unset( $node );
		return $tree;
	}

	/**
	 * Turns a tree (already health-attached, see attach_health_to_tree())
	 * into a flat list of rows for export — a spreadsheet can't show
	 * nesting directly, so depth (0, 1, 2…) and a breadcrumb-style
	 * full path (Parent > Child > Grandchild) both encode the
	 * hierarchy a tree view shows visually. A pre-order walk, so a
	 * page's own row always comes immediately before its children's.
	 */
	public static function flatten_tree_for_export( $tree, $depth = 0, $breadcrumb = '' ) {
		$rows = array();
		foreach ( $tree as $node ) {
			$path    = ( '' === $breadcrumb ) ? $node['title'] : $breadcrumb . ' > ' . $node['title'];
			$rows[]  = array(
				'depth'        => $depth,
				'path'         => $path,
				'title'        => $node['title'],
				'total'        => $node['total'],
				'broken_count' => $node['broken_count'],
			);
			if ( ! empty( $node['children'] ) ) {
				$rows = array_merge( $rows, self::flatten_tree_for_export( $node['children'], $depth + 1, $path ) );
			}
		}
		return $rows;
	}

	/**
	 * Cuts a string down to at most $max_chars characters without
	 * splitting a multibyte UTF-8 character in half. Deliberately
	 * doesn't use mb_substr()/mb_strlen() -- the mbstring extension is
	 * common but not guaranteed on every PHP install, whereas PCRE's
	 * "/u" (UTF-8) mode is effectively always available, since PCRE is
	 * compiled into PHP itself rather than being a separate extension.
	 * Falls back to a plain byte cut only if the input isn't valid
	 * UTF-8 to begin with, which a byte cut can't make any worse.
	 */
	public static function truncate_utf8_safe( $string, $max_chars ) {
		$string = (string) $string;
		$chars  = preg_split( '//u', $string, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			return substr( $string, 0, $max_chars );
		}
		if ( count( $chars ) <= $max_chars ) {
			return $string;
		}
		return implode( '', array_slice( $chars, 0, $max_chars ) );
	}

	/**
	 * Whether creating a redirect from $source_path to $target_url
	 * would send a visitor around in circles -- either directly (a
	 * rule pointing back to its own source) or indirectly, by chaining
	 * through one or more OTHER existing rules and eventually arriving
	 * back at $source_path. $existing_map is every other currently-
	 * enabled rule as normalized-source-path => target, so this never
	 * needs its own database access and can be tested with a plain
	 * array. An external destination always ends the chain immediately
	 * -- it can never loop back through this site's own redirect
	 * table. A chain that runs past a generous hop limit without
	 * resolving is treated as a loop defensively, rather than walked
	 * forever.
	 */
	public static function redirect_creates_loop( $source_path, $target_url, $existing_map ) {
		$home       = untrailingslashit( home_url() );
		$source_key = self::normalize_url_for_comparison( $source_path );
		$seen       = array( $source_key => true );
		$current    = $target_url;

		for ( $hop = 0; $hop < 20; $hop++ ) {
			$current_key = self::normalize_url_for_comparison( $current );
			if ( 0 === strpos( $current_key, $home ) ) {
				$path = substr( $current_key, strlen( $home ) );
				$path = ( '' === $path ) ? '/' : $path;
			} elseif ( '/' === substr( $current_key, 0, 1 ) ) {
				$path = $current_key;
			} else {
				return false; // An external destination -- the chain ends here, no loop possible through it.
			}
			$path_key = self::normalize_url_for_comparison( $path );
			if ( isset( $seen[ $path_key ] ) ) {
				return true; // Revisited a path already in this chain.
			}
			$seen[ $path_key ] = true;
			if ( ! isset( $existing_map[ $path_key ] ) ) {
				return false; // Chain ends here -- no further rule for this path.
			}
			$current = $existing_map[ $path_key ];
		}
		return true;
	}

	/**
	 * The inverse of everything else this plugin looks at: not "this
	 * link doesn't go anywhere good," but "nothing on this site links
	 * here." $candidates is every page eligible to be checked (id,
	 * title, url); $linked_targets is every internal URL this plugin
	 * has already recorded as appearing as a link somewhere (from the
	 * links table, so from content, menus, and widgets alike -- however
	 * this site's own scan settings are already configured). Returns
	 * the subset of candidates with no match, unchanged from how they
	 * were given (no guessing, no scoring -- just the ones nothing
	 * points to).
	 */
	public static function find_orphaned_pages( $candidates, $linked_targets ) {
		$normalized_targets = array();
		foreach ( $linked_targets as $target ) {
			$normalized_targets[ self::normalize_url_for_comparison( $target ) ] = true;
		}
		$orphans = array();
		foreach ( $candidates as $candidate ) {
			$key = self::normalize_url_for_comparison( $candidate['url'] );
			if ( ! isset( $normalized_targets[ $key ] ) ) {
				$orphans[] = $candidate;
			}
		}
		return $orphans;
	}

	/**
	 * Pulls the <title> out of an HTML document. Deliberately not a
	 * full HTML/DOM parse — a page's <title> is one of the most
	 * reliably well-formed tags on the web, and a lightweight regex
	 * avoids pulling in a parser just for this one element. Returns
	 * null if no title tag is found at all, never an empty guess.
	 */
	public static function extract_title( $html ) {
		if ( ! preg_match( '#<title[^>]*>(.*?)</title>#is', (string) $html, $m ) ) {
			return null;
		}
		$title = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		$title = preg_replace( '/\s+/', ' ', $title );
		return ( '' !== $title ) ? $title : null;
	}

	/**
	 * Same idea as extract_title(), for a page's first <h1>. Unlike a
	 * <title>, an <h1> can reasonably contain nested inline markup
	 * (<span>, <em>, and similar) -- that's stripped before decoding
	 * and collapsing whitespace, so what's compared is the same kind
	 * of plain, readable text as the title. Only the first <h1> found
	 * is used, matching how a page normally has exactly one.
	 */
	public static function extract_h1( $html ) {
		if ( ! preg_match( '#<h1[^>]*>(.*?)</h1>#is', (string) $html, $m ) ) {
			return null;
		}
		$text = wp_strip_all_tags( $m[1] );
		$text = html_entity_decode( trim( $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		return ( '' !== $text ) ? $text : null;
	}

	/**
	 * Normalizes a title before comparing two of them for drift --
	 * specifically, standalone 4-digit years (1900-2099) are blanked
	 * out, since a page's own title updating from one year to the next
	 * ("Results 2025" -> "Results 2026") is routine upkeep on the
	 * source's part, not the kind of subject change this feature is
	 * for. Whitespace differences are also ignored. This only ever
	 * removes signal that would cause a false positive -- it never
	 * changes whether two titles that already differ meaningfully
	 * still compare as different. The same normalization applies
	 * equally to an <h1>, which is why this isn't named title-specific.
	 */
	public static function normalize_title_for_drift( $title ) {
		$title = (string) $title;
		$title = preg_replace( '/\b(19|20)\d{2}\b/', '', $title );
		$title = preg_replace( '/\s+/', ' ', trim( $title ) );
		return $title;
	}

	/**
	 * True only when both titles are non-empty and, after normalizing
	 * away year-only noise, genuinely differ. A missing title on either
	 * side is never treated as drift -- there's nothing reliable to
	 * compare a missing value against, so silence is the honest
	 * answer, not a guess.
	 */
	public static function titles_indicate_drift( $old_title, $new_title ) {
		if ( ! $old_title || ! $new_title ) {
			return false;
		}
		return self::normalize_title_for_drift( $old_title ) !== self::normalize_title_for_drift( $new_title );
	}

	/**
	 * The overall drift decision: true if EITHER the title or the <h1>
	 * changed meaningfully. A generic, site-wide title template can
	 * stay identical across completely different pages while the <h1>
	 * — usually the page's own, specific heading — reveals a real
	 * subject change; checking both catches what checking title alone
	 * would miss. Each side is judged by the same rule as
	 * titles_indicate_drift() (a missing value on either side of a
	 * pair is never itself drift).
	 */
	public static function content_indicates_drift( $old_title, $new_title, $old_h1, $new_h1 ) {
		return self::titles_indicate_drift( $old_title, $new_title )
			|| self::titles_indicate_drift( $old_h1, $new_h1 );
	}

	/**
	 * Maps a list of values onto SVG coordinates for a line chart of the
	 * given pixel size, given a shared value range across however many
	 * series will be drawn (so multiple series on the same chart stay
	 * on one consistent scale rather than each being stretched to fill
	 * the chart independently, which would make them visually
	 * incomparable). SVG y grows downward, the opposite of a normal
	 * value axis, so this inverts it — a higher value ends up with a
	 * smaller y, higher up on the chart, the way a reader expects. A
	 * single point (nothing to draw a line between) is placed centered
	 * rather than dividing by zero.
	 */
	public static function svg_chart_points( $values, $width, $height, $min_value, $max_value ) {
		$count = count( $values );
		if ( 0 === $count ) {
			return array();
		}
		$range = $max_value - $min_value;
		$points = array();
		foreach ( $values as $i => $value ) {
			$x = ( $count > 1 ) ? ( $i / ( $count - 1 ) ) * $width : $width / 2;
			$y = ( $range > 0 ) ? $height - ( ( $value - $min_value ) / $range ) * $height : $height / 2;
			$points[] = array( round( $x, 2 ), round( $y, 2 ) );
		}
		return $points;
	}

	/**
	 * Strips the query string from a raw request URI, leaving just the
	 * path a 404 log entry should be keyed on -- so ?utm_source=... and
	 * similar tracking noise on an otherwise-identical dead URL don't
	 * fragment one real problem into many separate log rows.
	 */
	public static function normalize_404_path( $request_uri ) {
		$path = (string) $request_uri;
		$qpos = strpos( $path, '?' );
		if ( false !== $qpos ) {
			$path = substr( $path, 0, $qpos );
		}
		return $path;
	}

	/**
	 * Known crawler/bot signatures for the 404 log's bot/human split.
	 * Filterable so a site can extend it without editing this file.
	 */
	public static function bot_user_agent_patterns() {
		return apply_filters(
			'tflg_bot_user_agent_patterns',
			array(
				'bot',
				'spider',
				'crawl',
				'slurp',
				'mediapartners',
				'facebookexternalhit',
				'bingpreview',
				'yandex',
				'baiduspider',
				'duckduckbot',
				'applebot',
			)
		);
	}

	/**
	 * Whether a user-agent string looks like a known crawler. This is a
	 * heuristic, not proof -- a user agent can be forged in either
	 * direction. A request with no user agent at all is always treated
	 * as a bot, since no genuine browser omits one.
	 */
	public static function is_bot_user_agent( $user_agent ) {
		$user_agent = trim( (string) $user_agent );
		if ( '' === $user_agent ) {
			return true;
		}
		$lower = strtolower( $user_agent );
		foreach ( self::bot_user_agent_patterns() as $pattern ) {
			if ( false !== strpos( $lower, strtolower( $pattern ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a (already query-string-stripped) 404 path is noise not
	 * worth logging: common attack-probe targets that were never real
	 * content, and static-asset extensions that are a different kind of
	 * problem than a dead content page. Filterable, so a site with its
	 * own unusual file types can adjust this without editing the
	 * plugin.
	 */
	public static function is_excluded_404_path( $path ) {
		$patterns = apply_filters(
			'tflg_404_log_excluded_patterns',
			array(
				'#wp-config#i',
				'#\.env#i',
				'#\.git/#i',
				'#xmlrpc\.php#i',
				'#\.(sql|bak|zip|tar|gz)$#i',
				'#\.(css|js|map|woff2?|ttf|eot|ico)$#i',
			)
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * MD5 hash used as the DB-friendly unique key for a URL. Not for
	 * security — purely a fixed-length index key.
	 */
	public static function url_hash( $url ) {
		return md5( self::normalize_for_hash( $url ) );
	}

	/**
	 * Trim only. We intentionally do NOT lowercase or otherwise rewrite the
	 * URL before hashing — paths can be case-sensitive on some servers, and
	 * silently merging distinct URLs would be worse than a rare duplicate.
	 */
	public static function normalize_for_hash( $url ) {
		return trim( (string) $url );
	}

	/**
	 * Should this URL even be looked at? Filters out things that were never
	 * "checkable" in the first place (mailto:, javascript:, same-page
	 * anchors, empty hrefs used purely for JS hooks, etc.). Filterable so a
	 * site owner can exclude their own patterns (staging subdomains, known
	 * bot-blocking partners, intentionally-fake example.com links, etc.).
	 */
	public static function is_url_checkable( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url || '#' === $url ) {
			return false;
		}

		$skip_schemes = array( 'mailto:', 'tel:', 'sms:', 'javascript:', 'data:', 'file:', 'geo:', 'skype:' );
		foreach ( $skip_schemes as $scheme ) {
			if ( 0 === stripos( $url, $scheme ) ) {
				return false;
			}
		}

		if ( 0 === strpos( $url, '#' ) ) {
			return false; // Pure same-page anchor.
		}

		// User-configurable exclusions: one substring or /regex/ per line.
		$patterns = self::get_setting( 'exclude_patterns' );
		if ( ! empty( $patterns ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $patterns ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				if ( self::looks_like_regex_line( $line ) ) {
					if ( @preg_match( $line, $url ) ) { // phpcs:ignore -- user-supplied pattern, deliberately suppressed.
						return false;
					}
				} elseif ( false !== stripos( $url, $line ) ) {
					return false;
				}
			}
		}

		/**
		 * Filter whether a discovered URL should be checked at all.
		 *
		 * @param bool   $checkable Whether the URL will be checked.
		 * @param string $url       The raw URL as found in content.
		 */
		return (bool) apply_filters( 'tflg_is_url_checkable', true, $url );
	}

	public static function looks_like_regex_line( $line ) {
		return strlen( $line ) > 2 && '/' === $line[0] && '/' === substr( $line, -1 );
	}

	/**
	 * Checks every /regex/ line in an exclude-patterns block and returns
	 * the ones that don't actually compile — so a typo can be reported
	 * back to the person instead of just silently matching nothing
	 * forever. Non-regex (plain substring) lines can't be "invalid" and
	 * are skipped.
	 */
	public static function validate_exclude_patterns( $text ) {
		$invalid = array();
		if ( empty( $text ) ) {
			return $invalid;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || ! self::looks_like_regex_line( $line ) ) {
				continue;
			}
			if ( false === @preg_match( $line, '' ) ) { // phpcs:ignore -- deliberately probing for a compile error, not matching real data.
				$invalid[] = $line;
			}
		}
		return $invalid;
	}

	/**
	 * Resolve a possibly-relative URL against the site, then decide if its
	 * host matches this site (internal) or not (external).
	 */
	public static function classify_url( $url ) {
		$absolute = self::to_absolute_url( $url );
		$site_host = self::bare_host( home_url() );
		$url_host  = self::bare_host( $absolute );

		if ( '' === $url_host || $url_host === $site_host ) {
			return array( 'type' => 'internal', 'absolute' => $absolute );
		}
		return array( 'type' => 'external', 'absolute' => $absolute );
	}

	public static function to_absolute_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return $url;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
			return ( $scheme ? $scheme : 'https' ) . ':' . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return home_url( $url );
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $url ) ) {
			// A bare string that starts with this site's own domain (with
			// or without "www.") and no scheme at all — e.g. a stray href
			// written as "www.example.com/page" or "example.com/page" —
			// is a same-site absolute reference missing only its scheme,
			// not a relative path. Treating it as relative would fold the
			// site's own domain into the middle of the resulting path
			// (a real corruption pattern this fixes). This only ever
			// recognises the site's OWN domain specifically — a stray
			// "othersite.com/page" with no scheme is genuinely ambiguous
			// (it could just as easily be a relative path that happens to
			// contain a dot), so that case is deliberately left as-is
			// rather than guessed at.
			$site_host = self::bare_host( home_url() );
			$bare      = preg_replace( '#^www\.#i', '', strtolower( $url ) );
			if ( $site_host && ( $bare === $site_host || 0 === strpos( $bare, $site_host . '/' ) ) ) {
				$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
				return ( $scheme ? $scheme : 'https' ) . '://' . ltrim( $url, '/' );
			}
			// Relative path with no leading slash (rare in stored content,
			// but handle it rather than mis-flag as broken).
			return trailingslashit( home_url() ) . ltrim( $url, './' );
		}
		return $url;
	}

	public static function bare_host( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return '';
		}
		$host = strtolower( $host );
		return ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Toggle the "www." prefix on a URL's host, keeping everything else.
	 */
	public static function toggle_www( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return null;
		}
		$host = $parts['host'];
		if ( 0 === stripos( $host, 'www.' ) ) {
			$new_host = substr( $host, 4 );
		} else {
			$new_host = 'www.' . $host;
		}
		return self::rebuild_url( $parts, array( 'host' => $new_host ) );
	}

	/**
	 * Toggle http <-> https on a URL, keeping everything else.
	 */
	public static function toggle_scheme( $url, $to = 'https' ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) ) {
			return null;
		}
		return self::rebuild_url( $parts, array( 'scheme' => $to ) );
	}

	public static function rebuild_url( $parts, $overrides = array() ) {
		$parts = array_merge( $parts, $overrides );
		$url   = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' );
		$url  .= isset( $parts['host'] ) ? $parts['host'] : '';
		$url  .= isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$url  .= isset( $parts['path'] ) ? $parts['path'] : '';
		$url  .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$url  .= isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
		return $url;
	}

	public static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Human label for a check_result + http_status pair, used throughout
	 * the admin UI.
	 */
	public static function status_label( $check_result, $http_status = null, $last_checked = null ) {
		switch ( $check_result ) {
			case 'ok':
				return $http_status ? sprintf( __( 'OK (%d)', 'trade-free-link-guardian' ), $http_status ) : __( 'OK', 'trade-free-link-guardian' );
			case 'broken':
				return $http_status ? sprintf( __( 'Broken (%d)', 'trade-free-link-guardian' ), $http_status ) : __( 'Broken', 'trade-free-link-guardian' );
			case 'unknown':
				// The DB column's own default is 'unknown', so this
				// covers two different things: a link that's never been
				// checked at all (last_checked is still empty), and one
				// that WAS checked but came back genuinely inconclusive
				// (last_checked is set) — worth telling apart, since the
				// second one already has an explanation waiting in the
				// error note and the first one doesn't.
				return $last_checked ? __( 'Unclear', 'trade-free-link-guardian' ) : __( 'Not yet checked', 'trade-free-link-guardian' );
			default:
				return __( 'Not yet checked', 'trade-free-link-guardian' );
		}
	}

	public static function fix_status_label( $status ) {
		$labels = array(
			'auto'         => __( 'Fixed automatically', 'trade-free-link-guardian' ),
			'fixed_manual' => __( 'Fixed manually', 'trade-free-link-guardian' ),
			'unlinked'     => __( 'Unlinked', 'trade-free-link-guardian' ),
			'ignored'      => __( 'Ignored', 'trade-free-link-guardian' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	public static function verify_nonce_and_cap( $nonce_action, $cap = null ) {
		if ( null === $cap ) {
			$cap = TFLG_CAPABILITY;
		}
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'trade-free-link-guardian' ) ), 403 );
		}
		check_ajax_referer( $nonce_action, 'nonce' );
	}
}

/* =========================================================================
 * SECTION: TFLG_DB — schema and low-level data access
 * ========================================================================= */

class TFLG_DB {

	public static function links_table() {
		global $wpdb;
		return $wpdb->prefix . 'tflg_links';
	}

	public static function occurrences_table() {
		global $wpdb;
		return $wpdb->prefix . 'tflg_occurrences';
	}

	public static function fixlog_table() {
		global $wpdb;
		return $wpdb->prefix . 'tflg_fixlog';
	}

	public static function snapshots_table() {
		global $wpdb;
		return $wpdb->prefix . 'tflg_health_snapshots';
	}

	public static function log_404_table() {
		global $wpdb;
		return $wpdb->prefix . 'tflg_404_log';
	}

	public static function redirects_table() {
		global $wpdb;
		return $wpdb->prefix . 'tflg_redirects';
	}

	/**
	 * Create or upgrade the custom tables via dbDelta(). Safe to call on
	 * every activation and on version bumps.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$links            = self::links_table();
		$occurrences      = self::occurrences_table();
		$fixlog           = self::fixlog_table();
		$snapshots        = self::snapshots_table();
		$log_404          = self::log_404_table();
		$redirects        = self::redirects_table();

		$sql = "CREATE TABLE {$links} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url_hash CHAR(32) NOT NULL,
			url TEXT NOT NULL,
			url_type VARCHAR(10) NOT NULL DEFAULT 'external',
			check_result VARCHAR(10) NOT NULL DEFAULT 'unknown',
			http_status SMALLINT NULL,
			final_url TEXT NULL,
			redirect_type VARCHAR(10) NOT NULL DEFAULT 'none',
			fix_status VARCHAR(15) NOT NULL DEFAULT 'none',
			error_note VARCHAR(255) NULL,
			admin_note TEXT NULL,
			last_title TEXT NULL,
			title_drift_from TEXT NULL,
			title_checked_at DATETIME NULL,
			last_h1 TEXT NULL,
			h1_drift_from TEXT NULL,
			last_checked DATETIME NULL,
			first_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY check_result (check_result),
			KEY fix_status (fix_status),
			KEY last_checked (last_checked)
		) {$charset_collate};

		CREATE TABLE {$occurrences} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id BIGINT UNSIGNED NOT NULL,
			source_type VARCHAR(20) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_key VARCHAR(191) NOT NULL DEFAULT '',
			source_label VARCHAR(255) NULL,
			anchor_text VARCHAR(255) NULL,
			attr_source VARCHAR(10) NOT NULL DEFAULT 'html',
			raw_url TEXT NOT NULL,
			scan_run BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY link_id (link_id),
			KEY source (source_type, source_id),
			KEY scan_run (scan_run)
		) {$charset_collate};

		CREATE TABLE {$fixlog} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			old_url TEXT NOT NULL,
			new_url TEXT NULL,
			method VARCHAR(20) NOT NULL,
			occurrences_affected INT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			reverted TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY link_id (link_id),
			KEY created_at (created_at)
		) {$charset_collate};

		CREATE TABLE {$snapshots} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			snapshot_date DATE NOT NULL,
			total INT UNSIGNED NOT NULL DEFAULT 0,
			broken INT UNSIGNED NOT NULL DEFAULT 0,
			unknown INT UNSIGNED NOT NULL DEFAULT 0,
			fixed INT UNSIGNED NOT NULL DEFAULT 0,
			ignored INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY snapshot_date (snapshot_date)
		) {$charset_collate};

		CREATE TABLE {$log_404} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			path_hash CHAR(32) NOT NULL,
			path TEXT NOT NULL,
			referrer VARCHAR(500) NULL,
			hit_count INT UNSIGNED NOT NULL DEFAULT 1,
			bot_hit_count INT UNSIGNED NOT NULL DEFAULT 0,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash),
			KEY last_seen (last_seen)
		) {$charset_collate};

		CREATE TABLE {$redirects} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_path_hash CHAR(32) NOT NULL,
			source_path TEXT NOT NULL,
			target_url TEXT NULL,
			status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			hit_count INT UNSIGNED NOT NULL DEFAULT 0,
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_path_hash (source_path_hash)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'tflg_db_version', TFLG_DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'tflg_db_version' ) !== TFLG_DB_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Insert a link row if it doesn't exist for this URL; otherwise leave
	 * it untouched (checking is a separate step) and return its id.
	 */
	public static function get_or_create_link( $url, $url_type ) {
		global $wpdb;
		$hash  = TFLG_Util::url_hash( $url );
		$table = self::links_table();

		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $id ) {
			return (int) $id;
		}

		$wpdb->insert(
			$table,
			array(
				'url_hash'   => $hash,
				'url'        => $url,
				'url_type'   => $url_type,
				'first_seen' => TFLG_Util::now(),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function get_link( $link_id ) {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $link_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * A free-text note the admin attaches to a link -- their own
	 * reminder ("checked with legal, keep even if it stays broken"),
	 * not anything the checker itself generates (that's error_note).
	 * An empty string clears an existing note.
	 */
	public static function update_note( $link_id, $note ) {
		global $wpdb;
		$wpdb->update( self::links_table(), array( 'admin_note' => $note ), array( 'id' => (int) $link_id ) );
	}

	/**
	 * Records the outcome of a title check: the newly-seen title always
	 * updates last_title/last_h1 and title_checked_at (so the next
	 * due-check is computed from now, drift or not); title_drift_from
	 * and h1_drift_from are set only when a value is given for that
	 * side (a genuine difference was found there) and left untouched
	 * otherwise -- an unrelated later check should never quietly clear
	 * an unacknowledged drift that's still sitting there unreviewed.
	 */
	public static function update_title_check( $link_id, $new_title, $title_drift_from = null, $new_h1 = null, $h1_drift_from = null ) {
		global $wpdb;
		$data = array(
			'last_title'       => $new_title,
			'last_h1'          => $new_h1,
			'title_checked_at' => current_time( 'mysql' ),
		);
		if ( null !== $title_drift_from ) {
			$data['title_drift_from'] = $title_drift_from;
		}
		if ( null !== $h1_drift_from ) {
			$data['h1_drift_from'] = $h1_drift_from;
		}
		$wpdb->update( self::links_table(), $data, array( 'id' => (int) $link_id ) );
	}

	public static function acknowledge_drift( $link_id ) {
		global $wpdb;
		$wpdb->update( self::links_table(), array( 'title_drift_from' => null, 'h1_drift_from' => null ), array( 'id' => (int) $link_id ) );
	}

	public static function get_link_by_url( $url ) {
		global $wpdb;
		$table = self::links_table();
		$hash  = TFLG_Util::url_hash( $url );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE url_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function update_link( $link_id, $data ) {
		global $wpdb;
		$table   = self::links_table();
		$formats = array();
		foreach ( $data as $key => $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}
		$wpdb->update( $table, $data, array( 'id' => $link_id ), $formats, array( '%d' ) );
	}

	/**
	 * Move every occurrence from one link row to another, then delete the
	 * now-empty source row. Used when a fix causes two previously-distinct
	 * URLs (e.g. http and https of the same page) to collapse into one.
	 */
	public static function merge_links( $from_link_id, $into_link_id ) {
		global $wpdb;
		$occ = self::occurrences_table();
		$wpdb->update( $occ, array( 'link_id' => $into_link_id ), array( 'link_id' => $from_link_id ), array( '%d' ), array( '%d' ) );
		$wpdb->delete( self::links_table(), array( 'id' => $from_link_id ), array( '%d' ) );
	}

	public static function replace_link_url( $link_id, $new_url ) {
		$existing = self::get_link_by_url( $new_url );
		if ( $existing && (int) $existing->id !== (int) $link_id ) {
			self::merge_links( $link_id, $existing->id );
			return (int) $existing->id;
		}
		// A fix can change which host a URL points to (most notably: an
		// internal-looking link corrected into what turns out to be a
		// genuinely external one) — url_type has to be recomputed here,
		// not just left as whatever it was before the fix, or every
		// future re-check would use the wrong structural-vs-live logic.
		$classified = TFLG_Util::classify_url( $new_url );
		self::update_link(
			$link_id,
			array(
				'url'      => $new_url,
				'url_hash' => TFLG_Util::url_hash( $new_url ),
				'url_type' => $classified['type'],
			)
		);
		return (int) $link_id;
	}

	public static function insert_occurrence( $link_id, $source_type, $source_id, $source_key, $source_label, $anchor_text, $attr_source, $raw_url, $scan_run ) {
		global $wpdb;
		$wpdb->insert(
			self::occurrences_table(),
			array(
				'link_id'      => $link_id,
				'source_type'  => $source_type,
				'source_id'    => $source_id,
				'source_key'   => $source_key,
				'source_label' => mb_substr( (string) $source_label, 0, 255 ),
				'anchor_text'  => mb_substr( (string) $anchor_text, 0, 255 ),
				'attr_source'  => $attr_source,
				'raw_url'      => $raw_url,
				'scan_run'     => $scan_run,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	public static function get_occurrences_for_link( $link_id ) {
		global $wpdb;
		$table = self::occurrences_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE link_id = %d ORDER BY id ASC", $link_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function count_occurrences_for_link( $link_id ) {
		global $wpdb;
		$table = self::occurrences_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE link_id = %d", $link_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Remove occurrence rows left over from a previous scan run that were
	 * not re-confirmed in the current run (their source was deleted, or no
	 * longer contains that link), then drop any link row left with zero
	 * occurrences.
	 */
	public static function cleanup_after_scan( $scan_run ) {
		global $wpdb;
		$occ = self::occurrences_table();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$occ} WHERE scan_run <> %d", $scan_run ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::delete_orphaned_links();
	}

	/**
	 * Removes every link with zero occurrences left — content that used
	 * to reference it no longer does, most often because a page changed
	 * between scans. This already ran as part of cleanup_after_scan()
	 * above; extracted here so it can also run on demand, independent
	 * of a full scan, since a link can sit orphaned for a while in the
	 * window before the next scan happens to clean it up — and if an
	 * auto-fix or bulk action touches one in that window, it "succeeds"
	 * while changing zero actual content, which is exactly what a
	 * "0 places affected" Fix Log entry means. Returns how many rows
	 * were actually removed.
	 */
	public static function delete_orphaned_links() {
		global $wpdb;
		$occ   = self::occurrences_table();
		$links = self::links_table();
		$wpdb->query( "DELETE l FROM {$links} l LEFT JOIN {$occ} o ON o.link_id = l.id WHERE o.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->rows_affected;
	}

	public static function count_orphaned_links() {
		global $wpdb;
		$occ   = self::occurrences_table();
		$links = self::links_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$links} l LEFT JOIN {$occ} o ON o.link_id = l.id WHERE o.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Records today's counts as one row, keyed on the calendar date —
	 * running this more than once on the same day updates that same
	 * row rather than creating a second one, so it's safe to call from
	 * more than one place (a scheduled daily tick, and also right after
	 * a scan finishes) without ending up with duplicate or conflicting
	 * entries for one day.
	 */
	public static function take_health_snapshot() {
		global $wpdb;
		$table  = self::snapshots_table();
		$counts = self::counts_by_status();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (snapshot_date, total, broken, unknown, fixed, ignored) VALUES (%s, %d, %d, %d, %d, %d) " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. 'ON DUPLICATE KEY UPDATE total = VALUES(total), broken = VALUES(broken), unknown = VALUES(unknown), fixed = VALUES(fixed), ignored = VALUES(ignored)',
				current_time( 'Y-m-d' ),
				$counts['total'],
				$counts['broken'],
				$counts['unknown'],
				$counts['auto_fixed'],
				$counts['ignored']
			)
		);
	}

	/**
	 * The last $days days of snapshots, oldest first — the order a line
	 * chart wants to draw them in. A gap on some date just means no
	 * snapshot was taken that day (the plugin wasn't active yet, or a
	 * tick was missed); nothing fills it in, since there is no honest
	 * value to put there.
	 */
	public static function get_health_snapshots( $days = 90 ) {
		global $wpdb;
		$table = self::snapshots_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE snapshot_date >= %s ORDER BY snapshot_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d', strtotime( '-' . (int) $days . ' days', current_time( 'timestamp' ) ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			)
		);
	}

	/**
	 * Keeps roughly two years of daily history — generous for spotting
	 * a trend, bounded so the table can never grow without limit. Each
	 * row is a handful of small integers, so even the full two years is
	 * a trivial amount of data; this is about having a defined limit at
	 * all, not about needing to reclaim space.
	 */
	public static function prune_old_snapshots() {
		global $wpdb;
		$table = self::snapshots_table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE snapshot_date < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d', strtotime( '-730 days', current_time( 'timestamp' ) ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			)
		);
	}

	/**
	 * Logs one 404 hit, merging into an existing row for the same path
	 * (by path_hash) rather than creating a new row per hit -- a
	 * frequently-hit dead URL stays one row with a rising hit_count,
	 * not hundreds of near-duplicate rows. A referrer already on record
	 * is kept if this particular hit didn't have one, rather than being
	 * blanked out by a later, referrer-less request for the same path.
	 * No IP address is collected or stored anywhere in this table, by
	 * design, not merely by omission.
	 */
	public static function record_404_hit( $path, $referrer, $is_bot ) {
		global $wpdb;
		$table = self::log_404_table();
		$now   = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (path_hash, path, referrer, hit_count, bot_hit_count, first_seen, last_seen) VALUES (%s, %s, %s, 1, %d, %s, %s) " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. 'ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, bot_hit_count = bot_hit_count + VALUES(bot_hit_count), last_seen = VALUES(last_seen), '
				. "referrer = IF(VALUES(referrer) IS NOT NULL AND VALUES(referrer) != '', VALUES(referrer), referrer)",
				md5( $path ),
				$path,
				( '' !== (string) $referrer ) ? $referrer : null,
				$is_bot ? 1 : 0,
				$now,
				$now
			)
		);
	}

	/**
	 * The 404 log, most-hit paths first -- the ones worth looking at
	 * before the long tail of one-off requests.
	 */
	public static function get_404_log( $limit = 200 ) {
		global $wpdb;
		$table = self::log_404_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY hit_count DESC, last_seen DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			)
		);
	}

	public static function count_404_log() {
		global $wpdb;
		$table = self::log_404_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function delete_404_log_entry( $id ) {
		global $wpdb;
		$wpdb->delete( self::log_404_table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Keeps the log from growing without bound: a one-off request not
	 * seen again in 60 days is almost certainly not a real, ongoing
	 * problem worth keeping around, matching the precedent several
	 * comparable plugins use for the same kind of log.
	 */
	public static function prune_404_log() {
		global $wpdb;
		$table = self::log_404_table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE last_seen < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', strtotime( '-60 days', current_time( 'timestamp' ) ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			)
		);
	}

	/**
	 * The one enabled redirect rule matching this exact path, or null.
	 * Exact match only, by design -- this is meant for mapping one
	 * specific known-dead URL onto one specific destination (the same
	 * thing "create a redirect" from a 404 Log entry does), not pattern
	 * or wildcard matching across many URLs at once, which is a much
	 * larger feature this deliberately doesn't try to be.
	 */
	public static function get_matching_redirect( $path ) {
		global $wpdb;
		$table = self::redirects_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_path_hash = %s AND enabled = 1 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				md5( $path )
			)
		);
	}

	public static function increment_redirect_hit( $id ) {
		global $wpdb;
		$table = self::redirects_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hit_count = hit_count + 1 WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Creates a redirect rule, or updates it in place if one already
	 * exists for this exact source path -- so re-creating a rule for a
	 * path that already has one (say, pointing it somewhere new)
	 * replaces it rather than erroring out or producing a second,
	 * conflicting row for the same path.
	 */
	public static function create_redirect( $source_path, $target_url, $status_code = 301 ) {
		global $wpdb;
		$table = self::redirects_table();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (source_path_hash, source_path, target_url, status_code, hit_count, enabled, created_at) VALUES (%s, %s, %s, %d, 0, 1, %s) " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. 'ON DUPLICATE KEY UPDATE target_url = VALUES(target_url), status_code = VALUES(status_code), enabled = 1',
				md5( $source_path ),
				$source_path,
				$target_url,
				(int) $status_code,
				current_time( 'mysql' )
			)
		);
	}

	public static function get_all_redirects() {
		global $wpdb;
		$table = self::redirects_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Every currently-enabled redirect as normalized-path => target,
	 * for TFLG_Util::redirect_creates_loop() to walk without needing
	 * its own database access.
	 */
	public static function get_redirect_map() {
		global $wpdb;
		$table = self::redirects_table();
		$rows  = $wpdb->get_results( "SELECT source_path, target_url FROM {$table} WHERE enabled = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$map   = array();
		foreach ( $rows as $row ) {
			$map[ TFLG_Util::normalize_url_for_comparison( $row->source_path ) ] = $row->target_url;
		}
		return $map;
	}

	public static function delete_redirect( $id ) {
		global $wpdb;
		$wpdb->delete( self::redirects_table(), array( 'id' => (int) $id ) );
	}

	public static function clear_occurrences_for_source( $source_type, $source_id, $source_key = '' ) {
		global $wpdb;
		$occ = self::occurrences_table();
		$wpdb->delete( $occ, array( 'source_type' => $source_type, 'source_id' => $source_id, 'source_key' => $source_key ) );
	}

	/**
	 * Marks a source's existing occurrence rows as "seen" in the current
	 * scan run without touching their content — used when a source is
	 * provably unchanged since the last scan, so cleanup_after_scan()
	 * doesn't mistake its untouched rows for stale ones and delete them.
	 * Returns true if any rows existed to touch.
	 */
	public static function touch_occurrences_for_source( $source_type, $source_id, $source_key, $scan_run ) {
		global $wpdb;
		$updated = $wpdb->update(
			self::occurrences_table(),
			array( 'scan_run' => $scan_run ),
			array(
				'source_type' => $source_type,
				'source_id'   => $source_id,
				'source_key'  => $source_key,
			),
			array( '%d' ),
			array( '%s', '%d', '%s' )
		);
		return false !== $updated;
	}

	/**
	 * The same status/search interpretation the Links admin table uses,
	 * factored out so a bulk action that needs to operate on "everything
	 * matching the current filter" (not just the current page) can reuse
	 * it exactly, rather than risk a second copy drifting out of sync
	 * with what the table actually shows.
	 */
	public static function build_filter_where( $status, $search ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		switch ( $status ) {
			case 'broken':
				$where[] = "check_result = 'broken' AND fix_status = 'none'";
				break;
			case 'unknown':
				$where[] = "check_result = 'unknown' AND fix_status = 'none'";
				break;
			case 'fixed':
				$where[] = "fix_status IN ('auto','fixed_manual','unlinked')";
				break;
			case 'ignored':
				$where[] = "fix_status = 'ignored'";
				break;
		}

		if ( '' !== $search ) {
			$where[]  = 'url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		return array( implode( ' AND ', $where ), $params );
	}

	public static function count_links_matching( $status, $search ) {
		global $wpdb;
		list( $where_sql, $params ) = self::build_filter_where( $status, $search );
		$table = self::links_table();
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Full rows matching the filter, with id > $after_id as a cursor
	 * rather than a page offset — correct even while the set of matching
	 * rows is shrinking as a bulk action processes them (an item that
	 * just got ignored, for instance, stops matching a "needs attention"
	 * filter on the very next call), which an offset would not handle
	 * cleanly.
	 */
	public static function get_links_matching( $status, $search, $after_id, $limit ) {
		global $wpdb;
		list( $where_sql, $params ) = self::build_filter_where( $status, $search );
		$table      = self::links_table();
		$where_sql .= ' AND id > %d';
		$params[]   = (int) $after_id;
		$sql        = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id ASC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[]   = (int) $limit;
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function counts_by_status() {
		global $wpdb;
		$table = self::links_table();
		$rows  = $wpdb->get_results( "SELECT check_result, fix_status, COUNT(*) as c FROM {$table} GROUP BY check_result, fix_status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery

		$out = array(
			'total'      => 0,
			'broken'     => 0,
			'ok'         => 0,
			'unknown'    => 0,
			'auto_fixed' => 0,
			'ignored'    => 0,
		);
		foreach ( $rows as $row ) {
			$out['total'] += (int) $row->c;
			if ( 'ignored' === $row->fix_status ) {
				$out['ignored'] += (int) $row->c;
			} elseif ( 'auto' === $row->fix_status ) {
				$out['auto_fixed'] += (int) $row->c;
			} elseif ( 'broken' === $row->check_result ) {
				$out['broken'] += (int) $row->c;
			} elseif ( 'ok' === $row->check_result ) {
				$out['ok'] += (int) $row->c;
			} else {
				$out['unknown'] += (int) $row->c;
			}
		}
		return $out;
	}

	/**
	 * Given a list of URLs (typically just extracted from a post being
	 * saved, before this plugin's own scanner has ever looked at that
	 * specific save), returns which of them this plugin already knows,
	 * from past checking, to be broken. An empty list in means nothing
	 * to check and an empty list out; this never re-checks anything
	 * live, it only asks what's already on record.
	 */
	public static function get_known_broken_among( $urls ) {
		if ( empty( $urls ) ) {
			return array();
		}
		global $wpdb;
		$table        = self::links_table();
		$placeholders = implode( ', ', array_fill( 0, count( $urls ), '%s' ) );
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT url FROM {$table} WHERE check_result = 'broken' AND url IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$urls
			)
		);
	}

	/**
	 * The numbers a compliance or audit summary actually needs, for a
	 * given date range: fixes applied that are still in effect (a
	 * later-reverted fix isn't counted as a real fix, since it no
	 * longer is one), broken down by method, and how many distinct
	 * dead pages the passive 404 log first noticed in that window.
	 * Deliberately does not attempt a "time to fix" figure -- this
	 * plugin has no reliable record of the moment a link first went
	 * bad, only when it was last checked, so a duration computed from
	 * that would be a guess dressed up as a number, not something
	 * to put in front of an auditor.
	 */
	public static function get_compliance_summary( $start_date, $end_date ) {
		global $wpdb;
		$fixlog  = self::fixlog_table();
		$log_404 = self::log_404_table();

		$fixes_by_method = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT method, COUNT(*) as c FROM {$fixlog} WHERE reverted = 0 AND created_at BETWEEN %s AND %s GROUP BY method", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$start_date,
				$end_date
			)
		);

		$new_404_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$log_404} WHERE first_seen BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$start_date,
				$end_date
			)
		);

		$total_fixes = 0;
		foreach ( $fixes_by_method as $row ) {
			$total_fixes += (int) $row->c;
		}

		return array(
			'fixes_by_method' => $fixes_by_method,
			'total_fixes'     => $total_fixes,
			'new_404_count'   => $new_404_count,
		);
	}

	public static function insert_fixlog( $link_id, $old_url, $new_url, $method, $occurrences_affected ) {
		global $wpdb;
		$wpdb->insert(
			self::fixlog_table(),
			array(
				'link_id'               => $link_id,
				'old_url'               => $old_url,
				'new_url'               => $new_url,
				'method'                => $method,
				'occurrences_affected' => $occurrences_affected,
				'user_id'               => get_current_user_id(),
				'created_at'            => TFLG_Util::now(),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function get_fixlog_entry( $id ) {
		global $wpdb;
		$table = self::fixlog_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function mark_fixlog_reverted( $id ) {
		global $wpdb;
		$wpdb->update( self::fixlog_table(), array( 'reverted' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	public static function get_recent_fixlog( $limit = 20 ) {
		global $wpdb;
		$table = self::fixlog_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The most recent not-yet-reverted fixlog entry for one specific
	 * link, if any — used to offer an Undo option next to that link
	 * wherever it's shown individually (the book view), not just in the
	 * chronological Fix Log itself. Excludes the same methods
	 * revert_fix() itself refuses to auto-revert (ignore, restore_post,
	 * unlink), so this never offers an Undo that would just fail.
	 */
	public static function get_latest_undoable_fixlog_for_link( $link_id ) {
		global $wpdb;
		$table = self::fixlog_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE link_id = %d AND reverted = 0 AND method NOT IN ('ignore','restore_post','unlink') ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$link_id
			)
		);
	}

	/**
	 * Every distinct place links were found — one row per source
	 * (a post, a menu item, a widget), each with how many distinct
	 * links it contains. The basis for the book view's table of
	 * contents and its one-source-per-page navigation.
	 */
	public static function get_all_sources() {
		global $wpdb;
		$occ = self::occurrences_table();
		return $wpdb->get_results(
			"SELECT source_type, source_id, source_key, MAX(source_label) AS source_label, COUNT(DISTINCT link_id) AS link_count " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			. "FROM {$occ} GROUP BY source_type, source_id, source_key ORDER BY source_label ASC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * The same one-row-per-source grouping as get_all_sources(), with a
	 * health breakdown added — how many of each source's distinct links
	 * are broken or unclear, alongside the total. Counts follow the
	 * same COUNT(DISTINCT link_id) convention get_all_sources() already
	 * uses, so the same link appearing twice on one page is never
	 * double-counted here either. The basis for the Health Map.
	 */
	public static function get_source_health_summary() {
		global $wpdb;
		$occ   = self::occurrences_table();
		$links = self::links_table();
		return $wpdb->get_results(
			"SELECT o.source_type, o.source_id, o.source_key, MAX(o.source_label) AS source_label, " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			. "COUNT(DISTINCT o.link_id) AS total, "
			. "COUNT(DISTINCT CASE WHEN l.check_result = 'broken' THEN o.link_id END) AS broken_count, "
			. "COUNT(DISTINCT CASE WHEN l.check_result = 'unknown' THEN o.link_id END) AS unknown_count "
			. "FROM {$occ} o JOIN {$links} l ON l.id = o.link_id " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			. 'GROUP BY o.source_type, o.source_id, o.source_key'
		);
	}

	/**
	 * Raw url + check_result for every external link — deliberately
	 * just the two columns needed, and only external ones, since every
	 * internal link shares the exact same one destination host (this
	 * site itself), so grouping those by domain would only ever produce
	 * a single, meaningless group. The grouping itself happens in PHP
	 * (see TFLG_Util::group_links_by_domain()) rather than in SQL, since
	 * reliable host-from-URL extraction is what wp_parse_url() is for,
	 * not something worth reproducing as fragile SQL string surgery.
	 */
	public static function get_external_links_for_grouping() {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->get_results( "SELECT url, check_result FROM {$table} WHERE url_type = 'external'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Every internal URL this plugin has recorded as appearing as a
	 * link somewhere -- from whatever this site scans (content, and
	 * menus/widgets if those are enabled), which is exactly the
	 * "something links here" side of the orphan-page comparison.
	 */
	public static function get_internal_link_targets() {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->get_col( "SELECT url FROM {$table} WHERE url_type = 'internal'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Every occurrence on one specific source, each joined with its
	 * link's current, live data — so the book view can show the
	 * original as-found URL (raw_url) right alongside whatever it looks
	 * like now (possibly corrected) in a single query per page.
	 */
	public static function get_occurrences_for_source( $source_type, $source_id, $source_key ) {
		global $wpdb;
		$occ   = self::occurrences_table();
		$links = self::links_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id AS occurrence_id, o.raw_url, o.anchor_text, o.attr_source, " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. "l.id AS link_id, l.url AS current_url, l.url_type, l.check_result, l.http_status, l.error_note, l.fix_status, l.final_url, l.last_checked "
				. "FROM {$occ} o JOIN {$links} l ON l.id = o.link_id "
				. "WHERE o.source_type = %s AND o.source_id = %d AND o.source_key = %s ORDER BY o.id ASC",
				$source_type,
				$source_id,
				$source_key
			)
		);
	}

	public static function get_links_due_for_check( $hours, $limit ) {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE fix_status <> 'ignored' AND (last_checked IS NULL OR last_checked < %s) ORDER BY (last_checked IS NULL) DESC, last_checked ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - ( (int) $hours * HOUR_IN_SECONDS ) ),
				$limit
			)
		);
	}

	public static function count_links_due_for_check( $hours ) {
		global $wpdb;
		$table = self::links_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE fix_status <> 'ignored' AND (last_checked IS NULL OR last_checked < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - ( (int) $hours * HOUR_IN_SECONDS ) )
			)
		);
	}

	public static function drop_tables() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::occurrences_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::links_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::fixlog_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::snapshots_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::log_404_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::redirects_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}

/* =========================================================================
 * SECTION: TFLG_Scanner — discovers content and extracts links from it
 * ========================================================================= */

class TFLG_Scanner {

	/**
	 * Which post types get scanned. Always includes the block-based
	 * Navigation post type when it exists, regardless of settings, since
	 * that's structurally "menus" even though it's stored as a CPT.
	 */
	public static function get_scannable_post_types() {
		$configured = TFLG_Util::get_setting( 'post_types' );
		if ( ! is_array( $configured ) || empty( $configured ) ) {
			$configured = array( 'post', 'page' );
		}
		$types = array_values( array_unique( array_filter( $configured, 'post_type_exists' ) ) );
		if ( post_type_exists( 'wp_navigation' ) && ! in_array( 'wp_navigation', $types, true ) ) {
			$types[] = 'wp_navigation';
		}
		/**
		 * Let a developer register additional public post types to scan,
		 * or additional custom sources entirely (see tflg_extra_queue_items).
		 */
		return apply_filters( 'tflg_scannable_post_types', $types );
	}

	/**
	 * Every published page (WordPress's own natively hierarchical post
	 * type, unlike posts, which are flat) with what a sitemap tree
	 * needs: its id, title, URL path, and its own parent's id. Feeds
	 * directly into TFLG_Util::build_page_trees().
	 */
	public static function get_page_hierarchy_data() {
		$data = array();
		$q    = new WP_Query(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'orderby'                => 'title',
				'order'                  => 'ASC',
			)
		);
		foreach ( $q->posts as $post ) {
			$path = (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH );
			$data[] = array(
				'id'     => $post->ID,
				'title'  => get_the_title( $post ),
				'path'   => $path,
				'parent' => (int) $post->post_parent,
			);
		}
		return $data;
	}

	/**
	 * Post types a site owner can choose from in Settings (public, with an
	 * editor, minus attachments — those are handled as internal-link
	 * targets, not as sources).
	 */
	public static function get_selectable_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$out   = array();
		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$out[ $type->name ] = $type->label;
		}
		return $out;
	}

	public static function list_widget_instances() {
		$out              = array();
		$sidebars_widgets = wp_get_sidebars_widgets();
		if ( ! is_array( $sidebars_widgets ) ) {
			return $out;
		}
		$seen = array();
		foreach ( $sidebars_widgets as $widget_ids ) {
			if ( ! is_array( $widget_ids ) ) {
				continue;
			}
			foreach ( $widget_ids as $widget_id ) {
				if ( isset( $seen[ $widget_id ] ) ) {
					continue;
				}
				$seen[ $widget_id ] = true;
				if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
					continue;
				}
				$out[] = array( 'base' => $m[1], 'number' => (int) $m[2] );
			}
		}
		return $out;
	}

	public static function get_widget_content( $base, $number ) {
		$instances = get_option( 'widget_' . $base );
		if ( ! is_array( $instances ) || ! isset( $instances[ $number ] ) || ! is_array( $instances[ $number ] ) ) {
			return '';
		}
		$instance = $instances[ $number ];
		if ( isset( $instance['text'] ) && is_string( $instance['text'] ) ) {
			return $instance['text'];
		}
		if ( isset( $instance['content'] ) && is_string( $instance['content'] ) ) {
			return $instance['content'];
		}
		$fallback = '';
		foreach ( $instance as $value ) {
			if ( is_string( $value ) && false !== stripos( $value, 'href' ) ) {
				$fallback .= ' ' . $value;
			}
		}
		return $fallback;
	}

	public static function save_widget_content( $base, $number, $new_content ) {
		$option_name = 'widget_' . $base;
		$instances   = get_option( $option_name );
		if ( ! is_array( $instances ) || ! isset( $instances[ $number ] ) ) {
			return false;
		}
		if ( isset( $instances[ $number ]['text'] ) ) {
			$instances[ $number ]['text'] = $new_content;
		} elseif ( isset( $instances[ $number ]['content'] ) ) {
			$instances[ $number ]['content'] = $new_content;
		} else {
			return false;
		}
		return update_option( $option_name, $instances );
	}

	/**
	 * Build the full work queue for a scan run. Kept lightweight (IDs
	 * only) so even large sites produce a manageable array.
	 */
	public static function build_queue() {
		$queue      = array();
		$post_types = self::get_scannable_post_types();

		if ( ! empty( $post_types ) ) {
			$q = new WP_Query(
				array(
					'post_type'              => $post_types,
					'post_status'            => array( 'publish', 'private' ),
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
				)
			);
			foreach ( $q->posts as $post_id ) {
				$queue[] = array( 'type' => 'post', 'id' => (int) $post_id, 'key' => '' );
			}
		}

		if ( TFLG_Util::get_setting( 'check_menus' ) ) {
			$items = get_posts(
				array(
					'post_type'   => 'nav_menu_item',
					'post_status' => 'publish',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			);
			foreach ( $items as $item_id ) {
				if ( 'custom' === get_post_meta( $item_id, '_menu_item_type', true ) ) {
					$queue[] = array( 'type' => 'nav_menu_item', 'id' => (int) $item_id, 'key' => '' );
				}
			}
		}

		if ( TFLG_Util::get_setting( 'check_widgets' ) ) {
			foreach ( self::list_widget_instances() as $w ) {
				$queue[] = array( 'type' => 'widget', 'id' => $w['number'], 'key' => $w['base'] );
			}
		}

		/**
		 * Let a developer append custom sources (theme mods, ACF fields,
		 * anything site-specific) without touching this file. Each item:
		 * array('type' => 'custom:my_source', 'id' => 123, 'key' => '').
		 * Handle the matching 'type' via the tflg_scan_custom_source action.
		 */
		$queue = apply_filters( 'tflg_scan_queue', $queue );

		return $queue;
	}

	public static function start() {
		$run   = time();
		$queue = self::build_queue();
		set_transient( 'tflg_scan_queue_' . $run, $queue, HOUR_IN_SECONDS );
		update_option(
			'tflg_scan_state',
			array(
				'run'   => $run,
				'total' => count( $queue ),
				'done'  => 0,
				'phase' => 'scanning',
			),
			false
		);
		return array(
			'run'   => $run,
			'total' => count( $queue ),
		);
	}

	public static function process_batch( $batch_size = null ) {
		$state = get_option( 'tflg_scan_state' );
		if ( ! is_array( $state ) || 'scanning' !== ( isset( $state['phase'] ) ? $state['phase'] : '' ) ) {
			return array(
				'done'  => 0,
				'total' => 0,
				'phase' => 'idle',
			);
		}

		$run   = $state['run'];
		$queue = get_transient( 'tflg_scan_queue_' . $run );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		if ( null === $batch_size ) {
			$batch_size = max( 1, (int) TFLG_Util::get_setting( 'batch_size' ) );
		}

		$batch = array_splice( $queue, 0, $batch_size );
		$items = array();
		foreach ( $batch as $item ) {
			$label = self::scan_source( $item['type'], $item['id'], $item['key'], $run );
			if ( $label ) {
				$items[] = $label;
			}
		}

		set_transient( 'tflg_scan_queue_' . $run, $queue, HOUR_IN_SECONDS );
		$state['done'] += count( $batch );
		$state['items'] = $items;

		if ( empty( $queue ) ) {
			$state['phase'] = 'done';
			TFLG_DB::cleanup_after_scan( $run );
			delete_transient( 'tflg_scan_queue_' . $run );
			update_option( 'tflg_last_scan_time', TFLG_Util::now(), false );
		}

		update_option( 'tflg_scan_state', $state, false );
		return $state;
	}

	public static function scan_source( $type, $id, $key, $scan_run ) {
		switch ( $type ) {
			case 'post':
				return self::scan_post( (int) $id, $scan_run );
			case 'nav_menu_item':
				return self::scan_menu_item( (int) $id, $scan_run );
			case 'widget':
				return self::scan_widget( $key, (int) $id, $scan_run );
			default:
				/**
				 * Fires for queue items whose type this class does not
				 * itself understand (see the tflg_scan_queue filter).
				 * A developer's callback is responsible for calling
				 * TFLG_Scanner::record_links_for_source() itself.
				 */
				do_action( 'tflg_scan_custom_source', $type, $id, $key, $scan_run );
				return null;
		}
	}

	/**
	 * Each scan_* method returns a short human label for what it just
	 * processed (or null if there was nothing to do) — process_batch()
	 * collects these so the admin UI can show live proof of progress
	 * rather than just a number ticking up.
	 */
	public static function scan_post( $post_id, $scan_run ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		// If this exact post content was already scanned under the exact
		// same extraction settings, nothing new could possibly be found —
		// skip straight to confirming its existing occurrences are still
		// current for this run, rather than re-parsing unchanged content.
		$fingerprint = $post->post_modified_gmt . '|' . self::extraction_settings_fingerprint();
		if ( $fingerprint === get_post_meta( $post_id, '_tflg_last_scan_fingerprint', true ) ) {
			TFLG_DB::touch_occurrences_for_source( 'post', $post_id, '', $scan_run );
			return $post->post_title ? $post->post_title : ( '#' . $post_id );
		}

		$found = array();
		foreach ( self::extract_from_html( $post->post_content ) as $item ) {
			$found[ $item['url'] ] = $item;
		}
		if ( function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
			foreach ( self::extract_from_blocks( $post->post_content ) as $url ) {
				if ( ! isset( $found[ $url ] ) ) {
					$found[ $url ] = array(
						'url'         => $url,
						'anchor_text' => '',
						'attr_source' => 'block-attr',
					);
				}
			}
		}
		self::record_links_for_source( 'post', $post_id, '', $post->post_title, $found, $scan_run );
		update_post_meta( $post_id, '_tflg_last_scan_fingerprint', $fingerprint );
		return $post->post_title ? $post->post_title : ( '#' . $post_id );
	}

	/**
	 * A short hash of every setting that affects what extraction finds
	 * or keeps — check_images changes which tags are examined at all,
	 * exclude_patterns changes what survives filtering. Folded into the
	 * unchanged-content fast path above so a settings change is never
	 * masked by "the post itself didn't change."
	 */
	protected static function extraction_settings_fingerprint() {
		return md5(
			wp_json_encode(
				array(
					TFLG_Util::get_setting( 'check_images' ),
					TFLG_Util::get_setting( 'exclude_patterns' ),
				)
			)
		);
	}

	public static function scan_menu_item( $item_id, $scan_run ) {
		$url = get_post_meta( $item_id, '_menu_item_url', true );
		if ( ! $url ) {
			return null;
		}
		$title = get_the_title( $item_id );
		$found = array(
			$url => array(
				'url'         => $url,
				'anchor_text' => $title,
				'attr_source' => 'menu',
			),
		);
		self::record_links_for_source( 'nav_menu_item', $item_id, '', $title ? $title : $url, $found, $scan_run );
		return $title ? $title : $url;
	}

	public static function scan_widget( $base, $number, $scan_run ) {
		$content = self::get_widget_content( $base, $number );
		if ( '' === trim( (string) $content ) ) {
			return null;
		}
		$found = array();
		foreach ( self::extract_from_html( $content ) as $item ) {
			$found[ $item['url'] ] = $item;
		}
		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			foreach ( self::extract_from_blocks( $content ) as $url ) {
				if ( ! isset( $found[ $url ] ) ) {
					$found[ $url ] = array(
						'url'         => $url,
						'anchor_text' => '',
						'attr_source' => 'block-attr',
					);
				}
			}
		}
		/* translators: %s: widget type slug, e.g. "text" or "custom_html" */
		$label = sprintf( __( 'Widget (%s)', 'trade-free-link-guardian' ), $base );
		self::record_links_for_source( 'widget', $number, $base, $label, $found, $scan_run );
		return $label;
	}

	public static function record_links_for_source( $source_type, $source_id, $source_key, $source_label, $found, $scan_run ) {
		TFLG_DB::clear_occurrences_for_source( $source_type, $source_id, $source_key );
		foreach ( $found as $item ) {
			$raw_url = trim( $item['url'] );
			if ( ! TFLG_Util::is_url_checkable( $raw_url ) ) {
				continue;
			}
			$classified = TFLG_Util::classify_url( $raw_url );
			$link_id    = TFLG_DB::get_or_create_link( $classified['absolute'], $classified['type'] );
			TFLG_DB::insert_occurrence(
				$link_id,
				$source_type,
				$source_id,
				$source_key,
				$source_label,
				isset( $item['anchor_text'] ) ? $item['anchor_text'] : '',
				isset( $item['attr_source'] ) ? $item['attr_source'] : 'html',
				$raw_url,
				$scan_run
			);
		}
	}

	/* -----------------------------------------------------------------
	 * Extraction
	 * --------------------------------------------------------------- */

	/**
	 * Parse an HTML fragment with DOMDocument (far more forgiving of
	 * real-world markup than a regex) and pull href/src plus, for anchors,
	 * their visible text.
	 */
	// 5 MB is far beyond any real post's HTML — this exists purely so an
	// exceptional, extremely large piece of content gets the lighter
	// regex-based extraction below instead of a full DOM parse, rather
	// than slowing an otherwise-fast scan of many other, ordinary posts.
	const MAX_HTML_BYTES_FOR_DOM = 5242880;

	public static function extract_from_html( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) || false === stripos( $html, '<' ) ) {
			return array();
		}
		if ( ! class_exists( 'DOMDocument' ) || strlen( $html ) > self::MAX_HTML_BYTES_FOR_DOM ) {
			return self::extract_from_html_regex_fallback( $html );
		}

		$out  = array();
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadHTML(
			'<?xml encoding="UTF-8"?><div id="tflg-root">' . $html . '</div>',
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			return self::extract_from_html_regex_fallback( $html );
		}

		foreach ( $doc->getElementsByTagName( 'a' ) as $node ) {
			$href = $node->getAttribute( 'href' );
			if ( '' === trim( $href ) ) {
				continue;
			}
			$out[] = array(
				'url'         => $href,
				'anchor_text' => trim( preg_replace( '/\s+/', ' ', $node->textContent ) ),
				'attr_source' => 'html',
			);
		}

		if ( TFLG_Util::get_setting( 'check_images' ) ) {
			foreach ( $doc->getElementsByTagName( 'img' ) as $node ) {
				$src = $node->getAttribute( 'src' );
				if ( '' === trim( $src ) || 0 === strpos( $src, 'data:' ) ) {
					continue;
				}
				$out[] = array(
					'url'         => $src,
					'anchor_text' => $node->getAttribute( 'alt' ),
					'attr_source' => 'html',
				);
			}
		}

		return $out;
	}

	/**
	 * Last-resort extraction if the DOM extension isn't available. Less
	 * accurate (no real HTML awareness) but never worse than finding
	 * nothing.
	 */
	private static function extract_from_html_regex_fallback( $html ) {
		$out = array();
		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$out[] = array(
					'url'         => html_entity_decode( $m[1] ),
					'anchor_text' => trim( wp_strip_all_tags( $m[2] ) ),
					'attr_source' => 'html',
				);
			}
		}
		return $out;
	}

	/**
	 * Walk the parsed block tree looking for URL-shaped values under
	 * url/href/link-ish attribute keys. This is what catches dynamic
	 * blocks (e.g. some Navigation Link configurations) whose saved
	 * markup has no literal <a href> for the HTML pass above to find.
	 */
	public static function extract_from_blocks( $content ) {
		$blocks = parse_blocks( $content );
		$out    = array();
		self::walk_blocks_for_urls( $blocks, $out, 0 );
		return array_values( array_unique( $out ) );
	}

	// Depth caps are generous relative to any real content (ordinary
	// posts nest a handful of levels deep at most) — they exist purely
	// to bound recursion against pathologically deep, adversarially
	// crafted block/attribute structures, not to limit normal use.
	const MAX_BLOCK_NESTING = 50;
	const MAX_ATTR_NESTING  = 20;

	private static function walk_blocks_for_urls( $blocks, array &$out, $depth ) {
		if ( $depth > self::MAX_BLOCK_NESTING ) {
			return;
		}
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				self::walk_attrs_for_urls( $block['attrs'], $out, 0 );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_blocks_for_urls( $block['innerBlocks'], $out, $depth + 1 );
			}
		}
	}

	private static function walk_attrs_for_urls( $attrs, array &$out, $depth ) {
		if ( $depth > self::MAX_ATTR_NESTING ) {
			return;
		}
		foreach ( $attrs as $key => $value ) {
			if ( is_array( $value ) ) {
				self::walk_attrs_for_urls( $value, $out, $depth + 1 );
				continue;
			}
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$key_looks_like_url_field = (bool) preg_match( '/(url|href|^link$|linkurl)/i', (string) $key );
			$value_looks_like_url     = ( 0 === strpos( $value, 'http://' ) || 0 === strpos( $value, 'https://' ) || 0 === strpos( $value, '/' ) );
			if ( $key_looks_like_url_field && $value_looks_like_url ) {
				$out[] = $value;
			}
		}
	}
}

/* =========================================================================
 * SECTION: TFLG_Checker — decides whether a URL is alive
 * ========================================================================= */

class TFLG_Checker {

	/**
	 * Checks up to $limit due links, but never runs past $time_budget
	 * seconds regardless of how many are left — real external checks can
	 * each take up to the HTTP timeout, so an unbounded batch risks
	 * exceeding the server's own execution-time limit and dying with no
	 * feedback. The caller (AJAX handler) just asks again for the rest.
	 *
	 * Returns both a count and the individual results, so the admin UI
	 * can show live proof of what was just checked rather than just a
	 * number ticking up.
	 */
	/**
	 * Checks up to $limit due links, but never runs past $time_budget
	 * seconds regardless of how many are left — real external checks can
	 * each take up to the HTTP timeout, so an unbounded batch risks
	 * exceeding the server's own execution-time limit and dying with no
	 * feedback. The caller (AJAX handler) just asks again for the rest.
	 *
	 * Internal links are usually resolved structurally (fast, no HTTP)
	 * and are checked one at a time as before. External links — where
	 * checking used to spend nearly all its time — are checked in
	 * concurrent groups instead of one after another.
	 *
	 * Returns both a count and the individual results, so the admin UI
	 * can show live proof of what was just checked rather than just a
	 * number ticking up.
	 */
	public static function check_due_links( $limit, $time_budget = 20.0 ) {
		$hours = max( 1, (int) TFLG_Util::get_setting( 'recheck_after_hours' ) );
		$links = TFLG_DB::get_links_due_for_check( $hours, $limit );
		return self::check_link_rows( $links, $time_budget );
	}

	/**
	 * Concurrently checks a specific, already-fetched set of link rows —
	 * the shared core check_due_links() above uses for "whatever is due
	 * for a recheck," and a bulk action on the Links screen (or anything
	 * else that already knows exactly which rows it means) can reuse
	 * directly for "check exactly these, right now, regardless of
	 * timing." Same internal-probe routing, same host interleaving, same
	 * time-budget safety either way — extracted here so both callers
	 * share one implementation instead of two that could drift apart.
	 */
	public static function check_link_rows( array $links, $time_budget = 20.0 ) {
		$start = microtime( true );
		$items = array();

		// Internal links are resolved structurally wherever possible —
		// fast, no HTTP at all — one at a time, since there's no request
		// to batch. Only the ones that fall through (not a known post,
		// not an uploaded file) actually need a live probe, and those go
		// into the same concurrent pool as genuine external links below.
		// Getting this split right matters: earlier versions sent every
		// such internal link through a live probe one at a time even
		// though the probe itself uses the same concurrent-capable
		// mechanics as an external check — a real, sequential bottleneck
		// hiding behind what looked like an internal/external split.
		$needs_probe = array();
		$verify_internal = TFLG_Util::get_setting( 'verify_internal_via_http' );

		foreach ( $links as $link ) {
			if ( 'internal' !== $link->url_type ) {
				$needs_probe[] = $link;
				continue;
			}

			$structural = self::check_internal_structural( $link->url );
			if ( null === $structural ) {
				if ( $verify_internal ) {
					$needs_probe[] = $link;
					continue;
				}
				$structural = self::unverifiable_internal_result();
			}

			self::apply_check_result( $link, $structural );
			$items[] = array(
				'url'    => $link->url,
				'result' => $structural['check_result'],
				'status' => $structural['http_status'],
			);
			if ( ( microtime( true ) - $start ) >= $time_budget ) {
				return array(
					'done'  => count( $items ),
					'items' => $items,
				);
			}
		}

		// The time-budget check below runs once per group, on the
		// assumption that a group finishes in roughly one request's
		// worth of time because it ran concurrently. That assumption
		// only holds if concurrency is actually available — if it isn't,
		// check_external_batch() quietly falls back to checking that
		// same group one at a time internally, and a "group" of 8 could
		// then take up to 8x as long with no chance for the time budget
		// to intervene until the whole group finally finishes. Chunking
		// to size 1 in that case restores the original fine-grained,
		// per-link budget check instead of a coarse, misleading one.
		$concurrency = TFLG_Checker::concurrency_available()
			? max( 1, (int) TFLG_Util::get_setting( 'check_concurrency' ) )
			: 1;
		// A batch of same-host links found back-to-back in content
		// (common — several links to related pages on one institution's
		// site) would otherwise land in the same concurrent wave and
		// all fire at once: a small, real burst against one destination
		// even though the overall request rate across every destination
		// combined is entirely reasonable. Spreading same-host items
		// apart first means a wave only contains more than one of the
		// same host when there genuinely isn't enough variety to avoid
		// it — good manners cost nothing here, since checking is exactly
		// as fast either way.
		if ( $concurrency > 1 ) {
			$needs_probe = self::interleave_by_host( $needs_probe );
		}
		foreach ( array_chunk( $needs_probe, $concurrency ) as $group ) {
			$urls    = wp_list_pluck( $group, 'url' );
			$results = self::check_external_batch( $urls );
			foreach ( $group as $link ) {
				$result = isset( $results[ $link->url ] ) ? $results[ $link->url ] : self::blank_result( 'broken' );
				if ( 'internal' === $link->url_type && 'broken' === $result['check_result'] ) {
					// Same reinterpretation check_internal() applies to a
					// single-link probe: a failed self-check means "not
					// verifiable," not "confirmed broken."
					$result['check_result'] = 'unknown';
					$result['error_note']   = __( 'Not a known post or file, and a direct self-check did not resolve it either — worth a manual look.', 'trade-free-link-guardian' );
				}
				self::apply_check_result( $link, $result );
				$items[] = array(
					'url'    => $link->url,
					'result' => $result['check_result'],
					'status' => $result['http_status'],
				);
			}
			if ( ( microtime( true ) - $start ) >= $time_budget ) {
				break;
			}
		}

		return array(
			'done'  => count( $items ),
			'items' => $items,
		);
	}

	/**
	 * Round-robins a list of link rows across distinct hosts — one item
	 * from each host in turn — so consecutive items in the reordered
	 * list are spread across different destinations wherever there's
	 * enough variety to do so. Pure reordering; nothing here decides
	 * what gets checked, only what order it happens in.
	 */
	protected static function interleave_by_host( array $links ) {
		$by_host = array();
		foreach ( $links as $link ) {
			$host = TFLG_Util::bare_host( $link->url );
			$by_host[ $host ][] = $link;
		}
		$out = array();
		while ( ! empty( $by_host ) ) {
			foreach ( array_keys( $by_host ) as $host ) {
				$out[] = array_shift( $by_host[ $host ] );
				if ( empty( $by_host[ $host ] ) ) {
					unset( $by_host[ $host ] );
				}
			}
		}
		return $out;
	}

	public static function check_link( $link ) {
		$result = ( 'internal' === $link->url_type )
			? self::check_internal( $link->url )
			: self::check_external( $link->url );
		return self::apply_check_result( $link, $result );
	}

	/**
	 * Persists an already-computed result for a link (from either the
	 * single-URL or the concurrent-batch checking path) and fires the
	 * same after-check action either way.
	 */
	private static function apply_check_result( $link, $result ) {
		TFLG_DB::update_link(
			$link->id,
			array(
				'check_result'  => $result['check_result'],
				'http_status'   => $result['http_status'],
				'final_url'     => $result['final_url'],
				'redirect_type' => $result['redirect_type'],
				'error_note'    => $result['error_note'] ? mb_substr( $result['error_note'], 0, 255 ) : null,
				'last_checked'  => TFLG_Util::now(),
			)
		);

		/**
		 * Fires after a link has been (re)checked. $result includes
		 * check_result, http_status, final_url, redirect_type, error_note.
		 */
		do_action( 'tflg_after_check_link', $link->id, $result );

		return $result;
	}

	/**
	 * Content-drift check: does the page at a working external link
	 * still look like the same page? This is deliberately not part of
	 * the regular check above -- that one uses HEAD requests (or a
	 * 1-byte-range GET as its own fallback) specifically to avoid
	 * downloading page bodies at all, so this needs a real, separate
	 * GET every time it runs. To keep that added cost modest rather
	 * than doubling every single check, it only ever runs for links
	 * that are currently OK, external, and past their own, much
	 * longer-spaced interval (default 30 days) -- not on every check
	 * cycle. Off by default; only runs at all once explicitly enabled.
	 */
	public static function maybe_check_drift( $link_id, $result ) {
		if ( ! TFLG_Util::get_setting( 'enable_drift_detection' ) ) {
			return;
		}
		if ( 'ok' !== $result['check_result'] ) {
			return;
		}
		$link = TFLG_DB::get_link( $link_id );
		if ( ! $link || 'external' !== $link->url_type ) {
			return;
		}
		if ( $link->title_checked_at ) {
			$interval_days = (int) TFLG_Util::get_setting( 'drift_check_interval_days' );
			$due_at        = strtotime( $link->title_checked_at . ' UTC' ) + ( $interval_days * DAY_IN_SECONDS );
			if ( time() < $due_at ) {
				return;
			}
		}

		$response = wp_remote_get(
			$link->url,
			array(
				'timeout'            => max( 3, (int) TFLG_Util::get_setting( 'http_timeout' ) ),
				'user-agent'         => TFLG_Util::user_agent(),
				'sslverify'          => true,
				'reject_unsafe_urls' => true, // Same reasoning as fetch_one_hop() -- this URL comes from this site's own content, not necessarily from an administrator.
				'limit_response_size' => 1048576, // 1.33.1: only <title>/<h1> are needed; never buffer an arbitrarily large body.
			)
		);
		if ( is_wp_error( $response ) ) {
			return; // Try again at the next due date rather than recording anything from a failed fetch.
		}
		$body      = wp_remote_retrieve_body( $response );
		$new_title = TFLG_Util::extract_title( $body );
		$new_h1    = TFLG_Util::extract_h1( $body );
		if ( ! $new_title && ! $new_h1 ) {
			return;
		}

		$title_drift_from = TFLG_Util::titles_indicate_drift( $link->last_title, $new_title ) ? $link->last_title : null;
		$h1_drift_from     = TFLG_Util::titles_indicate_drift( $link->last_h1, $new_h1 ) ? $link->last_h1 : null;
		TFLG_DB::update_title_check( $link_id, $new_title, $title_drift_from, $new_h1, $h1_drift_from );
	}

	protected static function blank_result( $check_result = 'unknown' ) {
		return array(
			'check_result'  => $check_result,
			'http_status'   => null,
			'final_url'     => null,
			'redirect_type' => 'none',
			'error_note'    => null,
		);
	}

	/**
	 * Internal links get checked structurally wherever possible — no HTTP
	 * request needed at all for uploaded files or ordinary post/page
	 * permalinks, which is faster, kinder to the server, and exactly what
	 * "local" checking should mean.
	 */
	/**
	 * Structural-only check: uploads dir, url_to_postid(), the old-slug
	 * record. Returns a definitive result, or null if none of those
	 * apply and a live HTTP probe would be needed to say more. Split out
	 * from check_internal() so the caller (check_due_links()) can tell,
	 * before making any request, which internal links actually need one —
	 * and batch exactly those concurrently instead of one at a time.
	 */
	public static function check_internal_structural( $url ) {
		$upload_dir = wp_get_upload_dir();
		if ( ! empty( $upload_dir['baseurl'] ) && 0 === strpos( $url, $upload_dir['baseurl'] ) ) {
			$rel  = substr( $url, strlen( $upload_dir['baseurl'] ) );
			$rel  = strtok( $rel, '?#' );
			$path = $upload_dir['basedir'] . $rel;

			$result = self::blank_result( file_exists( $path ) ? 'ok' : 'broken' );
			if ( ! file_exists( $path ) ) {
				$result['error_note'] = __( 'Uploaded file no longer exists on disk.', 'trade-free-link-guardian' );
			}
			return $result;
		}

		$post_id = url_to_postid( $url );
		if ( $post_id > 0 ) {
			$status = get_post_status( $post_id );
			if ( 'publish' === $status ) {
				return self::blank_result( 'ok' );
			}
			$result                = self::blank_result( 'broken' );
			$result['error_note']  = ( 'trash' === $status )
				? __( 'The linked item has been moved to the trash.', 'trade-free-link-guardian' )
				/* translators: %s: post status such as draft or private */
				: sprintf( __( 'The linked item is not public (status: %s).', 'trade-free-link-guardian' ), $status );
			return $result;
		}

		$slug = self::slug_from_url( $url );
		if ( $slug && self::find_post_by_old_slug( $slug ) ) {
			$result                = self::blank_result( 'broken' );
			$result['error_note']  = __( 'This slug was renamed. An automatic fix is available (old-slug match).', 'trade-free-link-guardian' );
			return $result;
		}

		return null;
	}

	/**
	 * Result for an internal URL that check_internal_structural()
	 * couldn't resolve, when live self-checks are turned off — the same
	 * "give up cleanly" message used in both the single-link and batched
	 * paths, kept in one place so they can't drift apart.
	 */
	public static function unverifiable_internal_result() {
		$result               = self::blank_result( 'unknown' );
		$result['error_note'] = __( 'Could not be structurally verified (not a known post or file). Enable live self-checks in Settings for a definite answer, or check by hand.', 'trade-free-link-guardian' );
		return $result;
	}

	public static function check_internal( $url ) {
		$structural = self::check_internal_structural( $url );
		if ( null !== $structural ) {
			return $structural;
		}

		if ( ! TFLG_Util::get_setting( 'verify_internal_via_http' ) ) {
			return self::unverifiable_internal_result();
		}

		return self::check_external( $url, true );
	}

	public static function slug_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return '';
		}
		$path     = untrailingslashit( $path );
		$segments = explode( '/', $path );
		return end( $segments );
	}

	public static function find_post_by_old_slug( $slug ) {
		global $wpdb;
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slug
			)
		);
		if ( ! $post_id ) {
			return null;
		}
		return ( 'publish' === get_post_status( $post_id ) ) ? (int) $post_id : null;
	}

	/**
	 * Live HTTP check, used for external links and (optionally) for
	 * internal URLs that couldn't be verified structurally. Redirects are
	 * followed one hop at a time so a permanent chain (safe to auto-fix)
	 * can be told apart from a temporary one (should stay as a manual
	 * review item rather than get silently baked into stored content).
	 *
	 * The hop-by-hop state machine (apply_hop_outcome/finalize_hop_state)
	 * is shared with check_external_batch() below, which runs the same
	 * logic concurrently across many URLs — so classification can never
	 * drift between the two paths.
	 */
	public static function check_external( $url, $is_internal_probe = false ) {
		$timeout = max( 3, (int) TFLG_Util::get_setting( 'http_timeout' ) );
		$state   = self::new_hop_state( $url );

		for ( $hop = 0; $hop < 5 && ! $state['done']; $hop++ ) {
			list( $status_code, $location, $error_note ) = self::fetch_one_hop( $state['current'], $timeout );
			self::apply_hop_outcome( $state, $status_code, $location, $error_note );
		}

		return self::finalize_hop_state( $url, $state, $is_internal_probe );
	}

	/**
	 * One HEAD (falling back to a minimal GET on error, 0, or 405)
	 * against a single hop. Returns [status_code|null, location|null,
	 * error_message|null].
	 */
	/**
	 * With reject_unsafe_urls in effect (see fetch_one_hop() and
	 * maybe_check_drift()), WordPress's own SSRF protection would
	 * normally also block checking this site's OWN internal links
	 * whenever this site's domain happens to resolve to a private
	 * network address from the server's own point of view -- a real,
	 * common setup (containers, reverse proxies) unrelated to the
	 * SSRF risk this protection exists for. Explicitly allowing this
	 * site's own host, and only this site's own host, keeps internal
	 * link checking working normally without weakening the protection
	 * for anything else this plugin (or any other code on this site)
	 * requests.
	 */
	public static function allow_own_host( $external, $host ) {
		$own_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $own_host && strtolower( $host ) === strtolower( $own_host ) ) {
			return true;
		}
		return $external;
	}

	private static function fetch_one_hop( $url, $timeout ) {
		$args = array(
			'timeout'            => $timeout,
			'redirection'        => 0,
			'user-agent'         => TFLG_Util::user_agent(),
			'sslverify'          => true,
			// The URL being checked here ultimately comes from this
			// site's own content -- which can mean a lower-trusted
			// author, not just an administrator. Rejecting requests to
			// private/internal-network addresses is WordPress's own,
			// long-established mechanism for exactly this situation
			// (the same flag wp_safe_remote_*() itself sets); this
			// site's own domain is explicitly exempted below via the
			// http_request_host_is_external filter, so checking this
			// site's own internal links keeps working normally.
			'reject_unsafe_urls' => true,
		);

		$response    = wp_remote_head( $url, $args );
		$head_status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( is_wp_error( $response ) || 0 === $head_status || 405 === $head_status ) {
			// No response, or the server doesn't support HEAD for this
			// resource (405) — retry with a minimal-body GET rather than
			// misreporting a working link as broken.
			$response = wp_remote_get( $url, array_merge( $args, array( 'headers' => array( 'Range' => 'bytes=0-0' ) ) ) );
		}

		if ( is_wp_error( $response ) ) {
			return array( null, null, $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$location    = ( $status_code >= 300 && $status_code < 400 ) ? wp_remote_retrieve_header( $response, 'location' ) : null;
		return array( $status_code, $location, null );
	}

	/**
	 * Fresh per-URL hop-tracking state before any hop has run.
	 */
	private static function new_hop_state( $url ) {
		return array(
			'current'                    => $url,
			'redirect_kind'              => 'none',
			'final_url'                  => $url,
			'resolved'                   => false, // a final, non-redirect status was reached
			'done'                       => false, // no further hop should be fetched
			'status_code'                => null,
			'error_note'                 => null,
			'last_hop_failed_to_connect' => false, // distinguishes "redirect target unreachable" from "ran out of hops"
		);
	}

	/**
	 * Feeds one hop's raw outcome into a URL's tracking state, in place.
	 * Pure state transition, no I/O — this is the one place redirect
	 * classification happens, shared by every calling path.
	 */
	private static function apply_hop_outcome( array &$state, $status_code, $location, $error_note ) {
		if ( null === $status_code ) {
			$state['error_note']                 = $error_note;
			$state['done']                       = true;
			$state['last_hop_failed_to_connect']  = true;
			return;
		}

		$state['status_code'] = $status_code;
		$state['last_hop_failed_to_connect'] = false;

		if ( $status_code >= 300 && $status_code < 400 ) {
			if ( ! $location ) {
				$state['done'] = true; // malformed redirect; nothing more to follow
				return;
			}
			$state['current']       = self::resolve_relative( $state['current'], $location );
			$state['redirect_kind'] = self::classify_redirect_kind( $state['redirect_kind'], $status_code );
			$state['final_url']     = $state['current'];
			return; // not done — another hop needed
		}

		$state['resolved'] = true;
		$state['done']     = true;
	}

	/**
	 * Turns accumulated hop state into the same result shape check_link()
	 * expects, applying identical broken/ok/unknown rules regardless of
	 * whether the hops were fetched one at a time or concurrently.
	 */
	private static function finalize_hop_state( $original_url, array $state, $is_internal_probe = false ) {
		$status_code = $state['status_code'];

		$result                  = self::blank_result();
		$result['http_status']   = $status_code;
		$result['final_url']     = ( $state['final_url'] !== $original_url ) ? $state['final_url'] : null;
		$result['redirect_type'] = $state['redirect_kind'];

		if ( ! $state['resolved'] && null !== $status_code && $status_code >= 300 && $status_code < 400 ) {
			$result['check_result'] = 'broken';
			if ( $state['last_hop_failed_to_connect'] ) {
				// A hop DID redirect successfully (status_code is that
				// hop's 3xx) but the NEXT hop — the redirect's actual
				// destination — is what failed. "Too many redirects"
				// would be a misleading explanation for this; the real
				// story is a working redirect pointing nowhere.
				/* translators: %s: the underlying connection error for the redirect's destination */
				$result['error_note'] = sprintf( __( 'Redirects, but the destination could not be reached: %s', 'trade-free-link-guardian' ), $state['error_note'] );
			} else {
				$result['error_note'] = __( 'Too many redirects (redirect loop or chain longer than 5 hops).', 'trade-free-link-guardian' );
			}
		} elseif ( null !== $status_code && $status_code >= 200 && $status_code < 400 ) {
			$result['check_result'] = 'ok';
		} elseif ( null === $status_code ) {
			$result['check_result'] = 'broken';
			$result['error_note']   = $state['error_note'] ? $state['error_note'] : __( 'Could not connect.', 'trade-free-link-guardian' );
		} else {
			$result['check_result'] = 'broken';
			if ( 403 === $status_code ) {
				$result['error_note'] = __( 'Server returned 403 — it may just be blocking automated requests; worth a manual look before assuming it is really dead.', 'trade-free-link-guardian' );
			} elseif ( 404 === $status_code ) {
				$result['error_note'] = __( 'Page not found (404).', 'trade-free-link-guardian' );
			} else {
				/* translators: %d: HTTP status code */
				$result['error_note'] = sprintf( __( 'Server returned HTTP %d.', 'trade-free-link-guardian' ), $status_code );
			}
		}

		if ( $is_internal_probe && 'broken' === $result['check_result'] ) {
			$result['check_result'] = 'unknown';
			$result['error_note']   = __( 'Not a known post or file, and a direct self-check did not resolve it either — worth a manual look.', 'trade-free-link-guardian' );
		}

		return $result;
	}

	/**
	 * Whether real concurrent HTTP requests are available. WordPress has
	 * bundled the namespaced Requests library since 6.2; this plugin
	 * requires 6.5+, so this should always be true in practice — checked
	 * rather than assumed, with a correct sequential fallback either way.
	 */
	public static function concurrency_available() {
		return class_exists( 'WpOrg\Requests\Requests' ) && class_exists( 'WP_Http' ) && function_exists( 'curl_multi_init' );
	}

	/**
	 * Checks many external URLs at once. Uses the exact same per-hop
	 * classification rules as check_external() — both are built on
	 * apply_hop_outcome()/finalize_hop_state(), so a permanent redirect
	 * is never treated differently just because it was checked in a
	 * batch. The only thing that changes is that every URL's current hop
	 * is fetched in the same concurrent wave instead of one at a time.
	 *
	 * @param string[] $urls
	 * @return array url => result, same shape as check_external()'s return.
	 */
	public static function check_external_batch( array $urls, $is_internal_probe = false ) {
		$urls = array_values( array_unique( $urls ) );
		if ( empty( $urls ) ) {
			return array();
		}

		if ( ! self::concurrency_available() ) {
			$out = array();
			foreach ( $urls as $url ) {
				$out[ $url ] = self::check_external( $url, $is_internal_probe );
			}
			return $out;
		}

		$timeout = max( 3, (int) TFLG_Util::get_setting( 'http_timeout' ) );
		$states  = array();
		foreach ( $urls as $url ) {
			$states[ $url ] = self::new_hop_state( $url );
		}

		for ( $hop = 0; $hop < 5; $hop++ ) {
			$pending = array();
			foreach ( $states as $url => $state ) {
				if ( ! $state['done'] ) {
					$pending[ $url ] = $state['current'];
				}
			}
			if ( empty( $pending ) ) {
				break;
			}

			$hop_results = self::fire_concurrent_hop( $pending, 'HEAD', $timeout );

			$needs_get = array();
			foreach ( $hop_results as $url => $r ) {
				if ( $r['needs_get_fallback'] ) {
					$needs_get[ $url ] = $pending[ $url ];
				}
			}
			if ( ! empty( $needs_get ) ) {
				$get_results = self::fire_concurrent_hop( $needs_get, 'GET', $timeout );
				foreach ( $get_results as $url => $r ) {
					$hop_results[ $url ] = $r;
				}
			}

			foreach ( $hop_results as $url => $r ) {
				self::apply_hop_outcome( $states[ $url ], $r['status_code'], $r['location'], $r['error_note'] );
			}
		}

		$out = array();
		foreach ( $urls as $url ) {
			$out[ $url ] = self::finalize_hop_state( $url, $states[ $url ], $is_internal_probe );
		}
		return $out;
	}

	/**
	 * Fires one concurrent wave of the same method (HEAD or GET) against
	 * a set of URLs, via the Requests library WordPress already bundles
	 * and wp_remote_*() itself is built on — this calls its native
	 * concurrent entry point instead of one blocking request at a time.
	 *
	 * Two things a normal wp_remote_*() call gets for free are preserved
	 * explicitly here, since going around WP_Http::request() means going
	 * around them too: the WP_HTTP_BLOCK_EXTERNAL / WP_ACCESSIBLE_HOSTS
	 * check (reused directly from WP_Http, not reimplemented), and the
	 * http_request_args filter, so another plugin's HTTP customisation
	 * still applies.
	 *
	 * @param array  $url_map original-key => URL to fetch this wave
	 * @param string $method  'HEAD' or 'GET'
	 * @return array original-key => ['status_code','location','error_note','needs_get_fallback']
	 */
	private static function fire_concurrent_hop( array $url_map, $method, $timeout ) {
		$out  = array();
		$http = new WP_Http();
		$ua   = TFLG_Util::user_agent();

		// A configured proxy needs per-request routing decisions that
		// aren't worth replicating here; fall back to the well-tested
		// sequential path (still correct, just not concurrent) rather
		// than risk silently ignoring a site's proxy setup.
		$proxy = new WP_HTTP_Proxy();
		if ( $proxy->is_enabled() ) {
			foreach ( $url_map as $key => $url ) {
				list( $status_code, $location, $error_note ) = self::fetch_one_hop( $url, $timeout );
				$out[ $key ] = array(
					'status_code'        => $status_code,
					'location'           => $location,
					'error_note'         => $error_note,
					'needs_get_fallback' => false, // fetch_one_hop() already tried its own fallback.
				);
			}
			return $out;
		}

		$requests = array();
		foreach ( $url_map as $key => $url ) {
			if ( $http->block_request( $url ) ) {
				$out[ $key ] = array(
					'status_code'        => null,
					'location'           => null,
					'error_note'         => __( "Blocked by this site's HTTP request settings.", 'trade-free-link-guardian' ),
					'needs_get_fallback' => false,
				);
				continue;
			}

			// SSRF guard (1.33.1): wp_remote_*() applies 'reject_unsafe_urls'
			// itself, but Requests::request_multiple() below goes around
			// WP_Http::request(), so the same validation must be applied
			// explicitly here -- on EVERY hop, redirect targets included.
			// wp_http_validate_url() allows only http/https, rejects
			// credentials in the URL, private/loopback/link-local/metadata
			// IPs (e.g. 169.254.169.254) and non-standard ports, and
			// honours the http_request_host_is_external filter, so this
			// site's own host stays exempt (see allow_own_host()).
			if ( ! wp_http_validate_url( $url ) ) {
				$out[ $key ] = array(
					'status_code'        => null,
					'location'           => null,
					'error_note'         => __( 'Blocked: not a public http(s) address (private, loopback or internal-network targets are never requested).', 'trade-free-link-guardian' ),
					'needs_get_fallback' => false,
				);
				continue;
			}

			// Same filter wp_remote_*() applies, so per-host timeout
			// overrides or other HTTP customisation from other plugins
			// still takes effect here.
			$args = apply_filters(
				'http_request_args',
				array(
					'timeout'    => $timeout,
					'user-agent' => $ua,
					'sslverify'  => true,
					'method'     => $method,
				),
				$url
			);

			$requests[ $key ] = array(
				'url'     => $url,
				'type'    => ( 'HEAD' === $method ) ? WpOrg\Requests\Requests::HEAD : WpOrg\Requests\Requests::GET,
				'headers' => ( 'GET' === $method ) ? array( 'Range' => 'bytes=0-0' ) : array(),
				'options' => array(
					'timeout'         => isset( $args['timeout'] ) ? $args['timeout'] : $timeout,
					'connect_timeout' => isset( $args['timeout'] ) ? $args['timeout'] : $timeout,
					'useragent'       => isset( $args['user-agent'] ) ? $args['user-agent'] : $ua,
					'verify'          => apply_filters( 'https_ssl_verify', isset( $args['sslverify'] ) ? $args['sslverify'] : true, $url ),
				),
			);
		}

		if ( empty( $requests ) ) {
			return $out;
		}

		try {
			$responses = WpOrg\Requests\Requests::request_multiple(
				$requests,
				array( 'follow_redirects' => false )
			);
		} catch ( \Throwable $e ) {
			foreach ( $requests as $key => $r ) {
				$out[ $key ] = array(
					'status_code'        => null,
					'location'           => null,
					'error_note'         => $e->getMessage(),
					'needs_get_fallback' => false,
				);
			}
			return $out;
		}

		foreach ( $responses as $key => $response ) {
			if ( ! ( $response instanceof \WpOrg\Requests\Response ) ) {
				$out[ $key ] = array(
					'status_code'        => null,
					'location'           => null,
					'error_note'         => ( $response instanceof \Throwable ) ? $response->getMessage() : __( 'Request failed.', 'trade-free-link-guardian' ),
					'needs_get_fallback' => ( 'HEAD' === $method ),
				);
				continue;
			}

			$status      = (int) $response->status_code;
			$out[ $key ] = array(
				'status_code'        => $status,
				'location'           => ( $status >= 300 && $status < 400 ) ? $response->headers['location'] : null,
				'error_note'         => null,
				'needs_get_fallback' => ( 'HEAD' === $method && ( 0 === $status || 405 === $status ) ),
			);
		}

		return $out;
	}

	private static function classify_redirect_kind( $current_kind, $status_code ) {
		$this_hop = in_array( $status_code, array( 301, 308 ), true ) ? 'permanent' : 'temporary';
		if ( 'none' === $current_kind ) {
			return $this_hop;
		}
		return ( $current_kind === $this_hop ) ? $current_kind : 'mixed';
	}

	private static function resolve_relative( $base, $location ) {
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $location ) ) {
			return $location;
		}
		$base_parts = wp_parse_url( $base );
		$scheme     = isset( $base_parts['scheme'] ) ? $base_parts['scheme'] : 'https';
		$host       = isset( $base_parts['host'] ) ? $base_parts['host'] : '';
		$port       = isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '';

		if ( 0 === strpos( $location, '//' ) ) {
			return $scheme . ':' . $location;
		}
		if ( 0 === strpos( $location, '/' ) ) {
			return $scheme . '://' . $host . $port . $location;
		}
		$dir = isset( $base_parts['path'] ) ? trailingslashit( dirname( $base_parts['path'] ) ) : '/';
		return $scheme . '://' . $host . $port . $dir . $location;
	}
}

/* =========================================================================
 * SECTION: TFLG_Fixer — applies fixes to real content, safely and
 * reversibly. Every fix is an exact string substitution (never a
 * regeneration of markup), so undoing a fix is just re-applying the
 * substitution in reverse — no content snapshots need to be stored.
 * ========================================================================= */

class TFLG_Fixer {

	protected static $auto_methods = array( 'auto_protocol', 'auto_www', 'auto_redirect', 'auto_old_slug', 'auto_unmangle', 'auto_www_normalize', 'auto_scheme_typo' );

	/**
	 * Detects one precise, verifiable corruption pattern: an internal-
	 * looking URL that is really this site's own home URL followed by a
	 * second, complete external URL — either quote-delimited (a literal
	 * quote character, or its %22-encoded form, ending up inside an
	 * href value instead of properly delimiting it) or, just as often,
	 * with no delimiter at all — the site's own domain directly
	 * followed by "https://" a second time with nothing in between,
	 * which happens the same way: a URL that should have stood on its
	 * own ends up folded into a path on this site instead, so
	 * extraction reads the whole thing as one garbled internal link.
	 * This only ever returns a value when the unwrapped remainder is
	 * itself a well-formed http(s) URL — it does not guess, it
	 * precisely reverses one specific, recognisable mistake, which is
	 * why it's safe to apply automatically.
	 */
	public static function detect_quote_mangled_url( $url ) {
		$home = untrailingslashit( home_url() );
		if ( 0 !== strpos( $url, $home ) ) {
			return null;
		}
		$rest = ltrim( substr( $url, strlen( $home ) ), '/' );

		$markers = array( '%2522', '%22', '&quot;', '"' );
		foreach ( $markers as $marker ) {
			if ( 0 !== stripos( $rest, $marker ) ) {
				continue;
			}
			$candidate = substr( $rest, strlen( $marker ) );
			foreach ( $markers as $end_marker ) {
				$end_len = strlen( $end_marker );
				if ( strlen( $candidate ) > $end_len && 0 === strcasecmp( substr( $candidate, -$end_len ), $end_marker ) ) {
					$candidate = substr( $candidate, 0, -$end_len );
					break;
				}
			}
			if ( preg_match( '#^https?://[^\s"]+$#i', $candidate ) ) {
				return $candidate;
			}
		}

		// No marker at all — the site's own URL directly followed by a
		// second, complete http(s) address and nothing else. Kept as its
		// own explicit check rather than folded into the marker loop
		// above (an empty "marker" would technically match everything,
		// including at the point of stripping a same-length "end
		// marker" — safer as a clean, separate case than relying on
		// that edge behaving correctly).
		if ( preg_match( '#^https?://\S+$#i', $rest ) ) {
			return $rest;
		}

		return null;
	}

	/**
	 * For a same-site URL, checks whether its "www." status matches how
	 * WordPress's own Site Address is actually configured (Settings >
	 * General) — home_url() is the single source of truth for which
	 * form is this site's canonical one, deliberately not a fixed
	 * "always strip www" preference, since that would be wrong for a
	 * site whose real address does include it. Returns a corrected URL
	 * only when the link is genuinely the same site AND its www-status
	 * doesn't already match that reference; returns null for anything
	 * else — a different site entirely, or one that already matches.
	 */
	public static function detect_www_mismatch( $url ) {
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $url_host ) {
			return null;
		}
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $site_host ) {
			return null;
		}
		$bare_url_host  = preg_replace( '#^www\.#i', '', strtolower( $url_host ) );
		$bare_site_host = preg_replace( '#^www\.#i', '', strtolower( $site_host ) );
		if ( $bare_url_host !== $bare_site_host ) {
			return null; // not the same site at all
		}
		$site_has_www = ( 0 === stripos( $site_host, 'www.' ) );
		$url_has_www  = ( 0 === stripos( $url_host, 'www.' ) );
		if ( $site_has_www === $url_has_www ) {
			return null; // already matches the site's own canonical form
		}
		return TFLG_Util::toggle_www( $url );
	}

	/**
	 * Corrects a small, closed set of one-character-off typos of the
	 * http/https scheme itself — httpss, htps, htp, hhttp, hhttps — and
	 * nothing else about the URL. None of these are registered,
	 * legitimate URL schemes (per IANA's own URI scheme registry), so
	 * there's no ambiguity about what was actually meant; unlike a
	 * protocol *swap* (http vs https, where either can genuinely be
	 * correct and a live check is what settles it), a malformed scheme
	 * like this can never succeed as-is, so no live check is needed
	 * before correcting it — only before deciding whether the corrected
	 * URL also happens to be reachable, which is a separate question
	 * left for the next check.
	 */
	public static function detect_scheme_typo( $url ) {
		$typos = array(
			'#^httpss://#i' => 'https://',
			'#^hhttps://#i' => 'https://',
			'#^htps://#i'   => 'https://',
			'#^hhttp://#i'  => 'http://',
			'#^htp://#i'    => 'http://',
		);
		foreach ( $typos as $pattern => $replacement ) {
			if ( preg_match( $pattern, $url ) ) {
				return preg_replace( $pattern, $replacement, $url, 1 );
			}
		}
		return null;
	}

	/**
	 * Try deterministic, safe fixes in priority order. Never guesses —
	 * every candidate here is verified (either via a fresh HTTP check or a
	 * precise WordPress-native lookup) before being applied.
	 */
	/**
	 * The detection half of attempt_autofix(), pulled out on its own:
	 * runs the exact same chain of checks, in the exact same order,
	 * including the live verification requests protocol/www swaps
	 * genuinely need to be trustworthy — but never writes anything.
	 * Returns the method name and the proposed URL, or null if nothing
	 * matches. attempt_autofix() below is now just this plus
	 * apply_fix(); a report or preview can call this alone and never
	 * touch the database or rewrite any content.
	 */
	public static function propose_autofix( $link_id ) {
		$link = TFLG_DB::get_link( $link_id );
		if ( ! $link ) {
			return null;
		}

		if ( 'internal' === $link->url_type ) {
			if ( TFLG_Util::get_setting( 'autofix_unmangle' ) ) {
				$unmangled = self::detect_quote_mangled_url( $link->url );
				if ( $unmangled ) {
					return array(
						'method'       => 'auto_unmangle',
						'proposed_url' => $unmangled,
					);
				}
			}
			if ( TFLG_Util::get_setting( 'autofix_www_normalize' ) ) {
				$normalized = self::detect_www_mismatch( $link->url );
				if ( $normalized ) {
					return array(
						'method'       => 'auto_www_normalize',
						'proposed_url' => $normalized,
					);
				}
			}
			if ( TFLG_Util::get_setting( 'autofix_old_slug' ) ) {
				$slug    = TFLG_Checker::slug_from_url( $link->url );
				$post_id = $slug ? TFLG_Checker::find_post_by_old_slug( $slug ) : null;
				if ( $post_id ) {
					$new_url = get_permalink( $post_id );
					if ( $new_url ) {
						return array(
							'method'       => 'auto_old_slug',
							'proposed_url' => $new_url,
						);
					}
				}
			}
			return null;
		}

		if ( TFLG_Util::get_setting( 'autofix_scheme_typo' ) ) {
			$scheme_fixed = self::detect_scheme_typo( $link->url );
			if ( $scheme_fixed ) {
				return array(
					'method'       => 'auto_scheme_typo',
					'proposed_url' => $scheme_fixed,
				);
			}
		}

		if ( TFLG_Util::get_setting( 'autofix_protocol' ) && 0 === stripos( $link->url, 'http://' ) ) {
			$target = TFLG_Util::toggle_scheme( $link->url, 'https' );
			if ( $target ) {
				$check = TFLG_Checker::check_external( $target );
				if ( 'ok' === $check['check_result'] ) {
					return array(
						'method'       => 'auto_protocol',
						'proposed_url' => $check['final_url'] ? $check['final_url'] : $target,
					);
				}
			}
		}

		if ( TFLG_Util::get_setting( 'autofix_www' ) ) {
			$target = TFLG_Util::toggle_www( $link->url );
			if ( $target ) {
				$check = TFLG_Checker::check_external( $target );
				if ( 'ok' === $check['check_result'] ) {
					return array(
						'method'       => 'auto_www',
						'proposed_url' => $check['final_url'] ? $check['final_url'] : $target,
					);
				}
			}
		}

		if (
			TFLG_Util::get_setting( 'autofix_redirect' )
			&& 'ok' === $link->check_result
			&& 'permanent' === $link->redirect_type
			&& $link->final_url
		) {
			return array(
				'method'       => 'auto_redirect',
				'proposed_url' => $link->final_url,
			);
		}

		return null;
	}

	public static function attempt_autofix( $link_id ) {
		$link = TFLG_DB::get_link( $link_id );
		if ( ! $link ) {
			return array(
				'success' => false,
				'message' => __( 'Link not found.', 'trade-free-link-guardian' ),
			);
		}

		$proposal = self::propose_autofix( $link_id );
		if ( $proposal ) {
			return self::apply_fix( $link_id, $proposal['proposed_url'], $proposal['method'] );
		}

		if ( 'internal' === $link->url_type ) {
			return array(
				'success' => false,
				'message' => __( 'No deterministic fix available for this internal link — try Edit URL, or restore the item if it is in the trash.', 'trade-free-link-guardian' ),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'No deterministic fix found for this link. It may need a manual replacement, or the destination may simply be gone.', 'trade-free-link-guardian' ),
		);
	}

	/**
	 * Replace a link's URL everywhere it occurs, and log the change once.
	 */
	public static function apply_fix( $link_id, $new_url, $method ) {
		$link = TFLG_DB::get_link( $link_id );
		if ( ! $link ) {
			return array(
				'success' => false,
				'message' => __( 'Link not found.', 'trade-free-link-guardian' ),
			);
		}

		$new_url = esc_url_raw( $new_url );
		if ( '' === $new_url ) {
			// esc_url_raw() returns '' for a disallowed protocol; never
			// rewrite real content to an empty href (added 1.33.1).
			return array(
				'success' => false,
				'message' => __( 'That is not a valid URL (only http, https and similar standard schemes are accepted).', 'trade-free-link-guardian' ),
			);
		}
		$old_url     = $link->url;
		$occurrences = TFLG_DB::get_occurrences_for_link( $link_id );
		$affected    = 0;

		foreach ( $occurrences as $occ ) {
			if ( self::rewrite_occurrence_url( $occ, $occ->raw_url, $new_url ) ) {
				++$affected;
			}
		}

		$new_link_id = TFLG_DB::replace_link_url( $link_id, $new_url );
		TFLG_DB::update_link(
			$new_link_id,
			array( 'fix_status' => in_array( $method, self::$auto_methods, true ) ? 'auto' : 'fixed_manual' )
		);
		TFLG_DB::insert_fixlog( $new_link_id, $old_url, $new_url, $method, $affected );

		// Verify the new URL right away so the admin list reflects reality
		// immediately instead of showing a stale "broken" status next to a
		// freshly-applied fix.
		$fresh = TFLG_DB::get_link( $new_link_id );
		if ( $fresh ) {
			TFLG_Checker::check_link( $fresh );
		}

		return array(
			'success'  => true,
			'method'   => $method,
			'new_url'  => $new_url,
			'affected' => $affected,
		);
	}

	public static function rewrite_occurrence_url( $occ, $old_raw, $new_url ) {
		switch ( $occ->source_type ) {
			case 'post':
				return self::rewrite_post_url( (int) $occ->source_id, $old_raw, $new_url, $occ->attr_source );
			case 'nav_menu_item':
				update_post_meta( (int) $occ->source_id, '_menu_item_url', $new_url );
				return true;
			case 'widget':
				return self::rewrite_widget_url( $occ->source_key, (int) $occ->source_id, $old_raw, $new_url, $occ->attr_source );
			default:
				return (bool) apply_filters( 'tflg_rewrite_custom_source_url', false, $occ, $old_raw, $new_url );
		}
	}

	protected static function rewrite_post_url( $post_id, $old_raw, $new_url, $attr_source ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		$content = ( 'block-attr' === $attr_source )
			? self::rewrite_block_attr_url( $post->post_content, $old_raw, $new_url )
			: self::rewrite_html_attr_url( $post->post_content, $old_raw, $new_url );

		if ( $content === $post->post_content ) {
			return false;
		}
		$updated = wp_update_post( array(
			'ID'           => $post_id,
			// wp_update_post() expects slashed data and unslashes it; without
			// wp_slash() every backslash (e.g. the \u0022 escapes in block
			// comment JSON) was stripped from the saved content (fixed 1.33.1).
			'post_content' => wp_slash( $content ),
		), true );
		return ! is_wp_error( $updated ) && $updated > 0;
	}

	protected static function rewrite_widget_url( $base, $number, $old_raw, $new_url, $attr_source ) {
		$content = TFLG_Scanner::get_widget_content( $base, $number );
		if ( '' === $content ) {
			return false;
		}
		$new_content = ( 'block-attr' === $attr_source )
			? self::rewrite_block_attr_url( $content, $old_raw, $new_url )
			: self::rewrite_html_attr_url( $content, $old_raw, $new_url );

		if ( $new_content === $content ) {
			return false;
		}
		return (bool) TFLG_Scanner::save_widget_content( $base, $number, $new_content );
	}

	/**
	 * Minimal-diff attribute rewrite via the core HTML API: touches only
	 * the matching href/src token and leaves the rest of the markup
	 * byte-for-byte identical.
	 */
	public static function rewrite_html_attr_url( $html, $old_url, $new_url ) {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return self::rewrite_html_attr_url_fallback( $html, $old_url, $new_url );
		}
		$processor = new WP_HTML_Tag_Processor( $html );
		$changed   = false;
		while ( $processor->next_tag() ) {
			foreach ( array( 'href', 'src' ) as $attr ) {
				$value = $processor->get_attribute( $attr );
				if ( is_string( $value ) && $value === $old_url ) {
					$processor->set_attribute( $attr, $new_url );
					$changed = true;
				}
			}
		}
		return $changed ? $processor->get_updated_html() : $html;
	}

	protected static function rewrite_html_attr_url_fallback( $html, $old_url, $new_url ) {
		$quoted  = preg_quote( $old_url, '#' );
		$count   = 0;
		$replace = str_replace( '$', '\$', $new_url );
		$result  = preg_replace( '#(href|src)=(["\'])' . $quoted . '\2#i', '$1=$2' . $replace . '$2', $html, -1, $count );
		return ( $count > 0 && null !== $result ) ? $result : $html;
	}

	/**
	 * Rewrites a URL stored only in a block's attrs (dynamic blocks with
	 * no literal href in their saved markup) via a full parse/serialize
	 * round trip. May normalise incidental whitespace between blocks —
	 * an accepted, disclosed trade-off for this rarer case.
	 */
	public static function rewrite_block_attr_url( $content, $old_url, $new_url ) {
		$blocks  = parse_blocks( $content );
		$changed = false;
		self::walk_blocks_replace_url( $blocks, $old_url, $new_url, $changed, 0 );
		return $changed ? serialize_blocks( $blocks ) : $content;
	}

	// Same generous-but-bounded depth caps as TFLG_Scanner's read-only
	// walker, for the same reason: never affects real content, only
	// stops a pathologically nested structure from recursing unbounded.
	protected static function walk_blocks_replace_url( array &$blocks, $old_url, $new_url, &$changed, $depth ) {
		if ( $depth > TFLG_Scanner::MAX_BLOCK_NESTING ) {
			return;
		}
		foreach ( $blocks as &$block ) {
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				self::walk_attrs_replace_url( $block['attrs'], $old_url, $new_url, $changed, 0 );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_blocks_replace_url( $block['innerBlocks'], $old_url, $new_url, $changed, $depth + 1 );
			}
		}
		unset( $block );
	}

	protected static function walk_attrs_replace_url( array &$attrs, $old_url, $new_url, &$changed, $depth ) {
		if ( $depth > TFLG_Scanner::MAX_ATTR_NESTING ) {
			return;
		}
		foreach ( $attrs as &$value ) {
			if ( is_array( $value ) ) {
				self::walk_attrs_replace_url( $value, $old_url, $new_url, $changed, $depth + 1 );
				continue;
			}
			if ( is_string( $value ) && $value === $old_url ) {
				$value   = $new_url;
				$changed = true;
			}
		}
		unset( $value );
	}

	/**
	 * Unlink every occurrence of a link: for post/widget content this
	 * removes the <a> wrapper but keeps whatever was inside it; for a
	 * classic menu item (which has nothing to "unlink" — the whole item
	 * IS the link) it removes the menu item instead.
	 */
	public static function unlink_everywhere( $link_id ) {
		$link = TFLG_DB::get_link( $link_id );
		if ( ! $link ) {
			return array(
				'success' => false,
				'message' => __( 'Link not found.', 'trade-free-link-guardian' ),
			);
		}
		$occurrences = TFLG_DB::get_occurrences_for_link( $link_id );
		$affected    = 0;
		foreach ( $occurrences as $occ ) {
			if ( 'nav_menu_item' === $occ->source_type ) {
				if ( wp_delete_post( $occ->source_id, true ) ) {
					++$affected;
				}
				continue;
			}
			if ( self::unlink_in_source( $occ ) ) {
				++$affected;
			}
		}
		TFLG_DB::update_link( $link_id, array( 'fix_status' => 'unlinked' ) );
		TFLG_DB::insert_fixlog( $link_id, $link->url, null, 'unlink', $affected );
		return array(
			'success'  => true,
			'affected' => $affected,
		);
	}

	protected static function unlink_in_source( $occ ) {
		if ( 'post' === $occ->source_type ) {
			$post = get_post( $occ->source_id );
			if ( ! $post ) {
				return false;
			}
			$new_content = self::unlink_url_in_html( $post->post_content, $occ->raw_url );
			if ( null === $new_content || $new_content === $post->post_content ) {
				return false;
			}
			$updated = wp_update_post( array(
				'ID'           => $occ->source_id,
				'post_content' => wp_slash( $new_content ), // wp_update_post() unslashes; see rewrite_post_url().
			), true );
			return ! is_wp_error( $updated ) && $updated > 0;
		}
		if ( 'widget' === $occ->source_type ) {
			$content     = TFLG_Scanner::get_widget_content( $occ->source_key, $occ->source_id );
			$new_content = self::unlink_url_in_html( $content, $occ->raw_url );
			if ( null === $new_content || $new_content === $content ) {
				return false;
			}
			return (bool) TFLG_Scanner::save_widget_content( $occ->source_key, $occ->source_id, $new_content );
		}
		return false;
	}

	/**
	 * Tree-aware unwrap via DOMDocument: keeps any markup that was inside
	 * the anchor (bold text, an image, etc.) rather than flattening it to
	 * plain text. Falls back to a careful regex if ext-dom is unavailable.
	 */
	public static function unlink_url_in_html( $html, $url ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$quoted = preg_quote( $url, '#' );
			$result = preg_replace( '#<a\s[^>]*href=["\']' . $quoted . '["\'][^>]*>(.*?)</a>#is', '$1', $html, -1, $count );
			return ( $count > 0 && null !== $result ) ? $result : null;
		}

		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="UTF-8"?><div id="tflg-root">' . $html . '</div>',
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath   = new DOMXPath( $doc );
		$nodes   = $xpath->query( '//a[@href]' );
		$changed = false;

		foreach ( $nodes as $node ) {
			if ( $node->getAttribute( 'href' ) !== $url ) {
				continue;
			}
			$parent = $node->parentNode;
			if ( ! $parent ) {
				continue;
			}
			while ( $node->firstChild ) {
				$parent->insertBefore( $node->firstChild, $node );
			}
			$parent->removeChild( $node );
			$changed = true;
		}

		if ( ! $changed ) {
			return null;
		}

		$root_list = $xpath->query( '//div[@id="tflg-root"]' );
		$root      = $root_list->length ? $root_list->item( 0 ) : null;
		if ( ! $root ) {
			return null;
		}
		$inner = '';
		foreach ( $root->childNodes as $child ) {
			$inner .= $doc->saveHTML( $child );
		}
		return $inner;
	}

	public static function restore_trashed_post( $link_id ) {
		$link = TFLG_DB::get_link( $link_id );
		if ( ! $link ) {
			return array(
				'success' => false,
				'message' => __( 'Link not found.', 'trade-free-link-guardian' ),
			);
		}
		$post_id = url_to_postid( $link->url );
		if ( ! $post_id || 'trash' !== get_post_status( $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'This link is not pointing at an item currently in the trash.', 'trade-free-link-guardian' ),
			);
		}
		if ( ! wp_untrash_post( $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not restore the item from the trash.', 'trade-free-link-guardian' ),
			);
		}
		TFLG_DB::insert_fixlog( $link_id, $link->url, $link->url, 'restore_post', 0 );
		return array(
			'success' => true,
			'message' => __( 'Item restored from the trash.', 'trade-free-link-guardian' ),
		);
	}

	public static function ignore_link( $link_id ) {
		TFLG_DB::update_link( $link_id, array( 'fix_status' => 'ignored' ) );
		return array( 'success' => true );
	}

	public static function unignore_link( $link_id ) {
		TFLG_DB::update_link( $link_id, array( 'fix_status' => 'none' ) );
		return array( 'success' => true );
	}

	/**
	 * Every applied fix is an exact substitution, so reverting is just the
	 * same substitution in reverse — no stored content snapshots needed.
	 */
	public static function revert_fix( $fixlog_id ) {
		$entry = TFLG_DB::get_fixlog_entry( $fixlog_id );
		if ( ! $entry || $entry->reverted ) {
			return array(
				'success' => false,
				'message' => __( 'Nothing to revert.', 'trade-free-link-guardian' ),
			);
		}
		if ( in_array( $entry->method, array( 'ignore', 'restore_post' ), true ) ) {
			return array(
				'success' => false,
				'message' => __( 'This kind of entry cannot be auto-reverted.', 'trade-free-link-guardian' ),
			);
		}
		if ( 'unlink' === $entry->method ) {
			return array(
				'success' => false,
				'message' => __( 'Unlinking cannot be auto-reverted, since where the link used to sit is no longer tracked — you can re-add it by hand.', 'trade-free-link-guardian' ),
			);
		}

		$current_link = TFLG_DB::get_link_by_url( $entry->new_url );
		if ( ! $current_link ) {
			return array(
				'success' => false,
				'message' => __( 'Could not find the current link to revert.', 'trade-free-link-guardian' ),
			);
		}

		$result = self::apply_fix( $current_link->id, $entry->old_url, 'manual_replace' );
		if ( $result['success'] ) {
			TFLG_DB::mark_fixlog_reverted( $fixlog_id );
		}
		return $result;
	}

	/**
	 * The one feature that ever leaves this server: an on-demand, opt-in
	 * lookup against the Internet Archive's Wayback Machine. Never called
	 * automatically — only when a person clicks "Find archived version"
	 * and the setting is enabled.
	 */
	public static function wayback_lookup( $url ) {
		if ( ! TFLG_Util::get_setting( 'enable_wayback' ) ) {
			return array(
				'success' => false,
				'message' => __( 'The Wayback Machine lookup is turned off in Settings.', 'trade-free-link-guardian' ),
			);
		}
		$api      = 'https://archive.org/wayback/available?url=' . rawurlencode( $url );
		$response = wp_remote_get( $api, array(
			'timeout'             => 10,
			'user-agent'          => TFLG_Util::user_agent(),
			'limit_response_size' => 262144, // 1.33.1: the JSON answer is tiny.
		) );
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}
		$body         = json_decode( wp_remote_retrieve_body( $response ), true );
		$snapshot_url = isset( $body['archived_snapshots']['closest']['url'] ) ? $body['archived_snapshots']['closest']['url'] : '';
		if ( '' === $snapshot_url ) {
			return array(
				'success' => false,
				'message' => __( 'No archived snapshot was found for this URL.', 'trade-free-link-guardian' ),
			);
		}
		return array(
			'success'      => true,
			'snapshot_url' => esc_url_raw( $snapshot_url ),
		);
	}
}

/* =========================================================================
 * SECTION: TFLG_List_Table — the Links admin screen's native WP table.
 * Guarded: WP_List_Table only exists in wp-admin, and this file loads on
 * every request, so the class is declared only when is_admin() is true.
 * ========================================================================= */

if ( is_admin() ) {

	if ( ! class_exists( 'WP_List_Table' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}

	class TFLG_List_Table extends WP_List_Table {

		public function __construct() {
			parent::__construct(
				array(
					'singular' => 'tflg_link',
					'plural'   => 'tflg_links',
					'ajax'     => false,
				)
			);
		}

		public function get_columns() {
			return array(
				'cb'          => '<input type="checkbox" />',
				'url'         => __( 'Link', 'trade-free-link-guardian' ),
				'status'      => __( 'Status', 'trade-free-link-guardian' ),
				'occurrences' => __( 'Found in', 'trade-free-link-guardian' ),
				'last_checked' => __( 'Last checked', 'trade-free-link-guardian' ),
			);
		}

		public function get_sortable_columns() {
			return array(
				'url'          => array( 'url', false ),
				'status'       => array( 'check_result', false ),
				'occurrences'  => array( 'occurrences', false ),
				'last_checked' => array( 'last_checked', false ),
			);
		}

		protected function get_views() {
			$counts = TFLG_DB::counts_by_status();
			$views  = array();
			$views['all']     = $this->view_link( '', __( 'All', 'trade-free-link-guardian' ), $counts['total'] );
			$views['broken']  = $this->view_link( 'broken', __( 'Needs attention', 'trade-free-link-guardian' ), $counts['broken'] );
			$views['unknown'] = $this->view_link( 'unknown', __( 'Unverified', 'trade-free-link-guardian' ), $counts['unknown'] );
			$views['fixed']   = $this->view_link( 'fixed', __( 'Fixed', 'trade-free-link-guardian' ), $counts['auto_fixed'] );
			$views['ignored'] = $this->view_link( 'ignored', __( 'Ignored', 'trade-free-link-guardian' ), $counts['ignored'] );
			return $views;
		}

		protected function view_link( $status, $label, $count ) {
			$current = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$url     = add_query_arg( 'status', $status, remove_query_arg( array( 'paged', 's' ) ) );
			$class   = ( $current === $status ) ? ' class="current"' : '';
			return sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, esc_html( $label ), (int) $count );
		}

		public function get_bulk_actions() {
			return array(
				'recheck' => __( 'Re-check', 'trade-free-link-guardian' ),
				'autofix' => __( 'Auto-fix where possible', 'trade-free-link-guardian' ),
				'ignore'  => __( 'Ignore', 'trade-free-link-guardian' ),
				'forget'  => __( 'Stop tracking (does not change content)', 'trade-free-link-guardian' ),
			);
		}

		public function column_cb( $item ) {
			return sprintf( '<input type="checkbox" name="link_ids[]" value="%d" />', (int) $item->id );
		}

		public function column_url( $item ) {
			$type_label = ( 'internal' === $item->url_type ) ? __( 'internal', 'trade-free-link-guardian' ) : __( 'external', 'trade-free-link-guardian' );
			$out  = '<span class="tflg-url-type tflg-url-type--' . esc_attr( $item->url_type ) . '">' . esc_html( $type_label ) . '</span> ';
			$out .= '<a href="' . esc_url( $item->url ) . '" target="_blank" rel="noopener noreferrer" class="tflg-url">' . esc_html( $item->url ) . '</a>';
			if ( 'broken' === $item->check_result && ( (int) $item->occurrence_count >= 3 || (int) $item->menu_occurrence_count > 0 ) ) {
				$impact_bits = array();
				if ( (int) $item->menu_occurrence_count > 0 ) {
					$impact_bits[] = __( 'in a navigation menu', 'trade-free-link-guardian' );
				}
				if ( (int) $item->occurrence_count >= 3 ) {
					$impact_bits[] = sprintf(
						/* translators: %d: number of distinct places this broken link appears */
						_n( 'on %d page', 'on %d pages', (int) $item->occurrence_count, 'trade-free-link-guardian' ),
						(int) $item->occurrence_count
					);
				}
				$out .= '<div class="tflg-impact-badge">' . esc_html__( 'Wider impact:', 'trade-free-link-guardian' ) . ' ' . esc_html( implode( ', ', $impact_bits ) ) . '</div>';
			}
			if ( $item->final_url ) {
				$out .= '<div class="tflg-sub">' . esc_html__( 'Redirects to:', 'trade-free-link-guardian' ) . ' <a href="' . esc_url( $item->final_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item->final_url ) . '</a></div>';
			}
			if ( $item->admin_note ) {
				$out .= '<div class="tflg-sub tflg-note" title="' . esc_attr( $item->admin_note ) . '"><span aria-hidden="true">&#128221;</span> ' . esc_html( wp_trim_words( $item->admin_note, 12 ) ) . '</div>';
			}
			if ( $item->title_drift_from || $item->h1_drift_from ) {
				$out .= '<div class="tflg-drift-warning">' . esc_html__( 'Content may have changed:', 'trade-free-link-guardian' );
				if ( $item->title_drift_from ) {
					$out .= '<br />' . esc_html__( 'Title:', 'trade-free-link-guardian' )
						. ' <span class="tflg-sub">' . esc_html__( 'was', 'trade-free-link-guardian' ) . ' "' . esc_html( $item->title_drift_from ) . '"</span>'
						. ' &rarr; <strong>"' . esc_html( $item->last_title ) . '"</strong>';
				}
				if ( $item->h1_drift_from ) {
					$out .= '<br />' . esc_html__( 'Heading:', 'trade-free-link-guardian' )
						. ' <span class="tflg-sub">' . esc_html__( 'was', 'trade-free-link-guardian' ) . ' "' . esc_html( $item->h1_drift_from ) . '"</span>'
						. ' &rarr; <strong>"' . esc_html( $item->last_h1 ) . '"</strong>';
				}
				$out .= ' <a href="#" class="tflg-row-action" data-op="ack_drift" data-id="' . (int) $item->id . '">' . esc_html__( 'Acknowledge', 'trade-free-link-guardian' ) . '</a>';
				$out .= '</div>';
			}
			$out .= $this->row_actions( $this->build_row_actions( $item ) );
			return $out;
		}

		protected function build_row_actions( $item ) {
			$actions = array();
			$id      = (int) $item->id;

			$actions['recheck'] = $this->action_link( $id, 'recheck', __( 'Re-check', 'trade-free-link-guardian' ) );
			$actions['note']    = '<a href="#" class="tflg-row-action" data-op="note" data-id="' . $id . '" data-note="' . esc_attr( $item->admin_note ) . '">' . ( $item->admin_note ? esc_html__( 'Edit note', 'trade-free-link-guardian' ) : esc_html__( 'Add note', 'trade-free-link-guardian' ) ) . '</a>';

			if ( 'broken' === $item->check_result && 'none' === $item->fix_status ) {
				$actions['autofix']  = $this->action_link( $id, 'autofix', __( 'Auto-fix', 'trade-free-link-guardian' ) );
				$actions['replace']  = $this->action_link( $id, 'replace', __( 'Edit URL…', 'trade-free-link-guardian' ), $item->url );
				if ( 'internal' === $item->url_type ) {
					$actions['restore'] = $this->action_link( $id, 'restore_post', __( 'Restore from trash', 'trade-free-link-guardian' ) );
				} else {
					$actions['unlink'] = $this->action_link( $id, 'unlink', __( 'Unlink', 'trade-free-link-guardian' ) );
					if ( TFLG_Util::get_setting( 'enable_wayback' ) ) {
						$actions['wayback'] = $this->action_link( $id, 'wayback', __( 'Find archived version', 'trade-free-link-guardian' ) );
					}
					if ( TFLG_Util::get_setting( 'enable_archive_today' ) ) {
						$actions['archive_today'] = '<a href="' . esc_url( TFLG_Util::archive_today_url( $item->url ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Try archive.today', 'trade-free-link-guardian' ) . '</a>';
					}
					if ( TFLG_Util::get_setting( 'enable_memento' ) ) {
						$actions['memento'] = '<a href="' . esc_url( TFLG_Util::memento_url( $item->url ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Search Memento', 'trade-free-link-guardian' ) . '</a>';
					}
				}
				$actions['ignore'] = $this->action_link( $id, 'ignore', __( 'Ignore', 'trade-free-link-guardian' ) );
			} elseif ( 'ok' === $item->check_result && 'permanent' === $item->redirect_type && 'none' === $item->fix_status ) {
				$actions['use_final'] = $this->action_link( $id, 'use_final', __( 'Update to final URL', 'trade-free-link-guardian' ) );
			} elseif ( 'unknown' === $item->check_result && 'none' === $item->fix_status ) {
				// Every "unknown" result is an internal-type link a live
				// self-check couldn't settle either way — sometimes a
				// genuinely ambiguous case, sometimes (as with a link
				// mangled by bad source HTML) a URL that was never going
				// to resolve to anything, checkable or not. Either way,
				// a manual correction is often the only real fix.
				$actions['replace'] = $this->action_link( $id, 'replace', __( 'Edit URL…', 'trade-free-link-guardian' ), $item->url );
				$actions['unlink']  = $this->action_link( $id, 'unlink', __( 'Unlink', 'trade-free-link-guardian' ) );
				$actions['ignore']  = $this->action_link( $id, 'ignore', __( 'Ignore', 'trade-free-link-guardian' ) );
			} elseif ( 'ignored' === $item->fix_status ) {
				$actions['unignore'] = $this->action_link( $id, 'unignore', __( 'Stop ignoring', 'trade-free-link-guardian' ) );
			}

			return $actions;
		}

		protected function action_link( $id, $op, $label, $data_url = null ) {
			$data_attr = ( null !== $data_url ) ? ' data-url="' . esc_attr( $data_url ) . '"' : '';
			return sprintf(
				'<a href="#" class="tflg-row-action" data-op="%s" data-id="%d"%s>%s</a>',
				esc_attr( $op ),
				(int) $id,
				$data_attr,
				esc_html( $label )
			);
		}

		public function column_status( $item ) {
			$class = 'tflg-badge tflg-badge--' . esc_attr( $item->check_result );
			$label = TFLG_Util::status_label( $item->check_result, $item->http_status, $item->last_checked );
			$out   = '<span class="' . $class . '">' . esc_html( $label ) . '</span>';
			if ( $item->error_note ) {
				$out .= '<div class="tflg-sub">' . esc_html( $item->error_note ) . '</div>';
			}
			if ( 'none' !== $item->fix_status ) {
				$out .= '<div class="tflg-sub tflg-fix-status">' . esc_html( $this->fix_status_label( $item->fix_status ) ) . '</div>';
			}
			return $out;
		}

		protected function fix_status_label( $status ) {
			return TFLG_Util::fix_status_label( $status );
		}

		public function column_occurrences( $item ) {
			$occurrences = TFLG_DB::get_occurrences_for_link( $item->id );
			$count       = count( $occurrences );
			if ( 0 === $count ) {
				return '—';
			}
			$lines = array();
			$shown = array_slice( $occurrences, 0, 3 );
			foreach ( $shown as $occ ) {
				$lines[] = $this->occurrence_link( $occ );
			}
			$out = implode( '<br />', $lines );
			if ( $count > 3 ) {
				/* translators: %d: number of additional places a link appears */
				$out .= '<div class="tflg-sub">' . sprintf( esc_html__( '+ %d more', 'trade-free-link-guardian' ), $count - 3 ) . '</div>';
			}
			return $out;
		}

		protected function occurrence_link( $occ ) {
			$label = $occ->source_label ? $occ->source_label : __( '(untitled)', 'trade-free-link-guardian' );
			if ( 'post' === $occ->source_type ) {
				$edit = get_edit_post_link( $occ->source_id );
				return $edit ? '<a href="' . esc_url( $edit ) . '" class="tflg-link">' . esc_html( $label ) . '</a>' : esc_html( $label );
			}
			if ( 'nav_menu_item' === $occ->source_type ) {
				return esc_html__( 'Menu item:', 'trade-free-link-guardian' ) . ' ' . esc_html( $label );
			}
			return esc_html( $label );
		}

		public function column_last_checked( $item ) {
			if ( ! $item->last_checked ) {
				return esc_html__( 'Never', 'trade-free-link-guardian' );
			}
			$timestamp = strtotime( $item->last_checked . ' UTC' );
			/* translators: %s: human-readable time difference, e.g. "3 hours" */
			return sprintf( esc_html__( '%s ago', 'trade-free-link-guardian' ), human_time_diff( $timestamp, time() ) );
		}

		public function column_default( $item, $column_name ) {
			return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
		}

		public function prepare_items() {
			global $wpdb;

			$per_page = 20;
			$table    = TFLG_DB::links_table();
			$occ      = TFLG_DB::occurrences_table();

			$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			list( $where_sql, $params ) = TFLG_DB::build_filter_where( $status, $search );

			$allowed_orderby = array( 'url', 'check_result', 'last_checked', 'occurrences' );
			$orderby_key      = ( isset( $_REQUEST['orderby'] ) && in_array( wp_unslash( $_REQUEST['orderby'] ), $allowed_orderby, true ) ) ? sanitize_key( $_REQUEST['orderby'] ) : 'first_seen'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order            = ( isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// "Found in" isn't a real column here — a single link can
			// appear on more than one page, so there's no one value to
			// sort by directly. The alphabetically-first source label
			// for each link stands in as a representative sort key,
			// computed once per row via a correlated subquery — cheap,
			// since it only ever runs against this page's 20 rows (not
			// the whole table) and the lookup itself is indexed.
			$order_expr = ( 'occurrences' === $orderby_key ) ? 'found_in_sort' : "{$table}.{$orderby_key}";

			$current_page = $this->get_pagenum();
			$offset       = ( $current_page - 1 ) * $per_page;

			$sql            = "SELECT {$table}.*, (SELECT MIN(o.source_label) FROM {$occ} o WHERE o.link_id = {$table}.id) AS found_in_sort, "
							. "(SELECT COUNT(*) FROM {$occ} o WHERE o.link_id = {$table}.id) AS occurrence_count, " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							. "(SELECT COUNT(*) FROM {$occ} o WHERE o.link_id = {$table}.id AND o.source_type = 'nav_menu_item') AS menu_occurrence_count " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							. "FROM {$table} WHERE {$where_sql} ORDER BY {$order_expr} {$order}, {$table}.id ASC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$list_params    = $params;
			$list_params[]  = $per_page;
			$list_params[]  = $offset;

			$this->items = $wpdb->get_results( $wpdb->prepare( $sql, $list_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$total_items = TFLG_DB::count_links_matching( $status, $search );

			$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
			$this->set_pagination_args(
				array(
					'total_items' => $total_items,
					'per_page'    => $per_page,
					'total_pages' => (int) ceil( $total_items / $per_page ),
				)
			);
		}
	}
}

/* =========================================================================
 * SECTION: TFLG_Admin — menu, screens, assets
 * ========================================================================= */

class TFLG_Admin {

	protected static $hooks = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_tflg_export_csv', array( __CLASS__, 'handle_export_csv' ) );
		add_action( 'admin_post_tflg_export_compliance_csv', array( __CLASS__, 'handle_export_compliance_csv' ) );
		add_action( 'admin_post_tflg_export_sitemap_csv', array( __CLASS__, 'handle_export_sitemap_csv' ) );
		add_action( 'admin_post_tflg_create_redirect', array( __CLASS__, 'handle_create_redirect' ) );
		add_action( 'admin_post_tflg_delete_redirect', array( __CLASS__, 'handle_delete_redirect' ) );
	}

	public static function register_menu() {
		self::$hooks['dashboard'] = add_menu_page(
			__( 'Trade-Free Link Guardian', 'trade-free-link-guardian' ),
			__( 'Link Guardian', 'trade-free-link-guardian' ),
			TFLG_CAPABILITY,
			'tflg-dashboard',
			array( __CLASS__, 'render_dashboard' ),
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xMCAyIEwxNiA0LjUgTDE2IDkgTDEwIDE4IEw0IDkgTDQgNC41IFoiLz48L3N2Zz4K', // A plain shield outline, drawn from straight lines only (not hand-fitted curves this environment can't render-check) -- a small, deliberate identity of its own in the admin sidebar, in place of the generic chain-link dashicon several other link-related plugins also use.
			80
		);
		self::$hooks['dashboard'] = add_submenu_page( 'tflg-dashboard', __( 'Dashboard', 'trade-free-link-guardian' ), __( 'Dashboard', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-dashboard', array( __CLASS__, 'render_dashboard' ) );
		self::$hooks['links']     = add_submenu_page( 'tflg-dashboard', __( 'Broken Links', 'trade-free-link-guardian' ), __( 'Links', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-links', array( __CLASS__, 'render_links' ) );
		self::$hooks['book']      = add_submenu_page( 'tflg-dashboard', __( 'Book View', 'trade-free-link-guardian' ), __( 'Book View', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-book', array( __CLASS__, 'render_book' ) );
		self::$hooks['health']    = add_submenu_page( 'tflg-dashboard', __( 'Health Map', 'trade-free-link-guardian' ), __( 'Health Map', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-health', array( __CLASS__, 'render_health_map' ) );
		self::$hooks['domains']   = add_submenu_page( 'tflg-dashboard', __( 'By Domain', 'trade-free-link-guardian' ), __( 'By Domain', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-domains', array( __CLASS__, 'render_domain_view' ) );
		self::$hooks['trends']    = add_submenu_page( 'tflg-dashboard', __( 'Trends', 'trade-free-link-guardian' ), __( 'Trends', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-trends', array( __CLASS__, 'render_trends' ) );
		self::$hooks['settings']  = add_submenu_page( 'tflg-dashboard', __( 'Settings', 'trade-free-link-guardian' ), __( 'Settings', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-settings', array( __CLASS__, 'render_settings' ) );
		self::$hooks['log']       = add_submenu_page( 'tflg-dashboard', __( 'Fix Log', 'trade-free-link-guardian' ), __( 'Fix Log', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-log', array( __CLASS__, 'render_log' ) );
		self::$hooks['404log']    = add_submenu_page( 'tflg-dashboard', __( '404 Log', 'trade-free-link-guardian' ), __( '404 Log', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-404log', array( __CLASS__, 'render_404_log' ) );
		self::$hooks['redirects'] = add_submenu_page( 'tflg-dashboard', __( 'Redirects', 'trade-free-link-guardian' ), __( 'Redirects', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-redirects', array( __CLASS__, 'render_redirects' ) );
		self::$hooks['orphans']   = add_submenu_page( 'tflg-dashboard', __( 'Orphan Pages', 'trade-free-link-guardian' ), __( 'Orphan Pages', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-orphan-pages', array( __CLASS__, 'render_orphan_pages' ) );
		self::$hooks['compliance'] = add_submenu_page( 'tflg-dashboard', __( 'Compliance Report', 'trade-free-link-guardian' ), __( 'Compliance Report', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-compliance', array( __CLASS__, 'render_compliance_report' ) );
		self::$hooks['sitemap']    = add_submenu_page( 'tflg-dashboard', __( 'Sitemap Tree', 'trade-free-link-guardian' ), __( 'Sitemap Tree', 'trade-free-link-guardian' ), TFLG_CAPABILITY, 'tflg-sitemap', array( __CLASS__, 'render_sitemap_tree' ) );

		// Keeps the sidebar to the screens used most often. Everything
		// hidden here is still a fully working, directly linkable page —
		// this only removes it from the menu list, nothing about the
		// page itself changes -- and each one remains reachable from the
		// "More views" section added to the Dashboard for exactly this
		// reason, so nothing becomes harder to find, just less
		// permanently present in the sidebar for a feature not every
		// visit needs.
		foreach ( array( 'tflg-book', 'tflg-domains', 'tflg-trends', 'tflg-orphan-pages', 'tflg-compliance', 'tflg-sitemap' ) as $hidden_slug ) {
			remove_submenu_page( 'tflg-dashboard', $hidden_slug );
		}
	}

	public static function enqueue( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, self::$hooks, true ) ) {
			return;
		}

		wp_register_style( 'tflg-admin', false, array(), TFLG_VERSION );
		wp_enqueue_style( 'tflg-admin' );
		wp_add_inline_style( 'tflg-admin', self::css() );

		wp_register_script( 'tflg-admin', false, array(), TFLG_VERSION, true );
		wp_enqueue_script( 'tflg-admin' );
		wp_localize_script(
			'tflg-admin',
			'TFLG',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'tflg_ajax' ),
				'i18n'    => array(
					'confirmUnlink' => __( 'Remove this link from the content, keeping any text or image that was inside it?', 'trade-free-link-guardian' ),
					'promptReplace' => __( 'Edit the URL:', 'trade-free-link-guardian' ),
				'promptNote'    => __( 'Note for this link (leave blank to clear):', 'trade-free-link-guardian' ),
					'working'       => __( 'Working…', 'trade-free-link-guardian' ),
					'checking'      => __( 'Checking links…', 'trade-free-link-guardian' ),
					'scanning'      => __( 'Reading your content…', 'trade-free-link-guardian' ),
					'retrying'      => __( 'A request took too long — retrying…', 'trade-free-link-guardian' ),
					'gaveUp'        => __( 'Still not responding after several tries. Reload the page and try again — what was already checked has been saved.', 'trade-free-link-guardian' ),
					'cancelled'     => __( 'Stopped. What was already scanned or checked has been saved.', 'trade-free-link-guardian' ),
					'left'          => __( 'left', 'trade-free-link-guardian' ),
					/* translators: {checked} and {broken} are literal placeholder tokens replaced in JavaScript, not printf-style — keep them exactly as written. */
					'doneSummary'   => __( 'Done — checked {checked} links, {broken} need attention.', 'trade-free-link-guardian' ),
					'chooseBulkAction' => __( 'Choose a bulk action first.', 'trade-free-link-guardian' ),
					/* translators: {count} is a literal placeholder token replaced in JavaScript, not printf-style. */
					'bulkDone'      => __( 'Done — applied to {count} link(s).', 'trade-free-link-guardian' ),
					/* translators: {count} is a literal placeholder token replaced in JavaScript, not printf-style. */
					'bulkStopped'   => __( 'Stopped — applied to {count} link(s) before stopping.', 'trade-free-link-guardian' ),
					'genericError'  => __( 'Something went wrong.', 'trade-free-link-guardian' ),
					'reportColUrl'      => __( 'URL', 'trade-free-link-guardian' ),
					'reportColMethod'   => __( 'Proposed method', 'trade-free-link-guardian' ),
					'reportColProposed' => __( 'Proposed URL', 'trade-free-link-guardian' ),
					'reportNoneBroken'  => __( 'No broken links right now — nothing to report.', 'trade-free-link-guardian' ),
					'undoneOk'          => __( 'link undone', 'trade-free-link-guardian' ),
					'undoneOkPlural'    => __( 'links undone', 'trade-free-link-guardian' ),
					'undoneFailed'      => __( 'could not be undone', 'trade-free-link-guardian' ),
				),
			)
		);
		wp_add_inline_script( 'tflg-admin', self::js() );
	}

	protected static function css() {
		return <<<'CSS'
:root{
--tflg-accent:#0f5d6b;
--tflg-accent-hover:#0b4750;
--tflg-ok:#00a32a;
--tflg-danger:#d63638;
--tflg-warn:#dba617;
--tflg-warn-text:#996800;
--tflg-ink:#1d2327;
--tflg-ink-soft:#3c434a;
--tflg-ink-muted:#646970;
--tflg-border:#c3c4c7;
--tflg-border-soft:#dcdcde;
--tflg-border-hover:#8c8f94;
--tflg-surface:#fff;
--tflg-surface-sunken:#f6f7f7;
--tflg-hairline:#f0f0f1;
--tflg-space-1:4px;
--tflg-space-2:8px;
--tflg-space-3:16px;
--tflg-space-4:24px;
--tflg-space-5:32px;
--tflg-radius:6px;
}
#tflg-progress[hidden],#tflg-cancel-btn[hidden],#tflg-tally[hidden]{display:none!important}
.tflg-stats{display:flex;flex-wrap:wrap;gap:var(--tflg-space-3);margin:var(--tflg-space-3) 0}
.tflg-stat-card{display:block;background:var(--tflg-surface);border:1px solid var(--tflg-border);border-radius:var(--tflg-radius);padding:14px 18px;min-width:130px;text-decoration:none;transition:box-shadow .12s,border-color .12s}
.tflg-stat-card:hover,.tflg-stat-card:focus{border-color:var(--tflg-accent);box-shadow:0 1px 4px rgba(15,93,107,.15)}
.tflg-stat-card .tflg-stat-number{display:block;font-size:26px;font-weight:600;line-height:1.2;color:var(--tflg-ink)}
.tflg-stat-card .tflg-stat-label{display:block;margin-top:var(--tflg-space-1);color:var(--tflg-ink-muted);font-size:13px}
.tflg-stat-card--broken .tflg-stat-number{color:var(--tflg-danger)}
.tflg-stat-card--fixed .tflg-stat-number{color:var(--tflg-ok)}
.tflg-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;white-space:nowrap}
.tflg-badge--broken{background:#fcf0f1;color:var(--tflg-danger)}
.tflg-badge--ok{background:#edfaef;color:var(--tflg-ok)}
.tflg-badge--unknown{background:#fcf9e8;color:var(--tflg-warn-text)}
.tflg-sub{color:var(--tflg-ink-muted);font-size:12px;margin-top:var(--tflg-space-1)}
.tflg-drift-warning{margin-top:var(--tflg-space-1);padding:4px 8px;background:#fef8ee;border-left:3px solid var(--tflg-warn);font-size:12px;color:var(--tflg-ink-soft)}
.tflg-impact-badge{margin-top:var(--tflg-space-1);display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;background:#eef5f6;color:var(--tflg-accent)}
.tflg-drift-warning a{margin-left:6px}
.tflg-compliance-table{max-width:600px;margin-bottom:var(--tflg-space-4)}
.tflg-compliance-table th{width:60%;text-align:left}
@media print{
#adminmenumain,#wpadminbar,#wpfooter,#screen-meta-links,.tflg-no-print{display:none!important}
#wpcontent,#wpbody-content{margin-left:0!important;padding:0!important}
.tflg-compliance{margin:0}
}
.tflg-url,.tflg-sub a{word-break:break-all}
.tflg-fixlog-table td{word-break:break-all}
.tflg-url,.tflg-undo-fix,.tflg-row-action,.tflg-book-goto,.tflg-sub a,.tflg-fixlog-table a,.tflg-link{color:var(--tflg-accent);text-decoration:none}
.tflg-url:hover,.tflg-undo-fix:hover,.tflg-row-action:hover,.tflg-book-goto:hover,.tflg-sub a:hover,.tflg-fixlog-table a:hover,.tflg-link:hover{color:var(--tflg-accent-hover);text-decoration:underline}
.tflg-book-page h2 a{text-decoration:none}
.tflg-book-page h2 a:hover{text-decoration:underline}
.tflg-book{max-width:1180px}
.tflg-book-toolbar{display:flex;align-items:center;gap:var(--tflg-space-3);margin-bottom:14px}
.tflg-book-search-wrap{position:relative;flex:1 1 auto;max-width:400px}
.tflg-book-counter{color:var(--tflg-ink-muted);font-size:13px;white-space:nowrap}
.tflg-book-search-results{position:absolute;top:100%;left:0;right:0;z-index:10;background:var(--tflg-surface);border:1px solid var(--tflg-border);border-top:none;max-height:260px;overflow-y:auto;margin:0;padding:0;list-style:none;box-shadow:0 4px 8px rgba(0,0,0,.08)}
.tflg-book-search-results li{border-bottom:1px solid var(--tflg-hairline)}
.tflg-book-search-results li:last-child{border-bottom:none}
.tflg-book-search-results a{display:block;padding:8px 12px;color:var(--tflg-ink);text-decoration:none}
.tflg-book-search-results a:hover,.tflg-book-search-results a:focus{background:#eef5f6}
.tflg-book-layout{display:flex;gap:var(--tflg-space-4);align-items:flex-start}
.tflg-book-main{flex:1 1 auto;min-width:0}
.tflg-book-toc-sidebar{flex:0 0 300px;position:sticky;top:32px;max-height:calc(100vh - 80px);overflow-y:auto;background:var(--tflg-surface);border:1px solid var(--tflg-border-soft);border-radius:var(--tflg-radius);padding:18px 20px}
.tflg-book-toc-sidebar h2{margin-top:0;font-size:15px}
.tflg-book-page{display:none;background:var(--tflg-surface);border:1px solid var(--tflg-border-soft);border-radius:var(--tflg-radius);box-shadow:0 1px 3px rgba(0,0,0,.08);padding:32px 36px;min-height:320px}
.tflg-book-page--visible{display:block;animation:tflg-page-in .18s ease-out}
@keyframes tflg-page-in{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
.tflg-book-page h2{margin-top:0}
.tflg-book-page-url{margin-top:-8px;margin-bottom:20px}
.tflg-book-toc{margin:0;padding-left:20px}
.tflg-book-toc li{margin-bottom:var(--tflg-space-2);border-radius:3px}
.tflg-book-toc li.tflg-book-toc-current{background:#eef5f6;padding:4px 6px;margin-left:-6px;margin-right:-6px}
.tflg-book-toc li.tflg-book-toc-current>.tflg-book-goto{font-weight:600}
.tflg-book-goto{font-size:13px;text-decoration:none}
.tflg-book-goto:hover{text-decoration:underline}
.tflg-book-linklist{list-style:none;margin:0;padding:0}
.tflg-book-linklist>li{display:flex;gap:10px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--tflg-hairline)}
.tflg-book-linklist>li:last-child{border-bottom:none}
.tflg-book-linklist-actions{margin-top:var(--tflg-space-1);font-size:12px}
@media (max-width:960px){
.tflg-book-layout{flex-direction:column}
.tflg-book-toc-sidebar{position:static;flex:1 1 auto;width:100%;max-height:340px;order:-1}
}
.tflg-health-toolbar{margin:var(--tflg-space-3) 0}
.tflg-health-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));grid-auto-rows:90px;gap:var(--tflg-space-2);grid-auto-flow:dense;max-width:1180px}
.tflg-health-tile{display:flex;flex-direction:column;justify-content:flex-end;border-radius:8px;padding:10px;text-decoration:none;color:#fff;overflow:hidden;transition:transform .12s,box-shadow .12s;box-shadow:0 1px 2px rgba(0,0,0,.12)}
.tflg-health-tile:hover,.tflg-health-tile:focus{transform:translateY(-2px);box-shadow:0 4px 10px rgba(0,0,0,.2)}
.tflg-health-tile--md{grid-column:span 2}
.tflg-health-tile--lg{grid-column:span 2;grid-row:span 2}
.tflg-health-tile-title{font-size:12px;font-weight:600;line-height:1.25;text-shadow:0 1px 2px rgba(0,0,0,.35);overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical}
.tflg-health-tile--sm .tflg-health-tile-title{-webkit-line-clamp:2;font-size:11px}
.tflg-health-tile-count{font-size:11px;opacity:.9;margin-top:2px;text-shadow:0 1px 2px rgba(0,0,0,.35)}
.tflg-health-tile[hidden]{display:none}
.tflg-domain-table{max-width:900px}
.tflg-domain-table th.tflg-sortable{cursor:pointer;user-select:none;white-space:nowrap}
.tflg-domain-table th.tflg-sortable:hover{color:var(--tflg-accent)}
.tflg-domain-table th.tflg-sortable::after{content:'';display:inline-block;margin-left:4px;width:0;height:0;border-left:4px solid transparent;border-right:4px solid transparent;vertical-align:middle;opacity:.3}
.tflg-domain-table th.tflg-sort-asc::after{border-bottom:5px solid var(--tflg-accent);opacity:1}
.tflg-domain-table th.tflg-sort-desc::after{border-top:5px solid var(--tflg-accent);opacity:1}
.tflg-domain-health-bar{display:block;width:100px;height:10px;background:var(--tflg-hairline);border-radius:5px;overflow:hidden}
.tflg-domain-health-fill{display:block;height:100%;border-radius:5px}
.tflg-trends-chart{width:100%;max-width:960px;height:auto;background:var(--tflg-surface);border:1px solid var(--tflg-border-soft);border-radius:var(--tflg-radius);padding:8px}
.tflg-trends-gridline{stroke:var(--tflg-hairline);stroke-width:1}
.tflg-trends-axis-label{fill:var(--tflg-ink-muted);font-size:11px}
.tflg-trends-legend{display:flex;gap:20px;margin:14px 0 0;padding:0;list-style:none}
.tflg-trends-legend li{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--tflg-ink-soft)}
.tflg-trends-swatch{display:inline-block;width:12px;height:12px;border-radius:2px}
.tflg-url-type{font-size:10px;letter-spacing:.03em;color:var(--tflg-ink-muted);border:1px solid var(--tflg-border-soft);border-radius:3px;padding:1px 5px;margin-right:4px}
.tflg-progress{margin:14px 0;max-width:480px}
.tflg-progress-track{background:var(--tflg-border-soft);border-radius:4px;height:10px;overflow:hidden}
.tflg-progress-fill{background:var(--tflg-accent);height:100%;width:0%;transition:width .2s ease}
.tflg-progress-phase,.tflg-progress-label{font-size:12px;color:var(--tflg-ink-muted);margin-top:var(--tflg-space-1)}
.tflg-tally{margin-top:6px;font-size:12px;display:flex;flex-wrap:wrap;gap:var(--tflg-space-3);align-items:baseline}
.tflg-tally-item{color:var(--tflg-ink-muted)}
.tflg-tally-num{font-weight:600;font-variant-numeric:tabular-nums}
.tflg-tally-item--ok .tflg-tally-num{color:var(--tflg-ok)}
.tflg-tally-item--broken .tflg-tally-num{color:var(--tflg-danger)}
.tflg-tally-item--unknown .tflg-tally-num{color:var(--tflg-warn-text)}
.tflg-tally-rate{color:var(--tflg-border-hover);margin-left:auto}
.tflg-activity{list-style:none;margin:10px 0 0;padding:0;max-width:640px}
.tflg-activity li{display:flex;gap:8px;align-items:baseline;font-size:12px;padding:3px 0;border-bottom:1px solid var(--tflg-hairline);color:var(--tflg-ink-soft)}
.tflg-activity li:last-child{border-bottom:none}
.tflg-activity .tflg-activity-mark{flex:0 0 auto;font-weight:600}
.tflg-activity .tflg-activity-mark--ok{color:var(--tflg-ok)}
.tflg-activity .tflg-activity-mark--broken{color:var(--tflg-danger)}
.tflg-activity .tflg-activity-mark--unknown{color:var(--tflg-warn-text)}
.tflg-activity .tflg-activity-mark--scan{color:var(--tflg-border-hover)}
.tflg-activity .tflg-activity-text{word-break:break-all}
.tflg-activity a.tflg-activity-text{color:var(--tflg-accent);text-decoration:none}
.tflg-activity a.tflg-activity-text:hover{text-decoration:underline}
.tflg-help{color:var(--tflg-ink-muted);font-size:13px;max-width:760px}
.tflg-recent{list-style:disc;margin-left:20px}
.tflg-recent li{margin-bottom:var(--tflg-space-1);word-break:break-all}
.tflg-recent a{color:var(--tflg-accent);text-decoration:none}
.tflg-recent a:hover{text-decoration:underline}
.tflg-sitemap-tree{list-style:none;margin:0 0 var(--tflg-space-4);padding-left:0}
.tflg-sitemap-tree ul{list-style:none;margin:4px 0 4px 20px;padding-left:16px;border-left:1px solid var(--tflg-border-soft)}
.tflg-sitemap-tree li{padding:3px 0}
.tflg-sitemap-tree summary{cursor:pointer}
.tflg-sitemap-tree a{color:var(--tflg-accent);text-decoration:none}
.tflg-sitemap-tree a:hover{text-decoration:underline}
.tflg-sitemap-badge{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;color:#fff}
.tflg-welcome{background:var(--tflg-surface);border:1px solid var(--tflg-border-soft);border-left:4px solid var(--tflg-accent);border-radius:var(--tflg-radius);padding:var(--tflg-space-5);max-width:640px;margin:var(--tflg-space-3) 0}
.tflg-welcome h2{margin-top:0;font-size:19px}
.tflg-welcome p{max-width:520px;color:var(--tflg-ink-soft)}
.tflg-wizard{max-width:640px;margin:var(--tflg-space-3) 0;background:var(--tflg-surface);border:1px solid var(--tflg-border-soft);border-radius:var(--tflg-radius);padding:var(--tflg-space-5);box-shadow:0 1px 3px rgba(0,0,0,.06)}
.tflg-wizard-progress{display:flex;align-items:center;justify-content:center;margin-bottom:var(--tflg-space-5)}
.tflg-wizard-dot{display:flex;align-items:center;justify-content:center;flex:0 0 auto;width:32px;height:32px;border-radius:50%;background:var(--tflg-border-soft);color:var(--tflg-ink-muted);font-weight:600;font-size:14px;transition:background-color .25s,color .25s}
.tflg-wizard-dot--active,.tflg-wizard-dot--done{background:var(--tflg-accent);color:#fff}
.tflg-wizard-line{width:48px;height:2px;background:var(--tflg-border-soft);transition:background-color .25s}
.tflg-wizard-line--done{background:var(--tflg-accent)}
.tflg-wizard-panel{display:none}
.tflg-wizard-panel--active{display:block;animation:tflg-wizard-in .25s ease-out}
@keyframes tflg-wizard-in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.tflg-wizard-panel h2{margin-top:0;font-size:20px}
.tflg-wizard-sub{color:var(--tflg-ink-muted);font-size:13px;margin-top:-6px}
.tflg-wizard-panel fieldset{border:none;margin:0 0 var(--tflg-space-3);padding:0}
.tflg-wizard-panel legend{font-weight:600;font-size:13px;margin-bottom:6px;padding:0}
.tflg-wizard-check{display:block;margin-bottom:6px;font-size:14px}
.tflg-wizard-actions{margin-top:var(--tflg-space-4);display:flex;gap:12px;align-items:center}
.tflg-more-views{list-style:none;margin:var(--tflg-space-2) 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:var(--tflg-space-3);max-width:900px}
.tflg-more-views li{background:var(--tflg-surface);border:1px solid var(--tflg-border-soft);border-radius:var(--tflg-radius);padding:12px 14px}
.tflg-more-views .tflg-link{font-weight:600}
.tflg-more-views .tflg-sub{display:block;margin-top:2px}
CSS;
	}

	protected static function js() {
		return <<<'JS'
(function(){
	'use strict';
	var cancelled=false;
	var tally={ok:0,broken:0,unknown:0};
	var scanPhaseStart=0;
	var checkPhaseStart=0;
	function post(action,data){
		var params=Object.assign({action:action,nonce:TFLG.nonce},data||{});
		var body=new URLSearchParams(params);
		return fetch(TFLG.ajaxUrl,{method:'POST',credentials:'same-origin',body:body}).then(function(r){
			if(!r.ok){throw new Error('HTTP '+r.status);}
			return r.json();
		});
	}
	function setBar(done,total,phaseText,rateStr){
		var wrap=document.getElementById('tflg-progress');
		if(!wrap)return;
		var pct= total>0 ? Math.round((Math.min(done,total)/total)*100) : 100;
		var fill=wrap.querySelector('.tflg-progress-fill');
		fill.style.width=pct+'%';
		fill.setAttribute('aria-valuenow',String(pct));
		var label=Math.min(done,total)+' / '+total;
		if(rateStr)label+='  \u00b7  '+rateStr;
		wrap.querySelector('.tflg-progress-label').textContent=label;
		if(phaseText)wrap.querySelector('.tflg-progress-phase').textContent=phaseText;
	}
	function addActivity(entries,kind){
		var list=document.getElementById('tflg-activity');
		if(!list||!entries||!entries.length)return;
		entries.forEach(function(e){
			var li=document.createElement('li');
			var mark=document.createElement('span');
			if('scan'===kind){
				mark.className='tflg-activity-mark tflg-activity-mark--scan';
				mark.textContent='\u2022';
				var text=document.createElement('span');
				text.className='tflg-activity-text';
				text.textContent=e;
				li.appendChild(mark);
				li.appendChild(text);
			}else{
				var cls = 'ok'===e.result ? 'ok' : ('broken'===e.result ? 'broken' : 'unknown');
				mark.className='tflg-activity-mark tflg-activity-mark--'+cls;
				mark.textContent = 'ok'===e.result ? '\u2713' : ('broken'===e.result ? '\u2717' : '?');
				var link=document.createElement('a');
				link.className='tflg-activity-text';
				link.href=e.url;
				link.target='_blank';
				link.rel='noopener noreferrer';
				link.textContent=e.url+(e.status?' ('+e.status+')':'');
				li.appendChild(mark);
				li.appendChild(link);
			}
			list.insertBefore(li,list.firstChild);
		});
		while(list.children.length>8){
			list.removeChild(list.lastChild);
		}
	}
	function showRunningControls(){
		var scanBtn=document.getElementById('tflg-scan-btn');
		var cancelBtn=document.getElementById('tflg-cancel-btn');
		if(scanBtn)scanBtn.disabled=true;
		if(cancelBtn)cancelBtn.hidden=false;
	}
	var wizardEl=document.getElementById('tflg-wizard');
	if(wizardEl){
		var wizardPanels=Array.prototype.slice.call(wizardEl.querySelectorAll('.tflg-wizard-panel'));
		var wizardDots=Array.prototype.slice.call(wizardEl.querySelectorAll('.tflg-wizard-dot'));
		var wizardLines=Array.prototype.slice.call(wizardEl.querySelectorAll('.tflg-wizard-line'));
		var wizardCurrent=0;
		function wizardShow(n){
			if(n<0||n>=wizardPanels.length)return;
			wizardPanels[wizardCurrent].hidden=true;
			wizardPanels[wizardCurrent].classList.remove('tflg-wizard-panel--active');
			wizardCurrent=n;
			wizardPanels[wizardCurrent].hidden=false;
			wizardPanels[wizardCurrent].classList.add('tflg-wizard-panel--active');
			wizardDots.forEach(function(dot,i){
				dot.classList.toggle('tflg-wizard-dot--active',i===n);
				dot.classList.toggle('tflg-wizard-dot--done',i<n);
			});
			wizardLines.forEach(function(line,i){
				line.classList.toggle('tflg-wizard-line--done',i<n);
			});
			var heading=wizardPanels[wizardCurrent].querySelector('h2');
			if(heading){heading.setAttribute('tabindex','-1');heading.focus();}
		}
		Array.prototype.slice.call(wizardEl.querySelectorAll('.tflg-wizard-next')).forEach(function(btn){
			btn.addEventListener('click',function(){wizardShow(wizardCurrent+1);});
		});
		Array.prototype.slice.call(wizardEl.querySelectorAll('.tflg-wizard-back')).forEach(function(btn){
			btn.addEventListener('click',function(){wizardShow(wizardCurrent-1);});
		});
	}
	function showIdleControls(){
		var scanBtn=document.getElementById('tflg-scan-btn');
		var cancelBtn=document.getElementById('tflg-cancel-btn');
		if(scanBtn)scanBtn.disabled=false;
		if(cancelBtn)cancelBtn.hidden=true;
	}
	function showRetrying(){
		var wrap=document.getElementById('tflg-progress');
		if(wrap)wrap.querySelector('.tflg-progress-phase').textContent=TFLG.i18n.retrying;
	}
	function giveUp(){
		var wrap=document.getElementById('tflg-progress');
		if(wrap)wrap.querySelector('.tflg-progress-phase').textContent=TFLG.i18n.gaveUp;
		showIdleControls();
	}
	function runScan(){
		var wrap=document.getElementById('tflg-progress');
		if(!wrap)return;
		cancelled=false;
		showRunningControls();
		wrap.hidden=false;
		var list=document.getElementById('tflg-activity');
		if(list)list.innerHTML='';
		resetTally();
		setBar(0,1,TFLG.i18n.scanning);
		scanPhaseStart=Date.now();
		scanStart(0);
	}
	function cancelScan(){
		cancelled=true;
		post('tflg_scan_cancel',{}).catch(function(){});
		var wrap=document.getElementById('tflg-progress');
		if(wrap)wrap.querySelector('.tflg-progress-phase').textContent=TFLG.i18n.cancelled;
		showIdleControls();
	}
	function formatEta(sec){
		sec=Math.max(0,Math.round(sec));
		if(sec<60)return sec+'s';
		return Math.floor(sec/60)+'m '+(sec%60)+'s';
	}
	function rateText(doneCount,startTime,remaining){
		var elapsed=(Date.now()-startTime)/1000;
		if(elapsed<0.5||doneCount<=0)return '';
		var rate=doneCount/elapsed;
		var text=rate.toFixed(1)+'/s';
		if(remaining>0&&rate>0){
			text+=' \u00b7 ~'+formatEta(remaining/rate)+' '+TFLG.i18n.left;
		}
		return text;
	}
	function resetTally(){
		tally={ok:0,broken:0,unknown:0};
		var el=document.getElementById('tflg-tally');
		if(!el)return;
		el.hidden=true;
		['ok','broken','unknown'].forEach(function(k){
			var n=el.querySelector('[data-tally="'+k+'"]');
			if(n)n.textContent='0';
		});
		var r=el.querySelector('.tflg-tally-rate');
		if(r)r.textContent='';
	}
	function updateTally(items,remaining){
		if(!items||!items.length)return;
		items.forEach(function(it){
			var key='ok'===it.result?'ok':('broken'===it.result?'broken':'unknown');
			tally[key]++;
		});
		var el=document.getElementById('tflg-tally');
		if(!el)return;
		el.hidden=false;
		['ok','broken','unknown'].forEach(function(k){
			var n=el.querySelector('[data-tally="'+k+'"]');
			if(n)n.textContent=tally[k];
		});
		var doneCount=tally.ok+tally.broken+tally.unknown;
		var r=el.querySelector('.tflg-tally-rate');
		if(r)r.textContent=rateText(doneCount,checkPhaseStart,remaining);
	}
	function scanStart(attempt){
		if(cancelled)return;
		post('tflg_scan_start',{}).then(function(res){
			if(cancelled)return;
			if(!res.success){finish();return;}
			scanStep(res.data.total||0,0);
		}).catch(function(){
			if(cancelled)return;
			if(attempt>=5){giveUp();return;}
			showRetrying();setTimeout(function(){scanStart(attempt+1);},3000);
		});
	}
	function scanStep(total,attempt){
		if(cancelled)return;
		post('tflg_scan_batch',{}).then(function(res){
			if(cancelled)return;
			if(!res.success){finish();return;}
			var state=res.data;
			var done=state.done, tot=state.total||total;
			setBar(done,tot,TFLG.i18n.scanning,rateText(done,scanPhaseStart,tot-done));
			addActivity(state.items,'scan');
			if('scanning'===state.phase){
				scanStep(tot,0);
			}else{
				startChecking(0);
			}
		}).catch(function(){
			if(cancelled)return;
			if(attempt>=5){giveUp();return;}
			showRetrying();setTimeout(function(){scanStep(total,attempt+1);},3000);
		});
	}
	function startChecking(attempt){
		if(cancelled)return;
		setBar(0,1,TFLG.i18n.checking);
		post('tflg_check_start',{}).then(function(res){
			if(cancelled)return;
			var total = (res.success && res.data.total) ? res.data.total : 0;
			if(total<=0){ finish(); return; }
			checkPhaseStart=Date.now();
			var list=document.getElementById('tflg-activity');
			if(list)list.innerHTML=''; // clear leftover scan-phase entries so the feed doesn't mix "Widget (block)" with URL results
			checkStep(0,total,0);
		}).catch(function(){
			if(cancelled)return;
			if(attempt>=5){giveUp();return;}
			showRetrying();setTimeout(function(){startChecking(attempt+1);},3000);
		});
	}
	function checkStep(checked,total,attempt){
		if(cancelled)return;
		post('tflg_check_batch',{}).then(function(res){
			if(cancelled)return;
			if(!res.success){finish();return;}
			var data=res.data;
			checked+=data.checked||0;
			var tot=Math.max(total,checked);
			setBar(checked,tot,TFLG.i18n.checking);
			addActivity(data.items,'check');
			updateTally(data.items,tot-checked);
			if(data.more){
				checkStep(checked,total,0);
			}else{
				finish();
			}
		}).catch(function(){
			if(cancelled)return;
			if(attempt>=5){giveUp();return;}
			showRetrying();setTimeout(function(){checkStep(checked,total,attempt+1);},3000);
		});
	}
	function finish(){
		var checkedCount=tally.ok+tally.broken+tally.unknown;
		if(checkedCount>0){
			var wrap=document.getElementById('tflg-progress');
			var phaseEl=wrap&&wrap.querySelector('.tflg-progress-phase');
			if(phaseEl){
				phaseEl.textContent=TFLG.i18n.doneSummary
					.replace('{checked}',checkedCount)
					.replace('{broken}',tally.broken);
			}
			setTimeout(function(){window.location.reload();},1700);
			return;
		}
		window.location.reload();
	}
	function rowAction(el){
		var op=el.getAttribute('data-op');
		var id=el.getAttribute('data-id');
		var payload={op:op,link_id:id};
		if('unlink'===op && !window.confirm(TFLG.i18n.confirmUnlink))return;
		if('replace'===op){
			var current=el.getAttribute('data-url')||'';
			var url=window.prompt(TFLG.i18n.promptReplace,current);
			if(!url)return;
			payload.new_url=url;
		}
		if('note'===op){
			var currentNote=el.getAttribute('data-note')||'';
			var newNote=window.prompt(TFLG.i18n.promptNote,currentNote);
			if(null===newNote)return;
			payload.note=newNote;
		}
		var original=el.textContent;
		el.textContent=TFLG.i18n.working;
		post('tflg_row_action',payload).then(function(res){
			if(!res.success){
				window.alert(res.data&&res.data.message?res.data.message:TFLG.i18n.genericError);
				el.textContent=original;
				return;
			}
			if('wayback'===op && res.data && res.data.snapshot_url){
				var use=window.confirm('Archived version found:\n'+res.data.snapshot_url+'\n\nUse this as the replacement?');
				if(use){
					post('tflg_row_action',{op:'replace',link_id:id,new_url:res.data.snapshot_url}).then(function(res2){
						if(res2.success){
							window.location.reload();
						}else{
							window.alert(res2.data&&res2.data.message?res2.data.message:TFLG.i18n.genericError);
							el.textContent=original;
						}
					});
				}else{
					el.textContent=original;
				}
				return;
			}
			window.location.reload();
		});
	}
	function undoFix(el){
		var id=el.getAttribute('data-id');
		post('tflg_undo_fix',{fixlog_id:id}).then(function(res){
			if(res.success){
				window.location.reload();
			}else{
				window.alert(res.data&&res.data.message?res.data.message:TFLG.i18n.genericError);
			}
		});
	}
	function fixlogCheckboxes(){
		return Array.prototype.slice.call(document.querySelectorAll('.tflg-fixlog-checkbox'));
	}
	function updateUndoSelectedBtn(){
		var btn=document.getElementById('tflg-undo-selected-btn');
		if(!btn)return;
		btn.disabled=!fixlogCheckboxes().some(function(cb){return cb.checked;});
	}
	var fixlogSelectAll=document.getElementById('tflg-fixlog-select-all');
	if(fixlogSelectAll){
		fixlogSelectAll.addEventListener('change',function(){
			fixlogCheckboxes().forEach(function(cb){cb.checked=fixlogSelectAll.checked;});
			updateUndoSelectedBtn();
		});
	}
	document.addEventListener('change',function(e){
		if(e.target&&e.target.classList&&e.target.classList.contains('tflg-fixlog-checkbox')){
			if(fixlogSelectAll&&!e.target.checked)fixlogSelectAll.checked=false;
			updateUndoSelectedBtn();
		}
	});
	var undoSelectedBtn=document.getElementById('tflg-undo-selected-btn');
	if(undoSelectedBtn){
		undoSelectedBtn.addEventListener('click',function(){
			var ids=fixlogCheckboxes().filter(function(cb){return cb.checked;}).map(function(cb){return cb.value;});
			if(!ids.length)return;
			undoSelectedBtn.disabled=true;
			var progressEl=document.getElementById('tflg-undo-selected-progress');
			if(progressEl)progressEl.hidden=false;
			var successCount=0,failCount=0;
			function nextBatch(remainingIds){
				if(!remainingIds.length){
					if(progressEl)progressEl.hidden=true;
					var summary=successCount+' '+(1===successCount?TFLG.i18n.undoneOk:TFLG.i18n.undoneOkPlural);
					if(failCount)summary+=' — '+failCount+' '+TFLG.i18n.undoneFailed;
					window.alert(summary);
					window.location.reload();
					return;
				}
				if(progressEl)progressEl.textContent=TFLG.i18n.working;
				post('tflg_undo_fix_bulk',{fixlog_ids:remainingIds}).then(function(res){
					if(!res.success){
						if(progressEl)progressEl.hidden=true;
						undoSelectedBtn.disabled=false;
						window.alert(TFLG.i18n.genericError);
						return;
					}
					res.data.results.forEach(function(r){
						if(r.success){successCount++;}else{failCount++;}
					});
					nextBatch(res.data.remaining);
				}).catch(function(){
					if(progressEl)progressEl.hidden=true;
					undoSelectedBtn.disabled=false;
					window.alert(TFLG.i18n.genericError);
				});
			}
			nextBatch(ids);
		});
	}
	function dismiss404(el){
		var id=el.getAttribute('data-id');
		var row=el.closest('tr');
		post('tflg_dismiss_404',{id:id}).then(function(res){
			if(res.success){
				if(row)row.remove();
			}else{
				window.alert(res.data&&res.data.message?res.data.message:TFLG.i18n.genericError);
			}
		});
	}
	var bulkCancelled=false;
	function bulkAddActivity(entries){
		var list=document.getElementById('tflg-bulk-activity');
		if(!list||!entries||!entries.length)return;
		entries.forEach(function(e){
			var li=document.createElement('li');
			var mark=document.createElement('span');
			var cls = 'ok'===e.result ? 'ok' : ('broken'===e.result ? 'broken' : 'unknown');
			mark.className='tflg-activity-mark tflg-activity-mark--'+cls;
			mark.textContent = 'ok'===e.result ? '\u2713' : ('broken'===e.result ? '\u2717' : '\u2022');
			var link=document.createElement('a');
			link.className='tflg-activity-text';
			link.href=e.url;
			link.target='_blank';
			link.rel='noopener noreferrer';
			link.textContent=e.url;
			li.appendChild(mark);li.appendChild(link);
			list.insertBefore(li,list.firstChild);
		});
		while(list.children.length>8){list.removeChild(list.lastChild);}
	}
	function setBulkProgress(doneSoFar,total){
		var wrap=document.getElementById('tflg-bulk-progress');
		if(!wrap)return;
		var pct= total>0 ? Math.round((Math.min(doneSoFar,total)/total)*100) : 100;
		var fill=wrap.querySelector('.tflg-progress-fill');
		fill.style.width=pct+'%';
		fill.setAttribute('aria-valuenow',String(pct));
		wrap.querySelector('.tflg-progress-label').textContent=Math.min(doneSoFar,total)+' / '+total;
	}
	function runBulkFiltered(status,search,op,attempt,afterId,doneSoFar,total){
		if(bulkCancelled){
			var stoppedWrap=document.getElementById('tflg-bulk-progress');
			if(stoppedWrap)stoppedWrap.querySelector('.tflg-progress-phase').textContent=TFLG.i18n.bulkStopped.replace('{count}',doneSoFar);
			setTimeout(function(){window.location.reload();},1700);
			return;
		}
		post('tflg_bulk_filtered_batch',{status:status,s:search,bulk_op:op,after_id:afterId}).then(function(res){
			var wrap=document.getElementById('tflg-bulk-progress');
			if(!res.success){
				if(wrap)wrap.querySelector('.tflg-progress-phase').textContent=(res.data&&res.data.message)?res.data.message:TFLG.i18n.genericError;
				return;
			}
			var data=res.data;
			doneSoFar+=data.done||0;
			setBulkProgress(doneSoFar,total);
			bulkAddActivity(data.items);
			if(bulkCancelled){
				if(wrap)wrap.querySelector('.tflg-progress-phase').textContent=TFLG.i18n.bulkStopped.replace('{count}',doneSoFar);
				setTimeout(function(){window.location.reload();},1700);
			}else if(data.more){
				runBulkFiltered(status,search,op,0,data.last_id,doneSoFar,total);
			}else{
				if(wrap)wrap.querySelector('.tflg-progress-phase').textContent=TFLG.i18n.bulkDone.replace('{count}',doneSoFar);
				setTimeout(function(){window.location.reload();},1700);
			}
		}).catch(function(){
			var wrap=document.getElementById('tflg-bulk-progress');
			if(bulkCancelled){
				if(wrap)wrap.querySelector('.tflg-progress-phase').textContent=TFLG.i18n.bulkStopped.replace('{count}',doneSoFar);
				setTimeout(function(){window.location.reload();},1700);
				return;
			}
			if(attempt>=5){
				if(wrap)wrap.querySelector('.tflg-progress-phase').textContent=TFLG.i18n.gaveUp;
				return;
			}
			if(wrap)wrap.querySelector('.tflg-progress-phase').textContent=TFLG.i18n.retrying;
			setTimeout(function(){runBulkFiltered(status,search,op,attempt+1,afterId,doneSoFar,total);},3000);
		});
	}
	var bookEl=document.getElementById('tflg-book');
	if(bookEl){
		var bookPages=Array.prototype.slice.call(bookEl.querySelectorAll('.tflg-book-page'));
		var bookCurrent=0;
		function bookCompare(mode){
			return function(a,b){
				if('title'===mode){
					return (a.getAttribute('data-title')||'').localeCompare(b.getAttribute('data-title')||'');
				}
				var ca=parseInt(a.getAttribute('data-link-count'),10)||0;
				var cb=parseInt(b.getAttribute('data-link-count'),10)||0;
				if(ca!==cb){
					return 'links_desc'===mode ? (cb-ca) : (ca-cb);
				}
				// Same count on both sides -- always break the tie
				// alphabetically, regardless of direction, so the order
				// is fully deterministic rather than left to chance.
				return (a.getAttribute('data-title')||'').localeCompare(b.getAttribute('data-title')||'');
			};
		}
		function bookSortPages(mode){
			var currentKey=bookPages[bookCurrent]?bookPages[bookCurrent].getAttribute('data-source-key'):null;
			var pagesContainer=bookEl.querySelector('.tflg-book-pages');
			var tocList=document.getElementById('tflg-book-toc');
			var tocItems=tocList?Array.prototype.slice.call(tocList.querySelectorAll('li')):[];
			// Each page keeps its OLD index alongside it through the sort,
			// since that old index is the only link back to the matching
			// TOC <li> (tocItems was built in the same order bookPages
			// already was, before this sort touches either of them).
			var paired=bookPages.map(function(p,oldIndex){return {page:p,oldIndex:oldIndex};});
			var compare=bookCompare(mode);
			paired.sort(function(x,y){return compare(x.page,y.page);});
			paired.forEach(function(entry,newIndex){
				entry.page.setAttribute('data-page',String(newIndex));
				pagesContainer.appendChild(entry.page);
				var matchingLi=tocItems[entry.oldIndex];
				if(matchingLi){
					matchingLi.setAttribute('data-page',String(newIndex));
					var gotoLink=matchingLi.querySelector('.tflg-book-goto');
					if(gotoLink)gotoLink.setAttribute('data-page',String(newIndex));
					if(tocList)tocList.appendChild(matchingLi);
				}
			});
			bookPages=paired.map(function(entry){return entry.page;});
			var newCurrent=0;
			if(currentKey){
				for(var i=0;i<bookPages.length;i++){
					if(bookPages[i].getAttribute('data-source-key')===currentKey){newCurrent=i;break;}
				}
			}
			bookGoto(newCurrent);
		}
		var bookSortSelect=document.getElementById('tflg-book-sort');
		if(bookSortSelect)bookSortSelect.addEventListener('change',function(){bookSortPages(bookSortSelect.value);});
		function bookGoto(n){
			n=Math.max(0,Math.min(bookPages.length-1,n));
			bookPages.forEach(function(p,i){
				if(i===n){p.classList.add('tflg-book-page--visible');}
				else{p.classList.remove('tflg-book-page--visible');}
			});
			bookCurrent=n;
			var counter=document.getElementById('tflg-book-counter');
			if(counter)counter.textContent=(n+1)+' / '+bookPages.length;
			var toc=document.getElementById('tflg-book-toc');
			if(toc){
				toc.querySelectorAll('li').forEach(function(li){
					var isCurrent=(parseInt(li.getAttribute('data-page'),10)===n);
					li.classList.toggle('tflg-book-toc-current',isCurrent);
					if(isCurrent&&'scrollIntoView' in li)li.scrollIntoView({block:'nearest'});
				});
			}
			if(window.history&&window.history.replaceState)window.history.replaceState(null,'','#page-'+n);
		}
		function bookStep(delta){
			var n=bookCurrent+delta;
			if(n<0)n=bookPages.length-1;
			if(n>=bookPages.length)n=0;
			bookGoto(n);
		}
		var bookInitial=0;
		var bookHashMatch=/^#page-(\d+)$/.exec(window.location.hash);
		if(bookHashMatch){
			var bookWanted=parseInt(bookHashMatch[1],10);
			if(bookWanted>=0&&bookWanted<bookPages.length)bookInitial=bookWanted;
		}else{
			var bookSrcMatch=/^#src-(.+)$/.exec(window.location.hash);
			if(bookSrcMatch){
				var wantedKey=decodeURIComponent(bookSrcMatch[1]);
				for(var bi=0;bi<bookPages.length;bi++){
					if(bookPages[bi].getAttribute('data-source-key')===wantedKey){bookInitial=bi;break;}
				}
			}
		}
		bookGoto(bookInitial);
		var bookPrevBtn=document.getElementById('tflg-book-prev');
		if(bookPrevBtn)bookPrevBtn.addEventListener('click',function(){bookStep(-1);});
		var bookNextBtn=document.getElementById('tflg-book-next');
		if(bookNextBtn)bookNextBtn.addEventListener('click',function(){bookStep(1);});
		bookEl.querySelectorAll('.tflg-book-goto').forEach(function(a){
			a.addEventListener('click',function(e){
				e.preventDefault();
				bookGoto(parseInt(a.getAttribute('data-page'),10));
			});
		});
		var bookSearchInput=document.getElementById('tflg-book-search');
		var bookSearchResults=document.getElementById('tflg-book-search-results');
		if(bookSearchInput&&bookSearchResults){
			bookSearchInput.addEventListener('input',function(){
				var q=bookSearchInput.value.trim().toLowerCase();
				bookSearchResults.innerHTML='';
				if(!q){bookSearchResults.hidden=true;return;}
				var matches=bookPages.filter(function(p){
					return p.getAttribute('data-title').toLowerCase().indexOf(q)!==-1;
				}).slice(0,15);
				if(!matches.length){bookSearchResults.hidden=true;return;}
				matches.forEach(function(p){
					var li=document.createElement('li');
					var a=document.createElement('a');
					a.href='#';
					a.textContent=p.getAttribute('data-title');
					a.addEventListener('click',function(e){
						e.preventDefault();
						bookGoto(parseInt(p.getAttribute('data-page'),10));
						bookSearchResults.hidden=true;
						bookSearchInput.value='';
					});
					li.appendChild(a);
					bookSearchResults.appendChild(li);
				});
				bookSearchResults.hidden=false;
			});
			document.addEventListener('click',function(e){
				if(!bookSearchResults.contains(e.target)&&e.target!==bookSearchInput){
					bookSearchResults.hidden=true;
				}
			});
		}
		document.addEventListener('keydown',function(e){
			var tag=document.activeElement?document.activeElement.tagName:'';
			if('INPUT'===tag||'TEXTAREA'===tag)return;
			if('ArrowLeft'===e.key)bookStep(-1);
			if('ArrowRight'===e.key)bookStep(1);
		});
	}
	var cleanupOrphansBtn=document.getElementById('tflg-cleanup-orphans-btn');
	if(cleanupOrphansBtn){
		cleanupOrphansBtn.addEventListener('click',function(){
			cleanupOrphansBtn.disabled=true;
			var original=cleanupOrphansBtn.textContent;
			cleanupOrphansBtn.textContent=TFLG.i18n.working;
			post('tflg_cleanup_orphans',{}).then(function(res){
				if(res.success){
					var notice=document.getElementById('tflg-orphan-notice');
					if(notice)notice.remove();
				}else{
					cleanupOrphansBtn.disabled=false;
					cleanupOrphansBtn.textContent=original;
					window.alert(res.data&&res.data.message?res.data.message:TFLG.i18n.genericError);
				}
			}).catch(function(){
				cleanupOrphansBtn.disabled=false;
				cleanupOrphansBtn.textContent=original;
				window.alert(TFLG.i18n.genericError);
			});
		});
	}
	function csvField(value){
		value=String(value===null||value===undefined?'':value);
		// Formula-injection guard: a cell starting with =, +, -, @, tab, or
		// CR can be interpreted as an executable formula by spreadsheet
		// software -- prefix with a leading quote (a standard "treat as
		// text" escape) before any RFC 4180 quoting below, so the guard
		// survives being wrapped in quotes too.
		if(value.length>0 && /^[=+\-@\t\r]/.test(value)){
			value="'"+value;
		}
		// RFC 4180 quoting: wrap in quotes if the field contains a comma,
		// a quote, or a newline, doubling any internal quote.
		if(/[",\n\r]/.test(value)){
			value='"'+value.replace(/"/g,'""')+'"';
		}
		return value;
	}
	function downloadCsv(rows,filename){
		var csv=rows.map(function(row){return row.map(csvField).join(',');}).join('\r\n');
		var blob=new Blob([csv],{type:'text/csv;charset=utf-8;'});
		var url=URL.createObjectURL(blob);
		var a=document.createElement('a');
		a.href=url;
		a.download=filename;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);
	}
	var reportBtn=document.getElementById('tflg-report-btn');
	if(reportBtn){
		reportBtn.addEventListener('click',function(){
			var progressEl=document.getElementById('tflg-report-progress');
			reportBtn.disabled=true;
			progressEl.hidden=false;
			progressEl.textContent=TFLG.i18n.working;
			var rows=[[TFLG.i18n.reportColUrl,TFLG.i18n.reportColMethod,TFLG.i18n.reportColProposed]];
			var afterId=0;
			var doneCount=0;
			function fail(res){
				reportBtn.disabled=false;
				progressEl.hidden=true;
				window.alert(res&&res.data&&res.data.message?res.data.message:TFLG.i18n.genericError);
			}
			function nextBatch(total){
				post('tflg_report_batch',{after_id:afterId}).then(function(res){
					if(!res.success){fail(res);return;}
					res.data.items.forEach(function(item){
						rows.push([item.url,item.method,item.proposed]);
					});
					doneCount+=res.data.items.length;
					afterId=res.data.last_id;
					progressEl.textContent=doneCount+' / '+total;
					if(res.data.more){
						nextBatch(total);
					}else{
						downloadCsv(rows,'link-guardian-repair-report-'+new Date().toISOString().slice(0,10)+'.csv');
						reportBtn.disabled=false;
						progressEl.hidden=true;
					}
				}).catch(function(){fail(null);});
			}
			post('tflg_report_start',{}).then(function(res){
				if(!res.success){fail(res);return;}
				if(0===res.data.total){
					reportBtn.disabled=false;
					progressEl.hidden=true;
					window.alert(TFLG.i18n.reportNoneBroken);
					return;
				}
				nextBatch(res.data.total);
			}).catch(function(){fail(null);});
		});
	}
	var domainTable=document.getElementById('tflg-domain-table');
	if(domainTable){
		var domainSortState={key:null,dir:1};
		domainTable.querySelectorAll('th.tflg-sortable').forEach(function(th){
			th.addEventListener('click',function(){
				var key=th.getAttribute('data-sort');
				if(domainSortState.key===key){
					domainSortState.dir=-domainSortState.dir;
				}else{
					domainSortState.key=key;
					domainSortState.dir=('domain'===key)?1:-1;
				}
				var tbody=domainTable.querySelector('tbody');
				var rows=Array.prototype.slice.call(tbody.querySelectorAll('tr'));
				rows.sort(function(a,b){
					var av=a.getAttribute('data-'+key);
					var bv=b.getAttribute('data-'+key);
					if('domain'===key){
						return domainSortState.dir*av.localeCompare(bv);
					}
					return domainSortState.dir*(parseFloat(av)-parseFloat(bv));
				});
				rows.forEach(function(row){tbody.appendChild(row);});
				domainTable.querySelectorAll('th.tflg-sortable').forEach(function(h){
					h.classList.remove('tflg-sort-asc','tflg-sort-desc');
				});
				th.classList.add(domainSortState.dir>0?'tflg-sort-asc':'tflg-sort-desc');
			});
		});
	}
	var healthSearch=document.getElementById('tflg-health-search');
	var healthGrid=document.getElementById('tflg-health-grid');
	if(healthSearch&&healthGrid){
		healthSearch.addEventListener('input',function(){
			var q=healthSearch.value.trim().toLowerCase();
			healthGrid.querySelectorAll('.tflg-health-tile').forEach(function(tile){
				var matches=!q||tile.getAttribute('data-title').toLowerCase().indexOf(q)!==-1;
				tile.hidden=!matches;
			});
		});
	}
	var uaPreset=document.getElementById('tflg-ua-preset');
	if(uaPreset){
		uaPreset.addEventListener('change',function(){
			var uaField=document.getElementById('tflg-ua');
			if(!uaField||!this.value)return;
			uaField.value = ('__blank__'===this.value) ? '' : this.value;
		});
	}
	var linksForm=document.getElementById('tflg-links-form');
	if(linksForm){
		linksForm.addEventListener('submit',function(e){
			var checkbox=document.getElementById('tflg-select-all-matching');
			if(!checkbox||!checkbox.checked)return; // unchecked: ordinary per-page submit, unchanged
			var select=linksForm.querySelector('select[name="action"]');
			var op = select ? select.value : '-1';
			if('-1'===op){
				var select2=linksForm.querySelector('select[name="action2"]');
				op = select2 ? select2.value : '-1';
			}
			if('-1'===op){
				window.alert(TFLG.i18n.chooseBulkAction);
				e.preventDefault();
				return;
			}
			e.preventDefault();
			var status=checkbox.getAttribute('data-status')||'';
			var search=checkbox.getAttribute('data-search')||'';
			var total=parseInt(checkbox.getAttribute('data-total'),10)||0;
			var wrap=document.getElementById('tflg-bulk-progress');
			if(wrap){
				wrap.hidden=false;
				wrap.querySelector('.tflg-progress-phase').textContent=TFLG.i18n.working;
				wrap.querySelector('.tflg-progress-fill').style.width='0%';
				wrap.querySelector('.tflg-progress-label').textContent='0 / '+total;
				var list=document.getElementById('tflg-bulk-activity');
				if(list)list.innerHTML='';
			}
			bulkCancelled=false;
			runBulkFiltered(status,search,op,0,0,0,total);
		});
	}
	document.addEventListener('click',function(e){
		var scanBtn=e.target.closest('#tflg-scan-btn');
		if(scanBtn){e.preventDefault();runScan();return;}
		var cancelBtn=e.target.closest('#tflg-cancel-btn');
		if(cancelBtn){e.preventDefault();cancelScan();return;}
		var bulkCancelBtn=e.target.closest('#tflg-bulk-cancel-btn');
		if(bulkCancelBtn){e.preventDefault();bulkCancelled=true;bulkCancelBtn.disabled=true;return;}
		var rowBtn=e.target.closest('.tflg-row-action');
		if(rowBtn){e.preventDefault();rowAction(rowBtn);return;}
		var undoBtn=e.target.closest('.tflg-undo-fix');
		if(undoBtn){e.preventDefault();undoFix(undoBtn);return;}
		var dismiss404Btn=e.target.closest('.tflg-dismiss-404');
		if(dismiss404Btn){e.preventDefault();dismiss404(dismiss404Btn);return;}
	});
})();
JS;
	}

	public static function register_settings() {
		register_setting( 'tflg_settings_group', 'tflg_settings', array( __CLASS__, 'sanitize_settings' ) );
	}

	public static function sanitize_settings( $input ) {
		$current = TFLG_Util::get_settings();
		$out     = $current;

		$selectable         = TFLG_Scanner::get_selectable_post_types();
		$out['post_types']  = array();
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $pt ) {
				$pt = sanitize_key( $pt );
				if ( isset( $selectable[ $pt ] ) ) {
					$out['post_types'][] = $pt;
				}
			}
		}
		if ( empty( $out['post_types'] ) ) {
			$out['post_types'] = array( 'post', 'page' );
		}

		foreach ( array( 'check_menus', 'check_widgets', 'check_images', 'verify_internal_via_http', 'autofix_protocol', 'autofix_www', 'autofix_redirect', 'autofix_old_slug', 'autofix_unmangle', 'autofix_www_normalize', 'autofix_scheme_typo', 'email_notify', 'enable_wayback', 'enable_archive_today', 'enable_memento', 'enable_404_log', 'enable_drift_detection' ) as $bool_key ) {
			$out[ $bool_key ] = ! empty( $input[ $bool_key ] );
		}

		$out['http_timeout']        = isset( $input['http_timeout'] ) ? min( 30, max( 3, (int) $input['http_timeout'] ) ) : $current['http_timeout'];
		$out['check_concurrency']   = isset( $input['check_concurrency'] ) ? min( 60, max( 1, (int) $input['check_concurrency'] ) ) : $current['check_concurrency'];
		$out['recheck_after_hours'] = isset( $input['recheck_after_hours'] ) ? min( 720, max( 1, (int) $input['recheck_after_hours'] ) ) : $current['recheck_after_hours'];
		$out['drift_check_interval_days'] = isset( $input['drift_check_interval_days'] ) ? min( 365, max( 1, (int) $input['drift_check_interval_days'] ) ) : $current['drift_check_interval_days'];

		$out['user_agent']       = isset( $input['user_agent'] ) ? sanitize_text_field( $input['user_agent'] ) : '';
		$out['exclude_patterns'] = isset( $input['exclude_patterns'] ) ? sanitize_textarea_field( $input['exclude_patterns'] ) : '';

		$notify_email       = isset( $input['notify_email'] ) ? sanitize_text_field( $input['notify_email'] ) : '';
		$out['notify_email'] = ( '' !== $notify_email && is_email( $notify_email ) ) ? $notify_email : '';

		$invalid_patterns = TFLG_Util::validate_exclude_patterns( $out['exclude_patterns'] );
		if ( ! empty( $invalid_patterns ) ) {
			add_settings_error(
				'tflg_settings',
				'tflg_invalid_regex',
				sprintf(
					/* translators: %s: comma-separated list of invalid regex patterns the person entered */
					__( 'Saved, but these exclusion patterns are not valid regular expressions and will be ignored until fixed: %s', 'trade-free-link-guardian' ),
					implode( ', ', array_map( 'esc_html', $invalid_patterns ) )
				),
				'warning'
			);
		}

		$allowed_schedules = array( 'off', 'daily', 'weekly' );
		$out['schedule']   = ( isset( $input['schedule'] ) && in_array( $input['schedule'], $allowed_schedules, true ) ) ? $input['schedule'] : 'weekly';

		TFLG_Cron::reschedule( $out['schedule'] );

		// Marks the setup wizard as no longer needed, regardless of
		// whether THIS particular save came from the wizard itself or
		// from the regular Settings screen -- either way, an explicit
		// decision has now been made, which is the only thing that
		// actually matters here.
		update_option( 'tflg_wizard_done', 1 );

		return $out;
	}

	public static function render_dashboard() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}
		$counts    = TFLG_DB::counts_by_status();
		$last_scan = get_option( 'tflg_last_scan_time' );
		$recent    = TFLG_DB::get_recent_fixlog( 5 );
		$is_first_run = ( 0 === (int) $counts['total'] && ! $last_scan );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Trade-Free Link Guardian', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'Everything below runs on this server. Checking a link makes one request to that link\'s own destination and nowhere else — the only exceptions are the optional archive lookups (Wayback Machine, archive.today, Memento Time Travel), which stay off until you enable them in Settings.', 'trade-free-link-guardian' ); ?></p>
			<?php if ( ! TFLG_Checker::concurrency_available() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'Concurrent checking is not available on this server (likely missing the curl extension) — every link is checked one at a time, which will be considerably slower on a site with many links. See Settings for details.', 'trade-free-link-guardian' ); ?>
				</p></div>
			<?php endif; ?>

			<?php
			$wizard_done = (bool) get_option( 'tflg_wizard_done' );
			if ( $is_first_run && ! $wizard_done ) :
				$selectable = TFLG_Scanner::get_selectable_post_types();
				?>
				<div class="tflg-wizard" id="tflg-wizard">
					<div class="tflg-wizard-progress" aria-hidden="true">
						<span class="tflg-wizard-dot tflg-wizard-dot--active" data-dot="1">1</span><span class="tflg-wizard-line"></span>
						<span class="tflg-wizard-dot" data-dot="2">2</span><span class="tflg-wizard-line"></span>
						<span class="tflg-wizard-dot" data-dot="3">3</span><span class="tflg-wizard-line"></span>
						<span class="tflg-wizard-dot" data-dot="4">4</span>
					</div>
					<form method="post" action="options.php">
						<?php settings_fields( 'tflg_settings_group' ); ?>
						<input type="hidden" name="tflg_settings[verify_internal_via_http]" value="1" />
						<input type="hidden" name="tflg_settings[email_notify]" value="1" />
						<input type="hidden" name="tflg_settings[enable_404_log]" value="1" />

						<div class="tflg-wizard-panel tflg-wizard-panel--active" data-panel="1">
							<h2><?php esc_html_e( 'Find out what state your links are in', 'trade-free-link-guardian' ); ?></h2>
							<p><?php esc_html_e( 'Three quick questions, then a first scan — reading through your posts, pages, menus, and widgets, recording every link found, then checking whether each one still works. All on this server; nothing sent anywhere else.', 'trade-free-link-guardian' ); ?></p>
							<div class="tflg-wizard-actions">
								<button type="button" class="button button-primary tflg-wizard-next"><?php esc_html_e( 'Get started', 'trade-free-link-guardian' ); ?></button>
								<button type="submit" class="button-link tflg-wizard-skip"><?php esc_html_e( 'Skip this, use recommended defaults', 'trade-free-link-guardian' ); ?></button>
							</div>
						</div>

						<div class="tflg-wizard-panel" data-panel="2" hidden>
							<h2><?php esc_html_e( 'What should be scanned', 'trade-free-link-guardian' ); ?></h2>
							<p class="tflg-wizard-sub"><?php esc_html_e( 'Every option here can be changed later in Settings.', 'trade-free-link-guardian' ); ?></p>
							<fieldset>
								<legend><?php esc_html_e( 'Content types', 'trade-free-link-guardian' ); ?></legend>
								<?php foreach ( $selectable as $pt => $label ) : ?>
									<label class="tflg-wizard-check"><input type="checkbox" name="tflg_settings[post_types][]" value="<?php echo esc_attr( $pt ); ?>" <?php checked( in_array( $pt, array( 'post', 'page' ), true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<fieldset>
								<legend><?php esc_html_e( 'Also check', 'trade-free-link-guardian' ); ?></legend>
								<label class="tflg-wizard-check"><input type="checkbox" name="tflg_settings[check_menus]" value="1" checked /> <?php esc_html_e( 'Navigation menus', 'trade-free-link-guardian' ); ?></label>
								<label class="tflg-wizard-check"><input type="checkbox" name="tflg_settings[check_widgets]" value="1" checked /> <?php esc_html_e( 'Widgets', 'trade-free-link-guardian' ); ?></label>
							</fieldset>
							<div class="tflg-wizard-actions">
								<button type="button" class="button tflg-wizard-back"><?php esc_html_e( 'Back', 'trade-free-link-guardian' ); ?></button>
								<button type="button" class="button button-primary tflg-wizard-next"><?php esc_html_e( 'Next', 'trade-free-link-guardian' ); ?></button>
							</div>
						</div>

						<div class="tflg-wizard-panel" data-panel="3" hidden>
							<h2><?php esc_html_e( 'Automatic fixes', 'trade-free-link-guardian' ); ?></h2>
							<p class="tflg-wizard-sub"><?php esc_html_e( 'Every fix here is verified before it\'s applied — never a guess — and every one is logged, with an undo. Nothing is ever applied unless turned on.', 'trade-free-link-guardian' ); ?></p>
							<fieldset>
								<legend><?php esc_html_e( 'Pure pattern matches — no live request needed to know they\'re right', 'trade-free-link-guardian' ); ?></legend>
								<label class="tflg-wizard-check"><input type="checkbox" name="tflg_settings[autofix_redirect]" value="1" checked /> <?php esc_html_e( 'Update a link to where it already, permanently redirects', 'trade-free-link-guardian' ); ?></label>
								<label class="tflg-wizard-check"><input type="checkbox" name="tflg_settings[autofix_old_slug]" value="1" checked /> <?php esc_html_e( 'Follow this site\'s own page to its new address after a rename', 'trade-free-link-guardian' ); ?></label>
								<label class="tflg-wizard-check"><input type="checkbox" name="tflg_settings[autofix_unmangle]" value="1" checked /> <?php esc_html_e( 'Unwrap a link corrupted by a stray quote character', 'trade-free-link-guardian' ); ?></label>
								<label class="tflg-wizard-check"><input type="checkbox" name="tflg_settings[autofix_www_normalize]" value="1" checked /> <?php esc_html_e( 'Match this site\'s own www. / non-www. address', 'trade-free-link-guardian' ); ?></label>
								<label class="tflg-wizard-check"><input type="checkbox" name="tflg_settings[autofix_scheme_typo]" value="1" checked /> <?php esc_html_e( 'Correct a typo in http(s):// itself', 'trade-free-link-guardian' ); ?></label>
							</fieldset>
							<fieldset>
								<legend><?php esc_html_e( 'Checked live first, since only a real request can confirm these', 'trade-free-link-guardian' ); ?></legend>
								<label class="tflg-wizard-check"><input type="checkbox" name="tflg_settings[autofix_protocol]" value="1" checked /> <?php esc_html_e( 'Switch http:// to https:// once confirmed to work', 'trade-free-link-guardian' ); ?></label>
								<label class="tflg-wizard-check"><input type="checkbox" name="tflg_settings[autofix_www]" value="1" checked /> <?php esc_html_e( 'Add or remove www. once confirmed to work', 'trade-free-link-guardian' ); ?></label>
							</fieldset>
							<div class="tflg-wizard-actions">
								<button type="button" class="button tflg-wizard-back"><?php esc_html_e( 'Back', 'trade-free-link-guardian' ); ?></button>
								<button type="button" class="button button-primary tflg-wizard-next"><?php esc_html_e( 'Next', 'trade-free-link-guardian' ); ?></button>
							</div>
						</div>

						<div class="tflg-wizard-panel" data-panel="4" hidden>
							<h2><?php esc_html_e( 'Ready', 'trade-free-link-guardian' ); ?></h2>
							<p><?php esc_html_e( 'That\'s everything needed for a first pass. Saves these choices, then starts scanning right away — with a live progress bar, and a Stop button if you need it.', 'trade-free-link-guardian' ); ?></p>
							<p class="tflg-wizard-sub"><?php esc_html_e( 'Also worth a look once this is done: a Sitemap Tree of every page on this site, split by language whenever one is detected in the URL — the same page-by-page structure, but as a hierarchy instead of a flat list.', 'trade-free-link-guardian' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-sitemap' ) ); ?>"><?php esc_html_e( 'Open Sitemap Tree', 'trade-free-link-guardian' ); ?></a></p>
							<div class="tflg-wizard-actions">
								<button type="button" class="button tflg-wizard-back"><?php esc_html_e( 'Back', 'trade-free-link-guardian' ); ?></button>
								<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save and scan now', 'trade-free-link-guardian' ); ?></button>
							</div>
						</div>
					</form>
				</div>
			<?php elseif ( $is_first_run ) : ?>
				<div class="tflg-welcome">
					<h2><?php esc_html_e( 'Ready when you are', 'trade-free-link-guardian' ); ?></h2>
					<p><?php esc_html_e( 'Setup is done. Nothing has been scanned yet — the first scan reads through your posts, pages, menus, and widgets, records every link it finds, then checks whether each one still works.', 'trade-free-link-guardian' ); ?></p>
					<p>
						<button type="button" id="tflg-scan-btn" class="button button-primary button-hero"><?php esc_html_e( 'Scan site now', 'trade-free-link-guardian' ); ?></button>
						<button type="button" id="tflg-cancel-btn" class="button" hidden><?php esc_html_e( 'Stop', 'trade-free-link-guardian' ); ?></button>
					</p>
				</div>
			<?php else : ?>

			<div class="tflg-stats">
				<a class="tflg-stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-links' ) ); ?>"><span class="tflg-stat-number"><?php echo (int) $counts['total']; ?></span><span class="tflg-stat-label"><?php esc_html_e( 'Links tracked', 'trade-free-link-guardian' ); ?></span></a>
				<a class="tflg-stat-card tflg-stat-card--broken" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-links&status=broken' ) ); ?>"><span class="tflg-stat-number"><?php echo (int) $counts['broken']; ?></span><span class="tflg-stat-label"><?php esc_html_e( 'Need attention', 'trade-free-link-guardian' ); ?></span></a>
				<a class="tflg-stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-links&status=unknown' ) ); ?>"><span class="tflg-stat-number"><?php echo (int) $counts['unknown']; ?></span><span class="tflg-stat-label"><?php esc_html_e( 'Unverified', 'trade-free-link-guardian' ); ?></span></a>
				<a class="tflg-stat-card tflg-stat-card--fixed" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-links&status=fixed' ) ); ?>"><span class="tflg-stat-number"><?php echo (int) $counts['auto_fixed']; ?></span><span class="tflg-stat-label"><?php esc_html_e( 'Fixed', 'trade-free-link-guardian' ); ?></span></a>
				<a class="tflg-stat-card" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-links&status=ignored' ) ); ?>"><span class="tflg-stat-number"><?php echo (int) $counts['ignored']; ?></span><span class="tflg-stat-label"><?php esc_html_e( 'Ignored', 'trade-free-link-guardian' ); ?></span></a>
			</div>

			<p>
				<button type="button" id="tflg-scan-btn" class="button button-primary"><?php esc_html_e( 'Scan site now', 'trade-free-link-guardian' ); ?></button>
				<button type="button" id="tflg-cancel-btn" class="button" hidden><?php esc_html_e( 'Stop', 'trade-free-link-guardian' ); ?></button>
				<?php if ( $last_scan ) : ?>
					<span class="tflg-sub"><?php printf( /* translators: %s: human-readable time since last scan */ esc_html__( 'Last scan: %s ago', 'trade-free-link-guardian' ), esc_html( human_time_diff( strtotime( $last_scan . ' UTC' ), time() ) ) ); ?></span>
				<?php else : ?>
					<span class="tflg-sub"><?php esc_html_e( 'You have not scanned yet — this looks through your posts, pages, menus and widgets.', 'trade-free-link-guardian' ); ?></span>
				<?php endif; ?>
			</p>
			<?php endif; ?>

			<div id="tflg-progress" class="tflg-progress" hidden>
				<div class="tflg-progress-track"><div class="tflg-progress-fill" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div></div>
				<div class="tflg-progress-phase"></div>
				<div class="tflg-progress-label"></div>
				<div id="tflg-tally" class="tflg-tally" hidden>
					<span class="tflg-tally-item tflg-tally-item--ok"><span class="tflg-tally-num" data-tally="ok">0</span> <?php esc_html_e( 'ok', 'trade-free-link-guardian' ); ?></span>
					<span class="tflg-tally-item tflg-tally-item--broken"><span class="tflg-tally-num" data-tally="broken">0</span> <?php esc_html_e( 'broken', 'trade-free-link-guardian' ); ?></span>
					<span class="tflg-tally-item tflg-tally-item--unknown"><span class="tflg-tally-num" data-tally="unknown">0</span> <?php esc_html_e( 'unclear', 'trade-free-link-guardian' ); ?></span>
					<span class="tflg-tally-rate"></span>
				</div>
				<ul id="tflg-activity" class="tflg-activity" aria-live="polite"></ul>
			</div>

			<?php if ( $counts['broken'] > 0 ) : ?>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-links&status=broken' ) ); ?>" class="button"><?php esc_html_e( 'Review links that need attention', 'trade-free-link-guardian' ); ?></a></p>
			<?php endif; ?>

			<?php
			$orphan_count = TFLG_DB::count_orphaned_links();
			if ( $orphan_count > 0 ) :
				?>
				<div class="notice notice-info inline" id="tflg-orphan-notice">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of tracked links no longer referenced by any content */
								_n(
									'%d tracked link no longer appears in any content — probably removed or changed since the last scan. Safe to clean up now, or it clears itself at the next full scan.',
									'%d tracked links no longer appear in any content — probably removed or changed since the last scan. Safe to clean up now, or they clear themselves at the next full scan.',
									$orphan_count,
									'trade-free-link-guardian'
								),
								$orphan_count
							)
						);
						?>
						<button type="button" id="tflg-cleanup-orphans-btn" class="button button-small"><?php esc_html_e( 'Clean up now', 'trade-free-link-guardian' ); ?></button>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $recent ) : ?>
				<h2><?php esc_html_e( 'Recent activity', 'trade-free-link-guardian' ); ?></h2>
				<ul class="tflg-recent">
					<?php foreach ( $recent as $entry ) : ?>
						<li>
							<?php echo self::fixlog_summary_html( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixlog_summary_html() escapes internally and returns ready-to-echo HTML ?>
							<span class="tflg-sub">— <?php echo esc_html( human_time_diff( strtotime( $entry->created_at . ' UTC' ), time() ) ); ?> <?php esc_html_e( 'ago', 'trade-free-link-guardian' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-log' ) ); ?>" class="tflg-link"><?php esc_html_e( 'View full fix log', 'trade-free-link-guardian' ); ?></a></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'More views', 'trade-free-link-guardian' ); ?></h2>
			<p class="tflg-help"><?php esc_html_e( 'Kept out of the sidebar to keep it short — each of these is a full, regular screen, just one click from here instead of always-visible.', 'trade-free-link-guardian' ); ?></p>
			<ul class="tflg-more-views">
				<li><a class="tflg-link" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-book' ) ); ?>"><?php esc_html_e( 'Book View', 'trade-free-link-guardian' ); ?></a><span class="tflg-sub"><?php esc_html_e( 'Every page, page by page, with the links found there.', 'trade-free-link-guardian' ); ?></span></li>
				<li><a class="tflg-link" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-domains' ) ); ?>"><?php esc_html_e( 'By Domain', 'trade-free-link-guardian' ); ?></a><span class="tflg-sub"><?php esc_html_e( 'Links grouped by where they point, not where they were found.', 'trade-free-link-guardian' ); ?></span></li>
				<li><a class="tflg-link" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-trends' ) ); ?>"><?php esc_html_e( 'Trends', 'trade-free-link-guardian' ); ?></a><span class="tflg-sub"><?php esc_html_e( 'Tracked, broken, and fixed counts over time.', 'trade-free-link-guardian' ); ?></span></li>
				<li><a class="tflg-link" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-orphan-pages' ) ); ?>"><?php esc_html_e( 'Orphan Pages', 'trade-free-link-guardian' ); ?></a><span class="tflg-sub"><?php esc_html_e( 'Published pages nothing on the site links to.', 'trade-free-link-guardian' ); ?></span></li>
				<li><a class="tflg-link" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-compliance' ) ); ?>"><?php esc_html_e( 'Compliance Report', 'trade-free-link-guardian' ); ?></a><span class="tflg-sub"><?php esc_html_e( 'A dated, printable summary for sharing outside this dashboard.', 'trade-free-link-guardian' ); ?></span></li>
				<li><a class="tflg-link" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-sitemap' ) ); ?>"><?php esc_html_e( 'Sitemap Tree', 'trade-free-link-guardian' ); ?></a><span class="tflg-sub"><?php esc_html_e( 'Every page as a hierarchy, split by language when one is detected.', 'trade-free-link-guardian' ); ?></span></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Returns ready-to-echo HTML (not a plain string) — the old/new URLs
	 * are real, clickable links, escaped internally with esc_url()/
	 * esc_html() as appropriate. Callers must NOT wrap the result in
	 * esc_html() themselves, or the markup below would show up as
	 * literal escaped text instead of rendering as links.
	 */
	protected static function fixlog_summary_html( $entry ) {
		$methods = array(
			'auto_protocol'  => __( 'Switched to HTTPS', 'trade-free-link-guardian' ),
			'auto_www'       => __( 'Adjusted the www prefix', 'trade-free-link-guardian' ),
			'auto_redirect'  => __( 'Updated to the final redirect destination', 'trade-free-link-guardian' ),
			'auto_old_slug'  => __( 'Followed a renamed slug', 'trade-free-link-guardian' ),
			'auto_unmangle'  => __( 'Unwrapped a quote-mangled URL', 'trade-free-link-guardian' ),
			'auto_www_normalize' => __( 'Normalized the www. prefix to match your Site Address', 'trade-free-link-guardian' ),
			'auto_scheme_typo'   => __( 'Corrected a mistyped scheme (e.g. httpss://) to http:// or https://', 'trade-free-link-guardian' ),
			'manual_replace' => __( 'Replaced with a new URL', 'trade-free-link-guardian' ),
			'unlink'         => __( 'Unlinked', 'trade-free-link-guardian' ),
			'restore_post'   => __( 'Restored item from the trash', 'trade-free-link-guardian' ),
		);
		$label = isset( $methods[ $entry->method ] ) ? $methods[ $entry->method ] : $entry->method;

		$out = esc_html( $label ) . ': ' . self::fixlog_url_link( $entry->old_url );
		if ( $entry->new_url && $entry->new_url !== $entry->old_url ) {
			$out .= ' → ' . self::fixlog_url_link( $entry->new_url );
		}
		return $out;
	}

	protected static function fixlog_url_link( $url ) {
		if ( '' === (string) $url ) {
			return '';
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>';
	}

	public static function render_links() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}
		$notice = '';
		if ( isset( $_POST['tflg_bulk_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tflg_bulk_nonce'] ) ), 'tflg_bulk_action' ) ) {
			$notice = self::process_bulk_action_post();
		}

		$table = new TFLG_List_Table();
		$table->prepare_items();

		$status       = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_total = TFLG_DB::count_links_matching( $status, $search );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Links', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'Every link this plugin has ever tracked, one row each. Filter by status along the top — broken, unverified, fixed, or ignored — or search by URL. Each row has its own actions: edit, fix, ignore, or look up an archived version, depending on what applies.', 'trade-free-link-guardian' ); ?></p>
			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<form method="post" id="tflg-links-form">
				<?php wp_nonce_field( 'tflg_bulk_action', 'tflg_bulk_nonce' ); ?>
				<?php $table->search_box( __( 'Search links', 'trade-free-link-guardian' ), 'tflg-search' ); ?>
				<?php $table->views(); ?>
				<?php if ( $filter_total > 20 ) : ?>
					<p>
						<label>
							<input type="checkbox" id="tflg-select-all-matching" data-status="<?php echo esc_attr( $status ); ?>" data-search="<?php echo esc_attr( $search ); ?>" data-total="<?php echo (int) $filter_total; ?>" />
							<?php
							printf(
								/* translators: %d: total number of links matching the current filter */
								esc_html__( 'Instead, apply the chosen bulk action to all %d links matching this filter — not just this page.', 'trade-free-link-guardian' ),
								(int) $filter_total
							);
							?>
						</label>
					</p>
					<div id="tflg-bulk-progress" class="tflg-progress" hidden>
						<div class="tflg-progress-track"><div class="tflg-progress-fill" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div></div>
						<div class="tflg-progress-phase"></div>
						<div class="tflg-progress-label"></div>
						<button type="button" id="tflg-bulk-cancel-btn" class="button"><?php esc_html_e( 'Stop', 'trade-free-link-guardian' ); ?></button>
						<ul id="tflg-bulk-activity" class="tflg-activity" aria-live="polite"></ul>
					</div>
				<?php endif; ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	public static function render_book() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}
		$sources = TFLG_DB::get_all_sources();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Book View', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'Browse the site page by page — each page shows the links found there, in their original and (where fixed) corrected form, with Edit and Undo right alongside.', 'trade-free-link-guardian' ); ?></p>

			<?php if ( empty( $sources ) ) : ?>
				<p><?php esc_html_e( 'Nothing has been scanned yet — run a scan from the Dashboard first.', 'trade-free-link-guardian' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<div class="tflg-book" id="tflg-book">
				<div class="tflg-book-toolbar">
					<button type="button" id="tflg-book-prev" class="button" aria-label="<?php esc_attr_e( 'Previous page', 'trade-free-link-guardian' ); ?>">&laquo;</button>
					<div class="tflg-book-search-wrap">
						<input type="text" id="tflg-book-search" placeholder="<?php esc_attr_e( 'Search for a page…', 'trade-free-link-guardian' ); ?>" aria-label="<?php esc_attr_e( 'Search for a page', 'trade-free-link-guardian' ); ?>" class="regular-text" autocomplete="off" />
						<ul id="tflg-book-search-results" class="tflg-book-search-results" hidden></ul>
					</div>
					<label class="screen-reader-text" for="tflg-book-sort"><?php esc_html_e( 'Sort pages by', 'trade-free-link-guardian' ); ?></label>
					<select id="tflg-book-sort">
						<option value="title"><?php esc_html_e( 'Title (A–Z)', 'trade-free-link-guardian' ); ?></option>
						<option value="links_desc"><?php esc_html_e( 'Most links first', 'trade-free-link-guardian' ); ?></option>
						<option value="links_asc"><?php esc_html_e( 'Fewest links first', 'trade-free-link-guardian' ); ?></option>
					</select>
					<span id="tflg-book-counter" class="tflg-book-counter"></span>
					<button type="button" id="tflg-book-next" class="button" aria-label="<?php esc_attr_e( 'Next page', 'trade-free-link-guardian' ); ?>">&raquo;</button>
				</div>

				<div class="tflg-book-layout">
					<div class="tflg-book-main">
						<div class="tflg-book-pages">
							<?php
							foreach ( $sources as $i => $source ) :
								list( $title, $front_url ) = self::source_title_and_url( $source );
								$occurrences               = TFLG_DB::get_occurrences_for_source( $source->source_type, $source->source_id, $source->source_key );
								?>
								<div class="tflg-book-page" data-page="<?php echo (int) $i; ?>" data-title="<?php echo esc_attr( $title ); ?>" data-link-count="<?php echo (int) $source->link_count; ?>" data-source-key="<?php echo esc_attr( $source->source_type . ':' . $source->source_id . ':' . $source->source_key ); ?>">
									<h2>
										<?php if ( $front_url ) : ?>
											<a href="<?php echo esc_url( $front_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $title ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $title ); ?>
										<?php endif; ?>
									</h2>
									<?php if ( $front_url ) : ?>
										<p class="tflg-sub tflg-book-page-url"><a href="<?php echo esc_url( $front_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $front_url ); ?></a></p>
									<?php endif; ?>

									<?php if ( empty( $occurrences ) ) : ?>
										<p><?php esc_html_e( 'No links tracked here.', 'trade-free-link-guardian' ); ?></p>
									<?php else : ?>
										<ul class="tflg-book-linklist">
											<?php
											foreach ( $occurrences as $occ ) :
												// raw_url is the exact as-found string, which may be a
												// relative path (not yet resolved) — using it directly
												// as an href from within wp-admin would resolve against
												// the wrong page entirely. The same absolute form the
												// checker itself would have classified it to is what
												// "the original version" actually means here.
												$original_absolute = TFLG_Util::to_absolute_url( $occ->raw_url );
												?>
												<li>
													<span class="tflg-badge tflg-badge--<?php echo esc_attr( $occ->check_result ); ?>"><?php echo esc_html( TFLG_Util::status_label( $occ->check_result, $occ->http_status, $occ->last_checked ) ); ?></span>
													<div>
														<a href="<?php echo esc_url( $original_absolute ); ?>" target="_blank" rel="noopener noreferrer" class="tflg-url"><?php echo esc_html( $original_absolute ); ?></a>
														<?php if ( $occ->current_url && $occ->current_url !== $original_absolute ) : ?>
															<div class="tflg-sub">
																<?php esc_html_e( 'Corrected to:', 'trade-free-link-guardian' ); ?>
																<a href="<?php echo esc_url( $occ->current_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $occ->current_url ); ?></a>
															</div>
														<?php endif; ?>
														<?php if ( $occ->error_note ) : ?>
															<div class="tflg-sub"><?php echo esc_html( $occ->error_note ); ?></div>
														<?php endif; ?>
														<div class="tflg-book-linklist-actions">
															<a href="#" class="tflg-row-action" data-op="replace" data-id="<?php echo (int) $occ->link_id; ?>" data-url="<?php echo esc_attr( $occ->current_url ); ?>"><?php esc_html_e( 'Edit URL…', 'trade-free-link-guardian' ); ?></a>
															<?php
															$undo_entry = TFLG_DB::get_latest_undoable_fixlog_for_link( $occ->link_id );
															if ( $undo_entry ) :
																?>
																| <a href="#" class="tflg-undo-fix" data-id="<?php echo (int) $undo_entry->id; ?>"><?php esc_html_e( 'Undo', 'trade-free-link-guardian' ); ?></a>
															<?php endif; ?>
															<?php
															// Same condition build_row_actions() uses for the main
															// Links table -- broken, not already being fixed, and
															// external (the Wayback Machine archives web pages, not
															// wp-admin edit screens, so this never applies to an
															// internal link) -- kept in sync rather than repeated
															// with different logic that could quietly drift.
															if ( 'broken' === $occ->check_result && 'none' === $occ->fix_status && 'internal' !== $occ->url_type && TFLG_Util::get_setting( 'enable_wayback' ) ) :
																?>
																| <a href="#" class="tflg-row-action" data-op="wayback" data-id="<?php echo (int) $occ->link_id; ?>"><?php esc_html_e( 'Find archived version', 'trade-free-link-guardian' ); ?></a>
															<?php endif; ?>
															<?php if ( 'broken' === $occ->check_result && 'none' === $occ->fix_status && 'internal' !== $occ->url_type && TFLG_Util::get_setting( 'enable_archive_today' ) ) : ?>
																| <a href="<?php echo esc_url( TFLG_Util::archive_today_url( $occ->current_url ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Try archive.today', 'trade-free-link-guardian' ); ?></a>
															<?php endif; ?>
															<?php if ( 'broken' === $occ->check_result && 'none' === $occ->fix_status && 'internal' !== $occ->url_type && TFLG_Util::get_setting( 'enable_memento' ) ) : ?>
																| <a href="<?php echo esc_url( TFLG_Util::memento_url( $occ->current_url ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Search Memento', 'trade-free-link-guardian' ); ?></a>
															<?php endif; ?>
														</div>
													</div>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</div>

					<aside class="tflg-book-toc-sidebar" aria-label="<?php esc_attr_e( 'Table of contents', 'trade-free-link-guardian' ); ?>">
						<h2><?php esc_html_e( 'Table of contents', 'trade-free-link-guardian' ); ?></h2>
						<ol class="tflg-book-toc" id="tflg-book-toc">
							<?php
							foreach ( $sources as $i => $source ) :
								list( $title, $front_url ) = self::source_title_and_url( $source );
								?>
								<li data-page="<?php echo (int) $i; ?>" data-link-count="<?php echo (int) $source->link_count; ?>">
									<a href="#" class="tflg-book-goto" data-page="<?php echo (int) $i; ?>"><?php echo esc_html( $title ); ?></a>
									<span class="tflg-sub">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: number of links found on this page */
												_n( '%d link', '%d links', (int) $source->link_count, 'trade-free-link-guardian' ),
												(int) $source->link_count
											)
										);
										?>
									</span>
								</li>
							<?php endforeach; ?>
						</ol>
					</aside>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * A title and, where one meaningfully exists, a public front-end URL
	 * for a source. Only a 'post' source has one real corresponding
	 * page; a menu item or widget doesn't live at a single URL of its
	 * own, so those point at the relevant admin screen instead.
	 */
	public static function render_trends() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}
		$snapshots = TFLG_DB::get_health_snapshots( 90 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Trends', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'One data point per day, kept for roughly two years — whether things are getting better or worse over time, not just where they stand right now. A gap means no snapshot was taken that day, not zero links.', 'trade-free-link-guardian' ); ?></p>

			<?php if ( count( $snapshots ) < 2 ) : ?>
				<p><?php esc_html_e( 'Not enough history yet to draw a trend — this builds up automatically, one point per day, starting from today.', 'trade-free-link-guardian' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php
			$totals   = array_map( function( $s ) { return (int) $s->total; }, $snapshots );
			$brokens  = array_map( function( $s ) { return (int) $s->broken; }, $snapshots );
			$fixeds   = array_map( function( $s ) { return (int) $s->fixed; }, $snapshots );
			$dates    = array_map( function( $s ) { return $s->snapshot_date; }, $snapshots );

			$max_value = max( array_merge( $totals, $brokens, $fixeds, array( 1 ) ) ); // at least 1, so an all-zero history never divides by zero
			$min_value = 0;

			$margin_left   = 46;
			$margin_right  = 16;
			$margin_top    = 16;
			$margin_bottom = 30;
			$plot_width    = 900;
			$plot_height   = 260;
			$svg_width     = $plot_width + $margin_left + $margin_right;
			$svg_height    = $plot_height + $margin_top + $margin_bottom;

			$series = array(
				'total'  => array( 'values' => $totals, 'color' => '#646970', 'label' => __( 'Total tracked', 'trade-free-link-guardian' ) ),
				'broken' => array( 'values' => $brokens, 'color' => '#d63638', 'label' => __( 'Need attention', 'trade-free-link-guardian' ) ),
				'fixed'  => array( 'values' => $fixeds, 'color' => '#00a32a', 'label' => __( 'Fixed', 'trade-free-link-guardian' ) ),
			);

			$point_count  = count( $snapshots );
			$label_count  = min( 6, $point_count );
			$label_indexes = array();
			for ( $li = 0; $li < $label_count; $li++ ) {
				$label_indexes[] = ( $label_count > 1 ) ? (int) round( ( $li / ( $label_count - 1 ) ) * ( $point_count - 1 ) ) : 0;
			}
			$label_indexes = array_unique( $label_indexes );
			?>

			<svg viewBox="0 0 <?php echo (int) $svg_width; ?> <?php echo (int) $svg_height; ?>" class="tflg-trends-chart" role="img" aria-label="<?php esc_attr_e( 'Line chart of tracked, broken, and fixed link counts over time', 'trade-free-link-guardian' ); ?>">
				<?php foreach ( array( 0, 0.5, 1 ) as $fraction ) : ?>
					<?php $gy = $margin_top + $plot_height - ( $fraction * $plot_height ); ?>
					<line x1="<?php echo (int) $margin_left; ?>" y1="<?php echo esc_attr( $gy ); ?>" x2="<?php echo (int) ( $margin_left + $plot_width ); ?>" y2="<?php echo esc_attr( $gy ); ?>" class="tflg-trends-gridline" />
					<text x="<?php echo (int) ( $margin_left - 8 ); ?>" y="<?php echo esc_attr( $gy + 4 ); ?>" class="tflg-trends-axis-label" text-anchor="end"><?php echo (int) round( $fraction * $max_value ); ?></text>
				<?php endforeach; ?>

				<?php foreach ( $label_indexes as $li ) : ?>
					<?php $lx = $margin_left + ( ( $point_count > 1 ) ? ( $li / ( $point_count - 1 ) ) * $plot_width : $plot_width / 2 ); ?>
					<text x="<?php echo esc_attr( $lx ); ?>" y="<?php echo (int) ( $svg_height - 8 ); ?>" class="tflg-trends-axis-label" text-anchor="middle"><?php echo esc_html( mysql2date( get_option( 'date_format', 'M j' ), $dates[ $li ], false ) ); ?></text>
				<?php endforeach; ?>

				<?php foreach ( $series as $s ) : ?>
					<?php
					$points     = TFLG_Util::svg_chart_points( $s['values'], $plot_width, $plot_height, $min_value, $max_value );
					$point_strs = array();
					foreach ( $points as $p ) {
						$point_strs[] = ( $p[0] + $margin_left ) . ',' . ( $p[1] + $margin_top );
					}
					?>
					<polyline points="<?php echo esc_attr( implode( ' ', $point_strs ) ); ?>" fill="none" stroke="<?php echo esc_attr( $s['color'] ); ?>" stroke-width="2" />
				<?php endforeach; ?>
			</svg>

			<ul class="tflg-trends-legend">
				<?php foreach ( $series as $s ) : ?>
					<li><span class="tflg-trends-swatch" style="background-color:<?php echo esc_attr( $s['color'] ); ?>"></span><?php echo esc_html( $s['label'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	public static function render_domain_view() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}
		$links  = TFLG_DB::get_external_links_for_grouping();
		$groups = TFLG_Util::group_links_by_domain( $links );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'By Domain', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'Every other view in this plugin organizes by the page a link was found on. This one organizes by where links actually point — useful for spotting a pattern that looks like a handful of unrelated broken links until you see they all lead to the same external site. Click a domain to see its links on the Links screen.', 'trade-free-link-guardian' ); ?></p>

			<?php if ( empty( $groups ) ) : ?>
				<p><?php esc_html_e( 'No external links tracked yet.', 'trade-free-link-guardian' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php
			usort(
				$groups,
				function( $a, $b ) {
					$pa = TFLG_Util::health_percent( $a['broken'], $a['unknown'], $a['total'] );
					$pb = TFLG_Util::health_percent( $b['broken'], $b['unknown'], $b['total'] );
					if ( $pa === $pb ) {
						return $b['total'] - $a['total'];
					}
					return ( $pa < $pb ) ? 1 : -1;
				}
			);
			?>

			<table class="widefat striped tflg-domain-table" id="tflg-domain-table">
				<thead>
					<tr>
						<th class="tflg-sortable" data-sort="domain"><?php esc_html_e( 'Domain', 'trade-free-link-guardian' ); ?></th>
						<th class="tflg-sortable" data-sort="total"><?php esc_html_e( 'Links', 'trade-free-link-guardian' ); ?></th>
						<th class="tflg-sortable" data-sort="broken"><?php esc_html_e( 'Broken', 'trade-free-link-guardian' ); ?></th>
						<th class="tflg-sortable" data-sort="unknown"><?php esc_html_e( 'Unclear', 'trade-free-link-guardian' ); ?></th>
						<th class="tflg-sortable" data-sort="health"><?php esc_html_e( 'Health', 'trade-free-link-guardian' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $groups as $g ) : ?>
						<?php
						$pct   = TFLG_Util::health_percent( $g['broken'], $g['unknown'], $g['total'] );
						$color = TFLG_Util::health_color( $pct );
						?>
						<tr data-domain="<?php echo esc_attr( $g['domain'] ); ?>" data-total="<?php echo (int) $g['total']; ?>" data-broken="<?php echo (int) $g['broken']; ?>" data-unknown="<?php echo (int) $g['unknown']; ?>" data-health="<?php echo esc_attr( round( $pct, 2 ) ); ?>">
							<td><a class="tflg-link" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-links&s=' . rawurlencode( $g['domain'] ) ) ); ?>"><?php echo esc_html( $g['domain'] ); ?></a></td>
							<td><?php echo (int) $g['total']; ?></td>
							<td><?php echo (int) $g['broken']; ?></td>
							<td><?php echo (int) $g['unknown']; ?></td>
							<td><span class="tflg-domain-health-bar" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percentage of this domain's links needing attention */ __( '%d%% of links to this domain need attention', 'trade-free-link-guardian' ), round( $pct ) ) ); ?>"><span class="tflg-domain-health-fill" style="width:<?php echo esc_attr( round( $pct ) ); ?>%;background-color:<?php echo esc_attr( $color ); ?>"></span></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function render_health_map() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}
		$sources = TFLG_DB::get_source_health_summary();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Health Map', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'Every page with tracked links, at a glance — tile size reflects how many links a page has, color reflects how many of them need attention (unclear links count for half as much as a confirmed break). Click any tile to open that page in Book View. Worst first.', 'trade-free-link-guardian' ); ?></p>

			<?php if ( empty( $sources ) ) : ?>
				<p><?php esc_html_e( 'Nothing has been scanned yet — run a scan from the Dashboard first.', 'trade-free-link-guardian' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php
			usort(
				$sources,
				function( $a, $b ) {
					$pa = TFLG_Util::health_percent( $a->broken_count, $a->unknown_count, $a->total );
					$pb = TFLG_Util::health_percent( $b->broken_count, $b->unknown_count, $b->total );
					if ( $pa === $pb ) {
						return (int) $b->total - (int) $a->total;
					}
					return ( $pa < $pb ) ? 1 : -1;
				}
			);
			?>

			<div class="tflg-health-toolbar">
				<input type="text" id="tflg-health-search" placeholder="<?php esc_attr_e( 'Filter by page title…', 'trade-free-link-guardian' ); ?>" aria-label="<?php esc_attr_e( 'Filter by page title', 'trade-free-link-guardian' ); ?>" class="regular-text" autocomplete="off" />
			</div>

			<div class="tflg-health-grid" id="tflg-health-grid">
				<?php
				foreach ( $sources as $source ) :
					list( $title, $front_url ) = self::source_title_and_url( $source );
					$pct        = TFLG_Util::health_percent( $source->broken_count, $source->unknown_count, $source->total );
					$color      = TFLG_Util::health_color( $pct );
					$total      = (int) $source->total;
					$size_class = 'tflg-health-tile--sm';
					if ( $total > 10 ) {
						$size_class = 'tflg-health-tile--lg';
					} elseif ( $total > 3 ) {
						$size_class = 'tflg-health-tile--md';
					}
					$source_key = $source->source_type . ':' . $source->source_id . ':' . $source->source_key;
					$tooltip    = sprintf(
						/* translators: 1: page title, 2: broken link count, 3: unclear link count, 4: total link count */
						__( '%1$s — %2$d broken, %3$d unclear, %4$d links total', 'trade-free-link-guardian' ),
						$title,
						(int) $source->broken_count,
						(int) $source->unknown_count,
						$total
					);
					?>
					<a class="tflg-health-tile <?php echo esc_attr( $size_class ); ?>" style="background-color:<?php echo esc_attr( $color ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-book#src-' . rawurlencode( $source_key ) ) ); ?>" data-title="<?php echo esc_attr( $title ); ?>" title="<?php echo esc_attr( $tooltip ); ?>" aria-label="<?php echo esc_attr( $tooltip ); ?>">
						<span class="tflg-health-tile-title"><?php echo esc_html( $title ); ?></span>
						<span class="tflg-health-tile-count"><?php echo (int) ( $source->broken_count + $source->unknown_count ); ?>/<?php echo $total; ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	protected static function source_title_and_url( $source ) {
		$label = $source->source_label ? $source->source_label : __( '(untitled)', 'trade-free-link-guardian' );
		if ( 'post' === $source->source_type ) {
			$permalink = get_permalink( $source->source_id );
			return array( $label, $permalink ? $permalink : '' );
		}
		if ( 'nav_menu_item' === $source->source_type ) {
			return array(
				sprintf(
					/* translators: %s: the menu item's own label */
					__( 'Menu item: %s', 'trade-free-link-guardian' ),
					$label
				),
				admin_url( 'nav-menus.php' ),
			);
		}
		if ( 'widget' === $source->source_type ) {
			return array(
				sprintf(
					/* translators: %s: the widget's own label */
					__( 'Widget: %s', 'trade-free-link-guardian' ),
					$label
				),
				admin_url( 'widgets.php' ),
			);
		}
		return array( $label, '' );
	}

	protected static function process_bulk_action_post() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return '';
		}
		global $wpdb;

		$action = '';
		if ( ! empty( $_POST['action'] ) && '-1' !== $_POST['action'] ) {
			$action = sanitize_key( wp_unslash( $_POST['action'] ) );
		} elseif ( ! empty( $_POST['action2'] ) && '-1' !== $_POST['action2'] ) {
			$action = sanitize_key( wp_unslash( $_POST['action2'] ) );
		}
		if ( '' === $action || empty( $_POST['link_ids'] ) || ! is_array( $_POST['link_ids'] ) ) {
			return '';
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort only; some hosts disallow this.
		}

		$ids = array_map( 'intval', wp_unslash( $_POST['link_ids'] ) );
		foreach ( $ids as $id ) {
			switch ( $action ) {
				case 'recheck':
					$link = TFLG_DB::get_link( $id );
					if ( $link ) {
						TFLG_Checker::check_link( $link );
					}
					break;
				case 'autofix':
					TFLG_Fixer::attempt_autofix( $id );
					break;
				case 'ignore':
					TFLG_Fixer::ignore_link( $id );
					break;
				case 'forget':
					$wpdb->delete( TFLG_DB::occurrences_table(), array( 'link_id' => $id ), array( '%d' ) );
					$wpdb->delete( TFLG_DB::links_table(), array( 'id' => $id ), array( '%d' ) );
					break;
			}
		}
		/* translators: %d: number of links a bulk action was applied to */
		return sprintf( __( 'Done — applied to %d link(s).', 'trade-free-link-guardian' ), count( $ids ) );
	}

	public static function render_settings() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}
		$settings   = TFLG_Util::get_settings();
		$selectable = TFLG_Scanner::get_selectable_post_types();
		$counts     = TFLG_DB::counts_by_status();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Link Guardian Settings', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'What gets scanned, which automatic fixes are turned on, and how often things run. Every fix below is off by default unless it\'s a pure, verified pattern match — anything that makes a live request to decide starts off.', 'trade-free-link-guardian' ); ?></p>
			<?php settings_errors( 'tflg_settings' ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'tflg_settings_group' ); ?>

				<h2><?php esc_html_e( 'What to scan', 'trade-free-link-guardian' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Content types', 'trade-free-link-guardian' ); ?></th>
						<td>
							<?php foreach ( $selectable as $slug => $label ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="tflg_settings[post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $settings['post_types'], true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Also scan', 'trade-free-link-guardian' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="tflg_settings[check_menus]" value="1" <?php checked( $settings['check_menus'] ); ?> /> <?php esc_html_e( 'Classic menu custom links', 'trade-free-link-guardian' ); ?></label>
							<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="tflg_settings[check_widgets]" value="1" <?php checked( $settings['check_widgets'] ); ?> /> <?php esc_html_e( 'Widgets (text, custom HTML, block widgets)', 'trade-free-link-guardian' ); ?></label>
							<label style="display:block;"><input type="checkbox" name="tflg_settings[check_images]" value="1" <?php checked( $settings['check_images'] ); ?> /> <?php esc_html_e( 'Image sources too, not just links', 'trade-free-link-guardian' ); ?></label>
							<p class="description"><?php esc_html_e( 'Block-based Navigation menus are always included alongside your other content.', 'trade-free-link-guardian' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Checking', 'trade-free-link-guardian' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tflg-timeout"><?php esc_html_e( 'Request timeout (seconds)', 'trade-free-link-guardian' ); ?></label></th>
						<td><input type="number" id="tflg-timeout" name="tflg_settings[http_timeout]" min="3" max="30" value="<?php echo esc_attr( $settings['http_timeout'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="tflg-concurrency"><?php esc_html_e( 'Links checked at once', 'trade-free-link-guardian' ); ?></label></th>
						<td>
							<input type="number" id="tflg-concurrency" name="tflg_settings[check_concurrency]" min="1" max="60" value="<?php echo esc_attr( $settings['check_concurrency'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'External links (and internal links that need a live self-check) are sent into one real concurrent batch of this size — since they genuinely run in parallel, a larger number here rarely costs you extra time (a batch is only as slow as its single slowest link either way), it just gets more done per batch. Higher does mean more simultaneous outbound connections from your server at once. 1 disables concurrency entirely.', 'trade-free-link-guardian' ); ?></p>
							<p class="description">
								<?php if ( TFLG_Checker::concurrency_available() ) : ?>
									<span style="color:#00a32a;">✓</span> <?php esc_html_e( 'Concurrent checking is available on this server.', 'trade-free-link-guardian' ); ?>
								<?php else : ?>
									<span style="color:#996800;">⚠</span> <?php esc_html_e( 'Concurrent checking is NOT available on this server (likely missing the curl extension) — every check falls back to one at a time, which will be considerably slower on a site with many links.', 'trade-free-link-guardian' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tflg-recheck"><?php esc_html_e( 'Re-check a link after (hours)', 'trade-free-link-guardian' ); ?></label></th>
						<td>
							<input type="number" id="tflg-recheck" name="tflg_settings[recheck_after_hours]" min="1" max="720" value="<?php echo esc_attr( $settings['recheck_after_hours'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Within this window, the same URL will not be checked twice even if it appears in many places.', 'trade-free-link-guardian' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Unverifiable internal links', 'trade-free-link-guardian' ); ?></th>
						<td><label><input type="checkbox" name="tflg_settings[verify_internal_via_http]" value="1" <?php checked( $settings['verify_internal_via_http'] ); ?> /> <?php esc_html_e( 'Confirm them with a direct request to this site when they cannot be resolved structurally', 'trade-free-link-guardian' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="tflg-ua"><?php esc_html_e( 'User-Agent', 'trade-free-link-guardian' ); ?></label></th>
						<td>
							<?php
							$ua_presets      = TFLG_Util::preset_user_agents();
							$ua_labels       = TFLG_Util::preset_user_agent_labels();
							$ua_current_key  = '__blank__';
							foreach ( $ua_presets as $preset_key => $preset_string ) {
								if ( $preset_string === $settings['user_agent'] ) {
									$ua_current_key = $preset_key;
									break;
								}
							}
							if ( '' !== $settings['user_agent'] && '__blank__' === $ua_current_key ) {
								$ua_current_key = ''; // a genuinely custom value -- matches no preset and isn't blank
							}
							?>
							<select id="tflg-ua-preset">
								<option value="" <?php selected( '' === $ua_current_key ); ?>><?php esc_html_e( '— pick a preset, or type your own below —', 'trade-free-link-guardian' ); ?></option>
								<?php foreach ( $ua_presets as $preset_key => $preset_string ) : ?>
									<option value="<?php echo esc_attr( $preset_string ); ?>" <?php selected( $ua_current_key, $preset_key ); ?>><?php echo esc_html( $ua_labels[ $preset_key ] ); ?></option>
								<?php endforeach; ?>
								<option value="__blank__" <?php selected( '__blank__' === $ua_current_key ); ?>><?php esc_html_e( 'Identify honestly as this plugin (leave the field below blank)', 'trade-free-link-guardian' ); ?></option>
							</select>
							<br /><br />
							<input type="text" id="tflg-ua" name="tflg_settings[user_agent]" value="<?php echo esc_attr( $settings['user_agent'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( TFLG_Util::default_user_agent() ); ?>" />
							<p class="description"><?php esc_html_e( 'Picking a preset above fills in the field below, which stays editable — or choose "identify honestly" to leave it blank. If a lot of links come back 403, some sites are just blocking unfamiliar bots; a current, common browser User-Agent can help confirm that.', 'trade-free-link-guardian' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tflg-exclude"><?php esc_html_e( 'Never check URLs matching', 'trade-free-link-guardian' ); ?></label></th>
						<td>
							<textarea id="tflg-exclude" name="tflg_settings[exclude_patterns]" rows="4" class="large-text code"><?php echo esc_textarea( $settings['exclude_patterns'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line: a plain substring, or a /regular expression/.', 'trade-free-link-guardian' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Automatic fixes', 'trade-free-link-guardian' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Every automatic fix here is verified before it is applied — none of these are guesses.', 'trade-free-link-guardian' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'When a link is broken', 'trade-free-link-guardian' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="tflg_settings[autofix_scheme_typo]" value="1" <?php checked( $settings['autofix_scheme_typo'] ); ?> /> <?php esc_html_e( 'Correct a mistyped scheme that can never work as-is (httpss://, htps://, htp://, and similar) to http:// or https://', 'trade-free-link-guardian' ); ?></label>
							<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="tflg_settings[autofix_protocol]" value="1" <?php checked( $settings['autofix_protocol'] ); ?> /> <?php esc_html_e( 'Try https:// if http:// fails', 'trade-free-link-guardian' ); ?></label>
							<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="tflg_settings[autofix_www]" value="1" <?php checked( $settings['autofix_www'] ); ?> /> <?php esc_html_e( 'Try toggling the www. prefix', 'trade-free-link-guardian' ); ?></label>
							<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="tflg_settings[autofix_old_slug]" value="1" <?php checked( $settings['autofix_old_slug'] ); ?> /> <?php esc_html_e( 'Follow a renamed internal slug, using WordPress\' own old-slug record', 'trade-free-link-guardian' ); ?></label>
							<label style="display:block;"><input type="checkbox" name="tflg_settings[autofix_unmangle]" value="1" <?php checked( $settings['autofix_unmangle'] ); ?> /> <?php esc_html_e( 'Unwrap a URL mangled by a stray quote character in the source HTML (e.g. an internal-looking link that is really your site\'s URL followed by a quoted external one)', 'trade-free-link-guardian' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'When a link still works via redirect', 'trade-free-link-guardian' ); ?></th>
						<td><label><input type="checkbox" name="tflg_settings[autofix_redirect]" value="1" <?php checked( $settings['autofix_redirect'] ); ?> /> <?php esc_html_e( 'Update the stored URL to the final destination — only for permanent (301/308) redirects, never temporary ones', 'trade-free-link-guardian' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Even when a link already works', 'trade-free-link-guardian' ); ?></th>
						<td>
							<label><input type="checkbox" name="tflg_settings[autofix_www_normalize]" value="1" <?php checked( $settings['autofix_www_normalize'] ); ?> /> <?php esc_html_e( 'Normalize a link to your own site so its www. prefix matches your Site Address exactly (Settings > General) — applied to any matching internal link, not only ones currently showing as broken', 'trade-free-link-guardian' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Schedule & notifications', 'trade-free-link-guardian' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tflg-schedule"><?php esc_html_e( 'Automatic scan', 'trade-free-link-guardian' ); ?></label></th>
						<td>
							<select id="tflg-schedule" name="tflg_settings[schedule]">
								<option value="off" <?php selected( $settings['schedule'], 'off' ); ?>><?php esc_html_e( 'Off — scan manually', 'trade-free-link-guardian' ); ?></option>
								<option value="daily" <?php selected( $settings['schedule'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'trade-free-link-guardian' ); ?></option>
								<option value="weekly" <?php selected( $settings['schedule'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'trade-free-link-guardian' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email notification', 'trade-free-link-guardian' ); ?></th>
						<td>
							<label><input type="checkbox" name="tflg_settings[email_notify]" value="1" <?php checked( $settings['email_notify'] ); ?> /> <?php esc_html_e( 'Email when a scheduled scan finds new broken links', 'trade-free-link-guardian' ); ?></label>
							<p>
								<label for="tflg-notify-email"><?php esc_html_e( 'Send to:', 'trade-free-link-guardian' ); ?></label>
								<input type="email" id="tflg-notify-email" name="tflg_settings[notify_email]" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" />
							</p>
							<p class="description"><?php esc_html_e( 'Leave blank to use this site\'s admin email address. Set a specific address if that\'s not who should be reading these — a shared team inbox, or a person other than whoever is registered as this site\'s admin.', 'trade-free-link-guardian' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'The one external service (off by default)', 'trade-free-link-guardian' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Wayback Machine', 'trade-free-link-guardian' ); ?></th>
						<td>
							<label><input type="checkbox" name="tflg_settings[enable_wayback]" value="1" <?php checked( $settings['enable_wayback'] ); ?> /> <?php esc_html_e( 'Show a "Find archived version" button for dead external links', 'trade-free-link-guardian' ); ?></label>
							<p class="description"><?php esc_html_e( 'Looks up archive.org only when you click the button — never automatically — and stays off until you turn it on. Run by the Internet Archive, a long-established, transparent non-profit.', 'trade-free-link-guardian' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'archive.today', 'trade-free-link-guardian' ); ?></th>
						<td>
							<label><input type="checkbox" name="tflg_settings[enable_archive_today]" value="1" <?php checked( $settings['enable_archive_today'] ); ?> /> <?php esc_html_e( 'Show a "Try archive.today" link for dead external links', 'trade-free-link-guardian' ); ?></label>
							<p class="description"><?php esc_html_e( 'A separate archive service, worth knowing apart from the Wayback Machine above: its operator is anonymous and undisclosed, unlike the Internet Archive. This is a plain link that opens archive.today directly to look for itself — this plugin never checks it automatically, and there is no official way to check programmatically in the first place. Off until you turn it on.', 'trade-free-link-guardian' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Memento Time Travel', 'trade-free-link-guardian' ); ?></th>
						<td>
							<label><input type="checkbox" name="tflg_settings[enable_memento]" value="1" <?php checked( $settings['enable_memento'] ); ?> /> <?php esc_html_e( 'Show a "Search Memento" link for dead external links', 'trade-free-link-guardian' ); ?></label>
							<p class="description"><?php esc_html_e( 'A single lookup that checks several archives at once (the Wayback Machine, archive.today where it participates, and others), maintained by the research effort behind the Memento web standard — unlike archive.today, this one does have a genuinely official, documented public address. Also just a plain link, off until you turn it on.', 'trade-free-link-guardian' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '404 Log', 'trade-free-link-guardian' ); ?></th>
						<td>
							<label><input type="checkbox" name="tflg_settings[enable_404_log]" value="1" <?php checked( $settings['enable_404_log'] ); ?> /> <?php esc_html_e( 'Log requests that hit a missing page on this site', 'trade-free-link-guardian' ); ?></label>
							<p class="description"><?php esc_html_e( 'A different kind of problem than everything else on this page: someone (a visitor, or a link on another site) reaching a page here that no longer exists — something scanning this site\'s own content can never see for itself. Records only the requested path, its referrer if one was sent, and a hit count — never an IP address, by design, not just by omission. On by default; entries with no repeat hits for 60 days are cleaned up automatically.', 'trade-free-link-guardian' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Content drift detection', 'trade-free-link-guardian' ); ?></th>
						<td>
							<label><input type="checkbox" name="tflg_settings[enable_drift_detection]" value="1" <?php checked( $settings['enable_drift_detection'] ); ?> /> <?php esc_html_e( 'Watch working external links for a change in subject, not just a change in status', 'trade-free-link-guardian' ); ?></label>
							<p>
								<label for="tflg-drift-interval"><?php esc_html_e( 'Re-check every', 'trade-free-link-guardian' ); ?></label>
								<input type="number" id="tflg-drift-interval" name="tflg_settings[drift_check_interval_days]" min="1" max="365" style="width:70px" value="<?php echo esc_attr( $settings['drift_check_interval_days'] ); ?>" />
								<?php esc_html_e( 'days', 'trade-free-link-guardian' ); ?>
							</p>
							<p class="description"><?php esc_html_e( 'A link can return 200 OK while the page behind it has quietly become something else entirely — a cited regulation replaced by unrelated content, without ever technically "breaking." Watches both the page title and its main heading (<h1>), since a generic, site-wide title can stay identical while the heading — usually the page\'s own specific subject — genuinely changes. The regular check above never downloads a page\'s content at all (only its headers), specifically to stay fast and light — this is a genuinely separate, additional request per link, on its own, much slower schedule, which is why it defaults to off. A same-page year update ("Results 2025" to "Results 2026") is deliberately not treated as drift, in either the title or the heading.', 'trade-free-link-guardian' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Export', 'trade-free-link-guardian' ); ?></h2>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tflg_export_csv' ), 'tflg_export_csv' ) ); ?>"><?php esc_html_e( 'Download CSV of tracked links', 'trade-free-link-guardian' ); ?></a></p>

			<p>
				<button type="button" id="tflg-report-btn" class="button"><?php esc_html_e( 'Generate broken-links repair report', 'trade-free-link-guardian' ); ?></button>
				<span id="tflg-report-progress" class="tflg-sub" hidden></span>
			</p>
			<p class="tflg-help"><?php esc_html_e( 'Every currently broken link, and — for each — the deterministic fix this plugin would propose, if any, computed exactly the same way "Auto-fix where possible" would, including the same live checks a protocol or www swap genuinely needs. Nothing is applied; this only ever reads. Downloads as a CSV once complete.', 'trade-free-link-guardian' ); ?></p>

			<hr />
			<p class="tflg-sub">
				<?php if ( $counts['auto_fixed'] > 0 ) : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of links this installation has automatically fixed so far */
							_n(
								'This plugin has automatically fixed %d broken link so far, free, with no premium tier and nothing held back. If that saved you real time, you can',
								'This plugin has automatically fixed %d broken links so far, free, with no premium tier and nothing held back. If that saved you real time, you can',
								$counts['auto_fixed'],
								'trade-free-link-guardian'
							),
							$counts['auto_fixed']
						)
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'This plugin is free, with no premium tier and nothing held back. If it saves you real time, you can', 'trade-free-link-guardian' ); ?>
				<?php endif; ?>
				<a class="tflg-link" href="https://buymeacoffee.com/echo.of.the.strings" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'buy the developer a coffee', 'trade-free-link-guardian' ); ?></a> — <?php esc_html_e( 'entirely optional, never required, and this is the only place it\'s mentioned.', 'trade-free-link-guardian' ); ?>
			</p>
		</div>
		<?php
	}

	public static function render_log() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}
		$entries = TFLG_DB::get_recent_fixlog( 100 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Fix Log', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'Every fix this plugin has applied, most recent first — what changed, where, and when. Anything here can be undone individually, or several at once, unless the fix type doesn\'t support it (marked as such below).', 'trade-free-link-guardian' ); ?></p>
			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'Nothing has been fixed yet.', 'trade-free-link-guardian' ); ?></p>
			<?php else : ?>
				<p>
					<button type="button" id="tflg-undo-selected-btn" class="button" disabled><?php esc_html_e( 'Undo selected', 'trade-free-link-guardian' ); ?></button>
					<span id="tflg-undo-selected-progress" class="tflg-sub" hidden></span>
				</p>
				<table class="widefat striped tflg-fixlog-table">
					<thead>
						<tr>
							<td class="manage-column check-column"><input type="checkbox" id="tflg-fixlog-select-all" /></td>
							<th><?php esc_html_e( 'When', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'What happened', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'Places affected', 'trade-free-link-guardian' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php $entry_undoable = ! $entry->reverted && ! in_array( $entry->method, array( 'ignore', 'restore_post', 'unlink' ), true ); ?>
							<tr data-fixlog-id="<?php echo (int) $entry->id; ?>">
								<td class="check-column"><?php if ( $entry_undoable ) : ?><input type="checkbox" class="tflg-fixlog-checkbox" value="<?php echo (int) $entry->id; ?>" /><?php endif; ?></td>
								<td><?php echo esc_html( human_time_diff( strtotime( $entry->created_at . ' UTC' ), time() ) ); ?> <?php esc_html_e( 'ago', 'trade-free-link-guardian' ); ?></td>
								<td><?php echo self::fixlog_summary_html( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixlog_summary_html() escapes internally and returns ready-to-echo HTML ?></td>
								<td><?php echo (int) $entry->occurrences_affected; ?></td>
								<td>
									<?php if ( $entry_undoable ) : ?>
										<a href="#" class="tflg-undo-fix" data-id="<?php echo (int) $entry->id; ?>"><?php esc_html_e( 'Undo', 'trade-free-link-guardian' ); ?></a>
									<?php elseif ( $entry->reverted ) : ?>
										<span class="tflg-sub"><?php esc_html_e( 'Reverted', 'trade-free-link-guardian' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_create_redirect() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'trade-free-link-guardian' ) );
		}
		check_admin_referer( 'tflg_create_redirect' );

		$source_path = isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '';
		$source_path = TFLG_Util::normalize_404_path( $source_path );
		if ( '' !== $source_path && '/' !== substr( $source_path, 0, 1 ) ) {
			$source_path = '/' . $source_path;
		}

		$status_code = isset( $_POST['status_code'] ) ? (int) $_POST['status_code'] : 301;
		if ( ! in_array( $status_code, array( 301, 302, 410 ), true ) ) {
			$status_code = 301;
		}

		$target_url = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';

		if ( '' !== $source_path && ( '' !== $target_url || 410 === $status_code ) ) {
			if ( '' !== $target_url && TFLG_Util::redirect_creates_loop( $source_path, $target_url, TFLG_DB::get_redirect_map() ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=tflg-redirects&tflg_error=loop' ) );
				exit;
			}
			TFLG_DB::create_redirect( $source_path, ( '' !== $target_url ? $target_url : null ), $status_code );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tflg-redirects' ) );
		exit;
	}

	public static function handle_delete_redirect() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'trade-free-link-guardian' ) );
		}
		check_admin_referer( 'tflg_delete_redirect' );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id ) {
			TFLG_DB::delete_redirect( $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=tflg-redirects' ) );
		exit;
	}

	public static function render_compliance_report() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}

		$days       = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report parameter, no state change
		$end_ts     = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$start_ts   = $end_ts - ( $days * DAY_IN_SECONDS );
		$start_date = gmdate( 'Y-m-d H:i:s', $start_ts );
		$end_date   = gmdate( 'Y-m-d H:i:s', $end_ts );

		$current = TFLG_DB::counts_by_status();
		$period  = TFLG_DB::get_compliance_summary( $start_date, $end_date );
		?>
		<div class="wrap tflg-compliance">
			<h1><?php esc_html_e( 'Compliance Report', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help tflg-no-print"><?php esc_html_e( 'A formal summary of link-health activity, meant to be printed or saved as a PDF from your browser — this is not a new capability, it\'s the same data already tracked elsewhere in this plugin, laid out as a document rather than an interactive screen.', 'trade-free-link-guardian' ); ?></p>

			<form method="get" class="tflg-no-print">
				<input type="hidden" name="page" value="tflg-compliance" />
				<label for="tflg-report-days"><?php esc_html_e( 'Period:', 'trade-free-link-guardian' ); ?></label>
				<select id="tflg-report-days" name="days" onchange="this.form.submit()">
					<?php foreach ( array( 7, 30, 90, 180, 365 ) as $option ) : ?>
						<option value="<?php echo (int) $option; ?>" <?php selected( $days, $option ); ?>>
							<?php
							echo esc_html(
								/* translators: %d: number of days */
								sprintf( _n( 'Last %d day', 'Last %d days', $option, 'trade-free-link-guardian' ), $option )
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'trade-free-link-guardian' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tflg_export_compliance_csv&days=' . (int) $days ), 'tflg_export_compliance_csv' ) ); ?>"><?php esc_html_e( 'Export as CSV', 'trade-free-link-guardian' ); ?></a>
			</form>

			<h2><?php echo esc_html( get_bloginfo( 'name' ) ); ?> — <?php esc_html_e( 'Link Health Summary', 'trade-free-link-guardian' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: report period start date, 2: report period end date, 3: report generation timestamp */
						__( 'Period covered: %1$s to %2$s. Generated: %3$s.', 'trade-free-link-guardian' ),
						date_i18n( get_option( 'date_format' ), $start_ts ),
						date_i18n( get_option( 'date_format' ), $end_ts ),
						date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $end_ts )
					)
				);
				?>
			</p>

			<table class="widefat striped tflg-compliance-table">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Total links currently tracked', 'trade-free-link-guardian' ); ?></th>
						<td><?php echo (int) $current['total']; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Currently needing attention', 'trade-free-link-guardian' ); ?></th>
						<td><?php echo (int) $current['broken']; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Fixes applied in this period (still in effect)', 'trade-free-link-guardian' ); ?></th>
						<td><?php echo (int) $period['total_fixes']; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'New dead-page requests logged in this period', 'trade-free-link-guardian' ); ?></th>
						<td><?php echo (int) $period['new_404_count']; ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $period['fixes_by_method'] ) ) : ?>
				<h3><?php esc_html_e( 'Fixes by method', 'trade-free-link-guardian' ); ?></h3>
				<table class="widefat striped tflg-compliance-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Method', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'Count', 'trade-free-link-guardian' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $period['fixes_by_method'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->method ); ?></td>
								<td><?php echo (int) $row->c; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="tflg-sub"><?php esc_html_e( 'Note: this report does not include an average time-to-fix figure. This plugin records when a link was last checked, not the moment it first became broken, so a duration computed from that would not be reliable enough to state here.', 'trade-free-link-guardian' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Recursively renders one tree node (and everything under it) as
	 * an <li>. A leaf (no children) is a plain item; anything with
	 * children becomes a native <details>/<summary> pair -- expand
	 * and collapse without any JavaScript at all, and keyboard-
	 * operable by default since that's simply what the element does.
	 */
	protected static function render_tree_node_html( $node ) {
		$edit_link  = get_edit_post_link( $node['id'] );
		$title_html = '<a href="' . esc_url( $edit_link ? $edit_link : '#' ) . '">' . esc_html( $node['title'] ) . '</a>';

		$badge = '';
		if ( $node['total'] > 0 ) {
			$pct   = round( ( $node['broken_count'] / $node['total'] ) * 100 );
			$color = TFLG_Util::health_color( $pct );
			$badge = ' <span class="tflg-sitemap-badge" style="background-color:' . esc_attr( $color ) . '">' . (int) $node['broken_count'] . '/' . (int) $node['total'] . '</span>';
		}

		if ( empty( $node['children'] ) ) {
			return '<li>' . $title_html . $badge . '</li>';
		}

		$children_html = '';
		foreach ( $node['children'] as $child ) {
			$children_html .= self::render_tree_node_html( $child );
		}

		return '<li><details open><summary>' . $title_html . $badge . '</summary><ul>' . $children_html . '</ul></details></li>';
	}

	public static function render_sitemap_tree() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}

		$pages      = TFLG_Scanner::get_page_hierarchy_data();
		$trees      = TFLG_Util::build_page_trees( $pages );
		$health_raw = TFLG_DB::get_source_health_summary();
		$health_map = array();
		foreach ( $health_raw as $row ) {
			if ( 'post' === $row->source_type ) {
				$health_map[ (int) $row->source_id ] = array(
					'total'        => (int) $row->total,
					'broken_count' => (int) $row->broken_count,
				);
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sitemap Tree', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'Every published page as a hierarchy, following the parent/child structure already set for each one — split into a separate tree wherever a language prefix is detected in the URL. Detected, not certain: a path starting with something like /en/ or /fr/ is treated as that language\'s own section; this doesn\'t read a multilingual plugin\'s own language data. Each page shows how many of its tracked links are broken, if any.', 'trade-free-link-guardian' ); ?></p>
			<?php if ( ! empty( $trees ) ) : ?>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tflg_export_sitemap_csv' ), 'tflg_export_sitemap_csv' ) ); ?>"><?php esc_html_e( 'Download as CSV', 'trade-free-link-guardian' ); ?></a></p>
			<?php endif; ?>

			<?php if ( empty( $trees ) ) : ?>
				<p><?php esc_html_e( 'No published pages found.', 'trade-free-link-guardian' ); ?></p>
			<?php else : ?>
				<?php foreach ( $trees as $lang_key => $tree ) : ?>
					<?php $tree = TFLG_Util::attach_health_to_tree( $tree, $health_map ); ?>
					<h2><?php echo esc_html( '' === $lang_key ? get_bloginfo( 'language' ) : $lang_key ); ?></h2>
					<ul class="tflg-sitemap-tree">
						<?php
						foreach ( $tree as $root ) {
							echo self::render_tree_node_html( $root ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_tree_node_html() escapes internally and returns ready-to-echo HTML
						}
						?>
					</ul>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_orphan_pages() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}

		$post_types = TFLG_Scanner::get_scannable_post_types();
		$candidates = array();
		if ( ! empty( $post_types ) ) {
			$q = new WP_Query(
				array(
					'post_type'              => $post_types,
					'post_status'            => 'publish',
					'posts_per_page'         => -1,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'orderby'                => 'title',
					'order'                  => 'ASC',
				)
			);
			foreach ( $q->posts as $post ) {
				$candidates[] = array(
					'id'    => $post->ID,
					'title' => get_the_title( $post ),
					'url'   => get_permalink( $post ),
				);
			}
		}

		$linked_targets = TFLG_DB::get_internal_link_targets();
		$orphans        = TFLG_Util::find_orphaned_pages( $candidates, $linked_targets );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Orphan Pages', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'The inverse of everything else in this plugin: not a link that doesn\'t go anywhere good, but a published page nothing on this site links to. Compares against every internal link this plugin already knows about, from content, menus, and widgets alike (whatever this site is configured to scan) — no new external service, nothing sent anywhere. Run "Scan site now" from the Dashboard first if this list looks incomplete or stale.', 'trade-free-link-guardian' ); ?></p>

			<?php if ( empty( $candidates ) ) : ?>
				<p><?php esc_html_e( 'No scannable pages found — check the post types selected in Settings.', 'trade-free-link-guardian' ); ?></p>
			<?php elseif ( empty( $orphans ) ) : ?>
				<p><?php esc_html_e( 'None found — every published page here is linked from somewhere else on the site.', 'trade-free-link-guardian' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of orphaned pages found, 2: total number of pages checked */
							_n(
								'%1$d page out of %2$d checked has no incoming internal links.',
								'%1$d pages out of %2$d checked have no incoming internal links.',
								count( $orphans ),
								'trade-free-link-guardian'
							),
							count( $orphans ),
							count( $candidates )
						)
					);
					?>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'trade-free-link-guardian' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $orphans as $orphan ) : ?>
							<tr>
								<td><?php echo esc_html( $orphan['title'] ); ?></td>
								<td>
									<a class="tflg-link" href="<?php echo esc_url( $orphan['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'trade-free-link-guardian' ); ?></a>
									| <a class="tflg-link" href="<?php echo esc_url( get_edit_post_link( $orphan['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'trade-free-link-guardian' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_redirects() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}
		$redirects   = TFLG_DB::get_all_redirects();
		$prefill_src = isset( $_GET['source_path'] ) ? sanitize_text_field( wp_unslash( $_GET['source_path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pre-fills a form field only, no state change
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Redirects', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'Send a specific dead path on this site straight to a working destination — entirely local, no external service involved. Exact-path rules only: this maps one known URL onto one destination, not patterns across many URLs at once.', 'trade-free-link-guardian' ); ?></p>

			<?php if ( isset( $_GET['tflg_error'] ) && 'loop' === $_GET['tflg_error'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects which notice to display ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Not saved: this would send a visitor in a circle, either straight back to the same path or through one or more of your other existing redirects and eventually back here. Check the destination and your other rules.', 'trade-free-link-guardian' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Add a redirect', 'trade-free-link-guardian' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'tflg_create_redirect' ); ?>
				<input type="hidden" name="action" value="tflg_create_redirect" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tflg-redirect-source"><?php esc_html_e( 'From this path', 'trade-free-link-guardian' ); ?></label></th>
						<td><input type="text" id="tflg-redirect-source" name="source_path" class="regular-text" placeholder="/old-page/" value="<?php echo esc_attr( $prefill_src ); ?>" required="required" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="tflg-redirect-target"><?php esc_html_e( 'To this URL', 'trade-free-link-guardian' ); ?></label></th>
						<td><input type="url" id="tflg-redirect-target" name="target_url" class="regular-text" placeholder="https://…" />
							<p class="description"><?php esc_html_e( 'Leave blank only if using "Gone" below — there is nowhere to send a visitor for that status.', 'trade-free-link-guardian' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tflg-redirect-status"><?php esc_html_e( 'Type', 'trade-free-link-guardian' ); ?></label></th>
						<td>
							<select id="tflg-redirect-status" name="status_code">
								<option value="301"><?php esc_html_e( '301 — Permanent', 'trade-free-link-guardian' ); ?></option>
								<option value="302"><?php esc_html_e( '302 — Temporary', 'trade-free-link-guardian' ); ?></option>
								<option value="410"><?php esc_html_e( '410 — Gone (no destination)', 'trade-free-link-guardian' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add redirect', 'trade-free-link-guardian' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Existing redirects', 'trade-free-link-guardian' ); ?></h2>
			<?php if ( ! $redirects ) : ?>
				<p><?php esc_html_e( 'No redirects yet.', 'trade-free-link-guardian' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'From', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'To', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'Type', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'Hits', 'trade-free-link-guardian' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $redirects as $r ) : ?>
							<tr>
								<td><code><?php echo esc_html( $r->source_path ); ?></code></td>
								<td><?php echo $r->target_url ? '<code>' . esc_html( $r->target_url ) . '</code>' : '<span class="tflg-sub">&mdash;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both branches are escaped individually above ?></td>
								<td><?php echo (int) $r->status_code; ?></td>
								<td><?php echo (int) $r->hit_count; ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
										<?php wp_nonce_field( 'tflg_delete_redirect' ); ?>
										<input type="hidden" name="action" value="tflg_delete_redirect" />
										<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>" />
										<button type="submit" class="button-link tflg-row-action" onclick="return window.confirm('<?php echo esc_js( __( 'Delete this redirect?', 'trade-free-link-guardian' ) ); ?>')"><?php esc_html_e( 'Delete', 'trade-free-link-guardian' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_404_log() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			return;
		}
		$entries = TFLG_DB::get_404_log( 200 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '404 Log', 'trade-free-link-guardian' ); ?></h1>
			<p class="tflg-help"><?php esc_html_e( 'Real requests hitting a missing page on this site — from visitors, or links from other sites, not anything this plugin\'s own content scanner found. No IP address is ever recorded here. Most-hit paths first.', 'trade-free-link-guardian' ); ?></p>

			<?php if ( ! TFLG_Util::get_setting( 'enable_404_log' ) ) : ?>
				<p><?php esc_html_e( 'Logging is currently turned off in Settings.', 'trade-free-link-guardian' ); ?></p>
			<?php endif; ?>

			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'Nothing logged yet.', 'trade-free-link-guardian' ); ?></p>
			<?php else : ?>
				<table class="widefat striped tflg-404log-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Path', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'Hits', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'Referrer', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'First seen', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'Last seen', 'trade-free-link-guardian' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php $human_hits = max( 0, (int) $entry->hit_count - (int) $entry->bot_hit_count ); ?>
							<tr data-id="<?php echo (int) $entry->id; ?>">
								<td><a class="tflg-link" href="<?php echo esc_url( home_url( $entry->path ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $entry->path ); ?></a></td>
								<td>
									<?php echo (int) $entry->hit_count; ?>
									<?php if ( $entry->bot_hit_count > 0 ) : ?>
										<br /><span class="tflg-sub">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: number of hits believed to be from a real visitor, 2: number of hits believed to be from a bot or crawler */
												__( '%1$d likely human, %2$d likely bot', 'trade-free-link-guardian' ),
												$human_hits,
												(int) $entry->bot_hit_count
											)
										);
										?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $entry->referrer ) : ?>
										<a class="tflg-link" href="<?php echo esc_url( $entry->referrer ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $entry->referrer ); ?></a>
									<?php else : ?>
										<span class="tflg-sub">&mdash;</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( human_time_diff( strtotime( $entry->first_seen . ' UTC' ), time() ) ); ?> <?php esc_html_e( 'ago', 'trade-free-link-guardian' ); ?></td>
								<td><?php echo esc_html( human_time_diff( strtotime( $entry->last_seen . ' UTC' ), time() ) ); ?> <?php esc_html_e( 'ago', 'trade-free-link-guardian' ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-redirects&source_path=' . rawurlencode( $entry->path ) ) ); ?>"><?php esc_html_e( 'Create redirect', 'trade-free-link-guardian' ); ?></a>
									| <a href="#" class="tflg-dismiss-404" data-id="<?php echo (int) $entry->id; ?>"><?php esc_html_e( 'Dismiss', 'trade-free-link-guardian' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_export_csv() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'trade-free-link-guardian' ) );
		}
		check_admin_referer( 'tflg_export_csv' );

		global $wpdb;
		$table = TFLG_DB::links_table();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=link-guardian-export-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'URL', 'Type', 'Status', 'HTTP code', 'Redirects to', 'Fix status', 'Last checked' ) );

		// Paginated by id rather than one unbounded query, so a large
		// tracked-links table doesn't have to fit in memory all at once.
		$last_id = 0;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, url, url_type, check_result, http_status, final_url, fix_status, last_checked FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$last_id
				)
			);
			foreach ( $rows as $row ) {
				// A cell starting with =, +, -, @, or a tab/CR can be
				// interpreted as a formula by spreadsheet software when
				// the file is opened — prefix with a leading quote (a
				// standard, widely-supported "treat as text" escape) so
				// data pulled from web content is never executable.
				fputcsv(
					$out,
					array_map(
						array( __CLASS__, 'csv_safe_cell' ),
						array( $row->url, $row->url_type, $row->check_result, $row->http_status, $row->final_url, $row->fix_status, $row->last_checked )
					)
				);
				$last_id = (int) $row->id;
			}
		} while ( count( $rows ) === 500 );

		fclose( $out );
		exit;
	}

	public static function handle_export_compliance_csv() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'trade-free-link-guardian' ) );
		}
		check_admin_referer( 'tflg_export_compliance_csv' );

		$days       = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30;
		$end_ts     = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$start_ts   = $end_ts - ( $days * DAY_IN_SECONDS );
		$start_date = gmdate( 'Y-m-d H:i:s', $start_ts );
		$end_date   = gmdate( 'Y-m-d H:i:s', $end_ts );

		$current = TFLG_DB::counts_by_status();
		$period  = TFLG_DB::get_compliance_summary( $start_date, $end_date );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=link-guardian-compliance-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Site', get_bloginfo( 'name' ) ) );
		fputcsv( $out, array( 'Period start', date_i18n( 'Y-m-d', $start_ts ) ) );
		fputcsv( $out, array( 'Period end', date_i18n( 'Y-m-d', $end_ts ) ) );
		fputcsv( $out, array( 'Generated', date_i18n( 'Y-m-d H:i', $end_ts ) ) );
		fputcsv( $out, array() );
		fputcsv( $out, array( 'Metric', 'Value' ) );
		fputcsv( $out, array( 'Total links currently tracked', $current['total'] ) );
		fputcsv( $out, array( 'Currently needing attention', $current['broken'] ) );
		fputcsv( $out, array( 'Fixes applied in this period (still in effect)', $period['total_fixes'] ) );
		fputcsv( $out, array( 'New dead-page requests logged in this period', $period['new_404_count'] ) );
		fputcsv( $out, array() );
		fputcsv( $out, array( 'Fix method', 'Count' ) );
		foreach ( $period['fixes_by_method'] as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_safe_cell' ), array( $row->method, $row->c ) ) );
		}

		fclose( $out );
		exit;
	}

	public static function handle_export_sitemap_csv() {
		if ( ! current_user_can( TFLG_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'trade-free-link-guardian' ) );
		}
		check_admin_referer( 'tflg_export_sitemap_csv' );

		$pages      = TFLG_Scanner::get_page_hierarchy_data();
		$trees      = TFLG_Util::build_page_trees( $pages );
		$health_raw = TFLG_DB::get_source_health_summary();
		$health_map = array();
		foreach ( $health_raw as $row ) {
			if ( 'post' === $row->source_type ) {
				$health_map[ (int) $row->source_id ] = array(
					'total'        => (int) $row->total,
					'broken_count' => (int) $row->broken_count,
				);
			}
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=link-guardian-sitemap-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Language group', 'Depth', 'Path', 'Title', 'Total tracked links', 'Broken links' ) );
		foreach ( $trees as $lang_key => $tree ) {
			$tree  = TFLG_Util::attach_health_to_tree( $tree, $health_map );
			$rows  = TFLG_Util::flatten_tree_for_export( $tree );
			$label = ( '' === $lang_key ) ? get_bloginfo( 'language' ) : $lang_key;
			foreach ( $rows as $row ) {
				fputcsv(
					$out,
					array_map(
						array( __CLASS__, 'csv_safe_cell' ),
						array( $label, $row['depth'], $row['path'], $row['title'], $row['total'], $row['broken_count'] )
					)
				);
			}
		}

		fclose( $out );
		exit;
	}

	protected static function csv_safe_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}
}

/* =========================================================================
 * SECTION: TFLG_Ajax — every wp_ajax_ endpoint the admin UI calls.
 * Every handler checks capability + nonce first via the same helper.
 * ========================================================================= */

class TFLG_Ajax {

	public static function init() {
		foreach ( array( 'scan_start', 'scan_batch', 'scan_cancel', 'check_start', 'check_batch', 'row_action', 'undo_fix', 'undo_fix_bulk', 'bulk_filtered_start', 'bulk_filtered_batch', 'cleanup_orphans', 'dismiss_404', 'report_start', 'report_batch' ) as $action ) {
			add_action( 'wp_ajax_tflg_' . $action, array( __CLASS__, $action ) );
		}
	}

	public static function scan_start() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );
		wp_send_json_success( TFLG_Scanner::start() );
	}

	public static function scan_batch() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );
		wp_send_json_success( TFLG_Scanner::process_batch() );
	}

	/**
	 * Stops a scan the person cancelled from the UI. Only relevant to the
	 * "scanning" phase (discovering content), which has a persistent
	 * server-side state; the "checking" phase has nothing to clear here
	 * since every check that already ran is real, useful, saved data —
	 * cancelling it just means stop polling for more, which the browser
	 * handles on its own by not asking again. Clearing the state also
	 * keeps WP-Cron from silently resuming a scan the person deliberately
	 * stopped.
	 */
	public static function scan_cancel() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );
		$state = get_option( 'tflg_scan_state' );
		if ( is_array( $state ) && ! empty( $state['run'] ) ) {
			delete_transient( 'tflg_scan_queue_' . $state['run'] );
		}
		update_option( 'tflg_scan_state', array( 'phase' => 'idle' ), false );
		wp_send_json_success();
	}

	public static function check_start() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );
		$hours = max( 1, (int) TFLG_Util::get_setting( 'recheck_after_hours' ) );
		wp_send_json_success( array( 'total' => TFLG_DB::count_links_due_for_check( $hours ) ) );
	}

	public static function check_batch() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );
		// A short time budget here (not the long one used for the
		// background WP-Cron tick below) so the browser gets an update
		// every few seconds instead of waiting out a full 20-second
		// window before seeing anything — the underlying checking work
		// is exactly as fast either way, this only changes how often
		// progress is reported back.
		$result    = TFLG_Checker::check_due_links( 300, 4.0 );
		$hours     = max( 1, (int) TFLG_Util::get_setting( 'recheck_after_hours' ) );
		$remaining = TFLG_DB::get_links_due_for_check( $hours, 1 );
		wp_send_json_success(
			array(
				'checked' => $result['done'],
				'items'   => $result['items'],
				'more'    => ! empty( $remaining ),
			)
		);
	}

	public static function row_action() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );

		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$link_id = isset( $_POST['link_id'] ) ? (int) $_POST['link_id'] : 0;

		if ( ! $link_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing link.', 'trade-free-link-guardian' ) ) );
		}

		switch ( $op ) {
			case 'recheck':
				$link = TFLG_DB::get_link( $link_id );
				if ( ! $link ) {
					wp_send_json_error( array( 'message' => __( 'Link not found.', 'trade-free-link-guardian' ) ) );
				}
				wp_send_json_success( TFLG_Checker::check_link( $link ) );
				break;

			case 'autofix':
				$result = TFLG_Fixer::attempt_autofix( $link_id );
				empty( $result['success'] ) ? wp_send_json_error( $result ) : wp_send_json_success( $result );
				break;

			case 'replace':
				$new_url = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : '';
				if ( '' === $new_url ) {
					wp_send_json_error( array( 'message' => __( 'Please enter a URL.', 'trade-free-link-guardian' ) ) );
				}
				wp_send_json_success( TFLG_Fixer::apply_fix( $link_id, $new_url, 'manual_replace' ) );
				break;

			case 'unlink':
				wp_send_json_success( TFLG_Fixer::unlink_everywhere( $link_id ) );
				break;

			case 'note':
				$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
				TFLG_DB::update_note( $link_id, $note );
				wp_send_json_success( array( 'note' => $note ) );
				break;

			case 'ack_drift':
				TFLG_DB::acknowledge_drift( $link_id );
				wp_send_json_success();
				break;

			case 'use_final':
				$link = TFLG_DB::get_link( $link_id );
				if ( ! $link || ! $link->final_url ) {
					wp_send_json_error( array( 'message' => __( 'No final URL on record — try Re-check first.', 'trade-free-link-guardian' ) ) );
				}
				wp_send_json_success( TFLG_Fixer::apply_fix( $link_id, $link->final_url, 'auto_redirect' ) );
				break;

			case 'restore_post':
				$result = TFLG_Fixer::restore_trashed_post( $link_id );
				empty( $result['success'] ) ? wp_send_json_error( $result ) : wp_send_json_success( $result );
				break;

			case 'ignore':
				wp_send_json_success( TFLG_Fixer::ignore_link( $link_id ) );
				break;

			case 'unignore':
				wp_send_json_success( TFLG_Fixer::unignore_link( $link_id ) );
				break;

			case 'wayback':
				$link = TFLG_DB::get_link( $link_id );
				if ( ! $link ) {
					wp_send_json_error( array( 'message' => __( 'Link not found.', 'trade-free-link-guardian' ) ) );
				}
				$result = TFLG_Fixer::wayback_lookup( $link->url );
				empty( $result['success'] ) ? wp_send_json_error( $result ) : wp_send_json_success( $result );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Unknown action.', 'trade-free-link-guardian' ) ) );
		}
	}

	public static function undo_fix() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );
		$fixlog_id = isset( $_POST['fixlog_id'] ) ? (int) $_POST['fixlog_id'] : 0;
		$result    = TFLG_Fixer::revert_fix( $fixlog_id );
		empty( $result['success'] ) ? wp_send_json_error( $result ) : wp_send_json_success( $result );
	}

	/**
	 * Undoes several fix-log entries in one request rather than one
	 * click at a time. Reuses revert_fix() exactly as-is, once per
	 * selected id -- the same per-entry checks (already reverted, an
	 * unlink/ignore/restore_post entry that was never auto-revertible
	 * to begin with) apply identically here, so a bulk selection can
	 * never revert something the single Undo link wouldn't have
	 * allowed either. Time-boxed and resumable in slices, the same
	 * shape used elsewhere for anything that loops over a
	 * user-controlled batch, even though a plain revert makes no
	 * network request of its own -- a large enough selection, each
	 * rewriting content across every place its link occurs, could
	 * still genuinely add up.
	 */
	public static function undo_fix_bulk() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );

		$ids = isset( $_POST['fixlog_ids'] ) ? array_filter( array_map( 'absint', (array) $_POST['fixlog_ids'] ) ) : array();

		$results = array();
		$start   = microtime( true );
		foreach ( $ids as $id ) {
			$result    = TFLG_Fixer::revert_fix( $id );
			$results[] = array(
				'fixlog_id' => $id,
				'success'   => ! empty( $result['success'] ),
				'message'   => isset( $result['message'] ) ? $result['message'] : '',
			);
			if ( ( microtime( true ) - $start ) >= 4.0 ) {
				break;
			}
		}

		wp_send_json_success(
			array(
				'results'   => $results,
				'remaining' => array_slice( $ids, count( $results ) ),
			)
		);
	}

	public static function cleanup_orphans() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );
		$removed = TFLG_DB::delete_orphaned_links();
		wp_send_json_success( array( 'removed' => $removed ) );
	}

	public static function dismiss_404() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		TFLG_DB::delete_404_log_entry( $id );
		wp_send_json_success();
	}

	/**
	 * "Apply to everything matching the current filter" starts here: a
	 * plain count, so the browser knows the real total up front — the
	 * same reason the checking phase gets one before its own progress
	 * bar starts moving.
	 */
	public static function bulk_filtered_start() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$search = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		wp_send_json_success( array( 'total' => TFLG_DB::count_links_matching( $status, $search ) ) );
	}

	/**
	 * One time-boxed batch of a bulk action across everything matching
	 * the filter — the same "never risk a single request running long
	 * enough to hit a server execution-time limit" approach used for
	 * checking, applied here so "select all, apply to all" can never
	 * turn into the exact class of silent hang that took real work to
	 * track down and fix elsewhere in this plugin.
	 */
	public static function bulk_filtered_batch() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );

		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$search   = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		$op       = isset( $_POST['bulk_op'] ) ? sanitize_key( wp_unslash( $_POST['bulk_op'] ) ) : '';
		$after_id = isset( $_POST['after_id'] ) ? (int) $_POST['after_id'] : 0;

		if ( ! in_array( $op, array( 'recheck', 'autofix', 'ignore', 'forget' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown bulk action.', 'trade-free-link-guardian' ) ) );
		}

		// A generous fetch pool; each op's own time-budget logic below
		// governs how much of it actually gets processed in this call,
		// the same "fetch more than you need, let the budget decide"
		// pattern used for checking.
		$links = TFLG_DB::get_links_matching( $status, $search, $after_id, 300 );

		$items   = array();
		$last_id = $after_id;

		if ( 'recheck' === $op ) {
			// Re-checking benefits from the exact same concurrent
			// handling as the main checking flow — no reason a bulk
			// re-check from this screen should be any slower per link.
			$result = TFLG_Checker::check_link_rows( $links, 4.0 );
			$items  = $result['items'];
			foreach ( $links as $link ) {
				$last_id = max( $last_id, (int) $link->id );
			}
		} else {
			$start = microtime( true );
			foreach ( $links as $link ) {
				$last_id = (int) $link->id;
				switch ( $op ) {
					case 'autofix':
						$r        = TFLG_Fixer::attempt_autofix( $link->id );
						$items[]  = array(
							'url'    => $link->url,
							'result' => empty( $r['success'] ) ? 'unknown' : 'ok',
						);
						break;
					case 'ignore':
						TFLG_Fixer::ignore_link( $link->id );
						$items[] = array( 'url' => $link->url, 'result' => 'unknown' );
						break;
					case 'forget':
						global $wpdb;
						$wpdb->delete( TFLG_DB::occurrences_table(), array( 'link_id' => $link->id ), array( '%d' ) );
						$wpdb->delete( TFLG_DB::links_table(), array( 'id' => $link->id ), array( '%d' ) );
						$items[] = array( 'url' => $link->url, 'result' => 'unknown' );
						break;
				}
				if ( ( microtime( true ) - $start ) >= 4.0 ) {
					break;
				}
			}
		}

		$remaining = TFLG_DB::get_links_matching( $status, $search, $last_id, 1 );

		wp_send_json_success(
			array(
				'done'    => count( $items ),
				'last_id' => $last_id,
				'more'    => ! empty( $remaining ),
				'items'   => $items,
			)
		);
	}

	public static function report_start() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );
		wp_send_json_success( array( 'total' => TFLG_DB::count_links_matching( 'broken', '' ) ) );
	}

	/**
	 * One time-boxed batch of the "what would be proposed" report:
	 * calls propose_autofix() -- never attempt_autofix() -- so nothing
	 * this generates ever writes to the database or rewrites any
	 * content. Same cursor-and-time-budget shape as
	 * bulk_filtered_batch() above, for the same reason: this can cover
	 * hundreds of broken links, some of which need a genuine live
	 * check (a protocol or www swap can't be proposed honestly without
	 * one), so it has to be resumable in slices rather than risk one
	 * long request.
	 */
	public static function report_batch() {
		TFLG_Util::verify_nonce_and_cap( 'tflg_ajax' );

		$after_id = isset( $_POST['after_id'] ) ? (int) $_POST['after_id'] : 0;
		$links    = TFLG_DB::get_links_matching( 'broken', '', $after_id, 300 );

		$items   = array();
		$last_id = $after_id;
		$start   = microtime( true );
		foreach ( $links as $link ) {
			$last_id  = (int) $link->id;
			$proposal = TFLG_Fixer::propose_autofix( $link->id );
			$items[]  = array(
				'url'      => $link->url,
				'method'   => $proposal ? $proposal['method'] : '',
				'proposed' => $proposal ? $proposal['proposed_url'] : '',
			);
			if ( ( microtime( true ) - $start ) >= 4.0 ) {
				break;
			}
		}

		$remaining = TFLG_DB::get_links_matching( 'broken', '', $last_id, 1 );

		wp_send_json_success(
			array(
				'last_id' => $last_id,
				'more'    => ! empty( $remaining ),
				'items'   => $items,
			)
		);
	}
}

/* =========================================================================
 * SECTION: TFLG_Network_Admin — a network-wide rollup, only shown when
 * WordPress itself is a multisite network. Everything else in this
 * plugin looks at one site at a time, using per-site tables it already
 * relies on switch_to_blog() for elsewhere. This is the one screen
 * that steps back across all of them at once, live, reusing the exact
 * same counts_by_status() query already used by the single-site
 * Dashboard, once per site.
 * ========================================================================= */

class TFLG_Network_Admin {

	public static function init() {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'network_admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Link Guardian (Network)', 'trade-free-link-guardian' ),
			__( 'Link Guardian', 'trade-free-link-guardian' ),
			'manage_network',
			'tflg-network',
			array( __CLASS__, 'render' ),
			'dashicons-admin-links'
		);
	}

	/**
	 * One row per site where this plugin is actually active (network-
	 * activated, or turned on for that individual site) -- a site
	 * where it was never activated has none of this plugin's tables at
	 * all, so it's left out rather than queried into an error.
	 */
	protected static function gather_site_counts() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$basename = plugin_basename( TFLG_PLUGIN_FILE );
		$rows     = array();
		foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
			switch_to_blog( $site_id );
			if ( is_plugin_active( $basename ) ) {
				$counts      = TFLG_DB::counts_by_status();
				$page_counts = wp_count_posts( 'page' );
				$rows[]      = array(
					'site_id'    => $site_id,
					'name'       => get_bloginfo( 'name' ),
					'url'        => home_url(),
					'counts'     => $counts,
					'page_count' => $page_counts ? (int) $page_counts->publish : 0,
				);
			}
			restore_current_blog();
		}
		return $rows;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_network' ) ) {
			return;
		}
		$rows = self::gather_site_counts();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Link Guardian — Network', 'trade-free-link-guardian' ); ?></h1>
			<p><?php esc_html_e( 'Every site in this network where this plugin is active, one row each, worst first — the same numbers each site\'s own Dashboard already shows, gathered here in one place rather than checking each site individually.', 'trade-free-link-guardian' ); ?></p>

			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'This plugin isn\'t active on any site in this network yet.', 'trade-free-link-guardian' ); ?></p>
			<?php else : ?>
				<?php
				usort(
					$rows,
					function( $a, $b ) {
						return $b['counts']['broken'] - $a['counts']['broken'];
					}
				);
				?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Site', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'Total tracked', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'Needs attention', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'Fixed', 'trade-free-link-guardian' ); ?></th>
							<th><?php esc_html_e( 'Pages', 'trade-free-link-guardian' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['name'] ); ?><br /><span class="tflg-sub"><?php echo esc_html( $row['url'] ); ?></span></td>
								<td><?php echo (int) $row['counts']['total']; ?></td>
								<td><?php echo (int) $row['counts']['broken']; ?></td>
								<td><?php echo (int) $row['counts']['auto_fixed']; ?></td>
								<td><?php echo (int) $row['page_count']; ?></td>
								<td><a class="tflg-link" href="<?php echo esc_url( get_admin_url( $row['site_id'], 'admin.php?page=tflg-dashboard' ) ); ?>"><?php esc_html_e( 'Open', 'trade-free-link-guardian' ); ?></a> · <a class="tflg-link" href="<?php echo esc_url( get_admin_url( $row['site_id'], 'admin.php?page=tflg-sitemap' ) ); ?>"><?php esc_html_e( 'Sitemap', 'trade-free-link-guardian' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

/* =========================================================================
 * SECTION: TFLG_Editor_Check — proactive, at-the-moment-of-authoring
 * warning, the other side of this plugin's usual after-the-fact
 * scanning. Every other part of this plugin works the same way:
 * something gets published, a scan or a passive log notices a
 * problem with it later. This is the one exception -- checking on
 * every save, before publishing is even final, against links this
 * plugin already knows are broken from checking elsewhere on the
 * site. Deliberately narrow: it never makes a live network check of
 * its own (that would slow down every single save), it only asks
 * what is already on record.
 * ========================================================================= */

class TFLG_Editor_Check {

	const TRANSIENT_PREFIX = 'tflg_editor_warning_';

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'check_on_save' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_notice' ) );
	}

	public static function check_on_save( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, TFLG_Scanner::get_scannable_post_types(), true ) ) {
			return;
		}

		$found_urls = array();
		foreach ( TFLG_Scanner::extract_from_html( $post->post_content ) as $item ) {
			$found_urls[] = $item['url'];
		}
		if ( function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
			foreach ( TFLG_Scanner::extract_from_blocks( $post->post_content ) as $url ) {
				$found_urls[] = $url;
			}
		}

		if ( empty( $found_urls ) ) {
			delete_transient( self::TRANSIENT_PREFIX . $post_id );
			return;
		}

		$known_broken = TFLG_DB::get_known_broken_among( array_values( array_unique( $found_urls ) ) );
		if ( empty( $known_broken ) ) {
			delete_transient( self::TRANSIENT_PREFIX . $post_id );
			return;
		}

		set_transient( self::TRANSIENT_PREFIX . $post_id, $known_broken, 5 * MINUTE_IN_SECONDS );
	}

	public static function maybe_show_notice() {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only used to look up a stashed notice keyed by this same id
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return; // 1.33.1: don't show a post's known-broken URLs to a user who can't edit it.
		}
		$known_broken = get_transient( self::TRANSIENT_PREFIX . $post_id );
		if ( ! $known_broken ) {
			return;
		}
		delete_transient( self::TRANSIENT_PREFIX . $post_id );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Trade-Free Link Guardian:', 'trade-free-link-guardian' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of links already known to be broken */
						_n(
							'This content includes %d link already known to be broken, from checking elsewhere on this site:',
							'This content includes %d links already known to be broken, from checking elsewhere on this site:',
							count( $known_broken ),
							'trade-free-link-guardian'
						),
						count( $known_broken )
					)
				);
				?>
			</p>
			<ul style="margin-left:1.5em;list-style:disc;">
				<?php foreach ( $known_broken as $url ) : ?>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=tflg-links&s=' . rawurlencode( $url ) ) ); ?>"><code><?php echo esc_html( $url ); ?></code></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}

/* =========================================================================
 * SECTION: TFLG_404_Monitor — passive log of real front-end 404 hits
 *
 * A different kind of problem than everything else in this plugin: this
 * site's own content scanner can only ever find links that are broken
 * INSIDE content it controls. It has no way to know that some other
 * site's link, or an old bookmark, or a stale search-engine result,
 * points at a page here that no longer exists -- the request never
 * touches anything the scanner looks at. This is a passive net for
 * exactly that: logging real front-end 404s as they happen, not
 * fetching or checking anything itself. No IP address is ever
 * collected here, by design.
 * ========================================================================= */

class TFLG_404_Monitor {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_log' ) );
	}

	public static function maybe_log() {
		if ( ! is_404() ) {
			return;
		}
		$raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path    = TFLG_Util::normalize_404_path( $raw_uri );
		if ( '' === $path ) {
			return;
		}

		// A redirect rule is something the admin explicitly configured,
		// unlike the passive log below -- it always takes effect,
		// independent of whether 404 logging itself is turned on.
		$redirect = TFLG_DB::get_matching_redirect( $path );
		if ( $redirect ) {
			TFLG_DB::increment_redirect_hit( $redirect->id );
			if ( 410 === (int) $redirect->status_code ) {
				status_header( 410 );
				return; // "Gone" has nowhere to redirect to -- WordPress's own 404 template still renders, just with a 410 status instead of 404.
			}
			wp_redirect( $redirect->target_url, (int) $redirect->status_code ); // phpcs:ignore WordPress.Security.SafeRedirect -- target_url is admin-authored, not user input; wp_safe_redirect() would wrongly block a legitimate redirect to an external destination
			exit;
		}

		if ( ! TFLG_Util::get_setting( 'enable_404_log' ) ) {
			return;
		}
		if ( TFLG_Util::is_excluded_404_path( $path ) ) {
			return;
		}
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		// A visitor controls this header entirely and can send an
		// arbitrarily long value; the column is VARCHAR(500), and under
		// strict SQL mode (the default on many modern hosts) an
		// over-length value fails the insert outright rather than being
		// silently cut down to fit -- truncating here, safely on a
		// character boundary, means a deliberately oversized Referer
		// can never make this plugin's own logging of the hit fail.
		$referrer = TFLG_Util::truncate_utf8_safe( $referrer, 500 );
		$ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		TFLG_DB::record_404_hit( $path, $referrer, TFLG_Util::is_bot_user_agent( $ua ) );
	}
}

/* =========================================================================
 * SECTION: TFLG_Cron — the optional scheduled scan, driven off-browser
 * by the exact same Scanner/Checker pipeline the "Scan site now" button
 * uses. Runs in bounded chunks per tick and chains a near-immediate
 * follow-up event until a full pass is done, so no single cron request
 * risks timing out on a large site.
 * ========================================================================= */

class TFLG_Cron {

	const HOOK          = 'tflg_cron_tick';
	const FOLLOWUP_HOOK = 'tflg_cron_followup';
	const SNAPSHOT_HOOK = 'tflg_daily_snapshot';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( self::FOLLOWUP_HOOK, array( __CLASS__, 'tick' ) );
		add_action( self::SNAPSHOT_HOOK, array( __CLASS__, 'take_snapshot' ) );
		// Independent of the scan schedule setting (which can be off) --
		// health history should keep accumulating even for a site that
		// only ever scans manually. Also covers an existing install that
		// activated before this existed: the very next admin page load
		// notices the event isn't scheduled yet and schedules it, the
		// same self-healing approach maybe_upgrade() already uses for a
		// database change on an already-active install.
		if ( ! wp_next_scheduled( self::SNAPSHOT_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::SNAPSHOT_HOOK );
			// Also take one right now, rather than only from the first
			// scheduled tick an hour from now — an existing install (or
			// a site that only ever scans manually) gets today as its
			// first data point immediately, not a day or more later.
			self::take_snapshot();
		}
	}

	public static function take_snapshot() {
		TFLG_DB::take_health_snapshot();
		TFLG_DB::prune_old_snapshots();
		TFLG_DB::prune_404_log();
	}

	public static function add_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'trade-free-link-guardian' ),
			);
		}
		return $schedules;
	}

	public static function schedule_from_settings() {
		self::reschedule( TFLG_Util::get_setting( 'schedule' ) );
	}

	public static function reschedule( $schedule ) {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		if ( 'off' === $schedule ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, self::HOOK );
		}
	}

	/**
	 * Overlap guard (1.33.1): WP-Cron can fire the same hook twice (two
	 * concurrent page loads, or the scheduled tick racing a followup
	 * event). Two overlapping ticks would double the outbound request
	 * load and race on the shared scan state, so a short-lived lock lets
	 * only one run; a crashed run's lock expires by itself after 10 min.
	 */
	public static function tick() {
		if ( get_transient( 'tflg_cron_lock' ) ) {
			return;
		}
		set_transient( 'tflg_cron_lock', time(), 10 * MINUTE_IN_SECONDS );
		try {
			self::run_tick();
		} finally {
			delete_transient( 'tflg_cron_lock' );
		}
	}

	protected static function run_tick() {
		$state    = get_option( 'tflg_scan_state' );
		$mid_scan = is_array( $state ) && 'scanning' === ( isset( $state['phase'] ) ? $state['phase'] : '' );

		if ( ! $mid_scan ) {
			$run_state = get_option( 'tflg_cron_run_state' );
			if ( ! is_array( $run_state ) || 'idle' === ( isset( $run_state['phase'] ) ? $run_state['phase'] : 'idle' ) ) {
				TFLG_Scanner::start();
				$counts = TFLG_DB::counts_by_status();
				update_option( 'tflg_cron_run_state', array(
					'phase'         => 'running',
					'broken_before' => $counts['broken'],
				), false );
			}
		}

		$scan_state = TFLG_Scanner::process_batch( 50 );
		if ( 'scanning' === $scan_state['phase'] ) {
			self::schedule_followup();
			return;
		}

		// A longer budget here than the AJAX-driven path uses — nobody's
		// watching a live progress bar during a background cron tick, so
		// there's no reason to return early just for update cadence; a
		// longer per-tick budget means fewer WP-Cron ticks overall.
		TFLG_Checker::check_due_links( 300, 20.0 );
		$hours     = max( 1, (int) TFLG_Util::get_setting( 'recheck_after_hours' ) );
		$remaining = TFLG_DB::get_links_due_for_check( $hours, 1 );
		if ( ! empty( $remaining ) ) {
			self::schedule_followup();
			return;
		}

		$run_state = get_option( 'tflg_cron_run_state' );
		$before    = ( is_array( $run_state ) && isset( $run_state['broken_before'] ) ) ? (int) $run_state['broken_before'] : 0;
		$counts    = TFLG_DB::counts_by_status();
		update_option( 'tflg_cron_run_state', array( 'phase' => 'idle' ), false );

		if ( $counts['broken'] > $before && TFLG_Util::get_setting( 'email_notify' ) ) {
			self::send_notification_email( $counts['broken'], $counts['broken'] - $before );
		}
	}

	protected static function schedule_followup() {
		if ( ! wp_next_scheduled( self::FOLLOWUP_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::FOLLOWUP_HOOK );
		}
	}

	public static function send_notification_email( $total_broken, $new_count ) {
		$configured = TFLG_Util::get_setting( 'notify_email' );
		$to         = ( $configured && is_email( $configured ) ) ? $configured : get_option( 'admin_email' );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Link Guardian found broken links', 'trade-free-link-guardian' ), get_bloginfo( 'name' ) );
		$body    = sprintf(
			/* translators: 1: newly broken link count, 2: total links needing attention, 3: admin URL */
			__( "A scheduled scan found %1\$d new broken link(s), for %2\$d total needing attention.\n\nReview them here:\n%3\$s\n", 'trade-free-link-guardian' ),
			$new_count,
			$total_broken,
			admin_url( 'admin.php?page=tflg-links&status=broken' )
		);
		wp_mail( $to, $subject, $body );
	}

	public static function clear() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		$followup = wp_next_scheduled( self::FOLLOWUP_HOOK );
		if ( $followup ) {
			wp_unschedule_event( $followup, self::FOLLOWUP_HOOK );
		}
		$snapshot = wp_next_scheduled( self::SNAPSHOT_HOOK );
		if ( $snapshot ) {
			wp_unschedule_event( $snapshot, self::SNAPSHOT_HOOK );
		}
	}
}

/* =========================================================================
 * SECTION: TFLG_Plugin — bootstrap, activation, deactivation, uninstall
 * ========================================================================= */

class TFLG_Plugin {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
		if ( is_admin() ) {
			TFLG_Admin::init();
			TFLG_Ajax::init();
			TFLG_Editor_Check::init();
		}
		TFLG_Cron::init();
		TFLG_404_Monitor::init();
		TFLG_Network_Admin::init();
		add_action( 'tflg_after_check_link', array( 'TFLG_Checker', 'maybe_check_drift' ), 10, 2 );
		add_filter( 'http_request_host_is_external', array( 'TFLG_Checker', 'allow_own_host' ), 10, 2 );
		// A site created later, after this plugin is already network-
		// active, never goes through activate() — WordPress creates it
		// independently. Without this, that site would be missing its
		// tables the exact same way network-activating originally could
		// leave an existing site without them.
		add_action( 'wp_initialize_site', array( 'TFLG_Plugin', 'activate_new_site' ), 100 );
	}

	public function maybe_upgrade() {
		TFLG_DB::maybe_upgrade();
	}

	/**
	 * Runs once, when the plugin is activated. On a multisite network,
	 * "Network Activate" passes true here — and WordPress does NOT
	 * automatically repeat this for every site; each site has its own
	 * tables (a distinct $wpdb->prefix), so the per-site setup below has
	 * to be run once per site explicitly, or every site but the one that
	 * happened to be current at the moment of activation would silently
	 * end up without its own tables — the requests it makes would look
	 * entirely successful (a real 200 response) while querying tables
	 * that were never created for that particular site.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::activate_for_current_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_for_current_site();
	}

	protected static function activate_for_current_site() {
		TFLG_DB::create_tables();
		if ( false === get_option( 'tflg_settings', false ) ) {
			update_option( 'tflg_settings', TFLG_Util::default_settings(), false );
		}
		TFLG_Cron::schedule_from_settings();
	}

	/**
	 * A site created after this plugin is already network-active skips
	 * activate() entirely — WordPress sets the new site up on its own.
	 * Same per-site setup, run only if the plugin is actually
	 * network-active, so a site on a network where it's merely active on
	 * a few individual sites doesn't get tables it never asked for.
	 */
	public static function activate_new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( TFLG_PLUGIN_FILE ) ) ) {
			return;
		}
		$site_id = is_object( $site ) ? $site->blog_id : $site;
		switch_to_blog( $site_id );
		self::activate_for_current_site();
		restore_current_blog();
	}

	/**
	 * Runs on deactivation. Data is deliberately kept — deactivating is
	 * not the same as deleting. See uninstall() for full removal. Same
	 * per-site reasoning as activate(): a network-wide deactivation has
	 * to clear each site's own scheduled cron event individually.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( $site_id );
				TFLG_Cron::clear();
				restore_current_blog();
			}
			return;
		}
		TFLG_Cron::clear();
	}

	/**
	 * Runs only when the plugin is actually deleted from the Plugins
	 * screen. Removes every table, option and transient this plugin ever
	 * created — nothing is left behind. Registered as a static callback,
	 * as register_uninstall_hook() requires. Unlike activate/deactivate,
	 * WordPress doesn't pass a $network_wide flag here — uninstalling
	 * means gone everywhere, so every site on a multisite network is
	 * cleaned up regardless of how the plugin happened to be activated.
	 */
	public static function uninstall() {
		if ( is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::uninstall_for_current_site();
				restore_current_blog();
			}
			return;
		}
		self::uninstall_for_current_site();
	}

	protected static function uninstall_for_current_site() {
		global $wpdb;

		TFLG_DB::drop_tables();

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( 'tflg_' ) . '%'
			)
		);

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( '_transient_tflg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_tflg_' ) . '%'
			)
		);

		// The unchanged-content scan shortcut (see TFLG_Scanner::scan_post())
		// leaves one small meta value per scanned post — removed here so
		// uninstalling truly leaves nothing behind, on posts as much as
		// in the options table.
		delete_post_meta_by_key( '_tflg_last_scan_fingerprint' );
	}
}

register_activation_hook( TFLG_PLUGIN_FILE, array( 'TFLG_Plugin', 'activate' ) );
register_deactivation_hook( TFLG_PLUGIN_FILE, array( 'TFLG_Plugin', 'deactivate' ) );
register_uninstall_hook( TFLG_PLUGIN_FILE, array( 'TFLG_Plugin', 'uninstall' ) );

add_action( 'plugins_loaded', array( 'TFLG_Plugin', 'instance' ) );
