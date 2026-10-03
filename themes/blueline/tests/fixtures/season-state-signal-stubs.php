<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable.
/**
 * Scripted WordPress/WooCommerce stand-ins for SeasonStateSignalsTest.
 *
 * Loaded ONLY inside #[RunInSeparateProcess] tests: defining WP_Query or
 * wc_get_product() in the shared process would change what every other test
 * sees (season-state and homepage code branch on function_exists()).
 *
 * WP_Query here does not model core's query semantics (date_query and all):
 * it hands back the next scripted result and logs the args it was given, so
 * tests assert what the theme asks for and how it consumes the answer.
 *
 * @package blueline
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound -- test-only stub bundle, same trade-off as tests/bootstrap.php.

$GLOBALS['bl_test_wp_query_results']  = array();
$GLOBALS['bl_test_wp_query_args']     = array();
$GLOBALS['bl_test_wc_products']       = array();
$GLOBALS['bl_test_post_dates']        = array();
$GLOBALS['bl_test_object_taxonomies'] = array();
$GLOBALS['bl_test_object_term_slugs'] = array();

/**
 * Scripted WP_Query: each instance takes the next queued `posts` result.
 */
class WP_Query {

	/**
	 * Result IDs.
	 *
	 * @var array
	 */
	public $posts;

	/**
	 * Log the args and dequeue the next scripted result.
	 *
	 * @param array $args Query args.
	 */
	public function __construct( $args = array() ) {
		$GLOBALS['bl_test_wp_query_args'][] = $args;
		$this->posts                        = array_shift( $GLOBALS['bl_test_wp_query_results'] ) ?? array();
	}
}

/**
 * A product with fixed purchasable/in-stock answers.
 */
class Blueline_Test_Signal_Product {

	/**
	 * Constructor.
	 *
	 * @param bool $purchasable is_purchasable() answer.
	 * @param bool $in_stock    is_in_stock() answer.
	 */
	public function __construct( private bool $purchasable, private bool $in_stock ) {}

	/**
	 * Whether the product can be bought.
	 *
	 * @return bool
	 */
	public function is_purchasable(): bool {
		return $this->purchasable;
	}

	/**
	 * Whether the product is in stock.
	 *
	 * @return bool
	 */
	public function is_in_stock(): bool {
		return $this->in_stock;
	}
}

/**
 * Stand-in for wc_get_product(): a seeded product, or false.
 *
 * @param int $id Product ID.
 * @return Blueline_Test_Signal_Product|false
 */
function wc_get_product( $id ) {
	$seed = $GLOBALS['bl_test_wc_products'][ (int) $id ] ?? null;

	return null === $seed ? false : new Blueline_Test_Signal_Product( $seed[0], $seed[1] );
}

/**
 * Stand-in for get_post(): an object carrying only a seeded post_date.
 *
 * @param int $id Post ID.
 * @return object|null
 */
function get_post( $id ) {
	$date = $GLOBALS['bl_test_post_dates'][ (int) $id ] ?? null;

	return null === $date ? null : (object) array( 'post_date' => $date );
}

/**
 * Stand-in for get_object_taxonomies(): the seeded taxonomy names.
 *
 * @param string $object_type Post type (ignored).
 * @return string[]
 */
function get_object_taxonomies( $object_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with core.
	return $GLOBALS['bl_test_object_taxonomies'];
}

/**
 * Stand-in for wp_get_object_terms(): seeded slugs (or WP_Error) per taxonomy.
 *
 * @param int[]  $object_ids Object IDs (ignored).
 * @param string $taxonomy   Taxonomy.
 * @param array  $args       Args (ignored).
 * @return string[]|WP_Error
 */
function wp_get_object_terms( $object_ids, $taxonomy, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with core.
	return $GLOBALS['bl_test_object_term_slugs'][ $taxonomy ] ?? array();
}
