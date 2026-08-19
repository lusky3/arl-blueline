# S2026 Playoff Standings — Implementation Plan

> **EXECUTED 2026-08-12 17:34–17:45. All five tasks passed their verifications.**
>
> | Check | Result |
> |---|---|
> | Playoff tables now compute | all 5 divisions GP > 0; D1 shows Puck Dynasty & Hammers 1-0-0 (2 pts), Replacements & Kings 0-1-0 — matching the entered results |
> | Regular tables stopped counting playoffs | every team 15 → 14 GP; points fell by exactly what that team earned in its playoff game (2 for a win, 1 for a tie, 0 for a loss) |
> | `/playoffs` | 5 tables, Divisions 1–5, zero "Group A–G" leftovers. Canonical URL is `/standings/playoffs` (page 4733 is a child of Standings; `/playoffs` 301s there) |
> | Arithmetic | regular 308 team-games = **154 games** (= all 154 published regular events); playoff 22 team-games = **11 games**; no game double-counted |
>
> Rollback data: `2026-08-12-standings-backup.json` in this directory.

**Goal:** Make `/playoffs` show the five S2026 playoff standings tables, and make every S2026
league table count only its own half of the season — playoffs in the playoff tables, regular
season in the regular tables.

**Architecture:** No code. Three data changes on production: correct the date window on ten
`sp_table` posts, and replace the shortcode list on page 4733. SportsPress computes standings
live from `sp_event` results filtered by the table's taxonomy **and** its date window; the
taxonomy is already right, the date windows are not.

**Tech stack:** SportsPress Pro, WordPress page (block editor, `[team_standings <id>]` shortcodes),
wp-cli over SSH on `production-host`.

**Spec:** this document — derived from live inspection on 2026-08-12, not from assumption.

---

## Global Constraints

- Production, mid-season. Registration for W2026-27 is open; `/standings` and `/playoffs` are
  linked from the main nav (menu items 8654/8876/108374 → page 111, 10440/108376 → page 4733).
- **Do not touch the `sp_season` taxonomy.** Term structure is correct and shared with 20 seasons
  of history.
- **Do not edit the tables in the SportsPress admin UI** while doing this — the meta writes below
  are precise, and an admin re-save would rewrite `sp_teams`/`sp_adjustments`.
- The nginx full-page cache is currently **off** (see `nginx/incident-2026-08-12/`), so page
  changes appear immediately. Only `wp cache flush` (object cache) is needed.
- Every step is reversible; exact rollback values are captured in Task 1.

---

## Current state (verified 2026-08-12)

**Season terms:** `666` = S2026, `667` = S2026 Playoffs (a **child** of 666). W2026-27 is `674`.

**The ten S2026 tables, already correctly tagged:**

| Division | Regular (season 666) | Playoffs (season 667) | League term |
|---|---|---|---|
| Division 1 | 116143 | **116144** | 8 |
| Division 2 | 116147 | **116148** | 7 |
| Division 3 | 116151 | **116152** | 6 |
| Division 4 | 116154 | **116153** | 5 |
| Division 5 | 116156 | **116155** | 2 |

**Events split cleanly by date, with no overlap:**

- Regular season: 154 events, `2026-04-24 18:00` → `2026-07-31 23:00`
- Playoffs: 44 events, `2026-08-07 18:00` → `2026-08-28 23:00` (11 played, results entered)

### Four defects found

**D1 — `/playoffs` (page 4733) is a season stale.** It embeds seven **W2025-26** playoff tables
(114398–114404, "Group A"–"Group G", season 655). W2025-26 used seven groups across sub-divisions;
S2026 uses five divisions, so this is not a one-for-one ID swap.

**D2 — Four of five playoff tables have a 2025 date window.** 116144, 116148, 116153 and 116155
carry `sp_date = range`, `sp_date_from = 2025-04-18`, `sp_date_to = 2025-09-29` — inherited when
they were copied from the S2025 tables (`_sp_original = 112929`, old slug
`division-1-playoffs-s2025-copy`). They match **zero** 2026 events, so they render every team at
0-0-0 even though results are entered. Table 116144 matches 2 events with results by taxonomy
alone, and displays zeros purely because of the date filter.

**D3 — The fifth playoff table spans the whole season.** 116152 (D3) has a 2026 window but it
starts `2026-04-24`, which covers the regular season too. That is exactly the contamination this
plan is meant to prevent.

