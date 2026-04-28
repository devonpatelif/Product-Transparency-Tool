# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Industrial Finishes True Business Intelligence (TBI) — Price Transparency Tool**

A customer-facing single-page web application that displays product pricing data, enables price comparisons against customer-reported supplier pricing, and calculates real vs. stated discounts to expose markup inflation. Deployed to industrialfinishes.com.

## Tech Stack

- **Frontend:** Vanilla JS, HTML5, CSS3 — all self-contained in a single HTML file (`index.html`)
- **Backend:** PHP 7.0+ — three endpoints:
  - `api-data.php` — server-side search/filter/paginate of the catalog. **Never ships the full dataset to the browser.** Modes: `?meta=1` for vendor/category/total counts, default for paginated search, `?op=match` (POST) for scanner part-number matching.
  - `api-analyze.php` proxies uploaded invoice/quote images or PDFs to the Claude Vision API and returns structured line items
  - `api-email.php` captures email leads from the gate modal and appends them to `leads.csv`
- **Data:** ~15 MB JSON product catalog (`IVData.json`) with flexible field naming (auto-detected on the server). Normalized + serialized to `.cache/IVData.normalized.php` on first load so subsequent requests skip the parse.
- **Secrets:** `config.local.php` (gitignored) sets `ANTHROPIC_API_KEY` via `putenv()`; `api-analyze.php` reads it via `getenv()`

## Architecture

### Data Flow

