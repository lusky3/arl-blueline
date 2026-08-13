# Blueline P0 — Accessibility Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the two WCAG failures that exist in the theme today, and rebuild the contrast guard into a shared, extensible rule engine that the control panel and Occasions can safely validate against.

**Architecture:** The current guard is one 240-line `.mjs` script with its rules hardcoded, no tests, a token reader that throws on any non-hex value, and a `:root` finder that locks onto prose inside a comment. This plan decomposes it into three focused units — a CSS token reader, a contrast maths module, and a JSON rule table — each independently testable, then extends the rule table to cover the tokens it currently ignores. Two visible colour changes fall out of that: a two-tone focus indicator and a darker border token.

**Tech Stack:** Node 25 (`node --test`, built-in, ESM-native — no jest config needed), PHP 8.3, PHPUnit 12, `@wordpress/scripts` 34, phpcs/WPCS.

**Spec:** `docs/superpowers/specs/2026-08-13-blueline-control-panel-design.md` (§5, Phase 0)

## Global Constraints

- Text domain is `blueline`. Theme dir is `themes/blueline`; **run all npm/composer commands from there**, not the repo root.
- `phpcs:ignoreFile` is **forbidden**. It silently disables *all* linting for a file — this has already caused a shipped bug in this repo.
- All PHP output escaped at point of echo; `$wpdb` only via `prepare()`.
- **Production (`production-host.example`) is read-only. Never modify it.**
- This repo has **no git remote and no CI**. Every gate is a local command; Task 1 makes them one command.
- `themes/blueline/node_modules` must be excluded from every search.
- Existing baseline that must not regress: **153 PHPUnit tests / 355 assertions**, **14 contrast checks**, **25 staging smoke checks**.
- Contrast minimums, verbatim from the spec: body text **4.5:1**, large text and non-text/UI boundaries **3.0:1**.
- Never push, force-push, or merge to `main`.

## Phase scope

This plan covers **P0 only** — spec §5. P1 (the panel) and P2 (Occasions) get their own plans. P0 has no dependency on either and is independently valuable: it ships two accessibility fixes and the rule engine both later phases require.

## File Structure

| File | Responsibility |
|---|---|
| `themes/blueline/tools/lib/css-tokens.mjs` | **Create.** Parse a `:root` block into a token map; normalise values; resolve `var()` aliases. Pure, no I/O. |
| `themes/blueline/tools/lib/css-tokens.test.mjs` | **Create.** Tests for the above. |
| `themes/blueline/tools/lib/contrast.mjs` | **Create.** Relative luminance, contrast ratio, sRGB `color-mix` emulation, single-rule evaluation. Pure, no I/O. |
| `themes/blueline/tools/lib/contrast.test.mjs` | **Create.** Tests for the above. |
| `themes/blueline/tools/contrast-rules.json` | **Create.** The rule table. Becomes the shared contract for P1/P2's PHP validator. |
| `themes/blueline/tools/check-contrast.mjs` | **Modify.** Becomes a thin runner: read files, call the libs, print, exit. Keeps the three structural checks that cannot be expressed as rules. |
| `themes/blueline/style.css` | **Modify.** Split `--bl-focus`; darken `--bl-border-strong`; de-duplicate literal `rgba()`. |
| `themes/blueline/assets/src/css/base.css` | **Modify.** Two-tone focus indicator. |
| `themes/blueline/assets/src/css/{account,homepage,woocommerce,sportspress}.css` | **Modify.** Literal `rgba()` → `color-mix()`; remove the dead team-accent rule. |
| `themes/blueline/inc/team-colors.php` | **Modify.** Read shared thresholds; `blueline_readable_foreground()` returns pass/fail. |
| `themes/blueline/tests/bootstrap.php` | **Modify.** Real option store, real filter dispatch, honest `wp_kses`. |
| `.githooks/pre-commit` | **Create.** Runs the aggregate gate. |
| `themes/blueline/package.json` | **Modify.** `test:js`, `check` scripts. |

---

## Task 1: One gate command, a JS test runner, and a pre-commit hook

Nothing currently runs the five quality gates together, and there is no CI to run them. Every later task in this plan depends on being able to say "run the gate" in one line.

**Files:**
- Modify: `themes/blueline/package.json`
- Create: `.githooks/pre-commit`
- Create: `themes/blueline/tools/lib/css-tokens.mjs` (stub, so `node --test` has something to find)
- Create: `themes/blueline/tools/lib/css-tokens.test.mjs`

