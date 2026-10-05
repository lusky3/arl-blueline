<?php
/**
 * Stubs for the player-link module tests: the request-scoped object cache, a model of the
 * claim-pool get_posts() query, and the claim-pool seeding helper.
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

if ( ! function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
	/**
	 * Stand-in for wp_cache_add_non_persistent_groups(): records the groups in
	 * $GLOBALS['bl_core_test_non_persistent_groups'] (never reset, like core's per-process list).
	 *
	 * @param string|string[] $groups Group name(s).
	 * @return void
	 */
	function wp_cache_add_non_persistent_groups( $groups ) {
		$GLOBALS['bl_core_test_non_persistent_groups'] = array_values(
			array_unique( array_merge( $GLOBALS['bl_core_test_non_persistent_groups'] ?? array(), (array) $groups ) )
		);
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	/**
	 * Stand-in for wp_cache_get() over the shared in-memory cache store (keyed group:key, as the
	 * theme's wp_cache_add()/wp_cache_delete() stubs are).
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @param bool       $force Unused; signature parity with core.
	 * @param bool|null  $found Set to whether the key was present.
	 * @return mixed The value, or false when absent.
	 */
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		$cache_key = $group . ':' . $key;
		$found     = array_key_exists( $cache_key, $GLOBALS['bl_test_cache'] );

		return $found ? $GLOBALS['bl_test_cache'][ $cache_key ] : false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	/**
	 * Stand-in for wp_cache_set() over the shared in-memory cache store.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Value to store.
	 * @param string     $group  Cache group.
	 * @param int        $expire Unused; this stub models no elapsed time.
	 * @return true
	 */
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; no clock to expire against.
		$GLOBALS['bl_test_cache'][ $group . ':' . $key ] = $data;

		return true;
	}
}

if ( ! function_exists( 'wp_cache_flush' ) ) {
	/**
	 * Stand-in for wp_cache_flush(): empties the shared in-memory cache store.
	 *
	 * @return true
	 */
	function wp_cache_flush() {
		$GLOBALS['bl_test_cache'] = array();

		return true;
	}
}

