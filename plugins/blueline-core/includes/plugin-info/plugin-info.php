<?php
/**
 * Plugin details for wp-admin without WordPress.org: the "View details" link on the Plugins screen,
 * the info pop-up (plugin-information thickbox) and the icon on Dashboard > Updates.
 *
 * A plugin that is not listed on WordPress.org has no data for these screens. This module supplies
 * it locally and never makes an HTTP request:
 *
 * - `plugins_api` answers the `plugin_information` request for this plugin's slug from the plugin
 *   header and the bundled readme.txt (standard WordPress readme format), so core never asks
 *   api.wordpress.org.
 * - `site_transient_update_plugins` gets a `no_update` entry for this plugin (only when core has not
 *   put it in `response` or `no_update` itself), which is what makes core treat it as a known plugin.
 *   The injection works on a clone, so it is never written back to the database.
 * - The icon and banner file names are fixed by this module and live in assets/.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

// The slug WordPress asks about (the plugin folder and text domain).
const BLUELINE_CORE_PLUGIN_INFO_SLUG = 'blueline-core';

add_filter( 'plugins_api', 'blueline_core_plugin_info_api', 10, 3 );
/**
 * Answer the `plugin_information` request for this plugin from local data.
 *
 * @param false|object|array $result The result so far (false until something answers).
 * @param string             $action  The plugins_api() action.
 * @param object|array|null  $args    The request arguments; `slug` names the plugin.
 * @return false|object|array The plugin's data for our slug, otherwise $result unchanged.
 */
function blueline_core_plugin_info_api( $result, $action = '', $args = null ) {
	if ( 'plugin_information' !== $action ) {
		return $result;
	}

	$slug = '';
	if ( is_object( $args ) && isset( $args->slug ) ) {
		$slug = (string) $args->slug;
	} elseif ( is_array( $args ) && isset( $args['slug'] ) ) {
		$slug = (string) $args['slug'];
	}

	if ( BLUELINE_CORE_PLUGIN_INFO_SLUG !== $slug ) {
		return $result;
	}

	return blueline_core_plugin_info_data();
}

add_filter( 'site_transient_update_plugins', 'blueline_core_plugin_info_known_plugin' );
/**
 * Make WordPress treat this plugin as a known one: add it to the update transient's `no_update` list.
 *
 * Only an object value is touched, only when the plugin is in neither `response` nor `no_update`, and
 * every other entry stays as it is. A clone is returned so the (possibly cached) transient object is
 * not mutated and the entry is never persisted by code that reads and re-saves the transient.
 *
 * @param mixed $value The `update_plugins` site transient (an object, or false when unset).
 * @return mixed
 */
function blueline_core_plugin_info_known_plugin( $value ) {
	if ( ! is_object( $value ) ) {
		return $value;
	}

	$file = plugin_basename( BLUELINE_CORE_FILE );

	if ( isset( $value->response ) && is_array( $value->response ) && isset( $value->response[ $file ] ) ) {
		return $value;
	}

	if ( isset( $value->no_update ) && ! is_array( $value->no_update ) ) {
		return $value;
	}

	if ( isset( $value->no_update[ $file ] ) ) {
		return $value;
	}

	$info   = blueline_core_plugin_info_data();
	$assets = blueline_core_plugin_info_assets();
	$entry  = new stdClass();

	$entry->id           = 'local/plugins/' . BLUELINE_CORE_PLUGIN_INFO_SLUG;
	$entry->slug         = BLUELINE_CORE_PLUGIN_INFO_SLUG;
	$entry->plugin       = $file;
	$entry->new_version  = BLUELINE_CORE_VERSION;
	$entry->url          = (string) $info->homepage;
	$entry->package      = '';
	$entry->icons        = $assets['icons'];
	$entry->banners      = $assets['banners'];
	$entry->requires     = (string) $info->requires;
	$entry->requires_php = (string) $info->requires_php;

	$value = clone $value;

	$no_update          = isset( $value->no_update ) ? $value->no_update : array();
	$no_update[ $file ] = $entry;
	$value->no_update   = $no_update;

	return $value;
}

/**
 * Icon and banner URLs, built from the fixed file names in assets/.
 *
 * @return array{icons: array<string, string>, banners: array<string, string>}
 */
function blueline_core_plugin_info_assets(): array {
	// Versioned by file mtime: a redrawn image keeps its name, and a CDN would otherwise keep serving the old one.
	$url = static function ( string $name ): string {
		$path = BLUELINE_CORE_DIR . '/assets/' . $name;
		$ver  = is_file( $path ) ? (string) filemtime( $path ) : BLUELINE_CORE_VERSION;

		return plugins_url( 'assets/' . $name, BLUELINE_CORE_FILE ) . '?ver=' . $ver;
	};

	return array(
		'icons'   => array(
			'1x'      => $url( 'icon-128x128.png' ),
			'2x'      => $url( 'icon-256x256.png' ),
			'svg'     => $url( 'icon.svg' ),
			'default' => $url( 'icon-256x256.png' ),
		),
		'banners' => array(
			'low'  => $url( 'banner-772x250.png' ),
			'high' => $url( 'banner-1544x500.png' ),
		),
	);
}