**Interfaces:**
- Consumes: nothing.
- Produces: `npm run check` (all gates), `npm run test:js` (Node's built-in test runner over `tools/`). Every later task uses these exact command names.

- [ ] **Step 1: Write a failing test for a function that does not exist yet**

Create `themes/blueline/tools/lib/css-tokens.test.mjs`:

```javascript
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeValue } from './css-tokens.mjs';

test( 'normalizeValue expands 3-digit hex to 6', () => {
	assert.equal( normalizeValue( '#fff' ), '#ffffff' );
} );

test( 'normalizeValue lowercases 6-digit hex', () => {
	assert.equal( normalizeValue( '#FFFFFF' ), '#ffffff' );
} );

test( 'normalizeValue collapses internal whitespace', () => {
	assert.equal( normalizeValue( '3px   solid   red' ), '3px solid red' );
} );
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd themes/blueline && node --test tools/
```

Expected: FAIL — `Cannot find module '.../tools/lib/css-tokens.mjs'`.

- [ ] **Step 3: Create the module with the minimal implementation**

Create `themes/blueline/tools/lib/css-tokens.mjs`:

```javascript
/**
 * Normalise a CSS declaration value for comparison: expand 3-digit hex to 6,
 * lowercase hex, collapse runs of whitespace. Mirrors the behaviour the
 * editor.css parity check has always relied on -- style.css writes
 * `--bl-white: #FFFFFF` while editor.css writes `#fff`, and those must
 * compare equal.
 *
 * @param {string} value Raw declaration value.
 * @return {string} Normalised value.
 */
export function normalizeValue( value ) {
	const trimmed = value.trim();
	const hex3 = trimmed.match( /^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/ );
	if ( hex3 ) {
		return `#${ hex3[ 1 ] }${ hex3[ 1 ] }${ hex3[ 2 ] }${ hex3[ 2 ] }${ hex3[ 3 ] }${ hex3[ 3 ] }`.toLowerCase();
	}
	if ( /^#[0-9a-fA-F]{6}$/.test( trimmed ) ) {
		return trimmed.toLowerCase();
	}
	return trimmed.replace( /\s+/g, ' ' );
}
```

- [ ] **Step 4: Run the test and confirm it passes**

```bash
cd themes/blueline && node --test tools/
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Add the npm scripts**

In `themes/blueline/package.json`, replace the `"scripts"` block with:

```json
  "scripts": {
    "build": "wp-scripts build",
    "start": "wp-scripts start",
    "lint:css": "wp-scripts lint-style 'assets/src/css/**/*.css'",
    "lint:js": "wp-scripts lint-js 'assets/src/js/**/*.js'",
    "tokens:check": "node tools/check-contrast.mjs",
    "test:js": "node --test tools/",
    "check": "npm run lint:css && npm run lint:js && npm run test:js && npm run tokens:check && composer test && composer lint"
  },
```

- [ ] **Step 6: Verify the aggregate gate passes on a clean tree**

```bash
cd themes/blueline && npm run check
```

Expected: all six gates pass. Note the baseline in the commit message: 153 PHPUnit tests, 14 contrast checks.

If `composer lint` reports pre-existing violations, do **not** fix them in this task — record the count and open it as a separate concern. This task must not become a lint cleanup.

- [ ] **Step 7: Add the pre-commit hook**

Create `.githooks/pre-commit`:

```bash
#!/bin/sh
# Blueline quality gate. This repo has no CI and no remote, so this hook is
# the only automatic enforcement point. Enable with:
#   git config core.hooksPath .githooks
set -e

if git diff --cached --name-only | grep -q '^themes/blueline/'; then
	echo "blueline: running quality gate..."
	( cd themes/blueline && npm run check )
fi
```

Make it executable and enable it:

```bash
chmod +x .githooks/pre-commit
git config core.hooksPath .githooks
```

- [ ] **Step 8: Verify the hook fires**

```bash
touch themes/blueline/tools/lib/.hooktest && git add themes/blueline/tools/lib/.hooktest && git commit -m "test" --dry-run
```

Expected: the gate runs. Then clean up:

```bash
git reset HEAD themes/blueline/tools/lib/.hooktest && rm themes/blueline/tools/lib/.hooktest
```

- [ ] **Step 9: Commit**

```bash
git add themes/blueline/package.json themes/blueline/tools/lib/ .githooks/
git commit -m "build(blueline): single quality gate, JS test runner, pre-commit hook

This repo has no CI configuration and no git remote, so the five existing
gates (lint:css, lint:js, tokens:check, phpunit, phpcs) were five things a
contributor had to remember. 'npm run check' runs all of them; .githooks/
pre-commit runs that on any staged change under themes/blueline.

Tests use node --test rather than jest: tools/ is ESM, Node 25 runs ESM
natively, and this needs no transform config at all.

Baseline recorded: 153 PHPUnit tests, 14 contrast checks, all green."
```

---

## Task 2: Fix the `:root` finder locking onto a comment

`extractRootTokens()` does `source.indexOf( ':root' )`. In `assets/src/css/editor.css` the first `:root` in the file is **prose inside a comment on line 17**, not the real block on line 44. It works today only because no `{` appears in between — an accident that will break the first time someone edits that comment.

**Files:**
- Modify: `themes/blueline/tools/lib/css-tokens.mjs`
- Modify: `themes/blueline/tools/lib/css-tokens.test.mjs`

**Interfaces:**
- Consumes: `normalizeValue()` from Task 1.
- Produces: `extractRootTokens( source ) -> Map<string, string>` — a `--bl-*` name → raw value map from the first *real* `:root` rule. Used by Tasks 3, 4, and by `check-contrast.mjs`.

- [ ] **Step 1: Write the failing test**

Append to `themes/blueline/tools/lib/css-tokens.test.mjs`:

```javascript
import { extractRootTokens } from './css-tokens.mjs';

test( 'extractRootTokens ignores :root mentioned inside a comment', () => {
	const source = `
/*
 * Note: :root custom properties don't cross the iframe boundary.
 */
:root {
	--bl-ink: #132343;
}`;
	const tokens = extractRootTokens( source );
	assert.equal( tokens.get( '--bl-ink' ), '#132343' );
	assert.equal( tokens.size, 1 );
} );

test( 'extractRootTokens handles multiple declarations on one line', () => {
	const source = ':root { --bl-space-1: 0.25rem;  --bl-space-2: 0.5rem; }';
	const tokens = extractRootTokens( source );
	assert.equal( tokens.get( '--bl-space-1' ), '0.25rem' );
	assert.equal( tokens.get( '--bl-space-2' ), '0.5rem' );
} );

test( 'extractRootTokens keeps commas inside clamp()', () => {
	const source = ':root { --bl-text-lg: clamp(1.125rem, 0.5vw + 1rem, 1.25rem); }';
	assert.equal(
		extractRootTokens( source ).get( '--bl-text-lg' ),
		'clamp(1.125rem, 0.5vw + 1rem, 1.25rem)'
	);
} );

test( 'extractRootTokens strips a trailing comment after the semicolon', () => {
	const source = ':root { --bl-ink: #132343; /* 14.94 on paper */ }';
	assert.equal( extractRootTokens( source ).get( '--bl-ink' ), '#132343' );
} );

test( 'extractRootTokens accepts a grouped selector', () => {
	const source = ':root, .editor-styles-wrapper { --bl-ink: #132343; }';
	assert.equal( extractRootTokens( source ).get( '--bl-ink' ), '#132343' );
} );

test( 'extractRootTokens throws when there is no :root rule', () => {
	assert.throws( () => extractRootTokens( 'body { color: red; }' ), /no :root/ );
} );
```

- [ ] **Step 2: Run and confirm it fails**

```bash
cd themes/blueline && node --test tools/
```

Expected: FAIL — `extractRootTokens is not a function`.

- [ ] **Step 3: Implement it**

Append to `themes/blueline/tools/lib/css-tokens.mjs`:

```javascript
/**
 * Strip CSS block comments. Done before any structural scanning so that a
 * `:root` or a brace appearing in prose cannot be mistaken for real syntax --
 * assets/src/css/editor.css genuinely has ":root" in a comment 27 lines above
 * its actual :root rule, and the previous indexOf-based scan matched that one.
 *
 * @param {string} source CSS source text.
 * @return {string} Source with comments replaced by equivalent-length padding.
 */
function stripComments( source ) {
	return source.replace( /\/\*[\s\S]*?\*\//g, ( match ) =>
		match.replace( /[^\n]/g, ' ' )
	);
}

/**
 * Extract every `--bl-*` declaration from the first `:root` rule (optionally a
 * grouped selector such as `:root, .editor-styles-wrapper`).
 *
 * @param {string} source CSS source text.
 * @return {Map<string,string>} Token name to raw, trimmed value.
 */
export function extractRootTokens( source ) {
	const clean = stripComments( source );

	const selector = /(^|[};])\s*:root\b[^{]*\{/.exec( clean );
	if ( ! selector ) {
		throw new Error( 'no :root rule found' );
	}

	const braceStart = selector.index + selector[ 0 ].length - 1;
	let depth = 0;
	let end = braceStart;
	for ( ; end < clean.length; end++ ) {
		if ( clean[ end ] === '{' ) {
			depth++;
		} else if ( clean[ end ] === '}' ) {
			depth--;
			if ( depth === 0 ) {
				break;
			}
		}
	}

	const block = clean.slice( braceStart + 1, end );
	const tokens = new Map();
	const declaration = /(--bl-[\w-]+)\s*:\s*([^;]+);/g;
	let match;
	while ( ( match = declaration.exec( block ) ) !== null ) {
		tokens.set( match[ 1 ], match[ 2 ].trim() );
	}
	return tokens;
}
```

Note: `stripComments` pads with spaces rather than deleting, so byte offsets stay aligned with the original source — that keeps any future line-number reporting honest.

- [ ] **Step 4: Run and confirm it passes**

```bash
cd themes/blueline && node --test tools/
```

Expected: PASS, 9 tests.

- [ ] **Step 5: Verify against the two real stylesheets**

```bash
cd themes/blueline && node -e '
import("./tools/lib/css-tokens.mjs").then(async (m)=>{
  const fs=await import("node:fs");
  const s=m.extractRootTokens(fs.readFileSync("style.css","utf8"));
  const e=m.extractRootTokens(fs.readFileSync("assets/src/css/editor.css","utf8"));
  console.log("style.css tokens:",s.size,"| editor.css tokens:",e.size);
  console.log("style.css has --bl-ink:",s.get("--bl-ink"));
  console.log("editor.css has --bl-ink:",e.get("--bl-ink"));
});'
```

Expected: `style.css tokens: 46` (or whatever the current count is — **record it**), `editor.css tokens: 26`, both `--bl-ink` values `#132343`. If editor.css reports a wildly different count than 26, the comment-stripping changed which block was found — investigate before continuing.

- [ ] **Step 6: Commit**

```bash
git add themes/blueline/tools/lib/
git commit -m "fix(blueline): stop the token parser locking onto a commented-out :root

extractRootTokens scanned with indexOf(':root'), and editor.css's first
occurrence of that string is prose inside a comment 27 lines above the real
rule. It resolved correctly only because no brace happens to sit between the
two -- editing that comment would have silently pointed the parity check at
the wrong block.

Comments are now stripped before any structural scan (padded, not deleted, so
offsets stay aligned), and the selector match requires a real rule position.
Covered by tests for the comment case, multiple declarations per line, commas
inside clamp(), trailing comments, and grouped selectors."
```

---

## Task 3: Resolve `var()` aliases

`style.css` has four tokens whose value is a reference, not a literal: `--bl-surface: var(--bl-white)`, `--bl-surface-sunken`, `--bl-surface-inverse`, and `--bl-focus`. The current `token()` helper regex-matches `#rrggbb` and **throws** on anything else, so none of these can be used in a contrast rule today. Every later task needs them resolvable.

**Files:**
- Modify: `themes/blueline/tools/lib/css-tokens.mjs`
- Modify: `themes/blueline/tools/lib/css-tokens.test.mjs`

**Interfaces:**
- Consumes: `extractRootTokens()` from Task 2.
- Produces: `resolveToken( tokens, name ) -> string` — the fully-resolved, normalised value. Throws on an unknown name or a reference cycle. Used by `contrast.mjs` and by `check-contrast.mjs`.

- [ ] **Step 1: Write the failing test**

Append to `themes/blueline/tools/lib/css-tokens.test.mjs`:

```javascript
import { resolveToken } from './css-tokens.mjs';

test( 'resolveToken returns a literal unchanged but normalised', () => {
	const tokens = new Map( [ [ '--bl-white', '#FFFFFF' ] ] );
	assert.equal( resolveToken( tokens, '--bl-white' ), '#ffffff' );
} );

test( 'resolveToken follows a single var() alias', () => {
	const tokens = new Map( [
		[ '--bl-white', '#FFFFFF' ],
		[ '--bl-surface', 'var(--bl-white)' ],
	] );
	assert.equal( resolveToken( tokens, '--bl-surface' ), '#ffffff' );
} );

test( 'resolveToken follows a chain of aliases', () => {
	const tokens = new Map( [
		[ '--bl-white', '#FFFFFF' ],
		[ '--bl-surface', 'var(--bl-white)' ],
		[ '--bl-card', 'var(--bl-surface)' ],
	] );
	assert.equal( resolveToken( tokens, '--bl-card' ), '#ffffff' );
} );

test( 'resolveToken uses the var() fallback when the target is undefined', () => {
	const tokens = new Map( [ [ '--bl-x', 'var(--bl-missing, #123456)' ] ] );
	assert.equal( resolveToken( tokens, '--bl-x' ), '#123456' );
} );

test( 'resolveToken throws on a reference cycle', () => {
	const tokens = new Map( [
		[ '--bl-a', 'var(--bl-b)' ],
		[ '--bl-b', 'var(--bl-a)' ],
	] );
	assert.throws( () => resolveToken( tokens, '--bl-a' ), /cycle/ );
} );

test( 'resolveToken throws on an unknown token', () => {
	assert.throws( () => resolveToken( new Map(), '--bl-nope' ), /not defined/ );
} );

test( 'resolveToken leaves a composite shorthand alone after resolving its parts', () => {
	const tokens = new Map( [
		[ '--bl-accent-text', '#3F6E9D' ],
		[ '--bl-focus', '3px solid var(--bl-accent-text)' ],
	] );
	assert.equal( resolveToken( tokens, '--bl-focus' ), '3px solid #3f6e9d' );
} );
```

- [ ] **Step 2: Run and confirm it fails**

```bash
cd themes/blueline && node --test tools/
```

Expected: FAIL — `resolveToken is not a function`.

- [ ] **Step 3: Implement it**

Append to `themes/blueline/tools/lib/css-tokens.mjs`:

```javascript
/**
 * Resolve a token to a literal value, following var() references.
 *
 * style.css defines four tokens by reference rather than by literal
 * (--bl-surface, --bl-surface-sunken, --bl-surface-inverse, --bl-focus).
 * Contrast rules need the resolved value, and the previous hex-only reader
 * threw on all four -- which is why none of them could be guarded.
 *
 * @param {Map<string,string>} tokens Map from extractRootTokens().
 * @param {string}             name   Token name, e.g. '--bl-surface'.
 * @param {Set<string>}        [seen] Internal: names already visited.
 * @return {string} Fully-resolved, normalised value.
 */
export function resolveToken( tokens, name, seen = new Set() ) {
	if ( seen.has( name ) ) {
		throw new Error( `var() reference cycle at ${ name }` );
	}
	if ( ! tokens.has( name ) ) {
		throw new Error( `token ${ name } is not defined` );
	}
	seen.add( name );

	const resolved = tokens
		.get( name )
		.replace(
			/var\(\s*(--[\w-]+)\s*(?:,\s*([^)]+))?\)/g,
			( _match, ref, fallback ) => {
				if ( tokens.has( ref ) ) {
					return resolveToken( tokens, ref, seen );
				}
				if ( fallback !== undefined ) {
					return fallback.trim();
				}
				throw new Error( `token ${ ref } is not defined` );
			}
		);

	return normalizeValue( resolved );
}

/**
 * Resolve a token that must be a plain 6-digit hex colour.
 *
 * @param {Map<string,string>} tokens Map from extractRootTokens().
 * @param {string}             name   Token name.
 * @return {string} Lowercase `#rrggbb`.
 */
export function resolveColorToken( tokens, name ) {
	const value = resolveToken( tokens, name );
	if ( ! /^#[0-9a-f]{6}$/.test( value ) ) {
		throw new Error( `token ${ name } resolved to "${ value }", not a hex colour` );
	}
	return value;
}
```

- [ ] **Step 4: Run and confirm it passes**

```bash
cd themes/blueline && node --test tools/
```

Expected: PASS, 16 tests.

- [ ] **Step 5: Verify the four real aliases resolve**

```bash
cd themes/blueline && node -e '
Promise.all([import("./tools/lib/css-tokens.mjs"),import("node:fs")]).then(([m,fs])=>{
  const t=m.extractRootTokens(fs.readFileSync("style.css","utf8"));
  for (const n of ["--bl-surface","--bl-surface-sunken","--bl-surface-inverse","--bl-focus"]) {
    console.log(n,"=>",m.resolveToken(t,n));
  }
});'
```

Expected: `--bl-surface => #ffffff`, `--bl-surface-sunken => #f7fbfc`, `--bl-surface-inverse => #132343`, `--bl-focus => 3px solid #3f6e9d`.

- [ ] **Step 6: Commit**

```bash
git add themes/blueline/tools/lib/
git commit -m "feat(blueline): resolve var() aliases in the token reader

style.css defines --bl-surface, --bl-surface-sunken, --bl-surface-inverse and
--bl-focus by reference. The contrast guard's token reader matched #rrggbb
only and threw on anything else, so none of those four could ever appear in a
rule -- which is a large part of why the guard covers 11 pairings out of the
~40 the theme actually renders.

resolveToken() follows references (including chains and var() fallbacks) and
refuses cycles; resolveColorToken() additionally asserts the result is a hex
colour, so a rule referencing a shorthand fails loudly rather than silently
comparing garbage."
```

---

## Task 4: Move the rules into `contrast-rules.json`

The rules are currently a JS array literal. P1 and P2 need a PHP validator reading the *same* table — the spec's whole "the two guards cannot disagree" claim rests on there being one table. Extracting it now, while behaviour is unchanged and verifiable, is far safer than extracting it later alongside new rules.

**Files:**
- Create: `themes/blueline/tools/contrast-rules.json`
- Create: `themes/blueline/tools/lib/contrast.mjs`
- Create: `themes/blueline/tools/lib/contrast.test.mjs`
- Modify: `themes/blueline/tools/check-contrast.mjs`

**Interfaces:**
- Consumes: `resolveColorToken()` from Task 3.
- Produces: `contrastRatio( hexA, hexB ) -> number`; `evaluateRule( rule, tokens ) -> { id, ok, ratio, description }`. The JSON shape below is the contract P1's PHP validator will implement against.

- [ ] **Step 1: Capture the current output as the baseline to preserve**

```bash
cd themes/blueline && node tools/check-contrast.mjs > /tmp/contrast-before.txt; echo "exit=$?"; cat /tmp/contrast-before.txt
```

Expected: 14 `ok` lines, one `--` informational line, `exit=0`. **This file is the acceptance criterion for this task.**

- [ ] **Step 2: Write the failing test for the contrast module**

Create `themes/blueline/tools/lib/contrast.test.mjs`:

```javascript
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { contrastRatio, evaluateRule } from './contrast.mjs';

const round = ( n ) => Number( n.toFixed( 2 ) );

test( 'contrastRatio matches known WCAG values', () => {
	assert.equal( round( contrastRatio( '#000000', '#ffffff' ) ), 21 );
	assert.equal( round( contrastRatio( '#ffffff', '#ffffff' ) ), 1 );
	assert.equal( round( contrastRatio( '#132343', '#f7fbfc' ) ), 14.94 );
	assert.equal( round( contrastRatio( '#3f6e9d', '#f7fbfc' ) ), 5.13 );
} );

test( 'contrastRatio is symmetric', () => {
	assert.equal(
		contrastRatio( '#132343', '#f7fbfc' ),
		contrastRatio( '#f7fbfc', '#132343' )
	);
} );

test( 'evaluateRule passes a satisfied min rule', () => {
	const tokens = new Map( [
		[ '--bl-ink', '#132343' ],
		[ '--bl-paper', '#F7FBFC' ],
	] );
	const result = evaluateRule(
		{ id: 'x', description: 'ink on paper', fg: '--bl-ink', bg: '--bl-paper', min: 4.5 },
		tokens
	);
	assert.equal( result.ok, true );
	assert.equal( round( result.ratio ), 14.94 );
} );

test( 'evaluateRule fails an unsatisfied min rule', () => {
	const tokens = new Map( [
		[ '--bl-ice', '#74C0E1' ],
		[ '--bl-paper', '#F7FBFC' ],
	] );
	const result = evaluateRule(
		{ id: 'y', description: 'ice on paper', fg: '--bl-ice', bg: '--bl-paper', min: 4.5 },
		tokens
	);
	assert.equal( result.ok, false );
} );

test( 'evaluateRule supports a max bound for fill-only tokens', () => {
	const tokens = new Map( [
		[ '--bl-ice', '#74C0E1' ],
		[ '--bl-paper', '#F7FBFC' ],
	] );
	const result = evaluateRule(
		{ id: 'z', description: 'ice unusable as text', fg: '--bl-ice', bg: '--bl-paper', max: 3.0 },
		tokens
	);
	assert.equal( result.ok, true );
} );
```

- [ ] **Step 3: Run and confirm it fails**

```bash
cd themes/blueline && node --test tools/
```

Expected: FAIL — cannot find `./contrast.mjs`.

- [ ] **Step 4: Implement the contrast module**

Create `themes/blueline/tools/lib/contrast.mjs`:

```javascript
import { resolveColorToken } from './css-tokens.mjs';

const channel = ( c ) => {
	const s = c / 255;
	return s <= 0.04045 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
};

/**
 * WCAG relative luminance of a `#rrggbb` colour.
 *
 * @param {string} hex Lowercase 6-digit hex.
 * @return {number} Relative luminance, 0..1.
 */
export function luminance( hex ) {
	const n = parseInt( hex.slice( 1 ), 16 );
	return (
		0.2126 * channel( ( n >> 16 ) & 255 ) +
		0.7152 * channel( ( n >> 8 ) & 255 ) +
		0.0722 * channel( n & 255 )
	);
}

/**
 * WCAG contrast ratio between two colours.
 *
 * @param {string} a Hex colour.
 * @param {string} b Hex colour.
 * @return {number} Ratio, 1..21.
 */
export function contrastRatio( a, b ) {
	const x = luminance( a );
	const y = luminance( b );
	const hi = Math.max( x, y );
	const lo = Math.min( x, y );
	return ( hi + 0.05 ) / ( lo + 0.05 );
}

/**
 * Evaluate one rule from contrast-rules.json against a token map.
 *
 * A rule carries either `min` (the usual case: this pairing must be at least
 * this readable) or `max` (an inverse guard: this pairing must stay UNreadable,
 * so that a fill-only token cannot quietly become usable as text).
 *
 * @param {Object}             rule   Rule object.
 * @param {Map<string,string>} tokens Token map.
 * @return {{id:string,description:string,ok:boolean,ratio:number,bound:string}}
 */
export function evaluateRule( rule, tokens ) {
	const fg = resolveColorToken( tokens, rule.fg );
	const bg = resolveColorToken( tokens, rule.bg );
	const ratio = contrastRatio( fg, bg );
	const ok =
		rule.max !== undefined ? ratio < rule.max : ratio >= rule.min;

	return {
		id: rule.id,
		description: rule.description,
		ok,
		ratio,
		bound: rule.max !== undefined ? `max ${ rule.max }` : `min ${ rule.min }`,
	};
}
```

- [ ] **Step 5: Run and confirm the tests pass**

```bash
cd themes/blueline && node --test tools/
```

Expected: PASS, 21 tests.

- [ ] **Step 6: Create the rule table with exactly today's rules**

Create `themes/blueline/tools/contrast-rules.json`. These are the 10 `RULES` entries plus the ice inverse guard — **no new rules in this task**:

```json
{
  "$comment": "Shared contrast contract. Consumers: tools/check-contrast.mjs (build gate), the PHP save-time validator (P1), and the panel's advisory readout (P2). Adding a rule here must not require editing any consumer.",
  "version": 1,
  "rules": [
    { "id": "body-on-paper",        "description": "body text on paper",       "fg": "--bl-ink",         "bg": "--bl-paper",    "min": 4.5 },
    { "id": "secondary-on-paper",   "description": "secondary text on paper",  "fg": "--bl-ink-mid",     "bg": "--bl-paper",    "min": 4.5 },
    { "id": "accent-on-paper",      "description": "accent text on paper",     "fg": "--bl-accent-text", "bg": "--bl-paper",    "min": 4.5 },
    { "id": "ink-on-ice",           "description": "ink on ice fill (button)", "fg": "--bl-ink",         "bg": "--bl-ice",      "min": 4.5 },
    { "id": "paper-on-ink",         "description": "paper text on ink",        "fg": "--bl-paper",       "bg": "--bl-ink",      "min": 4.5 },
    { "id": "pale-on-ink",          "description": "pale text on ink",         "fg": "--bl-pale",        "bg": "--bl-ink",      "min": 4.5 },
    { "id": "ice-on-ink",           "description": "ice text on ink",          "fg": "--bl-ice",         "bg": "--bl-ink",      "min": 4.5 },
    { "id": "steel-border-paper",   "description": "steel border on paper",    "fg": "--bl-steel",       "bg": "--bl-paper",    "min": 3.0 },
    { "id": "paper-on-ink-deep",    "description": "paper text on ink-deep",   "fg": "--bl-paper",       "bg": "--bl-ink-deep", "min": 4.5 },
    { "id": "pale-on-ink-deep",     "description": "pale text on ink-deep",    "fg": "--bl-pale",        "bg": "--bl-ink-deep", "min": 4.5 },
    { "id": "ice-not-text-on-light","description": "--bl-ice correctly unusable as light-bg text", "fg": "--bl-ice", "bg": "--bl-paper", "max": 3.0 }
  ]
}
```

- [ ] **Step 7: Rewire `check-contrast.mjs` to read the table**

In `themes/blueline/tools/check-contrast.mjs`, replace the imports, the local `token`/`extractRootTokens`/`lin`/`lum`/`ratio` helpers, the `RULES` array, the rule loop, and the standalone `iceOnPaper` guard with:

```javascript
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { extractRootTokens, resolveColorToken, normalizeValue } from './lib/css-tokens.mjs';
import { contrastRatio, evaluateRule } from './lib/contrast.mjs';

const here = dirname( fileURLToPath( import.meta.url ) );
const css = readFileSync( resolve( here, '../style.css' ), 'utf8' );
const styleTokens = extractRootTokens( css );
const token = ( name ) => resolveColorToken( styleTokens, `--${ name }` );

const { rules } = JSON.parse(
	readFileSync( resolve( here, 'contrast-rules.json' ), 'utf8' )
);

let failed = 0;
for ( const rule of rules ) {
	const result = evaluateRule( rule, styleTokens );
	if ( ! result.ok ) {
		failed++;
	}
	console.log(
		`${ result.ok ? 'ok  ' : 'FAIL' } ${ result.description }: ${ result.ratio.toFixed( 2 ) } (${ result.bound })`
	);
}
```

Leave the three structural checks below it untouched — the editor.css parity loop, the `team-colors.php` mirror check, and the SportsPress STRK fixture. They are **not** contrast pairs and cannot be expressed in the table; they stay in JS deliberately. Update their `extractRootTokens`/`ratio` call sites to use the imported versions.

- [ ] **Step 8: Verify the output is unchanged except for the reworded inverse line**

```bash
cd themes/blueline && node tools/check-contrast.mjs > /tmp/contrast-after.txt; echo "exit=$?"; diff /tmp/contrast-before.txt /tmp/contrast-after.txt
```

Expected: `exit=0`, and the **only** diff is the `--bl-ice correctly unusable` line, which now reads `(max 3)` instead of its previous bespoke wording. Every other line must be byte-identical. If any ratio changed, the alias resolution from Task 3 altered a value — stop and investigate.

- [ ] **Step 9: Commit**

```bash
git add themes/blueline/tools/
git commit -m "refactor(blueline): move contrast rules to a shared JSON table

The rules were a JS array inside the build script, so the PHP that will
validate admin-chosen colours at save time (P1) would have had to duplicate
them -- and a duplicated rule table is a rule table that drifts. They now live
in tools/contrast-rules.json with the script as one consumer.

Rules are unchanged in this commit and the script's output is byte-identical
except for the ice inverse guard, which now prints its bound as '(max 3)'
because it is expressed as a max rule rather than bespoke code.

The three structural checks -- editor.css token parity, the team-colors.php
constant mirror, and the SportsPress STRK fixture -- deliberately stay in JS.
They assert things about source files, not contrast between two tokens, and
have no honest representation in a table of colour pairs."
```

---

## Task 5: A derived-value rule type for `color-mix()`

Six notice components — the theme's entire error/success surface, introduced by commit `bef3bbd` — sit on `color-mix(in srgb, var(--token) 8%, var(--bl-white))`. Neither guard can see a computed value, so the ground under that body text is unguarded.

**Files:**
- Modify: `themes/blueline/tools/lib/contrast.mjs`
- Modify: `themes/blueline/tools/lib/contrast.test.mjs`

**Interfaces:**
- Consumes: `contrastRatio()`, `resolveColorToken()`.
- Produces: `mixSrgb( hexA, percent, hexB ) -> string`. `evaluateRule()` gains support for `fg`/`bg` given as `{ "mix": [ tokenA, percent, tokenB ] }`. P1's PHP validator must implement `mixSrgb` identically.

- [ ] **Step 1: Write the failing test**

Append to `themes/blueline/tools/lib/contrast.test.mjs`:

```javascript
import { mixSrgb } from './contrast.mjs';

test( 'mixSrgb at 0% returns the second colour', () => {
	assert.equal( mixSrgb( '#000000', 0, '#ffffff' ), '#ffffff' );
} );

test( 'mixSrgb at 100% returns the first colour', () => {
	assert.equal( mixSrgb( '#000000', 100, '#ffffff' ), '#000000' );
} );

test( 'mixSrgb at 50% is the midpoint', () => {
	assert.equal( mixSrgb( '#000000', 50, '#ffffff' ), '#808080' );
} );

test( 'mixSrgb matches the 8% notice tint used in account.css', () => {
	// color-mix(in srgb, #1F7A4D 8%, #ffffff)
	assert.equal( mixSrgb( '#1f7a4d', 8, '#ffffff' ), '#edf4f1' );
} );

test( 'evaluateRule resolves a mix background', () => {
	const tokens = new Map( [
		[ '--bl-ink', '#132343' ],
		[ '--bl-success', '#1F7A4D' ],
		[ '--bl-white', '#FFFFFF' ],
	] );
	const result = evaluateRule(
		{
			id: 'ink-on-success-tint',
			description: 'body text on the success notice tint',
			fg: '--bl-ink',
			bg: { mix: [ '--bl-success', 8, '--bl-white' ] },
			min: 4.5,
		},
		tokens
	);
	assert.equal( result.ok, true );
	assert.ok( result.ratio > 12 );
} );
```

- [ ] **Step 2: Run and confirm it fails**

```bash
cd themes/blueline && node --test tools/
```

Expected: FAIL — `mixSrgb is not a function`.

- [ ] **Step 3: Implement it**

In `themes/blueline/tools/lib/contrast.mjs`, add `mixSrgb` and replace `evaluateRule`'s two resolution lines:

```javascript
/**
 * Emulate `color-mix(in srgb, a p%, b)`.
 *
 * CSS mixes in the given colour space without gamma-decoding for srgb, so this
 * is a plain per-channel linear interpolation on the 0-255 values, rounded the
 * way browsers round.
 *
 * NOTE: P1's PHP validator must implement this identically. A parity test
 * covers it; if this changes, that test must change with it.
 *
 * @param {string} a       Hex colour mixed in at `percent`.
 * @param {number} percent 0-100.
 * @param {string} b       Hex colour making up the remainder.
 * @return {string} Lowercase `#rrggbb`.
 */
export function mixSrgb( a, percent, b ) {
	const weight = percent / 100;
	const na = parseInt( a.slice( 1 ), 16 );
	const nb = parseInt( b.slice( 1 ), 16 );
	const chan = ( shift ) => {
		const ca = ( na >> shift ) & 255;
		const cb = ( nb >> shift ) & 255;
		return Math.round( ca * weight + cb * ( 1 - weight ) );
	};
	const hex = ( v ) => v.toString( 16 ).padStart( 2, '0' );
	return `#${ hex( chan( 16 ) ) }${ hex( chan( 8 ) ) }${ hex( chan( 0 ) ) }`;
}

/**
 * Resolve a rule endpoint: either a token name, or a mix descriptor.
 *
 * @param {string|Object}      endpoint Token name or `{ mix: [ a, pct, b ] }`.
 * @param {Map<string,string>} tokens   Token map.
 * @return {string} Hex colour.
 */
function resolveEndpoint( endpoint, tokens ) {
	if ( typeof endpoint === 'string' ) {
		return resolveColorToken( tokens, endpoint );
	}
	if ( endpoint && Array.isArray( endpoint.mix ) ) {
		const [ a, percent, b ] = endpoint.mix;
		return mixSrgb(
			resolveColorToken( tokens, a ),
			percent,
			resolveColorToken( tokens, b )
		);
	}
	throw new Error( `unsupported rule endpoint: ${ JSON.stringify( endpoint ) }` );
}
```

Then in `evaluateRule`, replace:

```javascript
	const fg = resolveColorToken( tokens, rule.fg );
	const bg = resolveColorToken( tokens, rule.bg );
```

with:

```javascript
	const fg = resolveEndpoint( rule.fg, tokens );
	const bg = resolveEndpoint( rule.bg, tokens );
```

- [ ] **Step 4: Run and confirm it passes**

```bash
cd themes/blueline && node --test tools/ && node tools/check-contrast.mjs
```

Expected: PASS, 26 tests; the guard still reports 14 checks, exit 0.

- [ ] **Step 5: Commit**

```bash
git add themes/blueline/tools/
git commit -m "feat(blueline): let contrast rules target a color-mix() ground

The account and WooCommerce notices introduced in bef3bbd sit on
color-mix(in srgb, <token> 8%, var(--bl-white)). A rule table of discrete hex
pairs cannot see a computed value, so the ground beneath the theme's entire
error and success surface was unguarded by construction.

Rules may now give fg or bg as { mix: [ tokenA, percent, tokenB ] }. mixSrgb()
reproduces CSS's srgb interpolation; P1's PHP validator must match it
bit-for-bit, which a parity test will assert once that validator exists."
```

---

## Task 6: Two-tone focus indicator

`--bl-focus: 3px solid var(--bl-accent-text)` is a shorthand, so no table of hex pairs can validate its colour — and the colour is currently **2.91:1 on `--bl-ink`**, below 1.4.11's 3:1 floor.

**There is no single colour that fixes this.** The focus ring is drawn on grounds spanning `#FFFFFF` to `#0D1729` plus `#74C0E1`; a colour dark enough for white is too light for ink, and `--bl-ice` sits where neither works. Verified across eight candidates — best case reached 2.64 on ice.

The fix is the standard two-tone indicator: an inner ring and an outer ring in contrasting colours, so at least one always contrasts with whatever it sits on. Verified: inner `#0D1729` + outer `#FFFFFF` gives a best-of ≥ 8.85 on every ground, and the two rings contrast with each other at 17.92:1.

**Files:**
- Modify: `themes/blueline/style.css`
- Modify: `themes/blueline/assets/src/css/base.css`
- Modify: `themes/blueline/tools/contrast-rules.json`

**Interfaces:**
- Consumes: the rule table from Task 4, the `min` bound semantics from Task 4.
- Produces: tokens `--bl-focus-width`, `--bl-focus-style`, `--bl-focus-color`, `--bl-focus-halo`. `--bl-focus` is **removed**; any consumer must be updated in this task.

- [ ] **Step 1: Add the failing rules first**

Add to the `rules` array in `themes/blueline/tools/contrast-rules.json`:

```json
    { "id": "focus-on-paper",     "description": "focus ring on paper",     "fg": "--bl-focus-color", "bg": "--bl-paper",     "min": 3.0 },
    { "id": "focus-on-white",     "description": "focus ring on white",     "fg": "--bl-focus-color", "bg": "--bl-white",     "min": 3.0 },
    { "id": "focus-on-ice",       "description": "focus ring on ice fill",  "fg": "--bl-focus-color", "bg": "--bl-ice",       "min": 3.0 },
    { "id": "focus-halo-on-ink",  "description": "focus halo on ink",       "fg": "--bl-focus-halo",  "bg": "--bl-ink",       "min": 3.0 },
    { "id": "focus-halo-on-deep", "description": "focus halo on ink-deep",  "fg": "--bl-focus-halo",  "bg": "--bl-ink-deep",  "min": 3.0 },
    { "id": "focus-two-tone",     "description": "focus ring vs its halo",  "fg": "--bl-focus-color", "bg": "--bl-focus-halo","min": 3.0 }
```

- [ ] **Step 2: Run the guard and confirm it fails for the right reason**

```bash
cd themes/blueline && node tools/check-contrast.mjs; echo "exit=$?"
```

Expected: **throws** — `token --bl-focus-color is not defined`. That is the correct failure: the rules exist before the tokens.

- [ ] **Step 3: Split the token in `style.css`**

In `themes/blueline/style.css`, replace:

```css
	--bl-focus: 3px solid var(--bl-accent-text);
	--bl-focus-offset: 2px;
```

with:

```css
	/*
	 * Two-tone focus indicator. The ring is drawn on grounds ranging from
	 * --bl-white to --bl-ink-deep, and NO single colour clears 3:1 against all
	 * of them (--bl-ice in the middle defeats every candidate). So the
	 * indicator is a dark ring plus a light halo: whichever ground it lands
	 * on, one of the two carries the contrast. Both are rule-guarded, as is
	 * the 3:1 between them. See WCAG 2.2 SC 1.4.11.
	 */
	--bl-focus-width:  3px;
	--bl-focus-style:  solid;
	--bl-focus-color:  #0D1729;
	--bl-focus-halo:   #FFFFFF;
	--bl-focus-offset: 2px;
```

- [ ] **Step 4: Run the guard and confirm the new rules pass**

```bash
cd themes/blueline && node tools/check-contrast.mjs; echo "exit=$?"
```

Expected: `exit=0`, 20 checks, all six new focus lines `ok`.

- [ ] **Step 5: Apply the indicator in `base.css`**

In `themes/blueline/assets/src/css/base.css`, replace the `:focus-visible` rule at lines 104-107:

```css
:focus-visible {
	outline: var(--bl-focus-width) var(--bl-focus-style) var(--bl-focus-color);
	outline-offset: var(--bl-focus-offset);
	/*
	 * The halo sits immediately outside the outline, so on a dark ground --
	 * header, nav drawer, footer, hero, scoreboard -- the light ring provides
	 * the 3:1 the dark outline cannot. box-shadow is used rather than a second
	 * outline because an element has only one outline, and unlike a border it
	 * adds no layout size.
	 */
	box-shadow: 0 0 0 calc(var(--bl-focus-offset) + var(--bl-focus-width) + 1px) var(--bl-focus-halo);
}
```

- [ ] **Step 6: Find and fix every other consumer of the removed token**

```bash
cd themes/blueline && grep -rn -- "--bl-focus\b" assets/src inc *.php sportspress 2>/dev/null | grep -v "focus-width\|focus-style\|focus-color\|focus-halo\|focus-offset"
```

Expected: no output. If any remain, update each to the split tokens. Also check `editor.css` — if it declares `--bl-focus`, the parity check will now fail because `style.css` no longer defines that name:

```bash
cd themes/blueline && grep -n -- "--bl-focus" assets/src/css/editor.css
```

Expected: no output (the parity check's own comment records that editor.css deliberately omits `--bl-focus`). If it appears, remove it.

- [ ] **Step 7: Build and run the full gate**

```bash
cd themes/blueline && npm run build && npm run check
```

Expected: all gates pass; contrast reports 20 checks.

- [ ] **Step 8: Verify the indicator visually**

Deploy to staging and tab through the header, the mobile nav drawer, the hero CTA, a standings table link, and a WooCommerce form field. On every one, a visible ring must be present on both light and dark grounds.

```bash
./scripts/deploy-theme.sh && ./scripts/smoke-staging.sh
```

Expected: smoke 25/25, exit 0. **The visual check is not optional** — this is a change to every interactive element on the site.

- [ ] **Step 9: Commit**

```bash
git add themes/blueline/style.css themes/blueline/assets/src/css/base.css themes/blueline/tools/contrast-rules.json themes/blueline/assets/dist
git commit -m "fix(blueline): two-tone focus indicator, fixing 1.4.11 on dark chrome

--bl-accent-text was 2.91:1 on --bl-ink -- below the 3:1 SC 1.4.11 requires --
so the focus ring on the hero and scoreboard did not meet AA. It was also a
shorthand token (3px solid var(...)), which no table of colour pairs can
validate, and it was the SAME token used for accent text: darkening it to
improve the one rule that governed it (5.13 -> 10.17 on paper) would have
driven the ring to 1.47 on ink while the gate reported success.

No single colour fixes this. The ring is drawn on grounds from #FFFFFF to
#0D1729 with --bl-ice in between; eight candidates were measured and the best
reached 2.64. So the indicator is now two-tone: a #0D1729 outline plus a
#FFFFFF halo. Best-of contrast is >= 8.85 on every ground and the two rings
clear 3:1 against each other (17.92). All six pairings are rule-guarded, and
the ring colour is now a colour token rather than a shorthand, so it stays
guarded.

--bl-focus is removed; consumers use --bl-focus-{width,style,color,halo}."
```

---

## Task 7: Darken `--bl-border-strong` to meet 1.4.11

`#C3D6E4` is **1.49:1** on `--bl-white` and 1.43 on `--bl-paper`. It is the only visual identifier of every form field, `select`, `textarea`, search box, select2 control, pagination chip and the standings toggle — 18 uses. 1.4.11 requires 3:1 for UI component boundaries, and this is the canonical example in that criterion's own Understanding document.

**Files:**
- Modify: `themes/blueline/style.css`
- Modify: `themes/blueline/tools/contrast-rules.json`
- Modify: `themes/blueline/assets/src/css/editor.css` (parity)

**Interfaces:**
- Consumes: the rule table.
- Produces: no new names. `--bl-border-strong` changes value from `#C3D6E4` to `#7C93A8`.

- [ ] **Step 1: Add the failing rules**

Add to `rules` in `themes/blueline/tools/contrast-rules.json`:

```json
    { "id": "border-strong-on-white", "description": "strong border on white", "fg": "--bl-border-strong", "bg": "--bl-white", "min": 3.0 },
    { "id": "border-strong-on-paper", "description": "strong border on paper", "fg": "--bl-border-strong", "bg": "--bl-paper", "min": 3.0 }
```

- [ ] **Step 2: Run the guard and confirm it fails with the real numbers**

```bash
cd themes/blueline && node tools/check-contrast.mjs; echo "exit=$?"
```

Expected: `exit=1`, with
`FAIL strong border on white: 1.49 (min 3)` and `FAIL strong border on paper: 1.43 (min 3)`.

- [ ] **Step 3: Change the token**

In `themes/blueline/style.css`, replace:

```css
	--bl-border-strong:    #C3D6E4;
```

with:

```css
	/*
	 * 3.18 on white, 3.06 on paper. This is the ONLY thing identifying a form
	 * field, select, textarea, search box or pagination chip -- their fill is
	 * --bl-white against a --bl-paper page, which is 1.05:1, so the boundary
	 * carries the whole affordance. SC 1.4.11 requires 3:1 for exactly this.
	 * The previous #C3D6E4 was 1.49.
	 */
	--bl-border-strong:    #7C93A8;
```

- [ ] **Step 4: Run the guard and confirm it passes**

```bash
cd themes/blueline && node tools/check-contrast.mjs; echo "exit=$?"
```

Expected: `exit=0`, 22 checks, both border lines `ok` at 3.18 and 3.06.

- [ ] **Step 5: Fix editor.css parity if it declares the token**

```bash
cd themes/blueline && grep -n -- "--bl-border-strong" assets/src/css/editor.css
```

If present, update it to `#7C93A8` so the parity check passes. Re-run `node tools/check-contrast.mjs` and confirm the parity line still reports `ok`.

- [ ] **Step 6: Build, gate, deploy, look at it**

```bash
cd themes/blueline && npm run build && npm run check && cd ../.. && ./scripts/deploy-theme.sh && ./scripts/smoke-staging.sh
```

Expected: gates pass, smoke 25/25.

**Then look at the site.** This darkens every form field border on the site by a large margin — registration checkout, the search form, the comment form, the player selector, pagination. It is a visible design change and needs a design judgement, not just a passing number. If it reads too heavy, the correct response is to adjust the hue toward the brand (a steel-blue at the same luminance) rather than to lighten it back below 3:1.

- [ ] **Step 7: Commit**

```bash
git add themes/blueline/style.css themes/blueline/assets/src/css/editor.css themes/blueline/tools/contrast-rules.json themes/blueline/assets/dist
git commit -m "fix(blueline): darken --bl-border-strong to meet SC 1.4.11

#C3D6E4 was 1.49:1 on white and 1.43 on paper, against the 3:1 that 1.4.11
requires for the boundary of a user interface component. It is the sole
identifier of every form field, select, textarea, search box, select2 control,
pagination chip and the standings toggle -- 18 uses -- because the field fill
(--bl-white) is 1.05:1 against the page ground (--bl-paper), so the border
carries the entire affordance. This is the canonical example in 1.4.11's own
Understanding document.

Now #7C93A8: 3.18 on white, 3.06 on paper. Both pairings are rule-guarded, so
it cannot drift back.

This is a visible design change to every form on the site, not a silent fix."
```

---

## Task 8: Cover the semantic tokens and the white ground

Two gaps remain in the table. The semantic tokens `--bl-success` / `--bl-warning` / `--bl-danger` have **no rule at all**, despite `bef3bbd` making them the border and tint of every notice — and `#FF0000`, the obvious thing to type for "danger", is 4.00:1 on white and fails. Separately, every rule measures against `--bl-paper`, but zebra table rows alternate white and paper (`sportspress.css:160-166`), so half the STRK cells and every card sit on `--bl-white` with nothing watching.

**Files:**
- Modify: `themes/blueline/tools/contrast-rules.json`

**Interfaces:**
- Consumes: the `mix` endpoint type from Task 5.
- Produces: no new names; the table grows to 33 rules.

- [ ] **Step 1: Add the rules**

Add to `rules` in `themes/blueline/tools/contrast-rules.json`:

```json
    { "id": "success-on-white",  "description": "success text on white", "fg": "--bl-success", "bg": "--bl-white", "min": 4.5 },
    { "id": "warning-on-white",  "description": "warning text on white", "fg": "--bl-warning", "bg": "--bl-white", "min": 4.5 },
    { "id": "danger-on-white",   "description": "danger text on white",  "fg": "--bl-danger",  "bg": "--bl-white", "min": 4.5 },

    { "id": "success-on-its-tint", "description": "success text on its own 8% tint", "fg": "--bl-success", "bg": { "mix": [ "--bl-success", 8, "--bl-white" ] }, "min": 4.5 },
    { "id": "warning-on-its-tint", "description": "warning text on its own 8% tint", "fg": "--bl-warning", "bg": { "mix": [ "--bl-warning", 8, "--bl-white" ] }, "min": 4.5 },
    { "id": "danger-on-its-tint",  "description": "danger text on its own 8% tint",  "fg": "--bl-danger",  "bg": { "mix": [ "--bl-danger",  8, "--bl-white" ] }, "min": 4.5 },
    { "id": "ink-on-steel-tint",   "description": "body text on the neutral notice tint", "fg": "--bl-ink", "bg": { "mix": [ "--bl-steel", 8, "--bl-white" ] }, "min": 4.5 },

    { "id": "body-on-white",      "description": "body text on white (cards, zebra rows)",      "fg": "--bl-ink",         "bg": "--bl-white", "min": 4.5 },
    { "id": "secondary-on-white", "description": "secondary text on white (STRK, table meta)",  "fg": "--bl-ink-mid",     "bg": "--bl-white", "min": 4.5 },
    { "id": "accent-on-white",    "description": "accent text on white",                        "fg": "--bl-accent-text", "bg": "--bl-white", "min": 4.5 }
```

- [ ] **Step 2: Run the guard**

```bash
cd themes/blueline && node tools/check-contrast.mjs; echo "exit=$?"
```

Expected: `exit=0`, 32 checks, all `ok`. Pre-computed, these are the ratios you should see:

| Rule | Ratio |
|---|---|
| success / warning / danger on white | 5.32 · 5.93 · 7.16 |
| success / warning / danger on own 8% tint | **4.76** · 5.31 · 6.29 |
| ink on the steel 8% tint | 14.21 |
| ink / ink-mid / accent on white | 15.57 · 8.94 · 5.35 |

The current defaults already satisfy all ten — that is the point: they are being **locked in** so they cannot silently regress, and so P2's Occasions have something to validate against.

Note **success on its own tint is 4.76 against a 4.5 floor** — a 0.26 margin. That is the tightest pairing in the theme, and P2 must not let an Occasion accent near it.

If any fails, do **not** relax the rule. Report the failure — it means a live AA bug the review did not catch.

- [ ] **Step 3: Confirm the derived-value rules actually bite**

Temporarily set `--bl-danger` to `#FF0000` in `style.css`, run the guard, and confirm it fails:

```bash
cd themes/blueline && sed -i 's/--bl-danger:  #A32C1B;/--bl-danger:  #FF0000;/' style.css && node tools/check-contrast.mjs | grep -i danger; echo "exit=${PIPESTATUS[0]}"
```

Expected: `FAIL danger text on white: 4.00 (min 4.5)`.

Then revert:

```bash
cd themes/blueline && sed -i 's/--bl-danger:  #FF0000;/--bl-danger:  #A32C1B;/' style.css && node tools/check-contrast.mjs | tail -3
```

Expected: back to 32 `ok`. **Confirm `git diff style.css` is empty before committing.**

- [ ] **Step 4: Commit**

```bash
git add themes/blueline/tools/contrast-rules.json
git commit -m "test(blueline): guard the semantic, tint and white-ground pairings

Three gaps the review found, none of which the 11 original rules covered:

--bl-success/--bl-warning/--bl-danger had no rule at all, despite bef3bbd
making them the border and tint of every notice on the site. #FF0000 -- the
obvious value to type for 'danger' -- is 4.00 on white and fails; the gate
would have said nothing.

The 8% color-mix() tints those notices sit on were unguarded because a table
of hex pairs cannot see a computed value. Task 5's mix endpoint fixes that;
these rules use it.

Every existing rule measured against --bl-paper, but zebra table rows
alternate white and paper, so half the STRK cells, every card and every form
field sit on --bl-white with nothing watching.

All ten pass at current defaults. They are locked in so they cannot regress,
and so P2's scheduled Occasions have a complete table to validate against --
an occasion accent that ships itself at midnight needs the gate to be
exhaustive, not indicative."
```

---

## Task 9: De-duplicate the literal `rgba()` values

Four places hard-code the RGB channels of a token instead of referencing it. Override the token and the text colour moves while its background stays — silently breaking a pairing that a code comment in `account.css` claims was "verified by hand".

**Files:**
- Modify: `themes/blueline/assets/src/css/account.css`
- Modify: `themes/blueline/assets/src/css/homepage.css`
- Modify: `themes/blueline/assets/src/css/woocommerce.css`
- Modify: `themes/blueline/style.css`

**Interfaces:**
- Consumes: nothing.
- Produces: no new names. Behaviour must be visually identical.

- [ ] **Step 1: Find every instance**

```bash
cd themes/blueline && grep -rn "rgba\?(\s*[0-9]" assets/src/css/ style.css
```

Record the list. The known four from the review: `account.css:396-404` (`rgba(31,122,77,0.08)` = `--bl-success`, `rgba(138,90,0,0.08)` = `--bl-warning`), `homepage.css:210` (`rgba(116,192,225,0.08)` = `--bl-ice`), `woocommerce.css:411` (`rgb(19 35 67 / 12%)` = `--bl-ink`), `style.css:62-63` (both shadows carry ink's RGB).

- [ ] **Step 2: Replace each with `color-mix()`**

For each match whose channels equal a token's value, replace it. Examples:

```css
/* account.css — was rgba(31, 122, 77, 0.08) */
background: color-mix(in srgb, var(--bl-success) 8%, var(--bl-white));

/* account.css — was rgba(138, 90, 0, 0.08) */
background: color-mix(in srgb, var(--bl-warning) 8%, var(--bl-white));

/* homepage.css — was rgba(116, 192, 225, 0.08) */
background: color-mix(in srgb, var(--bl-ice) 8%, var(--bl-white));

/* woocommerce.css — was rgb(19 35 67 / 12%) */
border-color: color-mix(in srgb, var(--bl-ink) 12%, transparent);
```

Leave the two `style.css` shadows as `rgba()` — a shadow over an unknown ground genuinely needs alpha, and `color-mix(..., transparent)` is not equivalent for compositing. Instead add a comment recording that their channels mirror `--bl-ink` deliberately.

Note the semantic difference: `rgba(r,g,b,0.08)` composites over whatever is behind it, whereas `color-mix(..., var(--bl-white))` produces an opaque colour. For the notice tints these are equivalent because the ground is always white — and the opaque form is what makes them *measurable* by Task 8's rules. Where the ground is not white, use `transparent` as the second operand to preserve compositing.

- [ ] **Step 3: Build and compare visually**

```bash
cd themes/blueline && npm run build && npm run check
```

Expected: all gates pass, 32 contrast checks.

Deploy and compare the account dashboard's paid/unpaid status chips, the homepage "New here?" watermark, and a WooCommerce cart before and after. They must be **visually identical** — this is a refactor.

- [ ] **Step 4: Add a lint rule preventing reintroduction**

Append to `themes/blueline/tools/check-contrast.mjs`, before the exit:

```javascript
/*
 * Finding: four CSS rules hard-coded the RGB channels of a token instead of
 * referencing it, so overriding the token moved the text colour while its
 * background stayed put -- breaking a pairing one of those files claimed in a
 * comment had been "verified by hand". This forbids reintroducing that.
 */
const cssDir = resolve( here, '../assets/src/css' );
const tokenRgbs = new Map();
for ( const [ name, raw ] of styleTokens ) {
	const value = normalizeValue( raw );
	if ( /^#[0-9a-f]{6}$/.test( value ) ) {
		const n = parseInt( value.slice( 1 ), 16 );
		tokenRgbs.set( `${ ( n >> 16 ) & 255 },${ ( n >> 8 ) & 255 },${ n & 255 }`, name );
	}
}

let literalRgba = 0;
for ( const file of readdirSync( cssDir ).filter( ( f ) => f.endsWith( '.css' ) ) ) {
	const src = readFileSync( resolve( cssDir, file ), 'utf8' );
	const re = /rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/g;
	let m;
	while ( ( m = re.exec( src ) ) !== null ) {
		const key = `${ +m[ 1 ] },${ +m[ 2 ] },${ +m[ 3 ] }`;
		if ( tokenRgbs.has( key ) ) {
			console.log(
				`FAIL ${ file } hard-codes the channels of ${ tokenRgbs.get( key ) } -- use color-mix() with the token`
			);
			literalRgba++;
		}
	}
}
if ( literalRgba ) {
	failed += literalRgba;
} else {
	console.log( 'ok   no CSS file hard-codes a token\'s RGB channels' );
}
```

Add `readdirSync` to the `node:fs` import at the top.

- [ ] **Step 5: Verify the lint catches a regression**

```bash
cd themes/blueline && sed -i '1i .bl-lint-probe { color: rgba(19, 35, 67, 0.5); }' assets/src/css/account.css && node tools/check-contrast.mjs | grep -i "hard-code"; sed -i '1d' assets/src/css/account.css && node tools/check-contrast.mjs | tail -2
```

Expected: first command prints a `FAIL ... hard-codes the channels of --bl-ink` line; after removing the probe, the check reports `ok`. Confirm `git diff assets/src/css/account.css` shows only the intended Step 2 changes.

- [ ] **Step 6: Commit**

```bash
git add themes/blueline/assets/src/css/ themes/blueline/style.css themes/blueline/tools/check-contrast.mjs themes/blueline/assets/dist
git commit -m "refactor(blueline): reference tokens instead of hard-coding their RGB

account.css, homepage.css and woocommerce.css each wrote a token's channels as
a literal rgba(). Overriding the token would have moved the foreground while
its background stayed -- and account.css's own comment asserted that pairing
had been verified by hand, which would have quietly stopped being true.

They now use color-mix() with the token. The opaque form is also what makes
these grounds measurable by the rules added in the previous commit.

style.css's two shadows keep rgba() deliberately: a shadow composites over an
unknown ground, so alpha is the correct construct there, and a comment now
records that their channels mirror --bl-ink on purpose.

A new guard check fails the build if any CSS file hard-codes the channels of a
defined token."
```

---

## Task 10: Remove the dead `--bl-team-accent` rule

`--bl-team-accent` is derived, contrast-darkened, escaped and printed on every team page, and read by exactly one CSS rule — `.bl-sp-hero--team .bl-sp-hero__flag` — whose class is emitted **only** by the *player* hero. The team hero renders no flag, so the rule matches nothing.

It is also wrong: the accent is derived against `--bl-paper` but painted on a team-primary fill. For any dark team colour `blueline_darken_to_contrast()` returns the input unchanged, giving **1.00:1** against its own background. Leaving it is leaving a 1.4.3 failure behind a selector someone will later make live.

**Files:**
- Modify: `themes/blueline/assets/src/css/sportspress.css`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing. `blueline_team_color_set()` keeps emitting `accent` — Task 11 decides its fate.

- [ ] **Step 1: Confirm the rule is genuinely dead**

```bash
cd themes/blueline && grep -rn "bl-sp-hero__flag" inc/ sportspress/ assets/src/css/
```

Expected: the class is emitted at `inc/sportspress.php:793` only, and that site must be inside the **player** hero. Read the surrounding function to confirm. If it turns out the team hero *does* emit a flag, **stop** — the rule is live and this becomes a contrast fix, not a deletion.

- [ ] **Step 2: Remove the dead rule**

Delete the `.bl-sp-hero--team .bl-sp-hero__flag { ... }` block at `sportspress.css:771-773`, and replace it with a comment recording why:

```css
/*
 * Deliberately no .bl-sp-hero--team .bl-sp-hero__flag rule.
 *
 * The team hero emits no flag element -- .bl-sp-hero__flag comes only from the
 * PLAYER hero (inc/sportspress.php) -- so the rule that used to sit here
 * matched nothing. It was also unsound: it painted --bl-team-accent, which is
 * derived for contrast against --bl-paper, on top of a --bl-team-primary fill.
 * For any dark team colour blueline_darken_to_contrast() returns its input
 * unchanged, so the chip rendered at 1.00:1 against its own background.
 *
 * If the team hero ever gains division chips, derive a SECOND accent against
 * --bl-team-primary rather than reusing the paper-derived one.
 */
```

- [ ] **Step 3: Confirm nothing else reads the token**

```bash
cd themes/blueline && grep -rn -- "--bl-team-accent" assets/src/ inc/ sportspress/
```

Expected: only `inc/team-colors.php` (which emits it) and the comment just added.

- [ ] **Step 4: Build, gate, deploy**

```bash
cd themes/blueline && npm run build && npm run check && cd ../.. && ./scripts/deploy-theme.sh && ./scripts/smoke-staging.sh
```

Expected: gates pass, smoke 25/25. Load a team page and confirm nothing changed visually — the rule was dead, so nothing should move.

- [ ] **Step 5: Commit**

```bash
git add themes/blueline/assets/src/css/sportspress.css themes/blueline/assets/dist
git commit -m "fix(blueline): remove the dead, unsound team-accent chip rule

.bl-sp-hero--team .bl-sp-hero__flag matched nothing: bl-sp-hero__flag is
emitted only by the player hero, and the team hero renders no flag. So
--bl-team-accent has been derived, darkened, escaped and printed on every team
page while being read by no rendered element.

The rule was also wrong. --bl-team-accent is darkened for contrast against
--bl-paper, but that selector painted it on a --bl-team-primary fill. For any
dark team colour -- navy, maroon, forest green -- blueline_darken_to_contrast()
returns its input unchanged, so the chip would have rendered at 1.00:1 against
its own background the moment the team hero gained a chip.

Removed rather than left latent, with a comment recording that a team-hero chip
needs a second accent derived against the primary, not the paper-derived one."
```

---

## Task 11: `team-colors.php` reads the shared thresholds and reports failure

`inc/team-colors.php` duplicates the contrast thresholds as `BLUELINE_CONTRAST_BODY = 4.5` and `BLUELINE_CONTRAST_LARGE = 3.0`. It is the fourth consumer of a table the spec claims has one source. More seriously, `blueline_readable_foreground()` "returns the better of the two even when neither reaches the requested threshold" — **silently**. A team whose colour has no readable foreground gets unreadable text and nothing reports it.

**Files:**
- Modify: `themes/blueline/inc/team-colors.php`
- Modify: `themes/blueline/tests/TeamColorsTest.php`

**Interfaces:**
- Consumes: `tools/contrast-rules.json`.
- Produces: `blueline_contrast_threshold( string $which ): float` where `$which` is `'body'` or `'large'`; `blueline_readable_foreground()` gains a by-reference `?bool $passes` out-parameter.

- [ ] **Step 1: Write the failing test**

Append to `themes/blueline/tests/TeamColorsTest.php`:

```php
	public function test_thresholds_come_from_the_shared_rules_table(): void {
		$json = json_decode(
			file_get_contents( __DIR__ . '/../tools/contrast-rules.json' ),
			true
		);
		$mins = array_column( $json['rules'], 'min' );

		$this->assertContains( 4.5, $mins, 'the shared table must define a 4.5 body minimum' );
		$this->assertContains( 3.0, $mins, 'the shared table must define a 3.0 large/non-text minimum' );
		$this->assertSame( 4.5, blueline_contrast_threshold( 'body' ) );
		$this->assertSame( 3.0, blueline_contrast_threshold( 'large' ) );
	}

	public function test_readable_foreground_reports_when_neither_option_passes(): void {
		// #808080 has no AA-passing foreground from {ink, paper}: best is ~3.9.
		$passes = null;
		$fg = blueline_readable_foreground( '#808080', $passes );

		$this->assertNotNull( $fg, 'it must still return a colour to render' );
		$this->assertFalse( $passes, 'but it must report that the colour fails' );
	}

	public function test_readable_foreground_reports_success_for_a_usable_colour(): void {
		$passes = null;
		blueline_readable_foreground( '#FFFFFF', $passes );

		$this->assertTrue( $passes );
	}
```

- [ ] **Step 2: Run and confirm it fails**

```bash
cd themes/blueline && ./vendor/bin/phpunit --filter TeamColorsTest
```

Expected: FAIL — `blueline_contrast_threshold()` undefined.

- [ ] **Step 3: Implement**

In `themes/blueline/inc/team-colors.php`, replace the two constants with a resolver:

```php
/**
 * A contrast threshold, read from the shared rule table so PHP and the build
 * guard cannot disagree about what "AA" means.
 *
 * tools/contrast-rules.json is the single contract; this file used to
 * duplicate 4.5 and 3.0 as constants, which made it a fourth place the
 * numbers could drift.
 *
 * @param string $which 'body' (4.5) or 'large' (3.0, also non-text/UI).
 * @return float
 */
function blueline_contrast_threshold( string $which ): float {
	static $cache = null;

	if ( null === $cache ) {
		$cache = array(
			'body'  => 4.5,
			'large' => 3.0,
		);

		$path = BLUELINE_DIR . '/tools/contrast-rules.json';
		if ( is_readable( $path ) ) {
			$json = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $json ) && ! empty( $json['rules'] ) ) {
				$mins = array_filter(
					array_column( $json['rules'], 'min' ),
					'is_numeric'
				);
				if ( $mins ) {
					$cache['body']  = (float) max( $mins );
					$cache['large'] = (float) min( $mins );
				}
			}
		}
	}

	return $cache[ $which ] ?? 4.5;
}
```

Then change `blueline_readable_foreground()` to report:

```php
/**
 * The more readable of ink/paper on the given background.
 *
 * Returns a colour even when NEITHER option reaches the body threshold --
 * something must render -- but now reports that via $passes so the caller can
 * fall back to theme tokens instead of shipping unreadable text. Previously
 * this failure was silent, which is the opposite of what this module exists
 * to do.
 *
 * @param string    $background Hex background.
 * @param bool|null $passes     Out: whether the returned colour clears AA.
 * @return string Hex foreground.
 */
function blueline_readable_foreground( string $background, ?bool &$passes = null ): string {
	$on_ink   = blueline_contrast_ratio( BLUELINE_TOKEN_INK, $background );
	$on_paper = blueline_contrast_ratio( BLUELINE_TOKEN_PAPER, $background );

	$best      = max( $on_ink, $on_paper );
	$passes    = $best >= blueline_contrast_threshold( 'body' );

	return $on_ink >= $on_paper ? BLUELINE_TOKEN_INK : BLUELINE_TOKEN_PAPER;
}
```

Keep `BLUELINE_TOKEN_INK` / `BLUELINE_TOKEN_PAPER` as constants for now — the build guard's mirror check still asserts them by source regex, and converting them to resolvers is P2 work (spec §8), not P0.

Update the existing call sites in `blueline_team_color_set()` to pass and honour `$passes`: when it is `false`, return the "no usable colour" fallback the function already documents at its `:225-227` path.

- [ ] **Step 4: Run and confirm it passes**

```bash
cd themes/blueline && ./vendor/bin/phpunit --filter TeamColorsTest
```

Expected: PASS.

- [ ] **Step 5: Run the whole suite and the guard**

```bash
cd themes/blueline && npm run check
```

Expected: **156** PHPUnit tests (153 + 3), all gates green, 32 contrast checks including the unchanged `team-colors.php` mirror line.

- [ ] **Step 6: Commit**

```bash
git add themes/blueline/inc/team-colors.php themes/blueline/tests/TeamColorsTest.php
git commit -m "fix(blueline): share contrast thresholds, and stop failing silently

inc/team-colors.php duplicated 4.5 and 3.0 as constants, making it a fourth
place the contrast contract could drift from -- the spec's claim that one JSON
table means the guards cannot disagree was false while that duplication stood.
It now reads tools/contrast-rules.json, falling back to the same literals if
the file is unreadable.

More consequentially, blueline_readable_foreground() returned 'the better of
the two even when neither reaches the requested threshold' and said nothing
about it. Roughly 15% of the sRGB cube has no AA-passing foreground from
{ink, paper}, so a team with such a colour got unreadable text from the module
whose entire purpose is preventing that. It now reports pass/fail, and
blueline_team_color_set() falls back to theme tokens when the derivation
cannot produce a readable pair."
```

---

## Task 12: Make the PHP test bootstrap honest

`tests/bootstrap.php` stubs `add_filter` as a **no-op**, `apply_filters` **ignores registered filters entirely**, `wp_kses` **always strips every tag**, and there is no option store at all. Every settings test P1 needs would pass vacuously against these. This is the last P0 item and the one that unblocks P1.

**Files:**
- Modify: `themes/blueline/tests/bootstrap.php`
- Create: `themes/blueline/tests/BootstrapFidelityTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: working `add_filter`/`apply_filters`/`add_action`/`do_action`, and `get_option`/`update_option`/`delete_option` over an in-memory store, resettable via `blueline_test_reset_options()`.

- [ ] **Step 1: Write tests that fail against the current stubs**

Create `themes/blueline/tests/BootstrapFidelityTest.php`:

```php
<?php
/**
 * The stub environment must be faithful enough that a passing test means
 * something. These assertions all failed against the original stubs.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

final class BootstrapFidelityTest extends TestCase {

	protected function setUp(): void {
		blueline_test_reset_options();
	}

	public function test_apply_filters_runs_registered_callbacks(): void {
		add_filter( 'bl_test_hook', static fn( $v ) => $v . '-filtered' );

		$this->assertSame( 'x-filtered', apply_filters( 'bl_test_hook', 'x' ) );
	}

	public function test_filters_run_in_priority_order(): void {
		add_filter( 'bl_test_order', static fn( $v ) => $v . 'b', 20 );
		add_filter( 'bl_test_order', static fn( $v ) => $v . 'a', 10 );

		$this->assertSame( 'ab', apply_filters( 'bl_test_order', '' ) );
	}

	public function test_apply_filters_passes_extra_arguments(): void {
		add_filter( 'bl_test_args', static fn( $v, $extra ) => $v . $extra, 10, 2 );

		$this->assertSame( 'xy', apply_filters( 'bl_test_args', 'x', 'y' ) );
	}

	public function test_options_round_trip(): void {
		$this->assertFalse( get_option( 'bl_missing' ) );
		$this->assertSame( 'fallback', get_option( 'bl_missing', 'fallback' ) );

		update_option( 'bl_thing', array( 'a' => 1 ) );
		$this->assertSame( array( 'a' => 1 ), get_option( 'bl_thing' ) );

		delete_option( 'bl_thing' );
		$this->assertFalse( get_option( 'bl_thing' ) );
	}

	public function test_update_option_runs_the_sanitize_filter(): void {
		add_filter( 'sanitize_option_bl_guarded', static fn( $v ) => strtoupper( (string) $v ) );
		update_option( 'bl_guarded', 'quiet' );

		$this->assertSame( 'QUIET', get_option( 'bl_guarded' ) );
	}

	public function test_wp_kses_post_keeps_allowed_markup(): void {
		$this->assertSame( '<strong>hi</strong>', wp_kses_post( '<strong>hi</strong>' ) );
	}

	public function test_wp_kses_post_strips_scripts(): void {
		$this->assertSame( 'hi', wp_kses_post( '<script>evil()</script>hi' ) );
	}
}
```

- [ ] **Step 2: Run and confirm they fail**

```bash
cd themes/blueline && ./vendor/bin/phpunit --filter BootstrapFidelityTest
```

Expected: FAIL — `blueline_test_reset_options()` undefined, and the filter/option assertions fail.

- [ ] **Step 3: Replace the stubs**

In `themes/blueline/tests/bootstrap.php`, replace the `add_filter`, `apply_filters` and `wp_kses` stubs, and add the option store:

```php
$GLOBALS['bl_test_hooks']   = array();
$GLOBALS['bl_test_options'] = array();

/**
 * Reset the in-memory option store. Call from setUp().
 */
function blueline_test_reset_options(): void {
	$GLOBALS['bl_test_options'] = array();
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['bl_test_hooks'][ $tag ][ $priority ][] = array(
			'cb'   => $callback,
			'args' => $accepted_args,
		);
		ksort( $GLOBALS['bl_test_hooks'][ $tag ] );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		foreach ( $GLOBALS['bl_test_hooks'][ $tag ] ?? array() as $bucket ) {
			foreach ( $bucket as $hook ) {
				$params = array_merge(
					array( $value ),
					array_slice( $args, 0, max( 0, $hook['args'] - 1 ) )
				);
				$value  = call_user_func_array( $hook['cb'], $params );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $tag, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {
		foreach ( $GLOBALS['bl_test_hooks'][ $tag ] ?? array() as $bucket ) {
			foreach ( $bucket as $hook ) {
				call_user_func_array( $hook['cb'], array_slice( $args, 0, $hook['args'] ) );
			}
		}
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['bl_test_options'] )
			? $GLOBALS['bl_test_options'][ $option ]
			: $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		// Mirrors core: register_setting()'s sanitize_option_{$option} filter
		// runs on EVERY write, which is why P1 puts its validator there.
		$value = apply_filters( "sanitize_option_{$option}", $value, $option );
		$GLOBALS['bl_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		unset( $GLOBALS['bl_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $string, $allowed_html = array() ) {
		$allowed = array_keys( is_array( $allowed_html ) ? $allowed_html : array() );
		return $allowed
			? strip_tags( (string) $string, '<' . implode( '><', $allowed ) . '>' )
			: strip_tags( (string) $string );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $string ) {
		return wp_kses(
			$string,
			array_fill_keys(
				array( 'a', 'strong', 'em', 'b', 'i', 'br', 'p', 'span', 'ul', 'ol', 'li', 'code' ),
				array()
			)
		);
	}
}
```

Remove the old no-op `add_filter`, the filter-ignoring `apply_filters`, and the always-stripping `wp_kses`, along with their `phpcs:ignore` comments.

- [ ] **Step 4: Run the new tests**

```bash
cd themes/blueline && ./vendor/bin/phpunit --filter BootstrapFidelityTest
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Run the entire suite — the real risk is here**

```bash
cd themes/blueline && ./vendor/bin/phpunit
```

Expected: **163 tests** (153 + 3 from Task 11 + 7 here), all passing.

`apply_filters` now actually dispatches, so any existing test that registered a filter and silently relied on it being ignored will change behaviour. If any of the original 153 fail, that failure is **information**: the test was asserting against a lie. Fix the test, not the bootstrap.

- [ ] **Step 6: Full gate**

```bash
cd themes/blueline && npm run check
```

Expected: every gate green.

- [ ] **Step 7: Commit**

```bash
git add themes/blueline/tests/
git commit -m "test(blueline): make the stub environment faithful enough to trust

tests/bootstrap.php stubbed add_filter as a no-op, apply_filters ignored every
registered filter, wp_kses stripped all tags unconditionally, and there was no
option store at all. Any test asserting that a filter fires, that a sanitizer
runs on save, or that markup survives escaping would have passed without
exercising anything -- which is exactly the shape of every test P1's settings
layer needs.

Filters now dispatch in priority order with accepted_args honoured, actions
share that machinery, options round-trip through an in-memory store, and
update_option runs sanitize_option_{\$option} the way core does -- the specific
behaviour P1 depends on to guarantee that WP-CLI and JSON import cannot bypass
validation. wp_kses now respects its allowlist.

BootstrapFidelityTest pins all of it, so a future stub cannot quietly go back
to lying.

163 tests passing."
```

---

## Self-Review

**Spec coverage (§5, P0.1–P0.9):**

| Spec item | Task |
|---|---|
| P0.1 `--bl-border-strong` | Task 7 |
| P0.2 focus ring on ink | Task 6 |
| P0.3 split `--bl-focus` | Task 6 |
| P0.4 extend rule table | Tasks 6, 7, 8 |
| P0.5 derived-value rule type | Task 5 |
| P0.6 de-duplicate `rgba()` | Task 9 |
| P0.7 dead `--bl-team-accent` | Task 10 |
| P0.8 CI | Task 1 — **scope-corrected**: no git remote exists, so this is a local aggregate gate plus a pre-commit hook, not GitHub Actions |
| P0.9 test bootstrap | Task 12 |
| §6.1.1 parser hazards (comment `:root`, aliases) | Tasks 2, 3 |
| §7.3 rules to JSON, 11 move / 3 stay in JS | Task 4 |
| §8 `readable_foreground` pass/fail | Task 11 |

**Deliberately deferred to P1/P2, with reasons:** the coverage inverse test ("every *tunable* colour token has ≥1 rule") needs the `tokens.json` manifest that defines *tunable*, which is P2 §7.4 — asserting it now would hard-code a token list that P2 immediately replaces. `BLUELINE_TOKEN_INK`/`_PAPER` → resolvers is P2 §8, and it forces the rewrite of the guard's mirror check; doing it in P0 would break a green gate for no P0 benefit.

**Placeholder scan:** no TBD/TODO; every code step carries real code; every command carries its expected output. Tasks 6, 7 and 9 include mandatory visual verification because they change rendered output.

**Type consistency:** `normalizeValue`, `extractRootTokens`, `resolveToken`, `resolveColorToken` (css-tokens.mjs); `luminance`, `contrastRatio`, `mixSrgb`, `evaluateRule` (contrast.mjs); `blueline_contrast_threshold`, `blueline_readable_foreground`, `blueline_test_reset_options` (PHP) — each defined once and referenced consistently. Rule ids are unique across all three tasks that add rules.

**Verified numbers:** every ratio in this plan (1.49, 1.43, 2.91, 3.35, 2.64, 5.13, 10.17, 1.47, 1.69, 3.18, 3.06, 8.85, 17.92, 4.00, 14.94) was computed against the WCAG formula, not estimated.

## Risks

1. **Tasks 6 and 7 are visible design changes.** The focus indicator changes on every interactive element; the border darkens on every form field. Both need a human to look at the result, and both steps say so.
2. **Task 12 may break existing tests.** Making `apply_filters` real can expose tests that relied on it being inert. That is a finding, not a regression.
3. **Task 4's byte-comparison is the safety net for the whole refactor.** If the diff shows anything beyond the reworded inverse line, stop.
4. **`tools/` must keep shipping.** `deploy-theme.sh` excludes `node_modules vendor tests .git *.map` — `tools/` survives by omission, and P1's PHP validator will read `contrast-rules.json` at runtime. Task 11 already reads it with an `is_readable()` fallback. P1 must make this explicit.