if ( ! function_exists( 'blueline_core_test_meta_clause_matches' ) ) {
	/**
	 * Evaluate one get_posts() meta_query node (a clause, or a nested relation group) against a
	 * post's meta rows. Models the compare operators the claim pool uses: =, IN, NOT IN and
	 * NOT EXISTS, with a missing row failing NOT IN as it does in SQL.
	 *
	 * @param array $node Clause ( key, compare, value ) or group ( relation + clauses ).
	 * @param array $meta The post's meta, key => value or Blueline_Test_Meta_Rows.
	 * @return bool
	 * @throws LogicException For a compare operator this model does not implement.
	 */
	function blueline_core_test_meta_clause_matches( array $node, array $meta ): bool {
		if ( ! isset( $node['key'] ) ) {
			$relation = strtoupper( (string) ( $node['relation'] ?? 'AND' ) );
			$results  = array();
			foreach ( $node as $key => $child ) {
				if ( 'relation' !== $key && is_array( $child ) ) {
					$results[] = blueline_core_test_meta_clause_matches( $child, $meta );
				}
			}

			return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
		}

		$stored = $meta[ (string) $node['key'] ] ?? null;
		if ( null === $stored ) {
			$rows = array();
		} else {
			$rows = $stored instanceof Blueline_Test_Meta_Rows ? $stored->rows : array( $stored );
		}
		$rows    = array_map( 'strval', $rows );
		$wanted  = array_map( 'strval', (array) ( $node['value'] ?? array() ) );
		$compare = strtoupper( (string) ( $node['compare'] ?? '=' ) );

		switch ( $compare ) {
			case 'NOT EXISTS':
				return array() === $rows;
			case '=':
				return in_array( (string) ( $node['value'] ?? '' ), $rows, true );
			case 'IN':
				return (bool) array_intersect( $rows, $wanted );
			case 'NOT IN':
				return (bool) array_diff( $rows, $wanted );
		}

		throw new LogicException( 'Unmodelled meta_query compare: ' . $compare ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test stub, not rendered.
	}
}

if ( ! function_exists( 'blueline_core_test_claim_pool_model' ) ) {
	/**
	 * Model of get_posts() for the claim-pool query only (blueline_current_season_unclaimed_player_ids()):
	 * sp_player posts matching its nested meta_query and sp_season tax_query, over the shared
	 * post-meta, registered-posts and post-terms stores. Every call's args are recorded in
	 * $GLOBALS['bl_core_test_claim_pool_queries'] so a test can assert the query shape.
	 *
	 * Like real get_posts() without a post_status arg, only published posts match. Returns null for
	 * any other query so the shared get_posts() stub answers it.
	 *
	 * @param array $args get_posts() args.
	 * @return int[]|null Post IDs, ascending, or null when this is not the claim-pool query.
	 */
	function blueline_core_test_claim_pool_model( array $args ): ?array {
		if ( 'sp_player' !== ( $args['post_type'] ?? '' ) || ! isset( $args['meta_query']['relation'] ) ) {
			return null;
		}

		$GLOBALS['bl_core_test_claim_pool_queries'][] = $args;

		$state  = &blueline_test_state();
		$status = (string) ( $args['post_status'] ?? 'publish' );
		$ids    = array_unique( array_merge( array_keys( $state['post_meta'] ), array_keys( $state['posts'] ) ) );
		$found  = array();

		foreach ( $ids as $post_id ) {
			$registered = $state['posts'][ $post_id ] ?? array();

			if ( isset( $registered['type'] ) && 'sp_player' !== $registered['type'] ) {
				continue;
			}

			if ( 'any' !== $status && isset( $registered['status'] ) && $registered['status'] !== $status ) {
				continue;
			}

			if ( ! blueline_core_test_meta_clause_matches( $args['meta_query'], $state['post_meta'][ $post_id ] ?? array() ) ) {
				continue;
			}

			foreach ( $args['tax_query'] ?? array() as $tax ) {
				$tagged = $state['post_terms'][ $post_id ][ $tax['taxonomy'] ] ?? array();
				if ( ! array_intersect( $tagged, array_map( 'intval', (array) $tax['terms'] ) ) ) {
					continue 2;
				}
			}

			$found[] = (int) $post_id;
		}

		sort( $found );

		// A test can simulate a concurrent writer by setting $GLOBALS['bl_core_test_after_claim_pool_query']
		// (runs once, just after the pool is computed, before the caller acts on it).
		if ( ! empty( $GLOBALS['bl_core_test_after_claim_pool_query'] ) ) {
			$callback = $GLOBALS['bl_core_test_after_claim_pool_query'];
			unset( $GLOBALS['bl_core_test_after_claim_pool_query'] );
			$callback();
		}

		return $found;
	}
}

// The shared get_posts() stub (themes/blueline/tests/bootstrap.php) asks this model first.
$GLOBALS['bl_test_get_posts_model'] = 'blueline_core_test_claim_pool_model';

if ( ! function_exists( 'blueline_core_test_seed_claim_pool' ) ) {
	/**
	 * Make $user_id the logged-in plain account named $name and publish $pool as eligible sp_player
	 * posts (a team set, no sp_user), so the real claim-pool query finds them. Titles come from a
	 * fake $wpdb, which the caller must drop afterwards.
	 *
	 * @param int                $user_id Account ID (also the current user).
	 * @param string             $name    The account's billing name.
	 * @param array<int, string> $pool    player_id => post_title.
	 * @return void
	 */
	function blueline_core_test_seed_claim_pool( int $user_id, string $name, array $pool ): void {
		unset( $GLOBALS['bl_core_test_claim_pool_queries'] );

		$state                          = &blueline_test_state();
		$state['current_user_id']       = $user_id;
		$state['post_types']            = array( 'sp_player' );
		$state['users'][ $user_id ]     = (object) array(
			'roles'        => array( 'customer' ),
			'display_name' => $name,
		);
		$state['user_meta'][ $user_id ] = array(
			'billing_first_name' => (string) strtok( $name, ' ' ),
			'billing_last_name'  => trim( (string) strstr( $name, ' ' ) ),
		);

		$rows = array();
		foreach ( $pool as $player_id => $title ) {
			$state['posts'][ $player_id ]     = array(
				'type'   => 'sp_player',
				'status' => 'publish',
				'author' => 0,
			);
			$state['post_meta'][ $player_id ] = array( 'sp_current_team' => '7' );
			$rows[]                           = (object) array(
				'ID'         => (string) $player_id,
				'post_title' => $title,
			);
		}

		$wpdb            = new Blueline_Core_Test_Wpdb();
		$wpdb->results   = $rows;
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test double for $wpdb.
	}
}
