<?php
/**
 * Homepage: registration offers. The Featured-tagged WooCommerce products
 * the hero can sell, their role labels, the price line and the
 * registration product's season label.
 *
 * Loaded by inc/homepage-modules.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one product_tag name that marks a registration product fit for the
 * homepage hero's own price line, as opposed to every OTHER real,
 * purchasable product the season's Registration category can also hold
 * (waitlist entries, a late-registration surcharge product, ...) --
 * see blueline_homepage_registration_offers()'s own docblock for why this
 * narrowing exists.
 */
const BLUELINE_HOMEPAGE_FEATURED_PRODUCT_TAG = 'Featured';

/**
 * Whether $product_id carries the given product_tag term, by exact name.
 * Pulled out of blueline_homepage_registration_offer_role_label() as its
 * own function specifically so it's unit-testable with this suite's
 * existing blueline_test_register_term()/blueline_test_set_post_terms()
 * mocks, the same way that function already is, without needing a real
 * WC_Product to reach it.
 *
 * @param int    $product_id Product post ID.
 * @param string $tag_name   Tag name to look for.
 * @return bool
 */
function blueline_product_has_tag( int $product_id, string $tag_name ): bool {
	if ( ! taxonomy_exists( 'product_tag' ) ) {
		return false;
	}

	$tags = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'names' ) );

	if ( is_wp_error( $tags ) ) {
		return false;
	}

	return in_array( $tag_name, $tags, true );
}

/**
 * Every purchasable, in-stock, "Featured"-tagged product in the current
 * season's Registration category, re-verified live, because Season State's
 * own transient can be up to 15 minutes stale, so this never trusts a
 * cached product list alone; a Register CTA must never quote a price for a
 * product that has since sold out or been unpublished. Sorted highest
 * price first.
 *
 * The hero must not read a SINGLE product id
 * ($state_data['product_id'], "the first purchasable product Season State
 * happened to find") and quote its price on the primary CTA; on this site
 * that would resolve to the $145 Goalie Registration outranking the $550
 * Player Registration by post ID, so the button would read "Register —
 * $145.00" while the skater the button was aimed at actually owes $550 at
 * checkout. This
 * intentionally does not pick a single "winner" product by name or ID
 * (the season's category can hold any number of registration products, and
 * pinned IDs/role names are a known trap in this codebase; see
 * BLUELINE_REGISTRATION_TERM_ID's own resolve-by-newest-term pattern); it
 * returns every live offer and lets blueline_homepage_registration_cta_pricing()
 * decide, from the actual set, whether one price or a breakdown belongs on
 * the page.
 *
 * A season's category can hold real, live products beyond Player and
 * Goalie Registration -- e.g. a $999.99 Player late-registration surcharge
 * and $0.00 Waitlist products -- and quoting all of them would make the
 * hero's price line read "Player $999.99 · Player $575.00 · Goalie $170.00
 * · Player Waitlist $0.00 · Goalie Waitlist $0.00". Every one of those is
 * a genuinely correct, purchasable product (the late-registration and
 * waitlist products need to stay live and on sale precisely when regular
 * registration is full or its window has passed) -- the question is only
 * what the homepage hero's own summary line should quote. Narrowing this specific
 * caller to the "Featured" product_tag (not a blanket rule on the season
 * category itself, which blueline_registration_season_product_ids() still
 * returns unfiltered for whoever else needs the full set) is what keeps
 * that distinction: tag exactly the products meant to answer "what does it
 * cost to register" on the homepage, and every other live offer keeps
 * selling normally everywhere else (/register, WooCommerce's own product
 * pages) without this hero ever mentioning it.
 *
 * A Featured product must also be a paid product the shop still lists
 * (blueline_registration_product_offer_kind()): the league hides a
 * registration product once its spots are filled, and the hero went on
 * quoting its price because the tag stayed on.
 *
 * @return array<int, array{product: object, price_label: string, price: float, role_label: string}>
 */
function blueline_homepage_registration_offers(): array {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return array();
	}

	$product_ids = blueline_registration_season_product_ids();

	$offers = array();

	foreach ( $product_ids as $product_id ) {
		if ( ! blueline_product_has_tag( $product_id, BLUELINE_HOMEPAGE_FEATURED_PRODUCT_TAG ) ) {
			continue;
		}

		$product = wc_get_product( $product_id );

		if ( 'paid' !== blueline_registration_product_offer_kind( $product ) ) {
			continue;
		}

		$price = $product->get_price();

		$offers[] = array(
			'product'     => $product,
			'price_label' => blueline_homepage_format_price( $product ),
			'price'       => ( '' !== $price && null !== $price ) ? (float) $price : 0.0,
			'role_label'  => blueline_homepage_registration_offer_role_label( $product ),
		);
	}

	usort(
		$offers,
		static function ( $a, $b ) {
			return $b['price'] <=> $a['price'];
		}
	);

	return $offers;
}

