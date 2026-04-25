=== Alibaba to WooCommerce Importer ===
Contributors: nasratulnayem
Tags: woocommerce, import, alibaba, products, chrome extension
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Alibaba products into WooCommerce via a Chrome extension and REST API, with optional AI-powered description rewriting.

== Description ==

Alibaba to WooCommerce Importer connects a companion Chrome extension to your WooCommerce store. Browse any Alibaba product page, click the extension, and the product is created in WooCommerce automatically — including title, description, pricing, images, attributes, and variations.

**Key features:**

* One-click product import from Alibaba product pages via Chrome extension
* Supports simple and variable products with full attribute/variation mapping
* Optional AI rewriting of product title and descriptions via OpenAI or Google Gemini
* Batch URL import queue — paste multiple Alibaba URLs and process them with progress tracking
* Application Password management built into the admin panel
* AI usage tracking with per-product token and cost breakdown

**Third-party services:**

This plugin optionally sends product content to external AI APIs for rewriting. These services are only called when AI rewriting is enabled and API keys are configured by the site administrator.

* **OpenAI** — product content is sent to the OpenAI API for rewriting.
  * Service: https://openai.com
  * Terms of Service: https://openai.com/policies/terms-of-use
  * Privacy Policy: https://openai.com/policies/privacy-policy

* **Google Gemini** — product content is sent to the Google Gemini API for rewriting.
  * Service: https://ai.google.dev
  * Terms of Service: https://ai.google.dev/terms
  * Privacy Policy: https://policies.google.com/privacy

No data is sent to these services without explicit configuration and activation by the administrator.

The plugin also facilitates importing product data that originates from Alibaba.com. Users are responsible for complying with Alibaba's Terms of Service when importing product data.

== Installation ==

1. Upload the `alibaba-woocommerce-importer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Ensure WooCommerce is installed and active.
4. Go to **ATW > Settings** in the WordPress admin menu.
5. Download the ATW Chrome extension from the link on the settings page and install it in Chrome.
6. Create an Application Password on the settings page and paste it into the Chrome extension settings along with your site URL and WordPress username.
7. Browse to any Alibaba product page and click the extension icon to import.

== Frequently Asked Questions ==

= Does this use the official Alibaba API? =

No. The Chrome extension reads the product page directly in your browser. No official Alibaba API credentials are required.

= Is AI rewriting required? =

No. AI rewriting is optional. You can import products with the original Alibaba copy by leaving AI settings unconfigured or disabling the rewrite toggle.

= Which AI providers are supported? =

OpenAI (GPT models) and Google Gemini. You can configure one or both and set a preferred provider order with automatic fallback.

= Will this work on multisite? =

The plugin is designed for single-site installations. Multisite support is not tested.

= Where are import run logs stored? =

Run logs are stored in `wp-content/uploads/atw-url-import/`. They are deleted when you use the "Clear All" button on the URL Import screen, or when the plugin is uninstalled.

== Screenshots ==

1. Settings page — Application Password management and AI rewrite configuration.
2. URL Import screen — batch queue with live progress counters.
3. AI Usage page — per-product token and cost breakdown.

== Changelog ==

= 0.1.0 =
* Initial release.
* Chrome extension import flow via REST API with Application Password authentication.
* Optional AI description rewriting via OpenAI and Google Gemini.
* Batch URL import queue with run history and failed-URL logs.
* AI usage tracking table with token counts and cost estimates.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
