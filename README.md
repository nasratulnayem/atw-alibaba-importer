# ATW — Alibaba Product Importer

> Import Alibaba products into WooCommerce in one click — via a companion Chrome extension, a secure REST API, and optional AI-powered description rewriting.

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-purple?logo=woocommerce)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-0.1.0-orange)](https://github.com/nasratulnayem/alibaba-woocommerce-importer/releases)

---

## What It Does

Browse any Alibaba product page, click the ATW Chrome extension, and the product is **instantly created in your WooCommerce store** — complete with title, description, pricing, images, attributes, and variations.

No CSV files. No copy-pasting. No manual data entry.

---

## Features

| Feature | Free | Pro |
|---------|------|-----|
| One-click product import via Chrome extension | ✅ | ✅ |
| Simple & variable products | ✅ | ✅ |
| Full attribute + variation mapping | ✅ | ✅ |
| Image sideloading from Alibaba CDN | ✅ | ✅ |
| Batch URL import queue with progress tracking | ✅ | ✅ |
| AI description rewriting (OpenAI / Gemini) | ✅ | ✅ |
| AI token + cost usage dashboard | ✅ | ✅ |
| Application Password manager (in admin) | ✅ | ✅ |
| Import limit (20/hour, 8h cooldown) | ✅ | — |
| **Unlimited imports** | — | ✅ |
| **No cooldown** | — | ✅ |

---

## How It Works

```
Alibaba Product Page
       │
       ▼
 ATW Chrome Extension  ──────────────────────────────────┐
 (reads page data in browser)                            │
       │                                                 │
       ▼                                                 ▼
POST /wp-json/awi/v1/import              Batch URL Import (Admin)
  (Application Password auth)           paste URLs → extension runs queue
       │
       ▼
 [Optional] AI Rewrite
  OpenAI or Gemini rewrites
  title + description
       │
       ▼
 WooCommerce Product Created
  (simple or variable, with images)
```

---

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+
- Chrome browser (for the companion extension)

---

## Installation

### Plugin

1. Download the latest release from the [Releases page](https://github.com/nasratulnayem/alibaba-woocommerce-importer/releases/latest).
2. Upload to `/wp-content/plugins/` and activate.
3. WooCommerce must be installed and active.

### Chrome Extension

1. Go to **ATW → Settings** in your WordPress admin.
2. Click **Download Extension** and load it in Chrome (`chrome://extensions` → Developer mode → Load unpacked).
3. On the Settings page, create an **Application Password** and paste it into the extension along with your site URL and username.

### AI Rewriting (Optional)

1. Go to **ATW → Settings → AI Rewrite Settings**.
2. Enter an OpenAI and/or Gemini API key.
3. Select provider order and preferred model.
4. Enable the rewrite toggle.

---

## REST API Endpoints

All endpoints require `manage_woocommerce` capability via WordPress Application Password authentication.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/wp-json/awi/v1/import` | Import a single product |
| `GET` | `/wp-json/awi/v1/ping` | Auth check |
| `GET` | `/wp-json/awi/v1/categories` | List WooCommerce categories |
| `GET` | `/wp-json/awi/v1/settings` | Get extension settings |
| `POST` | `/wp-json/awi/v1/settings` | Update extension settings |
| `POST` | `/wp-json/awi/v1/connect` | Generate bootstrap token for extension |
| `GET` | `/wp-json/awi/v1/pending-connect` | Retrieve credentials via token |

---

## Import Rate Limits (Free Plan)

- **20 imports per hour** per user
- Exceeding the limit triggers an **8-hour cooldown**
- Remaining quota shown live in the admin UI
- Pro plan removes all limits

---

## AI Provider Support

| Provider | Models |
|----------|--------|
| OpenAI | gpt-4o-mini, gpt-4.1-nano, gpt-4.1-mini, gpt-4o, gpt-4.1, gpt-3.5-turbo, custom |
| Google Gemini | gemini-2.5-flash, gemini-2.0-flash, gemini-1.5-flash, gemini-1.5-pro, custom |

AI rewrites product **title**, **short description**, and **long description** using your configured keywords and call-to-action URL.

---

## Security

- All admin actions protected by WordPress nonces
- REST API requires `manage_woocommerce` capability + Application Password
- Bootstrap endpoint (`/pending-connect`) uses 192-bit single-use token with IP binding and 5-minute TTL
- Cookie header injection prevention in scrape importer
- AI API keys stored in `wp_options` (server-side only, never returned via REST)

---

## File Structure

```
alibaba-woocommerce-importer/
├── alibaba-woocommerce-importer.php   # Plugin entry point
├── uninstall.php                      # Full cleanup on delete
├── README.txt                         # WordPress.org readme
├── assets/
│   └── url-import-admin.js            # Batch import UI script
├── freemius/                          # Freemius SDK (not included — download separately)
└── includes/
    ├── class-awi-admin.php            # Admin menus, settings, usage page
    ├── class-awi-rest.php             # REST endpoints + AI rewrite logic
    ├── class-awi-frontend.php         # Product video injection
    ├── class-awi-url-import.php       # Batch URL import AJAX handlers
    ├── class-awi-rate-limiter.php     # Free plan import quota
    ├── class-awi-freemius.php         # Freemius SDK init (Pro plan)
    ├── class-awi-importer.php         # CSV import (legacy)
    └── class-awi-scrape-importer.php  # HTML scrape import (legacy)
```

---

## Freemius / Pro Plan

This plugin uses [Freemius](https://freemius.com) for licence management.

To activate Pro:
1. Create a plugin at freemius.com and obtain your `plugin_id` and `public_key`.
2. Place the Freemius SDK in the `freemius/` directory.
3. Update `AWI_FS_PLUGIN_ID` and `AWI_FS_PUBLIC_KEY` in `includes/class-awi-freemius.php`.

The plugin works fully without Freemius installed (free plan, no SDK required).

---

## Changelog

### 0.1.0
- Initial release
- Chrome extension import flow via REST API
- Simple and variable product support
- AI rewriting via OpenAI and Google Gemini
- Batch URL import queue with run logs
- AI usage tracking dashboard
- Free plan rate limiting (20/hour, 8h cooldown)
- Freemius Pro plan integration stub
- WP.org compliance fixes

---

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)