/**
 * A short, human label for one registration product, such as "Player",
 * "Goalie", or "Player Waitlist", whatever the site's own WooCommerce
 * product_tag taxonomy says, so the price-breakdown subcopy (see
 * blueline_homepage_registration_cta_pricing()) never has to hardcode a
 * role name. Falls back to the product's own title with a trailing
 * "(Season Label)" parenthetical stripped (e.g. "Player Registration
 * (W2026-27)" -> "Player Registration") when no tag is set.
 *
 * Joins EVERY tag the product carries, not just the first: a waitlist
 * product here is tagged both its role ("Player"/"Goalie") AND
 * "Waitlist", and taking only tags[0] silently dropped the "Waitlist"
 * qualifier the moment term order put the role tag first -- the $0.00
 * waitlist offer then displayed with the exact same label as the paid
 * registration offer, reading as duplicate/broken data instead of two
 * distinct, real offers.
 *
 * BLUELINE_HOMEPAGE_FEATURED_PRODUCT_TAG is filtered out of that join --
 * confirmed live, without this a Featured product's own label read
 * "Featured Player" instead of "Player". That tag exists purely to pick
 * which products blueline_homepage_registration_offers() returns at all
 * (see its own docblock); it was never meant to be a display-facing role
 * word the way "Player"/"Goalie"/"Waitlist" are.
 *
 * @param object $product A WC_Product instance.
 * @return string
 */
function blueline_homepage_registration_offer_role_label( $product ): string {
	if ( taxonomy_exists( 'product_tag' ) ) {
		$tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );

		if ( ! is_wp_error( $tags ) ) {
			$tags = array_diff( $tags, array( BLUELINE_HOMEPAGE_FEATURED_PRODUCT_TAG ) );
		}

		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			return implode( ' ', $tags );
		}
	}

	$stripped = trim( (string) preg_replace( '/\s*\([^)]*\)\s*$/', '', $product->get_name() ) );

	return '' !== $stripped ? $stripped : (string) $product->get_name();
}

/**
 * Pure: given the current season's live registration offers (see
 * blueline_homepage_registration_offers()), decide what the hero's CTA
 * button and subcopy should say about price. Kept free of WooCommerce/
 * WordPress calls, because it only ever sees plain arrays the caller has already
 * extracted from a WC_Product, so the exact decision at stake
 * (one price on a button vs. several products at different prices) is
 * directly unit testable without a WC_Product fixture.
 *
 * - No offers at all: nothing to show; caller falls back to non-registration copy.
 * - Every offer the same price (including the common case of exactly one
 *   offer): that single price is unambiguous and safe to put on the CTA
 *   itself, exactly as before this fix.
 * - Offers at different prices: quoting any one of them on the button is
 *   the bait-and-switch this was fixed for, so the CTA carries no price at
 *   all and every distinct price gets its own line in the breakdown,
 *   labelled by blueline_homepage_registration_offer_role_label() and
 *   ordered highest first, matching what /register itself shows.
 *
 * @param array $offers Result of blueline_homepage_registration_offers().
 * @return array{cta_price_label: string, price_breakdown: string}
 */
function blueline_homepage_registration_cta_pricing( array $offers ): array {
	if ( empty( $offers ) ) {
		return array(
			'cta_price_label' => '',
			'price_breakdown' => '',
		);
	}

	$distinct_labels = array_unique( array_column( $offers, 'price_label' ) );

	if ( count( $distinct_labels ) <= 1 ) {
		return array(
			'cta_price_label' => $offers[0]['price_label'],
			'price_breakdown' => '',
		);
	}

	$parts = array();

	foreach ( $offers as $offer ) {
		if ( '' === $offer['price_label'] ) {
			continue;
		}

		$parts[] = '' !== $offer['role_label']
			? trim( $offer['role_label'] . ' ' . $offer['price_label'] )
			: $offer['price_label'];
	}

	return array(
		'cta_price_label' => '',
		'price_breakdown' => implode( ' · ', $parts ),
	);
}

/**
 * Plain-text formatted price (e.g. "$145.00"), read from the live product.
 * Never hard-code a price; this is the only source of the number.
 *
 * @param object $product A WC_Product instance.
 * @return string Empty string if the product has no price set.
 */
function blueline_homepage_format_price( $product ): string {
	$price = $product->get_price();

	if ( '' === $price || null === $price || ! function_exists( 'wc_price' ) ) {
		return '';
	}

	return html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Season label for the registration product's own category (e.g.
 * "Winter 2026-27"), the season being SOLD, not necessarily the season
 * currently being played.
 *
 * @param int $product_id Product ID.
 * @return string Empty string if unavailable.
 */
function blueline_homepage_registration_season_label( int $product_id ): string {
	if ( ! $product_id || ! taxonomy_exists( 'product_cat' ) ) {
		return '';
	}

	$terms = wp_get_post_terms( $product_id, 'product_cat' );

	if ( is_wp_error( $terms ) ) {
		return '';
	}

	$registration_term = blueline_resolve_registration_term();

	if ( $registration_term <= 0 ) {
		// Neither the configured term nor the documented fallback resolves
		// to a real product_cat term, so there is nothing valid to compare
		// $term->parent against. Guarding here (rather than comparing
		// against 0) is what keeps an ordinary top-level product category
		// from being mistaken for the registration season's parent; see
		// blueline_resolve_registration_term()'s docblock.
		return '';
	}

	foreach ( $terms as $term ) {
		if ( $registration_term === (int) $term->parent ) {
			return $term->name;
		}
	}

	return '';
}
