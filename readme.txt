=== DEV3 AI Product Options & Add-Ons for WooCommerce ===
Contributors: dev3tech
Tags: woocommerce, product options, product add-ons, extra options, conditional logic
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 10.3
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Extra product options with 25 field types, conditional logic, steps, repeaters, price formulas and global option sets, plus DEV3 AI that builds and checks them for you.

== Description ==

Add personalisation, upgrades and extras to any WooCommerce product: engraving text priced per letter, colour and image swatches, file uploads, dates, measurements with a price formula, gift options and more. Prices update live on the product page and flow through the cart, checkout (classic and block), orders, emails and the admin.

= Core features =
* 25 field types: text, textarea, email, URL, phone, number, range slider, dropdown, radio buttons, checkbox group, colour swatches, image swatches, image picker, single checkbox, toggle, date, time, date & time, file upload, colour picker, hidden value, repeater, section, step, paragraph.
* Conditional logic: show or hide any field, section or step when all/any rules match (is, is not, contains, empty, greater than, checked…).
* Sections, multi-step wizards and repeaters (e.g. one name and number per team player).
* Price types: flat, percent of the product price, per character (with free characters), per unit, quantity tiers and formulas such as `max(0, {len} - 10) * 2` or `{width} * {height} * 0.002`. Charge per item or once per cart line.
* Global option sets assigned by category (incl. sub-categories), tag or product, with priorities and exclusions; per-product fields, "own fields only" mode, set exclusion and per-product price overrides.
* Live price box, client and server validation (server is always authoritative).
* Cart, checkout, order, email and admin display; edit options from the cart; "order again" keeps options.
* File uploads with type, size and minimum-resolution rules, stored in a protected folder and linked from the order.
* Block cart & checkout and HPOS compatible; Gutenberg block, shortcode `[dev3po_product_options id="123"]`, Elementor widget and WPBakery element.
* Translations per field (WPML, Polylang, TranslatePress or site language), RTL stylesheets, translation-ready.
* Import/export option sets as JSON.

= DEV3 AI features (Google Gemini, your own key) =
* Generate options: DEV3 AI reads the product title and description and drafts the complete option set (fields, choices, prices, conditions) as structured JSON, validated against a schema.
* Logic checker: finds impossible conditions, circular dependencies and price conflicts before you publish (works without AI), plus an AI review for subtler problems.
* Formula helper: "add 2 per letter over 10 letters" becomes `max(0, {len} - 10) * 2`, validated and shown with worked examples.
* Labels, help texts, tooltips and translations into many languages.
* Upload vision check: DEV3 AI checks uploaded images for content fit and quality and tells the shopper how to fix it (warn or block).

Every AI result is a draft you accept, edit or reject. Nothing is saved without your click. The plugin is fully usable with AI turned off or without a key.

= Privacy =
AI features send only the data they need (product title/description, field labels, or an uploaded image for the vision check) to Google Gemini using your API key. The key stays on your server. Customer uploads can be exported and erased with the WordPress personal-data tools.

== Installation ==
1. Upload the plugin zip in Plugins › Add New › Upload, then activate it (WooCommerce must be active).
2. Open "DEV3 AI Product Options" in the admin menu.
3. Create an option set or open a product and use the "DEV3 AI Options" tab.
4. Optional: add a Google Gemini API key in API Config to turn on DEV3 AI.

== Frequently Asked Questions ==
= Does it work without an API key? =
Yes. Every field, condition, price type, upload and cart feature works without AI.

= Does it work with the block cart and checkout? =
Yes. Options appear in the block cart and checkout and prices are applied through the Store API.

= Can shoppers change options after adding to cart? =
Yes. The product link in the cart opens the product with the chosen options; saving replaces the cart line.

= Where are uploaded files stored? =
In wp-content/uploads/dev3po-uploads/ with random names and direct access denied (Apache). Files never ordered are deleted after the retention period (default 14 days). On Nginx, add a deny rule for that folder.

== Screenshots ==
1. Dashboard with stats and DEV3 AI tools.
2. Option set builder with assignment, logic checker and live preview.
3. Field editor: formula pricing with the DEV3 AI formula helper.
4. Product page with swatches, per-letter engraving and live total.
5. Multi-step wizard with upload on a phone.
6. Order with all chosen options.

== Changelog ==

= 1.0.1 =
* Branding: the upload vision check now says "DEV3 AI looks at each uploaded image".

= 1.0.0 =
* Initial release.
