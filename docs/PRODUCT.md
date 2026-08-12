# PRODUCT.md — ARL / rookiehockey.ca

## Register

**Brand.** The site is the league's public face and its sales surface. Design IS the product for
the pages that matter most (home, register, league info). The logged-in My Account area is the
one product-register surface; treat it as app UI, not marketing.

## What this is

The Adult Recreational League (ARL) — adult co-ed **beginner** ice hockey in Burlington, Ontario.
WordPress + SportsPress Pro (teams, players, events, standings, stats) + WooCommerce.

WooCommerce is not a store. It sells exactly one thing: a seasonal **registration**, which is the
league fee (~$500). There is no catalogue, no cart-building, no upsell.

## Who uses it

Two people, at the same time, with opposite needs:

1. **A nervous adult who has never played.** Deciding whether to spend ~$500 on a sport they
   think everyone else already knows. They need reassurance far more than they need data.
   Their questions are "will I be the worst one there", "what gear do I need", "what if I
   can't skate". If the site looks like a competitive league, they leave.
2. **An existing player mid-season.** Wants one fact, fast, usually on a phone, often in a car
   park: when and where is my next game, which sheet of ice, where do we sit in the standings.

## Purpose

Convert the first person and serve the second, **weighted by season**. Registration windows are
the conversion push; the rest of the year the site is a utility. The homepage shifts emphasis on
its own via `blueline_season_state()`.

## Brand personality

Welcoming, plain-spoken, unpretentious, and a bit funny. This is a beer-league that takes the
hockey seriously and itself lightly. Team names are jokes (Cherry Pickers, Knights of Ni,
Jagrbombers). The voice should never be corporate and never be intimidating.

"Never played? Perfect." is the tonal north star.

## Anti-references

- **Pro-sports broadcast sites.** Dark, dense, stat-heavy, aggressive. That is the single most
  obvious thing to build for a hockey league and it actively repels the beginner.
- **The previous site.** ThemeBoy Rookie on a tiled dark ice-texture background.
- **SaaS landing pages.** Gradient hero, three feature cards, big metric row.
- **Anything that implies you need to already be good.**

## Strategic design principles

1. **Light, not dark.** Almost every league site is dark. Being light is both differentiating
   and less intimidating. Dark is used deliberately, in bands, for drama.
2. **The brand mark is the design system.** Everything derives from the 2026 logo: a blue maple
   leaf carrying a diagonal banner in heavy italic varsity lettering.
3. **Data must stay readable.** Standings and stats are the most-visited pages. Personality
   never costs legibility in a table.
4. **The pad, not just the arena.** "Twin Rinks" does not tell a player which sheet of ice to
   walk to. Venue detail is a product requirement, not a nicety.
5. **Accessibility is a floor, not a finish.** WCAG 2.2 AA throughout, verified by a committed
   contrast guard rather than by eye.

## Accessibility requirements

WCAG 2.2 AA. Contrast ratios are asserted in CI (`tools/check-contrast.mjs`), not assumed.
Keyboard traversal, visible focus on every control including skewed ones, no horizontal page
scroll at 360px, and a working skip link.
