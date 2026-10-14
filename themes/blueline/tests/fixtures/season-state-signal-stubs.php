<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable.
/**
 * Scripted WordPress/WooCommerce stand-ins for SeasonStateSignalsTest and
 * HomepageSeasonRulesTest.
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
$GLOBALS['bl_test_object_term_ids']   = array();

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
 * A product with fixed purchasable/in-stock/visibility/price answers.
 */
class Blueline_Test_Signal_Product {

	/**
	 * Constructor.
	 *
	 * @param bool   $purchasable is_purchasable() answer.
	 * @param bool   $in_stock    is_in_stock() answer.
	 * @param string $visibility  get_catalog_visibility() answer.
	 * @param string $price       get_price() answer, WooCommerce's raw string.
	 * @param int    $id          get_id() answer.
	 */
	public function __construct(
		private bool $purchasable,
		private bool $in_stock,
		private string $visibility = 'visible',
		private string $price = '100',
		private int $id = 0
	) {}

	/**
	 * Product ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Product title.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Product ' . $this->id;
	}

	/**
	 * Raw price.
	 *
	 * @return string
	 */
	public function get_price(): string {
		return $this->price;
	}

	/**
	 * Catalog visibility: visible, catalog, search or hidden.
	 *
	 * @return string
	 */
	public function get_catalog_visibility(): string {
		return $this->visibility;
	}

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
 * Stand-in for wc_get_product(): a seeded product, or false. A seed is
 * `array( purchasable, in_stock [, visibility [, price ] ] )`.
 *
 * @param int $id Product ID.
 * @return Blueline_Test_Signal_Product|false
 */
function wc_get_product( $id ) {
	$seed = $GLOBALS['bl_test_wc_products'][ (int) $id ] ?? null;

	return null === $seed
		? false
		: new Blueline_Test_Signal_Product( $seed[0], $seed[1], $seed[2] ?? 'visible', $seed[3] ?? '100', (int) $id );
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
 * Stand-in for wp_get_object_terms(): seeded slugs/names (or WP_Error) per
 * taxonomy, or the seeded term IDs when `fields => ids` is asked for and
 * `bl_test_object_term_ids` holds that taxonomy.
 *
 * @param int[]  $object_ids Object IDs (ignored).
 * @param string $taxonomy   Taxonomy.
 * @param array  $args       Args; only `fields => ids` is honoured.
 * @return array|WP_Error
 */
function wp_get_object_terms( $object_ids, $taxonomy, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- signature parity with core.
	if ( 'ids' === ( $args['fields'] ?? '' ) && isset( $GLOBALS['bl_test_object_term_ids'][ $taxonomy ] ) ) {
		return $GLOBALS['bl_test_object_term_ids'][ $taxonomy ];
	}

	return $GLOBALS['bl_test_object_term_slugs'][ $taxonomy ] ?? array();
}