1. `index.html` always calls `api-data.php` (no environment branching). The bulk catalog is never sent to the browser.
2. On boot, the front end fetches `?meta=1` once — small payload (~10 KB) with vendor list, category map, and total counts. Used to populate filter dropdowns and the hero stat cards.
3. As the user searches/filters/sorts/paginates, the front end issues `GET api-data.php?q=…&category=…&vendor=…&sort=…&dir=…&limit=…&offset=…`. The server filters the (cached) normalized catalog and returns just the page of matching rows + `total` for pagination.
4. The mobile invoice scanner POSTs extracted line items to `api-data.php?op=match` — three-tier fuzzy matching (exact part #, partial, description-keyword) runs on the server, returning the same shape the front end used to compute locally.
5. Server-side normalization handles flexible JSON field names (`detectKeys()` mirrored from the original front-end logic) and Power BI `TableName[field]` key prefixes. Records with price ≤ 0 are dropped.
6. `selected` (comparison items) lives only on the client; persisted to `sessionStorage` (key: `ifs_sel`).

### Caching & rate limiting

- **Cache:** `loadCatalog()` in `api-data.php` checks APCu first (if available), then a serialized PHP file at `.cache/IVData.normalized.php`. Cold-path (cache miss) decodes the 8 MB JSON, normalizes it, writes the cache atomically. Cache invalidates when `IVData.json`'s mtime changes.
- **Rate limiting:** Per-IP sliding window — file-based state in `.ratelimit/<sha1(ip)>` with a separate `.lock` file. Default: 120 requests / 60 seconds. Returns 429 + `Retry-After` header when exceeded. Both `.cache/` and `.ratelimit/` get auto-generated `.htaccess` deny files; `htaccess-rules.txt` denies them at the parent level too.
- **Memory:** `ini_set('memory_limit', '256M')` at the top of `api-data.php`. PHP arrays carry ~6× the underlying JSON size; the cold-path normalize peaks around 150 MB. Hot path is cheap (cache hit).
- **Windows quirk:** `is_writable()` false-negatives on directories. Both the cache and rate-limit code skip that check and rely on the actual write attempt to fail gracefully.

### Key functions

| Function | Where | Purpose |
|---|---|---|
| `loadCatalog()` | `api-data.php` | APCu → file cache → cold-path JSON parse |
| `normalizeCatalog()` | `api-data.php` | Streaming normalize; frees source rows as it goes |
| `handleSearch()` | `api-data.php` | Filter + sort + paginate |
| `handleMatch()` | `api-data.php` | Three-tier fuzzy match for the scanner |
| `checkRateLimit()` | `api-data.php` | Per-IP sliding-window limiter |
| `loadData()` / `init()` | `index.html` | Boot: fetch `?meta=1`, populate dropdowns/stats, then fetch first page |
| `applyFilters()` / `fetchProducts()` | `index.html` | Trigger a server fetch with current state; sequence number guards against out-of-order responses |
| `render()` | `index.html` | Paginated table — `filtered` is now the current page only |
| `matchExtractedItems()` | `index.html` | POSTs scanner items to `?op=match`; returns matched results |
| `rebuildPanel()` | `index.html` | Savings calculator with markup/discount analysis |
| `exportCSV()` | `index.html` | Full comparison summary export |

### Email Gate (Lead Capture)

A modal in `index.html` blocks three high-value actions until the user submits an email:
- **`compare` trigger:** firing when adding a 2nd item to the comparison panel ([index.html:1444](index.html#L1444))
- **`scan` trigger:** firing when opening the mobile invoice scanner ([index.html:2309, 2347](index.html#L2309))
- **`export` trigger:** firing when clicking the CSV export button ([index.html:1855](index.html#L1855))

Flow: `requireEmail(trigger, onSuccess)` → modal appears with trigger-specific copy from `EMAIL_GATE_COPY` → on submit, email is saved to `localStorage` (key: `ifs_email`) AND fire-and-forget POSTed to `api-email.php` → `onSuccess` callback runs. Once captured, the gate never appears again on that browser. The server stores `timestamp, email, trigger, ip, user_agent` rows in `leads.csv` (CRLF-locked writes, log-injection-safe). UX never blocks on the network call — `keepalive: true` lets it complete after navigation.

### Mobile Invoice Scanner

On mobile (`matchMedia('(max-width: 768px)')`), a scan screen lets users photograph or upload a supplier invoice/quote. Flow: image → `POST api-analyze.php` (multipart `image` field) → Claude Vision extracts line items as JSON → `matchExtractedItems()` cross-references part numbers against the catalog (normalized: stripped of spaces/dashes/dots/slashes and leading zeros, uppercased) → matched items auto-populate the comparison panel.

- PDF uploads are sent as `document` content blocks; images as `image` blocks
- Max upload 10 MB; types: JPEG, PNG, GIF, WebP, PDF
- The extraction prompt lives only in `api-analyze.php`. The browser never calls Claude directly — there is no front-end fallback path that could expose the API key.

### Savings Calculator Logic

The panel computes supplier markup by deriving the supplier's list price from user-entered "Your Pay" and "Your Discount %", then comparing against MSRP. Key thresholds:
- **Overprice:** youPay > MSRP (realDisc < -0.05%)
- **Ripoff:** gap between stated and real discount > 3%
- **Fair:** gap ≤ 1% and not overpriced

### Theming

Dark theme (default, navy/charcoal + lime green accent `#c2d501`) and light theme (forest green `#185641`). Toggle button in top-right. All styles are embedded in `index.html`.

## Development

**Requires PHP** — opening `index.html` directly via `file://` no longer works because the front end always fetches from `api-data.php`. Run `php -S localhost:8000` from the project root, then visit `http://localhost:8000/`.

**Local testing:** All three endpoints (`api-data.php`, `api-analyze.php`, `api-email.php`) skip the origin check when `HTTP_HOST` does not contain `industrialfinishes.com`, so localhost dev works without spoofing headers. To test production-mode origin enforcement, send `Host: industrialfinishes.com` with curl (the rate-limit + origin-check tests in the project history use this pattern).

**Cache + rate-limit dirs:** `.cache/` and `.ratelimit/` are auto-created on first request. They're gitignored implicitly via being dot-prefixed; safe to delete if you need to force a cache rebuild.

**Deployment:** Upload `index.html`, `api-data.php`, `api-analyze.php`, `api-email.php`, `IVData.json`, and `config.local.php` (with production key) to web root. Apply rules from `htaccess-rules.txt` — these block direct public access to `IVData.json`, `leads.csv`, `.cache/`, and `.ratelimit/`. `.gitignore` keeps `config.local.php` and `.env` out of commits — never commit API keys, captured leads, or cache files.

## Security Considerations

- **No bulk catalog in browser.** `api-data.php` always paginates; the only thing visible in DevTools is the current page slice (~25 rows by default). The DevTools "Save as on the JSON request" attack is closed.
- **Origin / referer enforcement.** All three endpoints check origin in production (when `HTTP_HOST` contains `industrialfinishes.com`); skipped on other hostnames so localhost dev works.
- **Rate limiting.** `api-data.php` enforces 120 requests / 60 seconds per IP. Defeats brute-force enumeration scrapers (returns 429 with `Retry-After`).
- **`.htaccess` denies** direct public access to `IVData.json`, `leads.csv`, `.cache/`, and `.ratelimit/`.
- **Lead capture hardening.** `api-email.php` whitelists the trigger value to `[a-z0-9_-]` and length-caps email (254) and user-agent (255) to prevent log injection into `leads.csv`.
- **XSS.** `esc()` handles HTML escaping in search highlighting and tooltips.
- **What "MSRP" means here:** the catalog's MSRP is **manufacturer** suggested retail, not Industrial Finishes' own pricing. The competitively sensitive asset is the *curated catalog* itself (which products IFS sells, vendor relationships, the normalization work) — MSRP in isolation is not a trade secret.

## File Roles

| File | Role |
|------|------|
| `index.html` | Main application — CSS + JS + HTML including desktop table, mobile scan screen, and email gate modal |
| `api-data.php` | Server-side search/filter/paginate + scanner match + rate limit. Sole entry point for catalog data. |
| `api-analyze.php` | Origin-validated Claude Vision proxy for invoice/quote extraction |
| `api-email.php` | Origin-validated lead capture endpoint — appends to `leads.csv` |
| `config.local.php` | Gitignored — sets `ANTHROPIC_API_KEY` via `putenv()` |
| `IVData.json` | Product catalog source of truth (manufacturer MSRP). **Server-only**, never sent to browser. |
| `.cache/IVData.normalized.php` | Auto-generated serialized normalized catalog (rebuilt when `IVData.json` mtime changes). Safe to delete. |
| `.ratelimit/<hash>` | Auto-generated per-IP request-timestamp files. Safe to delete to reset limits. |
| `leads.csv` | Captured email leads — created at runtime, blocked by `.htaccess`, do not commit |
| `htaccess-rules.txt` | Apache security rules for deployment (blocks `IVData.json`, `leads.csv`, `.cache/`, `.ratelimit/`) |
