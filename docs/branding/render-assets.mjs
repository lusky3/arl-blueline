// Renders the wp-admin artwork: the theme's screenshot.png and the plugin's icon/banner.
//
//   node docs/branding/render-assets.mjs
//
// Needs playwright-core (themes/blueline devDependency) and a Chromium; set CHROMIUM to its
// path if Playwright's default is not installed. Fonts are the theme's own (Barlow Condensed,
// Inter), read from themes/blueline/assets/fonts, so the art matches the site. Colours are the
// brand tokens from themes/blueline/style.css. Output is committed; re-run only to change it.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const THEME = path.join(ROOT, 'themes/blueline');
const PLUGIN = path.join(ROOT, 'plugins/blueline-core');
const req = createRequire(path.join(THEME, 'package.json'));
const { chromium } = req('playwright-core');

// The maple-leaf silhouette used for the brand device (viewBox -2015 -2000 4030 4030).
const LEAF = fs.readFileSync(new URL('./maple-leaf-path.txt', import.meta.url), 'utf8').trim();

const C = { ink: '#132343', deep: '#0D1729', mid: '#2E4A74', steel: '#5188B7', ice: '#74C0E1', pale: '#9ACDE7', paper: '#F7FBFC' };
const font = (file) => pathToFileURL(path.join(THEME, 'assets/fonts', file)).href;

const FONT_CSS = `
@font-face{font-family:'Barlow Condensed';font-weight:800;font-style:italic;src:url('${font('barlow-condensed-800italic.woff2')}')}
@font-face{font-family:'Barlow Condensed';font-weight:700;font-style:italic;src:url('${font('barlow-condensed-700italic.woff2')}')}
@font-face{font-family:'Barlow Condensed';font-weight:600;font-style:normal;src:url('${font('barlow-condensed-600.woff2')}')}
@font-face{font-family:'Inter';font-weight:100 900;src:url('${font('inter-variable.woff2')}')}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:100%;height:100%;overflow:hidden}
`;

const leaf = (fill, size, style = '') => `<svg viewBox="-2015 -2000 4030 4030" width="${size}" height="${size}" style="${style}" aria-hidden="true"><path fill="${fill}" d="${LEAF}"/></svg>`;
const rings = (stroke, size, style = '') => `<svg viewBox="0 0 400 400" width="${size}" height="${size}" style="${style}" fill="none" stroke="${stroke}" stroke-width="7"><circle cx="200" cy="200" r="190"/><circle cx="200" cy="200" r="122"/><circle cx="200" cy="200" r="22" fill="${stroke}" stroke="none"/></svg>`;

// ---- theme screenshot (WordPress shows 4:3; 1200x900 is the documented size) ----
const screenshot = `<style>${FONT_CSS}
body{background:${C.deep};font-family:Inter,sans-serif;position:relative}
.bar{position:absolute;inset:0 0 auto 0;height:118px;background:${C.deep};border-bottom:14px solid ${C.ice};display:flex;align-items:center;padding:0 64px;gap:22px}
.bar b{font:800 italic 46px 'Barlow Condensed';color:#fff;letter-spacing:.02em}
.bar i{margin-left:auto;font:700 italic 26px 'Barlow Condensed';color:${C.ink};background:${C.ice};padding:8px 26px;transform:skew(-12deg);text-transform:uppercase}
.hero{position:absolute;inset:132px 0 0 0;background:linear-gradient(160deg,${C.ink},${C.deep} 70%)}
.t{position:absolute;left:64px;top:228px;color:#fff}
.t small{display:inline-block;font:700 italic 26px 'Barlow Condensed';color:${C.ink};background:${C.ice};padding:6px 22px;transform:skew(-12deg);text-transform:uppercase;letter-spacing:.04em}
.t h1{font:800 italic 200px/0.92 'Barlow Condensed';text-transform:uppercase;margin-top:26px;letter-spacing:-.005em}
.t h1 span{color:${C.ice}}
.t p{font:500 30px/1.4 Inter;color:${C.pale};margin-top:30px;max-width:520px}
.card{position:absolute;right:64px;bottom:72px;width:420px;background:${C.paper};border-radius:16px;padding:30px 34px;box-shadow:0 18px 50px rgba(0,0,0,.4)}
.card h3{font:800 italic 34px 'Barlow Condensed';color:${C.ink};text-transform:uppercase}
.card h3:after{content:'';display:block;width:56px;height:6px;background:${C.ice};margin-top:8px}
.teams{display:flex;align-items:center;justify-content:space-between;margin:22px 0 18px}
.teams span{width:70px;height:70px;border-radius:50%;background:#fff;border:3px solid ${C.steel};display:grid;place-items:center}
.teams em{font:700 italic 22px 'Barlow Condensed';color:${C.mid};text-transform:uppercase}
.clock{display:flex;gap:26px;justify-content:center}
.clock div{text-align:center;font:800 italic 48px 'Barlow Condensed';color:${C.ink}}
.clock small{display:block;font:700 15px Inter;color:${C.mid};letter-spacing:.06em;font-style:normal;margin-top:-2px}
</style>
<div class="hero"></div>
${rings(C.ice, 760, 'position:absolute;right:-170px;top:60px;opacity:.13')}
${leaf(C.ice, 520, 'position:absolute;right:34px;top:150px;opacity:.12')}
<div class="bar">${leaf(C.ice, 60)}<b>A.R.L</b><i>Register to play</i></div>
<div class="t"><small>Theme for the A.R.L.</small><h1>Blue<span>line</span></h1><p>Schedules, standings and registration for a recreational hockey league.</p></div>
<div class="card"><h3>The next puck drop</h3>
<div class="teams"><span>${leaf(C.steel, 40)}</span><em>vs</em><span>${leaf(C.mid, 40)}</span></div>
<div class="clock"><div>00<small>DAYS</small></div><div>21<small>HRS</small></div><div>58<small>MINS</small></div><div>08<small>SECS</small></div></div></div>`;

