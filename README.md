<p align="center">
  <img src="https://www.smokepurer.com/image/catalog/logo/smoke-purer-logo.png" alt="SmokePurer" width="300">
</p>

<h1 align="center">SmokePurer Sync for WooCommerce</h1>

<p align="center"><em>Import the SmokePurer dropship range into WooCommerce and keep stock in sync — automatically.</em></p>

<p align="center">
  <img alt="Release" src="https://img.shields.io/github/v/release/webdeviant/SmokePurer-WooCommerce?label=release&color=2271b1">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.3%2B-777bb4">
  <img alt="WooCommerce" src="https://img.shields.io/badge/WooCommerce-8.0%2B-96588a">
  <img alt="License" src="https://img.shields.io/badge/license-GPL--2.0-green">
</p>

---

## Overview

SmokePurer supplies thousands of vape and e‑liquid products as CSV feeds — but no plugin to get them into your shop, and their live stock changes every few minutes. **This plugin does it all for you, in the background.**

It handles:

- 🛍️ **Full catalogue** — simple *and* variable products (e.g. an e‑liquid in 10mg / 20mg)
- 🔄 **Live stock sync** every 5 minutes, updating only what changed
- 💷 **Your pricing** — adds your markup to trade prices, with tidy `.99` rounding
- 🖼️ Images, brands, categories, weights, sale prices and pre‑orders
- 🛡️ **Safety checks** so a broken feed can't wipe your stock or prices
- ⏱️ Runs itself — no manual imports

---

## Requirements

> ### ⚠️ PHP 8.3 or newer is required.

| | |
|---|---|
| **PHP** | **8.3+** |
| WordPress | 6.4+ |
| WooCommerce | 8.0+ (validated on 10.9) |
| Dependencies | None — WordPress + WooCommerce only |

---

## Installation

1. Download **`SmokePurer-WooCommerce-x.x.x.zip`** from the [Releases](https://github.com/webdeviant/SmokePurer-WooCommerce/releases) page.
2. **Plugins → Add New → Upload Plugin**, choose the ZIP, install, and **Activate**.
3. Go to **WooCommerce → SmokePurer Sync → Settings**, set your **Markup %**, and save.
4. On the **Dashboard**, click **Run now** for *Catalogue import*, then for *Stock sync*.

> 💡 For reliable 5‑minute timing, disable WP‑Cron (`define('DISABLE_WP_CRON', true);` in `wp-config.php`) and run `wp action-scheduler run` from a real server cron every minute.

---

## Settings

**WooCommerce → SmokePurer Sync → Settings.** The essentials:

| Setting | What it does | Default |
|---|---|---|
| **Markup %** | Amount added on top of the supplier's trade price. `100` = double the cost. **`0` sells at cost!** | `100` |
| **Price rounding** | Tidies the price to end in `.99`, `.95`, nearest 5p/10p, or exact | Charm `.99` |
| **Margin guard** | Never lets the price drop to or below cost | On |
| **New products** | Create as **Draft** (review first) or **Publish** live | Draft |
| **Variation label** | The dropdown name for a variable product's options | Options |
| **Images** | Download and attach product images | On |
| **Image throttle / retries / backoff** | Slows image downloads so the supplier doesn't rate‑limit a big burst | 200 ms · 2 · 1000 ms |
| **Assign categories** | Off = import products with **no category** (left unassigned) | On |
| **Ongoing updates** | Per‑field control of what keeps syncing *after* a product's first import (price, name, description, categories, weight, brand, tags, image). New products always import in full; **stock always syncs**. | price · weight · tags on; content frozen |
| **Category mapping** | Maps the supplier's messy category names to your tidy ones | — |
| **When a SKU is disabled** | Retire discontinued products to Draft or Out of stock | Draft |
| **Stock sync / Catalogue / Reconcile** | How often each job runs | 5 min / 1 hr / daily |
| **Circuit‑breaker** | Aborts a run if a feed suddenly shrinks by more than this % (stops a broken feed wiping your shop) | 15% |

> 💷 **VAT:** the price this plugin writes is treated as your regular price. Whether it includes VAT is set once, store‑wide, under **WooCommerce → Settings → Tax**.

---

## FAQ

**Do I need other plugins?** No — just WooCommerce.

**Will it overwrite categories or attributes I add by hand?** No. It preserves extra categories and custom attributes; it only manages the fields it owns.

**Why are new products in Draft?** Vape products are age‑restricted and regulated, so you get to review new lines before they go live. Change it in Settings if you prefer.

**Some images are missing after a big import.** The supplier's server throttles very large bursts, so a few are skipped and retried on later runs (the throttle setting reduces this).

**How do I import without categories?** Untick **Assign categories** in Settings — products import with no category attached.

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Products far too cheap | **Markup %** is `0` — set it in Settings |
| Prices off by 20% | Check **WooCommerce → Settings → Tax** (prices include/exclude VAT) |
| "Row count dropped… aborting" | The circuit‑breaker caught a shrunken feed; it retries next run |
| Nothing runs on schedule | Set up a real server cron (see Installation) |
| Need detailed logs | **WooCommerce → Status → Logs**, source `smokepurer-sync` |

---

## Changelog

See [`changelog.txt`](changelog.txt) for the full history. Latest highlights:

- **1.0.4** — park the supplier's dead (404) image URLs so they aren't re‑fetched every run; "Retry missing images" button.
- **1.0.3** — per-field control of what keeps syncing after a product's first import; admin warning if the stock sync stalls.
- **1.0.2** — option to import without categories; refreshed docs.
- **1.0.1** — image throttling & backoff; SSRF hardening; PHP 8.3–8.5 verified.
- **1.0.0** — initial public release.

---

## License

Released under the **GNU General Public License v2.0 or later** (GPL‑2.0‑or‑later) — the same license as WordPress and WooCommerce.