**D4 — The regular tables are absorbing playoff results right now.** All five have `sp_date = 0`
(date filtering off) and are tagged season 666. SportsPress hardcodes
`'include_children' => true` (`sportspress-pro/includes/sportspress/includes/sp-core-functions.php:886`),
and 667 is a child of 666. Measured: a season-666 query returns **165** published events with
children included versus **154** without — the 11 played playoff games. Table 116143's matched
event list contains playoff events 116459 and 116460. **`/standings` is currently wrong**, and it
gets worse with every playoff result entered.

D4 answers the question behind the request: tagging alone cannot separate the two halves, because
the playoff term is a child of the regular term. The date window is the only lever SportsPress
gives us, so both sides need one.

### Chosen fix

Give every table an explicit, non-overlapping date window, using the gap between
`2026-07-31 23:00` and `2026-08-07 18:00`:

- **Regular tables:** `sp_date = range`, `2026-04-01` → `2026-07-31`
- **Playoff tables:** `sp_date = range`, `2026-08-01` → `2026-08-31`

Season and league terms stay exactly as they are.

---

## Task 1: Capture rollback state

**Objects:** pages 4733; tables 116143, 116144, 116147, 116148, 116151, 116152, 116153, 116154,
116155, 116156.

- [ ] **Step 1: Dump current state to a local file**

```bash
cat > /tmp/arl-standings-backup.php <<'EOF'
<?php
$out = array( 'taken' => current_time('mysql'), 'page_4733' => get_post(4733)->post_content, 'tables' => array() );
foreach ( array(116143,116144,116147,116148,116151,116152,116153,116154,116155,116156) as $id ) {
  $out['tables'][$id] = array(
    'title'     => get_the_title($id),
    'sp_date'      => get_post_meta($id,'sp_date',true),
    'sp_date_from' => get_post_meta($id,'sp_date_from',true),
    'sp_date_to'   => get_post_meta($id,'sp_date_to',true),
    'seasons'   => wp_get_post_terms($id,'sp_season',array('fields'=>'ids')),
    'leagues'   => wp_get_post_terms($id,'sp_league',array('fields'=>'ids')),
  );
}
echo wp_json_encode($out, JSON_PRETTY_PRINT);
EOF
scp -q /tmp/arl-standings-backup.php production-host:/tmp/arl-bk.php
ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs \
  eval-file /tmp/arl-bk.php --user=9 --skip-themes 2>/dev/null; rm -f /tmp/arl-bk.php' \
  > ~/git/rookiehockey.ca/docs/plans/2026-08-12-standings-backup.json
rm -f /tmp/arl-standings-backup.php
```

- [ ] **Step 2: Verify the backup parsed and holds all ten tables**

```bash
python3 -c "import json;d=json.load(open('$HOME/git/rookiehockey.ca/docs/plans/2026-08-12-standings-backup.json'));print(len(d['tables']),'tables');print(len(d['page_4733']),'bytes of page content')"
```

Expected: `10 tables` and a non-zero byte count. Do not proceed otherwise.

---

## Task 2: Give the five playoff tables an S2026 playoff date window

**Objects:** 116144 (D1), 116148 (D2), 116152 (D3), 116153 (D4), 116155 (D5).

Fixes **D2** and **D3**.

- [ ] **Step 1: Write the change**

```php
<?php
$ids = array( 116144, 116148, 116152, 116153, 116155 );
foreach ( $ids as $id ) {
	update_post_meta( $id, 'sp_date', 'range' );
	update_post_meta( $id, 'sp_date_from', '2026-08-01' );
	update_post_meta( $id, 'sp_date_to', '2026-08-31' );
	echo $id, '  ', get_the_title( $id ), "  -> range 2026-08-01..2026-08-31\n";
}
wp_cache_flush();
```

- [ ] **Step 2: Verify each table now returns real playoff numbers**

```php
<?php
foreach ( array(116144=>'D1',116148=>'D2',116152=>'D3',116153=>'D4',116155=>'D5') as $id => $d ) {
	$t = new SP_League_Table( $id );
	$rows = $t->data();
	$played = 0; $pts = 0;
	foreach ( $rows as $team => $r ) {
		if ( 0 === $team ) { continue; }
		$played += (int) ( $r['gp'] ?? 0 );
		$pts    += (int) ( $r['pts'] ?? 0 );
	}
	echo $d, ' table ', $id, ': teams=', count($rows)-1, ' total GP=', $played, ' total PTS=', $pts, "\n";
}
```