// ---- plugin icon (256 and 128; ice ground so it reads apart from the theme's navy) ----
const icon = (size) => `<style>${FONT_CSS}body{background:${C.ice}}
.w{position:absolute;inset:0;display:grid;place-items:center}
.k{position:absolute;left:0;right:0;bottom:${size * 0.075}px;text-align:center;font:800 italic ${size * 0.15}px 'Barlow Condensed';color:${C.ink};letter-spacing:.12em;text-transform:uppercase}
</style>
<div class="w">${rings(C.ink, size * 0.97, 'position:absolute;opacity:.16')}${leaf(C.ink, size * 0.56, `margin-top:-${size * 0.04}px`)}</div>
<div class="k">core</div>`;

// ---- plugin banner (laid out at 772x250; the 1544x500 file is the same layout at 2x) ----
// WordPress draws the plugin's name in a dark box over the bottom-left corner of the banner, so
// that corner stays empty: wordmark and tagline sit top-left, the leaf and rings to the right.
const banner = `<style>${FONT_CSS}
body{background:linear-gradient(110deg,${C.deep},${C.ink} 75%);position:relative}
h1{position:absolute;left:34px;top:26px;white-space:nowrap;font:800 italic 46px/1 'Barlow Condensed';color:#fff;text-transform:uppercase}
h1 span{color:${C.ice}}
p{position:absolute;left:36px;top:80px;font:500 13px Inter;color:${C.pale}}
.bar{position:absolute;left:0;right:0;bottom:0;height:6px;background:${C.ice}}
</style>
${rings(C.ice, 330, 'position:absolute;right:20px;top:-40px;opacity:.12')}
${leaf(C.ice, 150, 'position:absolute;right:110px;top:50%;transform:translateY(-50%)')}
<h1>Blueline <span>Core</span></h1><p>League features that stay with you when the theme changes.</p><div class="bar"></div>`;

const jobs = [
	[screenshot, 1200, 900, 1, path.join(THEME, 'screenshot.png')],
	[icon(256), 256, 256, 1, path.join(PLUGIN, 'assets/icon-256x256.png')],
	[icon(128), 128, 128, 1, path.join(PLUGIN, 'assets/icon-128x128.png')],
	[banner, 772, 250, 2, path.join(PLUGIN, 'assets/banner-1544x500.png')],
	[banner, 772, 250, 1, path.join(PLUGIN, 'assets/banner-772x250.png')],
];

const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {});
fs.mkdirSync(path.join(PLUGIN, 'assets'), { recursive: true });
for (const [html, w, h, scale, out] of jobs) {
	const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: scale });
	const page = await ctx.newPage();
	const tmp = path.join(ROOT, 'docs/branding', '.render.html');
	fs.writeFileSync(tmp, `<!doctype html><meta charset="utf-8">${html}`);
	await page.goto(pathToFileURL(tmp).href);
	await page.evaluate(() => document.fonts.ready);
	await page.screenshot({ path: out });
	await ctx.close();
	console.log('wrote', path.relative(ROOT, out), `${w * scale}x${h * scale}`);
}
fs.rmSync(path.join(ROOT, 'docs/branding', '.render.html'), { force: true });

// Vector icon (no fonts needed): same device as the PNGs, without the wordmark.
const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256"><rect width="256" height="256" fill="${C.ice}"/><g fill="none" stroke="${C.ink}" stroke-width="3" opacity=".16"><circle cx="128" cy="128" r="122"/><circle cx="128" cy="128" r="78"/></g><circle cx="128" cy="128" r="14" fill="${C.ink}" opacity=".16"/><svg x="56" y="46" width="144" height="144" viewBox="-2015 -2000 4030 4030"><path fill="${C.ink}" d="${LEAF}"/></svg></svg>\n`;
fs.writeFileSync(path.join(PLUGIN, 'assets/icon.svg'), svg);
console.log('wrote plugins/blueline-core/assets/icon.svg');
await browser.close();
