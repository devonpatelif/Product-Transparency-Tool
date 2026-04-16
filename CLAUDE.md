# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Industrial Finishes True Business Intelligence (TBI) — Price Transparency Tool**

A customer-facing single-page web application that displays product pricing data, enables price comparisons against customer-reported supplier pricing, and calculates real vs. stated discounts to expose markup inflation. Deployed to industrialfinishes.com.

## Tech Stack

- **Frontend:** Vanilla JS, HTML5, CSS3 — all self-contained in a single HTML file (`index.html`)
- **Backend:** PHP 7.0+ — two endpoints, both using the same origin-validation pattern:
  - `api-data.php` serves `IVData.json` to the catalog table
  - `api-analyze.php` proxies uploaded invoice/quote images or PDFs to the Claude Vision API and returns structured line items
- **Data:** ~8MB JSON product catalog (`IVData.json`) with flexible field naming (auto-detected at runtime)
- **Secrets:** `config.local.php` (gitignored) sets `ANTHROPIC_API_KEY` via `putenv()`; `api-analyze.php` reads it via `getenv()`
- **Power BI:** `IFS Products.pbix` is the data source; MCP server configured for Power BI modeling operations

## Architecture

### Data Flow

1. `index.html` detects environment: production loads via `api-data.php`, dev loads `IVData.json` directly
2. `detectKeys()` auto-detects JSON field names (supports multiple naming conventions per field)
3. `normalize()` converts raw records to a standard 10-field format
4. Records with price ≤ 0 are filtered out
5. Three global arrays manage state: `ALL` (full dataset), `filtered` (current view), `selected` (comparison items)
6. `sessionStorage` (key: `ifs_sel`) persists user selections across page reloads

### Key Functions

| Function | Purpose |
|----------|---------|
| `detectKeys()` | Flexible JSON field name mapping |
| `normalize()` | Raw record → standard object |
| `applyFilters()` | Real-time search + category/vendor filtering |
| `doSort()` | Column sorting with direction toggle |
| `render()` | Paginated table with row animations |
| `rebuildPanel()` | Savings calculator with markup/discount analysis |
| `exportCSV()` | Full comparison summary export |

### Mobile Invoice Scanner

On mobile (`matchMedia('(max-width: 768px)')`), a scan screen lets users photograph or upload a supplier invoice/quote. Flow: image → `POST api-analyze.php` (multipart `image` field) → Claude Vision extracts line items as JSON → `matchExtractedItems()` cross-references part numbers against the catalog (normalized: stripped of spaces/dashes/dots/slashes and leading zeros, uppercased) → matched items auto-populate the comparison panel.

- PDF uploads are sent as `document` content blocks; images as `image` blocks
- Max upload 10 MB; types: JPEG, PNG, GIF, WebP, PDF
- The extraction prompt is duplicated in both `api-analyze.php` and `index.html` (`SCAN_PROMPT`) — keep them in sync when editing

### Savings Calculator Logic

The panel computes supplier markup by deriving the supplier's list price from user-entered "Your Pay" and "Your Discount %", then comparing against MSRP. Key thresholds:
- **Overprice:** youPay > MSRP (realDisc < -0.05%)
- **Ripoff:** gap between stated and real discount > 3%
- **Fair:** gap ≤ 1% and not overpriced

### Theming

Dark theme (default, navy/charcoal + lime green accent `#c2d501`) and light theme (forest green `#185641`). Toggle button in top-right. All styles are embedded in `index.html`.

## Development

**No build step required.** Open `index.html` in a browser. It auto-detects non-production environment and loads `IVData.json` directly.

**Local scanner testing:** `api-analyze.php` skips the origin check when `HTTP_HOST` does not contain `industrialfinishes.com`, so it works against `localhost`. Requires a PHP server (`php -S localhost:8000`) and `config.local.php` present with a valid `ANTHROPIC_API_KEY`.

**Deployment:** Upload `index.html`, `api-data.php`, `api-analyze.php`, `IVData.json`, and `config.local.php` (with production key) to web root. Apply rules from `htaccess-rules.txt` to block direct JSON access. `.gitignore` keeps `config.local.php` and `.env` out of commits — never commit API keys.

## Security Considerations

- `api-data.php` restricts data access to requests originating from `industrialfinishes.com` only
- `htaccess-rules.txt` blocks direct public access to `IVData.json`
- `esc()` helper handles HTML escaping to prevent XSS in search highlighting
- Pricing data is sensitive — do not expose raw JSON endpoints without origin validation

## File Roles

| File | Role |
|------|------|
| `index.html` | Main application — CSS + JS + HTML including desktop table and mobile scan screen |
| `api-data.php` | Origin-validated data proxy for the catalog |
| `api-analyze.php` | Origin-validated Claude Vision proxy for invoice/quote extraction |
| `config.local.php` | Gitignored — sets `ANTHROPIC_API_KEY` via `putenv()` |
| `IVData.json` | Product catalog (source of truth for MSRP) |
| `htaccess-rules.txt` | Apache security rules for deployment |
| `.mcp.json` | Power BI Modeling MCP server config |
| `Testing/` | Light-theme prototype and test data |
| `Backup/` | Previous versions for rollback reference |