Expected: **total GP > 0 for every division**, because 11 playoff games are played with results
entered (D1 and D2 each played 2, D3 3, D4 2, D5 2 on 2026-08-07). A division still showing
`GP=0` means its events are not tagged season 667 — stop and investigate that division before
continuing.

- [ ] **Step 3: Confirm no regular-season game leaked in**

```php
<?php
$t = new SP_League_Table( 116144 );
// D1 played exactly 2 playoff games on 2026-08-07; each team should show GP<=1 per game played
foreach ( $t->data() as $team => $r ) {
	if ( 0 === $team ) { continue; }
	echo str_pad( get_the_title( $team ), 26 ), ' GP=', ( $r['gp'] ?? '?' ), ' PTS=', ( $r['pts'] ?? '?' ), "\n";
}
```

Expected: four D1 teams, each `GP=1` (one playoff game each so far). If any team shows `GP` in the
teens, the regular season is still being counted — the date window did not apply.

---

## Task 3: Stop the regular tables counting playoff games

**Objects:** 116143 (D1), 116147 (D2), 116151 (D3), 116154 (D4), 116156 (D5).

Fixes **D4**. Do this even though the request was about the playoff tables — without it, the two
sets of standings double-count the same games and `/standings` stays wrong.

- [ ] **Step 1: Record each regular table's current points, to prove the change does what we expect**

```php
<?php
foreach ( array(116143,116147,116151,116154,116156) as $id ) {
	$t = new SP_League_Table( $id );
	foreach ( $t->data() as $team => $r ) {
		if ( 0 === $team ) { continue; }
		echo $id, '  ', str_pad( get_the_title( $team ), 26 ), ' GP=', ($r['gp'] ?? '?'), ' PTS=', ($r['pts'] ?? '?'), "\n";
	}
}
```

Save this output — it is the "before" for Step 3.

- [ ] **Step 2: Apply the regular-season window**

```php
<?php
foreach ( array( 116143, 116147, 116151, 116154, 116156 ) as $id ) {
	update_post_meta( $id, 'sp_date', 'range' );
	update_post_meta( $id, 'sp_date_from', '2026-04-01' );
	update_post_meta( $id, 'sp_date_to', '2026-07-31' );
	echo $id, '  ', get_the_title( $id ), "  -> range 2026-04-01..2026-07-31\n";
}
wp_cache_flush();
```

- [ ] **Step 3: Verify points dropped by exactly the playoff games**

Re-run the Step 1 snippet. Expected: every team's `GP` falls by the number of playoff games it has
played (1 for most teams, since only the 2026-08-07 round is complete), and points fall
accordingly — e.g. Hammers in D1 should lose the 2 points from beating Kings 2–1 on 2026-08-07.
Teams that have not played a playoff game yet should be unchanged.

If nothing changes, the date filter is not being applied — stop and re-check `sp_date` really
reads `range`.

---

## Task 4: Point `/playoffs` at the S2026 tables

**Object:** page **4733** (`/playoffs`, title "Playoffs").

Fixes **D1**. Order matches `/standings` (page 111): D1 → D5.

- [ ] **Step 1: Replace the page content**

```php
<?php
$content = <<<'HTML'
<!-- wp:spacer {"height":"50px"} -->
<div style="height:50px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:paragraph -->
<p>The Division standings for the ARL Summer 2026 (S2026) Playoffs.</p>
<!-- /wp:paragraph -->

<!-- wp:shortcode -->
[team_standings 116144]
<!-- /wp:shortcode -->

<!-- wp:shortcode -->
[team_standings 116148]
<!-- /wp:shortcode -->

<!-- wp:shortcode -->
[team_standings 116152]
<!-- /wp:shortcode -->

<!-- wp:shortcode -->
[team_standings 116153]
<!-- /wp:shortcode -->

<!-- wp:shortcode -->
[team_standings 116155]
<!-- /wp:shortcode -->
HTML;

$r = wp_update_post( array( 'ID' => 4733, 'post_content' => $content ), true );
echo is_wp_error( $r ) ? 'ERROR: ' . $r->get_error_message() : 'page 4733 updated', "\n";
wp_cache_flush();
```