/**
 * The plugin_information object for this plugin.
 *
 * @param string|null $readme_path Readme to read (default: this plugin's readme.txt).
 * @return stdClass
 */
function blueline_core_plugin_info_data( ?string $readme_path = null ): stdClass {
	$headers = blueline_core_plugin_info_headers();
	$readme  = blueline_core_plugin_info_readme( $readme_path );
	$assets  = blueline_core_plugin_info_assets();

	$homepage       = $headers['PluginURI'];
	$author         = $headers['Author'];
	$author_profile = '' !== $headers['AuthorURI'] ? $headers['AuthorURI'] : $homepage;

	$info = new stdClass();

	$info->name           = '' !== $headers['Name'] ? $headers['Name'] : 'Blueline Core';
	$info->slug           = BLUELINE_CORE_PLUGIN_INFO_SLUG;
	$info->version        = BLUELINE_CORE_VERSION;
	$info->author         = '' !== $author_profile
		? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $author_profile ), esc_html( $author ) )
		: esc_html( $author );
	$info->author_profile = $author_profile;
	$info->homepage       = $homepage;
	$info->requires       = '' !== $headers['RequiresWP'] ? $headers['RequiresWP'] : (string) ( $readme['headers']['requires at least'] ?? '' );
	$info->requires_php   = '' !== $headers['RequiresPHP'] ? $headers['RequiresPHP'] : (string) ( $readme['headers']['requires php'] ?? '' );
	$info->tested         = (string) ( $readme['headers']['tested up to'] ?? '' );

	// WordPress reads the update transient (and so this) several times a request; build the markup once.
	static $sections_cache = array();
	$cache_key             = (string) $readme_path;

	if ( ! isset( $sections_cache[ $cache_key ] ) ) {
		$sections = array();
		foreach ( array( 'description', 'installation', 'changelog' ) as $section ) {
			$html = blueline_core_plugin_info_markup( (string) ( $readme['sections'][ $section ] ?? '' ) );
			if ( '' !== $html ) {
				$sections[ $section ] = $html;
			}
		}
		if ( ! isset( $sections['description'] ) && '' !== $headers['Description'] ) {
			$sections['description'] = blueline_core_plugin_info_markup( $headers['Description'] );
		}

		$sections_cache[ $cache_key ] = $sections;
	}

	$info->sections      = $sections_cache[ $cache_key ];
	$info->banners       = $assets['banners'];
	$info->icons         = $assets['icons'];
	$info->download_link = '';

	return $info;
}

/**
 * The plugin file's headers, read once per request.
 *
 * @return array<string, string> Keys: Name, PluginURI, Description, Author, RequiresWP, RequiresPHP.
 */
function blueline_core_plugin_info_headers(): array {
	static $headers = null;

	if ( null === $headers ) {
		$read    = get_file_data(
			BLUELINE_CORE_FILE,
			array(
				'Name'        => 'Plugin Name',
				'PluginURI'   => 'Plugin URI',
				'Description' => 'Description',
				'Author'      => 'Author',
				'AuthorURI'   => 'Author URI',
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
			),
			'plugin'
		);
		$headers = array();
		foreach ( array( 'Name', 'PluginURI', 'Description', 'Author', 'AuthorURI', 'RequiresWP', 'RequiresPHP' ) as $key ) {
			$headers[ $key ] = isset( $read[ $key ] ) ? trim( (string) $read[ $key ] ) : '';
		}
	}

	return $headers;
}

/**
 * Parse a readme.txt, once per request and path. A missing or unreadable file yields empty parts and
 * no warning.
 *
 * @param string|null $path Readme to read (default: this plugin's readme.txt).
 * @return array{headers: array<string, string>, sections: array<string, string>}
 */
function blueline_core_plugin_info_readme( ?string $path = null ): array {
	static $cache = array();

	$path = $path ?? BLUELINE_CORE_DIR . '/readme.txt';

	if ( ! isset( $cache[ $path ] ) ) {
		$text = '';
		if ( is_file( $path ) && is_readable( $path ) ) {
			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a small local file shipped with the plugin, not a remote URL.
			$text     = is_string( $contents ) ? $contents : '';
		}

		$cache[ $path ] = blueline_core_plugin_info_parse_readme( $text );
	}

	return $cache[ $path ];
}

/**
 * Split a readme.txt into its header block (lower-cased keys) and `== Section ==` bodies (lower-cased names).
 *
 * @param string $text Contents of a readme.txt in the standard WordPress format.
 * @return array{headers: array<string, string>, sections: array<string, string>}
 */
