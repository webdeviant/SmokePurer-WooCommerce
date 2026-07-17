# SmokePurer Sync for WooCommerce

Automatically imports the SmokePurer dropship product feeds into your WooCommerce store and keeps stock levels up to date — hands-free.

> ### ⚠️ Requires PHP 8.3 or higher
> This plugin will not run on older PHP versions. See [Requirements](#requirements).

---

## Project Overview

SmokePurer is a vape/e-liquid dropship supplier. They publish their catalogue and live stock as a set of CSV files (feeds) but do **not** provide a plugin to get that data into your shop. Doing it by hand — thousands of products, prices, images, and stock levels that change every few minutes — is not realistic.

**SmokePurer Sync does it for you.** Once installed and configured, it quietly runs in the background and:

- Creates and updates your products from the supplier's catalogue.
- Keeps stock levels accurate by checking the supplier's live stock feed every few minutes.
- Adds your markup to the supplier's trade (cost) prices automatically.
- Handles pre-order ("coming soon") products, sale prices, product images, brands, categories, weights, and discontinued lines.

### Key features

- **Fast, accurate stock sync** — checks the supplier's stock feed on a short schedule (every 5 minutes by default) and only touches the products whose stock actually changed, so it stays quick even with thousands of products.
- **Full catalogue import** — builds both simple products and variable products (e.g. an e-liquid available in 10mg and 20mg) automatically.
- **Automatic pricing** — turns the supplier's trade price into your retail price using a markup you set, with tidy "charm" pricing (e.g. £8.99).
- **Safety first** — if a supplier feed is broken or arrives half-downloaded, the plugin refuses to apply it rather than wiping your stock or prices.
- **Pre-orders** — "coming soon" products are listed as available on back-order rather than showing a fake stock number.
- **Runs itself** — no manual imports; everything is scheduled and self-healing.

### Benefits

- Saves hours of manual data entry and constant stock checking.
- Reduces overselling by keeping stock close to the supplier's live figures.
- Protects your shop from bad supplier data with built-in safety checks.
- One place to control your markup, categories, and how products go live.

### Intended use cases

- A WooCommerce shop reselling SmokePurer's dropship range.
- Any store that wants a resilient, scheduled CSV-feed importer as a starting point (the feed URLs and column names are configurable).

---

## Requirements

| Requirement | Minimum | Notes |
|---|---|---|
| **PHP** | **8.3** | **Required.** The plugin will not activate on older PHP. |
| WordPress | 6.4 | Validated against the current release. |
| WooCommerce | 8.0 | Validated against WooCommerce 10.9. Must be installed and active. |
| Action Scheduler | Bundled with WooCommerce | Used to run the scheduled jobs. No separate install needed. |
| Outbound HTTPS | — | Your server must be able to fetch the supplier's feed URLs. |

There are **no external libraries or Composer dependencies** — the plugin uses only WordPress and WooCommerce.

---

## Installation

1. Download the release ZIP (`SmokePurer-WooCommerce-1.0.0.zip`).
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, and click **Install Now**.
   *(Or copy the `smokepurer-sync` folder into `wp-content/plugins/`.)*
3. Click **Activate**.
4. Go to **WooCommerce → SmokePurer Sync → Settings** and set your **Markup %** and category mapping (see [Configuration](#configuration)).
5. On the **Dashboard** tab, click **Run now** next to *Catalogue import* to bring in products, then **Run now** next to *Stock sync*.

### For reliable timing (recommended)

WordPress's built-in scheduler (WP-Cron) only runs when someone visits your site, which is not dependable for a 5-minute stock job. For accurate timing, disable WP-Cron and drive the scheduler from a real server cron job:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```bash
# server crontab — every minute
* * * * * wp action-scheduler run --quiet >/dev/null 2>&1
```

---

## Configuration

All settings live under **WooCommerce → SmokePurer Sync → Settings**. Everything is explained in plain English below.

### Pricing

**Markup %**
- **What it does:** How much to add on top of the supplier's trade (cost) price to get your selling price. `100` means "double the cost". `50` means "add half".
- **Default:** `100`
- **Recommended:** Whatever margin your business needs. Start around `100` and adjust.
- **Example:** A £4.50 trade price with `100%` markup becomes £9.00, then rounded to £9.99 (see rounding below).
- **⚠️ Important:** If you leave this at `0`, products import at cost and you make no money. The dashboard warns you if markup is 0.
- **Note on VAT:** The price produced here is handed to WooCommerce as your regular price. Whether that number includes or excludes VAT is controlled by **WooCommerce → Settings → Tax → "Prices entered with tax"**. Set that once for your shop.

**Price rounding**
- **What it does:** Tidies the final price so it ends in a nice figure. *Charm .99* makes every price end in `.99` within the same pound (rounding up).
- **Default:** `Charm .99`
- **Options:** Charm .99, Charm .95, Nearest 5p, Nearest 10p, None (exact).
- **Example:** £9.02 with *Charm .99* becomes £9.99; £14.00 becomes £14.99.

**Margin guard**
- **What it does:** A safety net so rounding can never drop your price to or below the supplier's cost.
- **Default:** On
- **Recommended:** Leave on.

### Product build

**New products**
- **What it does:** Whether brand-new products appear as **Draft** (hidden until you review them) or **Publish** (live immediately).
- **Default:** `Draft`
- **Recommended:** `Draft`. Vape products are age-restricted and legally regulated (TPD/nicotine), so reviewing new lines before they go live is safer.

**Variation attribute label**
- **What it does:** Variable products (like an e-liquid in several strengths) need an attribute name. The feed only gives the value (e.g. "20MG"), so this is the label customers see it under.
- **Default:** `Options`
- **Example:** Set to `Nicotine Strength` and the product page shows a "Nicotine Strength" dropdown.

**Images**
- **What it does:** Whether to download and attach product images from the supplier.
- **Default:** On
- **Note:** The first full image import downloads thousands of images and can take a while; images already imported are skipped on later runs. If a few images fail (the supplier's server can throttle a big burst), later runs quietly retry them.

**Image download throttle / retries / backoff**
- **What it does:** Controls how gently images are downloaded. **Throttle** is a short pause (in milliseconds) before each image download so a big burst doesn't get rate-limited by the supplier's server. **Retries** is how many times a failed download is re-attempted. **Backoff** is the wait (in milliseconds) between retries, which doubles each time.
- **Defaults:** 200 ms throttle · 2 retries · 1000 ms backoff.
- **Recommended:** The defaults suit a large one-off import. Set the throttle to `0` to disable it if your supplier doesn't rate-limit. A genuine "not found" (404) is never retried; only timeouts and temporary errors are.
- **Note:** Higher throttle = gentler on the supplier but a slower import.

**Categories (auto-create)**
- **What it does:** If a category in your mapping doesn't exist yet, create it automatically.
- **Default:** On

**Fallback category**
- **What it does:** Where products go when their supplier category isn't in your mapping.
- **Default:** `Uncategorised`

**Category mapping**
- **What it does:** The supplier's category names are messy and inconsistent (e.g. "Eliquid Nic Salt", "E-liquid Nic Salt"). This maps them to your own tidy categories. One rule per line: `Supplier Category = Your Category`. Matching ignores upper/lower case and the "e-liquid / eliquid" spelling differences.
- **Default:** Empty (everything goes to the fallback category)
- **Example:**
  ```
  Eliquid Nic Salt = Nic Salt E-Liquids
  Open Pod - Pod Kits = Pod Kits
  ```

### Retirement (discontinued products)

**When a SKU is disabled**
- **What it does:** What to do when the supplier marks a product as discontinued: set it to **Draft** (hidden) or **Out of stock** (visible but unbuyable).
- **Default:** `Draft`

**Retirement safety cap**
- **What it does:** Refuses to retire more than this share of your catalogue in a single run — a guard against a bad "disabled" feed hiding your whole shop.
- **Default:** `20%`

### Schedules & safety

**Stock sync every / Catalogue import every / Reconcile every**
- **What they do:** How often each job runs (in seconds).
- **Defaults:** Stock `300` (5 min), Catalogue `3600` (1 hour), Reconcile `86400` (daily).
- **Recommended:** Keep stock at 300 — the supplier recommends checking every 5 minutes.

**Circuit-breaker**
- **What it does:** Aborts a run if a feed suddenly shrinks by more than this percentage compared to the last good download — this is what stops a broken/half-downloaded feed from wiping your stock or prices.
- **Default:** `15%`

**Enabled**
- **What it does:** Master on/off switch for all scheduled jobs.
- **Default:** On

### Feed URLs

The seven supplier feed URLs (catalogue, coming-soon, stock, images, tags, weights, disabled list). These come pre-filled with SmokePurer's addresses and rarely need changing.

---

## Features (in detail)

### The two-speed design

The plugin runs **two independent jobs** because the data changes at very different speeds:

- **Stock sync (fast, every 5 min)** — reads only the small `SKU,Quantity` feed, compares it to the last download, and writes stock **only** for the products that changed. It never rewrites product data, so it stays fast at thousands of SKUs.
- **Catalogue import (slow, hourly)** — builds and updates the products themselves (names, prices, descriptions, images, categories, variations). It never overwrites stock on an existing product — stock is owned solely by the stock-sync job.

### Product building

- **Simple and variable products** are created from a single flat feed. Rows that share a "Parent SKU" become one variable product with variations.
- **Trade → retail pricing** with your markup, charm rounding, and a margin guard.
- **Sale prices** are applied when present and **actively cleared** when they disappear from the feed (because the feed is a full snapshot each time).
- **Pre-orders**: the supplier uses a special stock value of `90000` to mean "coming soon". The plugin turns that into a proper WooCommerce back-order state instead of showing 90,000 in stock.
- **Weights** are converted from grams into your store's weight unit.
- **Brands** are assigned to WooCommerce's native brand taxonomy when available.
- **Tags** ("Sale", "End of Line") are normalised and attached.

### Built-in safety

- **Download validation** — every feed is checked (proper HTTP response, not an error page, correct header row) before a single row is used.
- **Circuit-breaker** — a run is aborted if a feed's row count collapses versus the last good download.
- **Strict column matching** — columns are matched by name, and the expected columns are verified, so a reordered or renamed feed column fails loudly instead of corrupting data.
- **Resilient importing** — the catalogue import runs in small batches that pick up where they left off, so it never times out, and it recovers automatically if a batch is interrupted.
- **Graceful failures** — a missing image or bad row is logged and skipped; it never halts the import.

### Monitoring

The **Dashboard** shows each job's last result, when it last ran, when it runs next, and the "last good" row counts used by the circuit-breaker. Detailed logs are under **WooCommerce → Status → Logs** (source `smokepurer-sync`), and scheduled runs under **WooCommerce → Status → Scheduled Actions** (group `smokepurer-sync`).

---

## Usage Examples

**First-time setup**

1. Set **Markup %** to `100` and add a few category mappings.
2. **Dashboard → Run now** on *Catalogue import*. Watch the products appear (a large catalogue is processed in the background in batches).
3. **Dashboard → Run now** on *Stock sync* to pull in live stock.
4. Review the new **Draft** products, then publish the ones you want live.

**Changing your margin later**

1. **Settings → Markup %**, change the value, save.
2. The next catalogue run re-prices every product. (To apply immediately, use **Run now**.)

**Renaming the variation label**

Set **Variation attribute label** to `Nicotine Strength` and re-run the catalogue import; variable products will show a "Nicotine Strength" selector.

---

## Plugin Structure

```
smokepurer-sync/
├── smokepurer-sync.php              Main plugin file (header, constants, bootstrap)
├── uninstall.php                    Cleanup on delete
├── readme.txt                       WordPress-style readme
├── README.md                        This file
├── changelog.txt                    Public changelog
├── assets/
│   └── admin.css                    Admin dashboard styling
└── includes/
    ├── class-sps-plugin.php         Orchestrator + scheduling
    ├── class-sps-settings.php       Settings store + defaults
    ├── class-sps-admin.php          Dashboard + settings UI
    ├── class-sps-logger.php         Logging + run-status tracking
    ├── class-sps-lock.php           Cooperative locking
    ├── class-sps-feed-client.php    Feed download, validation, circuit-breaker
    ├── class-sps-csv.php            RFC-4180 CSV parsing + schema checks
    ├── class-sps-price.php          Trade → retail price transformation
    ├── class-sps-categories.php     Category mapping
    ├── class-sps-images.php         Image sideloading with de-duplication
    ├── class-sps-catalogue-importer.php  Catalogue import (products + variations)
    ├── class-sps-stock-sync.php     Fast stock sync
    └── class-sps-reconciler.php     Retirement of discontinued lines
```

---

## Frequently Asked Questions

**Do I need any other plugins?**
Only WooCommerce (which bundles Action Scheduler). No Composer or external libraries.

**Will it overwrite prices I set by hand?**
The catalogue import manages price, stock, category, and the variation/brand attributes it owns. It preserves extra categories and custom attributes you add. If you want full manual control of a product, that product should not be managed by the feed.

**How does it handle the supplier changing stock every few minutes?**
The stock-sync job pulls the live stock feed on a short schedule and updates only what changed. Set it to 5 minutes (the supplier's recommendation).

**What happens to discontinued products?**
Products listed in the supplier's "disabled" feed are set to Draft (or Out of stock), governed by a safety cap so a bad feed can't hide your whole shop.

**Why are my new products in Draft?**
That's the default, so you can review age-restricted/regulated products before they go live. Change it in Settings if you prefer.

**Some product images are missing after the first import — why?**
A large image import can trigger the supplier's server to throttle requests, so a few images are skipped (and logged). The plugin only fetches missing images on each run, so subsequent runs fill the gaps.

---

## Troubleshooting

**Products imported at cost / far too cheap.**
Your **Markup %** is `0` (or too low). Set it in Settings. The dashboard shows a warning when markup is 0.

**Prices look off by 20%.**
Check **WooCommerce → Settings → Tax → "Prices entered with tax"** — it decides whether your prices include or exclude VAT.

**A job shows an error like "Row count dropped… aborting".**
That's the circuit-breaker doing its job — the feed came back much smaller than usual (often a temporary supplier issue). It will retry on the next scheduled run; no action needed unless it persists.

**Stock isn't updating.**
Confirm the plugin is **Enabled**, WooCommerce is active, and the scheduler is running (see the "reliable timing" note under Installation). Check the next-run times on the Dashboard.

**Nothing runs on schedule.**
WP-Cron only fires on site traffic. For a low-traffic site, set up a real server cron as shown in [Installation](#installation).

**Where are the detailed logs?**
**WooCommerce → Status → Logs**, source `smokepurer-sync`.

---

## Changelog

## 1.0.1

- Added image download **throttling and retry with exponential backoff** so a large image import doesn't get rate-limited by the supplier's server.
- **Security hardening:** feed-supplied image URLs are now validated against internal/loopback/reserved hosts before download (SSRF protection), and the host guard blocks IPv6-literal and numeric-encoded IP bypasses and resolves hostnames.
- **Compatibility:** verified clean on PHP 8.3, 8.4 and 8.5 (fixed a PHP 8.4+ `fgetcsv()` deprecation).

## 1.0.0

Initial public release.

---

## License

This plugin is licensed under the **GNU General Public License v2.0 or later** (GPL-2.0-or-later), the same license as WordPress and WooCommerce.

See <https://www.gnu.org/licenses/gpl-2.0.html> for the full license text.
