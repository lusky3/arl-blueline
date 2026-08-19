# WooCommerce template overrides

Copies of the WooCommerce templates overridden in the **`rookie-child`** theme on
production (`production-host`). Deployed path:

```
wp-content/themes/rookie-child/woocommerce/…
```

Each file is core's template with **one line added** — nothing else is changed.

| File | Core `@version` | Added |
|---|---|---|
| `checkout/review-order.php` | 11.0.0 | `do_action( "arl_review_order_after_subtotal" )` |
| `cart/cart-totals.php` | 2.3.6 | `do_action( "arl_cart_totals_after_subtotal" )` |

## Why they exist

The Early Bird promotion is a **sale price** on the product, so WooCommerce folds the
saving into the line price and the customer never sees they saved anything. A registrant
reported thinking he had missed the discount because his receipt showed only `$550.00`.

To show it as its own discount line, the row has to sit immediately **after** the subtotal.
Core provides no hook there:

- `woocommerce_review_order_after_cart_contents` fires *before* the subtotal, so the
  discount rendered above the figure it reduces.
- `woocommerce_review_order_before_shipping` sits **inside** the
  `if ( needs_shipping() )` conditional — registrations are virtual, so it never fires.

Hence the overrides. They add only a hook; all logic lives in Code Snippets
**snippet 55** ("Early Bird saving shown as a labelled discount line"), which renders:

```
Player Registration (W2026-27) × 1     $575.00
Subtotal                               $575.00
Early Bird discount                    -$25.00
Returning player discount              -$25.00
Processing Fee                          $18.68
Total                                  $543.68
```

The display is derived from `_arl_earlybird_saving`, stamped on each line item at
checkout so it stays accurate after the sale ends and the price returns to `$575`.

## Maintenance

Template overrides go stale. If WooCommerce updates either template, WooCommerce →
Status → Templates will flag it. To refresh:

1. Copy the new core template from `wp-content/plugins/woocommerce/templates/…`
2. Re-add the single `do_action` line immediately after the `<tr class="cart-subtotal">` block
3. Update the `@version` note in the header

If an override is ever removed without the hook being re-added, the Early Bird row simply
stops rendering — prices and totals are unaffected, since this is display-only.