function blueline_core_plugin_info_parse_readme( string $text ): array {
	$headers = array();
	$bodies  = array();
	$current = null;

	$text = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );

	foreach ( (array) preg_split( '/\R/', $text ) as $line ) {
		$line = (string) $line;

		if ( 1 === preg_match( '/^==\s*([^=].*?)\s*==\s*$/', $line, $match ) ) {
			$current            = strtolower( trim( $match[1] ) );
			$bodies[ $current ] = $bodies[ $current ] ?? '';
			continue;
		}

		if ( null === $current ) {
			if ( 1 === preg_match( '/^===\s*(.+?)\s*===\s*$/', $line, $match ) ) {
				$headers['name'] = trim( $match[1] );
			} elseif ( 1 === preg_match( '/^([A-Za-z][A-Za-z ]*?):\s*(.*?)\s*$/', $line, $match ) ) {
				$headers[ strtolower( $match[1] ) ] = $match[2];
			}
			continue;
		}

		$bodies[ $current ] .= $line . "\n";
	}

	return array(
		'headers'  => $headers,
		'sections' => array_map( 'trim', $bodies ),
	);
}

/**
 * Convert a readme section body to safe HTML: everything is escaped first, then only a few tags are
 * built from the structure, then the result is run through wp_kses with a small allowlist (a subset
 * of what wp_kses_post allows).
 *
 * Supports `= x.y.z =` sub-headings (h4), `* ` bullet lists, `1. ` ordered lists, blank-line
 * paragraphs and the inline `**bold**` and `` `code` ``. Anything else is plain paragraph text.
 *
 * @param string $body Section body in readme.txt format.
 * @return string HTML, or '' for an empty body.
 */
function blueline_core_plugin_info_markup( string $body ): string {
	$blocks = array(); // Each: array( type, items ), type being h4, p, ul or ol.
	$open   = null;    // Index of the block still collecting lines, or null.

	foreach ( (array) preg_split( '/\R/', $body ) as $line ) {
		$line = rtrim( (string) $line );

		if ( '' === trim( $line ) ) {
			$open = null;
			continue;
		}

		if ( 1 === preg_match( '/^=\s*([^=].*?)\s*=\s*$/', $line, $match ) ) {
			$blocks[] = array( 'h4', array( $match[1] ) );
			$open     = null;
			continue;
		}

		$type = null;
		$text = $line;
		if ( 1 === preg_match( '/^[*-]\s+(.*)$/', $line, $match ) ) {
			$type = 'ul';
			$text = $match[1];
		} elseif ( 1 === preg_match( '/^\d+\.\s+(.*)$/', $line, $match ) ) {
			$type = 'ol';
			$text = $match[1];
		}

		if ( null !== $type ) {
			if ( null === $open || $blocks[ $open ][0] !== $type ) {
				$blocks[] = array( $type, array() );
				$open     = count( $blocks ) - 1;
			}
			$blocks[ $open ][1][] = $text;
			continue;
		}

		if ( null !== $open && 'p' === $blocks[ $open ][0] ) {
			$blocks[ $open ][1][] = trim( $line );
		} elseif ( null !== $open && 1 === preg_match( '/^\s/', $line ) && 'h4' !== $blocks[ $open ][0] ) {
			// An indented line continues the list item above it.
			$last                         = count( $blocks[ $open ][1] ) - 1;
			$blocks[ $open ][1][ $last ] .= ' ' . trim( $line );
		} else {
			$blocks[] = array( 'p', array( trim( $line ) ) );
			$open     = count( $blocks ) - 1;
		}
	}

	$html = '';
	foreach ( $blocks as $block ) {
		list( $type, $items ) = $block;

		if ( 'h4' === $type ) {
			$html .= '<h4>' . blueline_core_plugin_info_inline( $items[0] ) . '</h4>';
		} elseif ( 'p' === $type ) {
			$html .= '<p>' . blueline_core_plugin_info_inline( implode( ' ', $items ) ) . '</p>';
		} else {
			$html .= '<' . $type . '>';
			foreach ( $items as $item ) {
				$html .= '<li>' . blueline_core_plugin_info_inline( $item ) . '</li>';
			}
			$html .= '</' . $type . '>';
		}
	}

	return wp_kses(
		$html,
		array(
			'h4'     => array(),
			'p'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'strong' => array(),
			'code'   => array(),
		)
	);
}

/**
 * Escape one line of readme text and apply the inline `**bold**` and `` `code` ``.
 *
 * Escaping happens first, so the only tags in the output are the ones added here.
 *
 * @param string $text Raw text.
 * @return string Escaped HTML.
 */
function blueline_core_plugin_info_inline( string $text ): string {
	$parts = (array) preg_split( '/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
	$html  = '';

	foreach ( $parts as $part ) {
		$part = (string) $part;
		if ( strlen( $part ) > 2 && '`' === $part[0] && '`' === substr( $part, -1 ) ) {
			$html .= '<code>' . esc_html( substr( $part, 1, -1 ) ) . '</code>';
			continue;
		}

		$html .= (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', esc_html( $part ) );
	}

	return $html;
}
