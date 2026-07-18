=== SmokePurer Sync for WooCommerce ===
Contributors: e-liquids.uk
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.3
WC requires at least: 8.0
WC tested up to: 10.9
Stable tag: 1.0.4
License: GPLv2 or later

Imports the SmokePurer dropship CSV feeds into WooCommerce products and keeps
stock in sync from the 5-minute quantity feed. No external dependencies.

== How it works ==

Three decoupled jobs, all run by WooCommerce's built-in Action Scheduler:

1. Stock sync (default every 5 min)
   Reads ONLY the SKU,Quantity feed, diffs against the previous pull and writes
   only the SKUs whose quantity changed, via wc_update_product_stock(). This is
   what makes a real 5-minute cadence affordable at ~6,000 SKUs. It never touches
   product data. The 90000 sentinel is mapped to a pre-order (backorder) state.

2. Catalogue import (default hourly)
   Builds/updates simple and variable products from the descriptions +
   coming-soon feeds, joined with the weights, images and tags feeds. It runs in
   small batches (a self-rescheduling Action Scheduler cursor) so it never hits a
   PHP timeout. It writes product DATA only and never overwrites stock on an
   existing product (stock is owned by job #1). Trade prices are converted to
   retail via your markup + rounding rules; a vanished sale price is actively
   cleared; new products are created as Draft by default for a compliance review.

3. Reconcile (default daily)
   Retires products listed in the "disabled last 7 days" delta feed (Draft or
   Out of stock). A safety cap aborts if it would retire an implausible share of
   the catalogue at once.

== Safety rails ==

* Every full-snapshot feed is validated (HTTP OK, not an HTML error page, SKU
  header present) before a single row is parsed.
* A circuit-breaker aborts a run if a feed's row count collapses vs the last good
  pull, so a truncated download can't zero the catalogue's stock or prices.
* Columns are matched strictly by header NAME, and the expected header set is
  asserted up front, so a reordered/renamed feed column fails loudly.
* CSV parsing is RFC-4180 aware (handles the multi-line HTML in the Description
  column and strips a UTF-8 BOM).

== Installation ==

1. Zip the `smokepurer-sync` folder and upload via Plugins > Add New > Upload,
   or copy the folder to wp-content/plugins/. Activate.
2. Go to WooCommerce > SmokePurer Sync > Settings.
3. Set your Markup % (IMPORTANT — 0% imports at cost), rounding, and category
   mapping. Confirm the feed URLs.
4. Decide your WooCommerce Tax setting ("Prices entered with tax") — the markup
   result is interpreted according to it.
5. Use "Run now" on the Dashboard to trigger the first catalogue import, then the
   first stock sync. Watch progress on the Dashboard; details in WooCommerce >
   Status > Logs (source smokepurer-sync).

== Recommended hosting note ==

WordPress's default WP-Cron is triggered by site traffic and is unreliable for a
5-minute job. For dependable stock timing, disable WP-Cron and drive Action
Scheduler from a real system cron:

    define('DISABLE_WP_CRON', true);   // in wp-config.php
    # then, every minute, hit:
    # wp action-scheduler run   (WP-CLI)  — or — curl the site's wp-cron.php

== Changelog ==

= 1.0.4 =
* Images the supplier serves as "not found" (404) are now parked after one attempt and no longer re-downloaded (or re-logged) on every catalogue run - a big reduction in wasted requests and log noise. Parked images are retried automatically after 30 days.
* Added a "Retry missing images" button (Dashboard > Maintenance) to clear the parked list and re-pull on demand, e.g. after the supplier fixes the images. The dashboard shows how many are currently parked.
* Fixed the image-failure log line to report the actual number of attempts (a permanent 404 is tried once, not the retry maximum).

= 1.0.3 =
* Added per-field "keep updating" options that control what continues to sync on a product AFTER its first import (price, name, description, categories, weight, brand, tags, image). New products always import in full; stock always syncs. Default: price/weight/tags keep syncing, presentational content is frozen so your manual edits are preserved.
* Added an admin warning notice if the stock sync has failed or stopped running, so a silent stoppage is noticed without opening the dashboard (internal only; no external services).

= 1.0.2 =
* Added an option to import products without categories (products are left unassigned).
* Refreshed the README documentation.

= 1.0.1 =
* Added image download throttling and retry with exponential backoff to avoid supplier rate-limiting during large image imports.
* Security: feed-supplied image URLs are now validated against internal/loopback/reserved hosts before download (SSRF hardening); the host guard also blocks IPv6-literal and numeric-encoded IP bypasses and resolves hostnames.
* Compatibility: verified clean on PHP 8.3, 8.4 and 8.5 (fixed a PHP 8.4+ fgetcsv() deprecation).

= 1.0.0 =
* Initial release.