- [ ] **Step 2: Verify the stored content**

```php
<?php
$c = get_post( 4733 )->post_content;
preg_match_all( '/\[team_standings (\d+)\]/', $c, $m );
echo 'tables on page: ', implode( ',', $m[1] ), "\n";
echo 'still references W2025-26 tables: ',
	( preg_match( '/1143(98|99)|1144(0[0-4])/', $c ) ? 'YES — FAIL' : 'no' ), "\n";
```

Expected: `116144,116148,116152,116153,116155` and `no`.

- [ ] **Step 3: Verify the rendered page**

```bash
curl -sS https://www.rookiehockey.ca/playoffs -o /tmp/po.html -w 'HTTP %{http_code}\n'
grep -c 'sp-data-table\|sp-league-table' /tmp/po.html      # expect 5 tables
grep -oE 'Division [1-5]' /tmp/po.html | sort -u            # expect Division 1..5
grep -c 'Group [A-G]' /tmp/po.html                          # expect 0 (no W2025-26 leftovers)
rm -f /tmp/po.html
```

---

## Task 5: Whole-page verification

- [ ] **Step 1: Both pages render, with the right split**

```bash
for u in /standings /playoffs; do
  curl -sS -o /dev/null -w "  $u -> %{http_code}\n" "https://www.rookiehockey.ca$u"
done
```

- [ ] **Step 2: Sanity-check the arithmetic**

Total games counted across the five regular tables plus the five playoff tables should equal the
number of played S2026 games — 154 regular fixtures scheduled, of which those with results, plus
the 11 played playoff games. No game should appear in both sets.

```php
<?php
$reg = array(116143,116147,116151,116154,116156);
$pos = array(116144,116148,116152,116153,116155);
foreach ( array( 'regular' => $reg, 'playoff' => $pos ) as $label => $ids ) {
	$gp = 0;
	foreach ( $ids as $id ) {
		$t = new SP_League_Table( $id );
		foreach ( $t->data() as $team => $r ) { if ( 0 !== $team ) { $gp += (int) ( $r['gp'] ?? 0 ); } }
	}
	// each game contributes GP to two teams
	echo $label, ': ', $gp, ' team-games = ', $gp / 2, " games\n";
}
```

Expected: `playoff: 22 team-games = 11 games` (the 2026-08-07 round). Regular should equal twice
the number of completed regular fixtures and must **not** include the 11.

---

## Rollback

Restore from `docs/plans/2026-08-12-standings-backup.json`:

```php
<?php
// copy docs/plans/2026-08-12-standings-backup.json to production-host:/tmp/restore.json first
$d = json_decode( file_get_contents( '/tmp/restore.json' ), true );
wp_update_post( array( 'ID' => 4733, 'post_content' => $d['page_4733'] ) );
foreach ( $d['tables'] as $id => $t ) {
	update_post_meta( $id, 'sp_date', $t['sp_date'] );
	update_post_meta( $id, 'sp_date_from', $t['sp_date_from'] );
	update_post_meta( $id, 'sp_date_to', $t['sp_date_to'] );
}
wp_cache_flush();
```

---

## Out of scope — needs a decision from Cody

1. **No "Standings | S2026" archive page exists.** Every prior season has one (S2016–S2025,
   W2015-16–W2025-26), built to a fixed shape: an `<h2>Regular Season</h2>` + five tables, then
   `<h2>Playoffs</h2>` + five tables (see page 116298 for S2025, and menu item 116299 linking it).
   When S2026 closes, that page should be created from the ten table IDs above and `/standings`
   and `/playoffs` repointed at W2026-27. Not done here — it is a separate seasonal-rollover job.
2. **Past seasons are probably contaminated the same way.** The S2025 pair (112928 regular,
   112929 playoff) share one window, `2025-04-18` → `2025-09-29`, so the S2025 *regular* table has
   the same child-term problem D4 describes. Every archived season's regular standings may include
   its own playoff games. Fixing that is a bulk job across ~20 seasons and would change published
   historical standings, so it needs an explicit decision rather than being swept in here.
3. **The `sp_date_from`/`sp_date_to` values on the regular tables were junk** before this plan
   (mixed 2025 and 2026 dates) but harmless while `sp_date = 0`. After Task 3 they are meaningful,
   so future season rollovers must set them — copying a table forward carries the old window, which
   is exactly what caused D2.
