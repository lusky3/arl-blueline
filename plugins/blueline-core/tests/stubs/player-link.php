<?php
/**
 * Stubs for the player-link module tests (claim handler, ownership CLI).
 *
 * @package blueline-core
 */

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stand-in for esc_url_raw(): returns the URL unchanged.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	/**
	 * Stand-in for wp_list_pluck() over arrays of arrays.
	 *
	 * @param array      $input List of arrays.
	 * @param int|string $field Key to pluck.
	 * @return array
	 */
	function wp_list_pluck( $input, $field ) {
		return array_column( (array) $input, $field );
	}
}

if ( ! function_exists( 'wp_get_referer' ) ) {
	/**
	 * Stand-in for wp_get_referer(): $GLOBALS['bl_core_test_referer'], or false.
	 *
	 * @return string|false
	 */
	function wp_get_referer() {
		return $GLOBALS['bl_core_test_referer'] ?? false;
	}
}

if ( ! function_exists( 'kses_remove_filters' ) ) {
	/**
	 * Stand-in for kses_remove_filters(): counts calls.
	 *
	 * @return void
	 */
	function kses_remove_filters() {
		$GLOBALS['bl_core_test_kses_removed'] = ( $GLOBALS['bl_core_test_kses_removed'] ?? 0 ) + 1;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	/**
	 * Stand-in for wp_update_post(): records the call and applies post_author
	 * to the registered-posts store. Set $GLOBALS['bl_core_test_update_post_error']
	 * to a WP_Error to make it fail.
	 *
	 * @param array $postarr  Post fields; ID required.
	 * @param bool  $wp_error Whether to return a WP_Error on failure.
	 * @return int|WP_Error
	 */
	function wp_update_post( $postarr, $wp_error = false ) {
		$GLOBALS['bl_core_test_updated_posts'][] = $postarr;

		if ( ! empty( $GLOBALS['bl_core_test_update_post_error'] ) ) {
			return $wp_error ? $GLOBALS['bl_core_test_update_post_error'] : 0;
		}

		$state = &blueline_test_state();
		$id    = (int) $postarr['ID'];
		if ( isset( $postarr['post_author'] ) ) {
			$state['posts'][ $id ]['author'] = (int) $postarr['post_author'];
		}

		return $id;
	}
}

if ( ! function_exists( 'add_post_meta' ) ) {
	/**
	 * Stand-in for add_post_meta() over the shared post_meta store, with core's
	 * unique semantics: a unique add fails (false) when the key already has any
	 * row, a non-unique add appends a row (stored as a Blueline_Test_Meta_Rows).
	 *
	 * A test can simulate a concurrent writer by setting
	 * $GLOBALS['bl_core_test_before_add_post_meta'] (runs just before the write)
	 * or $GLOBALS['bl_core_test_after_add_post_meta'] (runs just after it); each
	 * receives ( $post_id, $key, $value ) and is cleared once it has run.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @param bool   $unique  Whether the key must have no other row.
	 * @return int|false A truthy id on success, false on a refused unique add.
	 */
	function add_post_meta( $post_id, $key, $value, $unique = false ) {
		if ( ! empty( $GLOBALS['bl_core_test_before_add_post_meta'] ) ) {
			$callback = $GLOBALS['bl_core_test_before_add_post_meta'];
			unset( $GLOBALS['bl_core_test_before_add_post_meta'] );
			$callback( $post_id, $key, $value );
		}

		$state    = &blueline_test_state();
		$existing = $state['post_meta'][ (int) $post_id ][ (string) $key ] ?? null;

		if ( null !== $existing && $unique ) {
			return false;
		}

		if ( null === $existing ) {
			$state['post_meta'][ (int) $post_id ][ (string) $key ] = $value;
		} else {
			$rows   = $existing instanceof Blueline_Test_Meta_Rows ? $existing->rows : array( $existing );
			$rows[] = $value;

			$state['post_meta'][ (int) $post_id ][ (string) $key ] = new Blueline_Test_Meta_Rows( $rows );
		}

		if ( ! empty( $GLOBALS['bl_core_test_after_add_post_meta'] ) ) {
			$callback = $GLOBALS['bl_core_test_after_add_post_meta'];
			unset( $GLOBALS['bl_core_test_after_add_post_meta'] );
			$callback( $post_id, $key, $value );
		}

		return 1;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	/**
	 * Stand-in for delete_post_meta() over the shared post_meta store: with a
	 * $value only the rows holding that value (string compare) are removed, with
	 * '' every row for the key. Fallback only: tests/stubs/avatars.php sorts
	 * first and defines its own (scalar-only) version, which then wins.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Row value to remove, or '' for all rows.
	 * @return bool Whether anything was removed.
	 */
	function delete_post_meta( $post_id, $key, $value = '' ) {
		$state    = &blueline_test_state();
		$existing = $state['post_meta'][ (int) $post_id ][ (string) $key ] ?? null;

		if ( null === $existing ) {
			return false;
		}

		$rows = $existing instanceof Blueline_Test_Meta_Rows ? $existing->rows : array( $existing );
		$kept = array();
		foreach ( $rows as $row ) {
			if ( '' !== (string) $value && (string) $row !== (string) $value ) {
				$kept[] = $row;
			}
		}

		if ( count( $kept ) === count( $rows ) ) {
			return false;
		}

		if ( array() === $kept ) {
			unset( $state['post_meta'][ (int) $post_id ][ (string) $key ] );
		} elseif ( 1 === count( $kept ) ) {
			$state['post_meta'][ (int) $post_id ][ (string) $key ] = $kept[0];
		} else {
			$state['post_meta'][ (int) $post_id ][ (string) $key ] = new Blueline_Test_Meta_Rows( $kept );
		}

		return true;
	}
}
