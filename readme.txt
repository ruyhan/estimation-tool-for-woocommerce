=== Estimation Tool for WooCommerce ===
Contributors: ruyhan
Donate link: https://ruyhan.com/
Tags: woocommerce, estimation, quote, pdf, product quote
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 3.16.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let visitors build a WooCommerce product estimate, capture their contact details, and download a branded PDF quotation.

== Description ==

Estimation Tool for WooCommerce adds a frontend interface where customers can search your catalog, add products and quantities to a worksheet, and download a PDF estimate. Submissions are stored in WordPress so you can review, restore, or permanently delete them later, and you can re-download the PDF for any submission from the admin.

**Features**

* Frontend product search bound to your WooCommerce catalog (simple, variable, grouped and external products)
* Quantity controls, suggested products, and a live running total in the active currency
* Lead capture form with international phone validation (intl-tel-input — 70+ countries)
* Save & resume — customers email themselves a link to come back later
* Fully branded PDF export (TCPDF): company logo, name, tagline, footer note, store locations, PDF metadata — all editable from the admin
* Admin notifications: customer confirmation emails, HTML email body with token replacement, multiple admin recipients, From name/email, optional PDF attachment
* Admin submissions list with search, sort, workflow status (new / contacted / quoted / won / lost), trash, restore, bulk actions, CSV export, PDF re-download
* Admin dashboard widget with 30-day stats and top products
* `[estitofo_form]` shortcode and a matching Elementor widget
* WooCommerce HPOS and Cart/Checkout Blocks compatible

**How to use**

1. Configure your branding under *Estimations → Settings*.
2. Drop the `[estitofo_form]` shortcode onto any page, or use the Elementor widget *Estimation Tool*.
3. Customers fill the form and receive a PDF; you receive the submission in *Estimations → Submissions*.

== Premium add-on (optional) ==

The free plugin is a complete estimation / quotation tool — every feature listed above is fully usable without paying anything.

If your business needs more, **Estimation Tool for WooCommerce — Pro** is a separately-sold add-on that extends the free plugin with:

* **Convert estimations to WooCommerce orders** — manually from the admin list, or automatically on submission, with your chosen initial order status.
* **File uploads on the customer form** — let customers attach drawings, spec sheets or photos. Configurable max size and allowed extensions.
* **Google reCAPTCHA v3** — score-based spam filtering on form submissions.
* **Discount / tax / shipping fields** — add custom pricing logic directly on the customer form.
* **Multiple PDF templates** — Classic / Modern / Minimal styles.
* **30-day analytics dashboard** — submission funnel, won/lost values, top products, time-series chart.
* **White-label mode** — strip the plugin author credit from PDFs.

The Pro add-on is sold separately on CodeCanyon. The free plugin works fully on its own and never expires or limits functionality.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through the WordPress Plugins screen.
2. Make sure WooCommerce is installed and active.
3. Activate the plugin.
4. Visit *Estimations → Settings* to set your company branding for the PDF.
5. Add `[estitofo_form]` to any page, or insert the Elementor widget.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =
Yes. Product data, pricing, and currency all come from WooCommerce.

= Does it work with Elementor? =
Yes. An *Estimation Tool* widget appears in the Elementor editor (Elementor 3.5 or higher).

= Is it compatible with WooCommerce High-Performance Order Storage (HPOS)? =
Yes. The plugin declares compatibility with `custom_order_tables` and `cart_checkout_blocks`.

= How are submissions stored? =
In a dedicated database table (`wp_estimation_submissions`). Soft-deleted rows go to a trash view and can be restored or permanently deleted.

= What happens on uninstall? =
The submissions table and the plugin's options are removed. Active submissions are deleted.

= How is the PDF generated? =
The plugin ships TCPDF (LGPL-3.0) for PDF generation. No external service is contacted.

== Third-Party Libraries ==

* **TCPDF** — LGPL-3.0 — https://tcpdf.org
* **intl-tel-input** — MIT — https://github.com/jackocnr/intl-tel-input

== Screenshots ==

1. Frontend estimation builder with product search and live total
2. Submission form with international phone validation
3. Admin submission list with bulk actions
4. PDF quotation output
5. Settings screen for company branding

== Changelog ==

= 3.16.3 =
* Upgraded the bundled TCPDF library from 6.10.0 to 6.11.3 (latest stable). Brings PHP 8.5 deprecation fixes (curl_close, null array offset, imagedestroy, xml_parser_free), font-subsetting checksum fix, SVG rendering fix, image-on-footer fix, and the security/path-traversal fixes from 6.9.1–6.9.3. No API changes affect this plugin — generated PDFs are byte-identical aside from a `/Producer` header bump.
* Bumped "Tested up to" to WordPress 6.9 (was 6.7) so the plugin shows up correctly in the WordPress.org plugin directory's compatibility filter.
* Trimmed the TCPDF bundle further: dropped the unused `images/` (demo logo), `tools/` (CLI utilities), `Makefile`, and `CHANGELOG.TXT` from the shipped library — plugin remains under 4 MB.

= 3.16.2 =
* Settings page layout cleanup: the form is now rendered as a single unified card. Previously, tabs with multiple stacked `form-table` blocks (especially Pro Features) showed inconsistent padding, asymmetric borders, and section headings floating between cards. Section `<h2>` headings inside the form are now styled as proper section dividers, row spacing is even across all tabs, and the submit-button row integrates with the card via a hairline rule.

= 3.16.1 =
* Added an honest "Premium add-on" section to the readme description listing what the optional Pro add-on adds on top of the free plugin (WC order conversion, file uploads, reCAPTCHA, analytics, more PDF templates). Free plugin remains 100% fully functional on its own.
* Added one modest italicised line at the bottom of the Settings page mentioning the Pro add-on (hidden automatically when Pro is installed). Single sentence, single link — no buttons, no banners, no popups.
* `estitofo_pro_purchase_url` filter so site owners / Pro can override the upsell URL.

= 3.16.0 =
* WordPress.org review revision — addresses all reviewer feedback:
  * PDF and Notifications settings tabs are now **fully functional in the free plugin** (no locked UI, no Pro gating). All branding fields — company name, logo, footer, From name/email, customer email body with tokens, multiple admin recipients, PDF attachment — are available to every user.
  * Renamed internal prefix from generic `wc_estimation_*` / `WC_Estimation_*` to plugin-unique `estitofo_*` / `Estitofo_*` throughout: classes, defined constants, option keys, transients, AJAX action names, hook names, nonces, REST namespace, JS globals, shortcode (`[estitofo_form]`). The plugin slug and text domain stay the same.
  * Inline `<script>` / `<style>` blocks in admin pages moved into a dedicated `assets/js/admin-settings.js` enqueued via `admin_enqueue_scripts`, and a new section in `assets/css/admin-estimation.css`. No more inline tags in PHP output.
  * `apply_filters('estitofo_save_settings', …)` now receives an early-sanitized snapshot of the submitted array (every value run through `sanitize_text_field`) instead of the raw `$_POST` data. Add-ons can re-sanitize their own keys as needed.
  * Added `Requires Plugins: woocommerce` header so WordPress can pre-check the WooCommerce dependency before activation.
  * Pro upsell UI removed from the free plugin — buyers who want Pro find it on the readme description; the free plugin no longer promotes paid features in the admin.

= 3.15.3 =
* Plugin header: Plugin URI and Author URI now differ — Plugin URI points to the WordPress.org listing for this plugin, Author URI stays as the author's homepage. Fixes the WP.org submission validation error.

= 3.15.2 =
* Pro upsell links centralised behind a single constant `Estitofo_Plugin::PRO_PURCHASE_URL` so the destination is easy to swap when the Pro item is published. Default points to a CodeCanyon search URL (no 404) until the author updates it with the live CodeCanyon item URL.

= 3.15.1 =
* Slimmer footprint: stripped unused TCPDF font assets (CJK fonts, Arabic fonts, DejaVu TTF source folders, .z compressed font streams) — the plugin uses only the built-in PDF14 core fonts (Helvetica / Courier / Times / Symbol / ZapfDingbats), so 24 MB of unused font data has been removed. Plugin now downloads at ~1.5 MB instead of 6+ MB. PDF generation is byte-identical.

= 3.15.0 =
* New "Frontend Subheading" field on the General settings tab — the smaller line of text shown under the hero title is now fully editable. Leave it blank to hide the subheading entirely.
* Both heading and subheading are exposed as shortcode attributes (`[wc_estimation_tool heading="…" subheading="…"]`) and Elementor widget controls.
* General tab heading field gets a helpful description: "Big title shown above the estimation form. Leave blank to use the default."

= 3.14.1 =
* Search icon no longer overlaps the placeholder when themes override input padding. Forced `padding-left: 48px !important` on `.wc-search-input` (via a more specific `.wc-estimation-wrapper .wc-search-input` selector), pinned the icon to `left: 16px / width: 18px` with `!important`, and added `text-indent: 0 !important` to neutralise theme rules. Net result: at least 14px clear space between the icon's right edge and the start of the placeholder text on every theme.

= 3.14.0 =
* Fixed: Default Phone Country setting (e.g. `bd`) wasn't reflecting on the frontend because `enqueue_assets` was reading the legacy standalone option `wc_estimation_default_country` instead of the consolidated `wc_estimation_settings` array. Now uses `Estitofo_Options::get('default_country')` so the saved value is honoured.
* Default / Restrict Country settings are now native `<select>` dropdowns with flag emoji, country name and dial code (e.g. 🇧🇩 Bangladesh (+880)) — no more typing ISO codes from memory.
* Phone validator tightened back up: only the **selected** country is probed. Previous "OR across many countries" multi-check let invalid numbers slip through by accidentally matching a different country's pattern. New flow: try input as-typed → try leading-zero-stripped → both against the selected country only. Country mismatch (BD digits with US flag) is correctly rejected; valid BD `01851932715` with BD flag is correctly accepted.

= 3.13.0 =
* Phone validator now runs the user's input through every libphonenumber call combination (`isValidNumber` + `isPossibleNumber`, with raw input + E.164 + leading-zero-stripped form, with both upper- and lower-case ISO country codes) and accepts if ANY of them returns true. Bangladesh's leading-zero national format like `01851932715` now reliably validates against the BD flag because at least one of those combinations will recognise it.
* Added an opt-in console diagnostic: append `?wcestphonedebug=1` to the URL and every validation prints `{input, country, accepted, tries: [...]}` to the browser console — makes it trivial to see which library call accepted/rejected a given number.

= 3.12.0 =
* Phone validation rewritten to trust intl-tel-input + libphonenumber directly — no more layered custom logic. Switched `nationalMode` to `true` so the library expects users to type their local format (e.g. `01851932715` with the leading zero), which is what people actually do. `isValidNumber()` is now the single source of truth: green tick when it returns true, specific error message via `getValidationError()` when false.

= 3.11.0 =
* Phone validation now correctly rejects country/number mismatches. Previous version had a "7–15 digits" universal fallback that accepted a Bangladesh number under the US flag. The new validator parses the input into E.164 (via `iti.getNumber()`), verifies the resulting prefix matches the selected country's dial code, then asks `isPossibleNumber()` whether the full E.164 is plausible for that country. Valid `01851932715` with 🇧🇩 selected passes; the same digits with 🇺🇸 selected now fail.

= 3.10.0 =
* Phone validation: now correctly accepts national-prefix forms like Bangladesh's `01851932715`. Previously libphonenumber's `isValidNumber()` rejected the leading-zero national form even though it's how 99% of users actually type their number. The validator now layers four soft-pass checks: `isValidNumber()` → `isPossibleNumber()` → `getValidationError()` IS_POSSIBLE codes → digit-count plausibility (7–15). The wrong-country `+xx` prefix check remains a hard fail so the BD-on-US-flag case is still rejected.

= 3.9.2 =
* Phone validation: real-world-tolerant. libphonenumber's `isValidNumber()` rejects perfectly-good numbers that aren't in the strictest canonical form (leading-zero national prefix, mobile/landline-only ranges, etc.). The validator now treats those as acceptable when `getValidationError()` reports `IS_POSSIBLE` (0) or `IS_POSSIBLE_LOCAL_ONLY` (4) — the field clears silently. Only real format errors (wrong country, too short, too long, invalid length) block submission.
* Mismatched-country detection retained: typing `+xx…` with a different flag selected still fails fast.

= 3.9.1 =
* Hotfix: the bundled `utils.js` was distributed as an ES module ending with `export default utils;`. When loaded via a regular <script> tag (as we and intl-tel-input both do) the `export` keyword threw `Uncaught SyntaxError: Unexpected token 'export'`, the global `window.intlTelInputUtils` was never set, and all country-aware phone validation silently degraded to the lenient fallback. Replaced the trailing 3 lines so the file works as a classic script: assigns `window.intlTelInputUtils = window.intlTelInputUtilsTemp`.

= 3.9.0 =
* Phone validation no longer hangs on "Checking phone number…". The intl-tel-input `utils.js` library (country rules) is now pre-enqueued via `wp_enqueue_script` so it loads with the page instead of being lazy-loaded by intl-tel-input after init — eliminates the race window where validation got stuck waiting.
* If `utils.js` is still blocked (CSP / hardened host), the validator now falls back to a 7–15 digit length check after 3 seconds instead of staying in "Checking…" forever.

= 3.8.0 =
* Phone validation: strict mismatched-country detection. Selecting a US flag and typing a Bangladesh number (or vice versa) now fails validation instantly with "Doesn't look like a valid United States number." Cross-checks `isValidNumber()` against `intlTelInputUtils.getRegionCodeForNumber()` and against any user-typed `+xx` prefix.
* Form submit is now blocked while `utils.js` is still loading instead of falling through to a lenient length check — eliminates the race window where an invalid number could slip in before country rules arrived.
* The submit button visually disables (greys out) while the phone field is invalid; the validation pill under the input shows a ✓ or ⚠ icon.
* Server-side: extra phone sanity check (7–15 digit length) and country-prefix verification when "Restrict to Country" is configured.

= 3.7.0 =
* PNG logos with transparent backgrounds now stay transparent in the generated PDF instead of being flattened onto white. When GD or Imagick is available TCPDF's _parsepng() extracts the alpha channel and embeds it as a PDF soft mask — your brand mark blends with the brand-coloured header band.
* The white-background flatten is only used as a true fallback when neither extension is available.

= 3.6.0 =
* Fixed: TCPDF crashed with `TypeError: in_array(): Argument #2 must be of type array, null given` and a chain of "Undefined property" warnings on PHP 8.2.x + opcache. Root cause: TCPDF 6.10 declares properties like `protected $fontkeys = array();` but never reassigns them in `__construct()`. On certain PHP/opcache combinations, the class-property default isn't materialised on the instance, so every later read returns NULL. We now run a reflection-based property pre-seed right after `new TCPDF()` that force-initialises `fontkeys`, `numfonts`, `fonts`, `font_subsetting`, `pdfa_mode`, `isunicode`, `FillColor`, `DrawColor`, `TextColor`, `CoreFonts`, and 9 other dependencies. The fix is contained inside our class — TCPDF itself isn't patched.

= 3.5.0 =
* Switched the default PDF font to core 'helvetica' instead of 'dejavusans'. Avoids loading external TTF font files — fixes the PHP 8.2+ "Undefined property: TCPDF::$fontkeys" warnings and the cascading `in_array(NULL)` fatal that hit hosts without the GD extension. Non-Latin currency symbols still display correctly via the ISO-code fallback in money().
* Logo diagnostic now gives **specific, actionable instructions** when it detects "PNG with alpha + no GD + no Imagick" — the most common silent-failure cause. Tells the admin to either (a) upload a JPG / flat PNG, (b) enable the PHP `gd` extension in php.ini, or (c) install Imagick.
* Removed an experimental pure-PHP PNG alpha-strip that would have produced visually-corrupt logos.

= 3.4.0 =
* Logo: auto-backfills `logo_id` from `logo_url` on the PDF tab — older saved logos now work without re-uploading.
* Logo embed: two-stage fallback — first tries raw-bytes via TCPDF's `@` prefix, then falls back to a regular path-based `Image()` call. If both fail, WP_DEBUG logs the exact stage that broke.
* Logo diagnostic is now always visible (not hidden behind a "details" toggle) and shows: resolved path, readable flag, file size, extension, GD/Imagick availability, TCPDF loaded, safe-path, and bytes loaded.

= 3.3.0 =
* PDF logo: now embedded as raw image data via TCPDF's `@` prefix instead of a filesystem path. This bypasses every open_basedir / mixed-slash / hardened-host issue that was preventing the logo from appearing.
* New logo diagnostic readout in the Pro PDF tab — shows the resolved path, file size, and a precise reason if the logo can't be embedded.
* PDF design refinement: gradient-style header band (primary colour + accent triangle on the right + razor-thin lighter strip below), bigger total panel with a brand-coloured TOTAL stripe at the bottom, tighter spacing.
* Search icon: fixed overlap with placeholder text on themes that override input line-height. Input is now a fixed 50px tall with line-height matched for vertical centering, icon explicitly 20×20 with `!important` size overrides.

= 3.2.0 =
* Fixed: search icon was clipping into the input on some themes — search input is now a fixed 48px tall with absolute-positioned 20px icon at 16px left inset.
* Admin: complete settings page redesign — modern card layout, branded heading badge, soft shadows, sticky tab styling, focus rings on inputs.
* Admin: submissions list — coloured status pills (using `:has()` selector), animated modal with backdrop blur, customer info card in modal, cleaner action buttons.
* Admin: Pro features showcase card at the top of the General tab — 8-feature grid with icons, shows "Pro license active" when licensed.
* Logo resolution: hardened `resolve_logo_path()` with path normalization (collapses mixed slashes), 4-stage fallback (attachment ID → URL parse → reverse-resolve URL → strip thumb suffix), and `WP_DEBUG` error_log diagnostics when resolution fails.

= 3.1.0 =
* Modern frontend redesign: hero card with gradient + brand-tinted "stardust", soft-shadow product cards, animated total counter, confetti on successful submit.
* Featured / popular products grid auto-renders below the search bar when the cart is empty.
* 3-step progress indicator (Browse → Your details → Done) advances visually as the customer moves through the flow.
* Country-aware phone validation — switching the flag re-validates against that country's format with a specific error message ("Number is too short for Bangladesh", etc.).
* Toast notifications replace inline yellow banners (success / warning / error variants).
* Sticky cart summary on mobile when the cart has items.
* PDF: complete redesign with brand-coloured header band, big "ESTIMATION" title, Bill-To card, zebra-striped product rows, total panel, and page numbers; product images render properly; PNG-with-alpha logos are auto-flattened; missing thumbnails get a vector placeholder; small quotations fit on a single page (no blank trailing page).
* Pro logo upload now stores attachment ID alongside the URL — PDF generation uses `get_attached_file()` for rock-solid path resolution. Logo preview + Remove button in the admin.
* Currency symbol HTML entities (e.g. `&#2547;` for BDT) now decode correctly; non-Latin symbols fall back to the ISO code (BDT, INR, THB, KRW, …) so amounts always render.
* Settings → Tools tab gets a "How to add the form to a page" onboarding card with copy-to-clipboard shortcode, Elementor widget instructions (with auto-detection pill), and PHP template snippet.
* Save & resume: cart contents + contact fields persist to localStorage and survive a page refresh.
* Robust product search: simple / variable / grouped / external products all included; cascading fallbacks via `wc_get_products`, `name__like`, SKU, and finally `WP_Query`.

= 3.0.0 =
* Settings consolidated under a single `wc_estimation_settings` option (one-time migration from v2.1 keys).
* Added save & resume — customers email themselves a link that loads their estimation back in.
* Added admin workflow statuses (new / contacted / quoted / won / lost) with inline editing.
* Added admin dashboard widget (30-day rollup, latest leads, top products).
* Added CSV export from the admin list.
* Added REST API namespace `wc-estimation/v1` (search, suggested, categories, save, resume) — front-end now uses REST with an admin-ajax fallback.
* Product search now matches simple, variable, grouped and external products (was simple-only).
* PDF customization and Notifications are now Pro features. The free plugin still generates PDFs and sends a basic admin notification using defaults; the tabs in Settings show a Pro upgrade teaser when Pro is not active.
* Pro add-on integration: tab filter, settings-save filter, default-settings filter.
* Uninstall now also cleans up rate-limit transients and tmp PDF directory.

= 2.1.0 =
* Renamed to "Estimation Tool for WooCommerce" for trademark compliance.
* Added a Settings page for company name, logo, footer note, and store list (PDF branding is no longer hard-coded).
* International phone validation via intl-tel-input (no longer limited to Bangladesh); default and restricted country are configurable.
* Prefixed all AJAX action names with `wc_estimation_` to avoid collisions.
* Switched admin PDF download to `admin-post.php` POST with nonce in body.
* Added honeypot and per-IP rate limiting to public AJAX endpoints.
* Whitelisted list-table sort columns; sanitized all `$_REQUEST` reads.
* Replaced raw `date()` calls with `date_i18n()` / `gmdate()`.
* Removed debug `console.log`; internationalized all frontend strings via `wp_localize_script`.
* Replaced direct HTML interpolation in JS with safe DOM construction (XSS hardening).
* Declared compatibility with WooCommerce HPOS and Cart/Checkout Blocks.
* Added Elementor minimum-version guard (3.5+).
* Added DB schema version option for forward-compatible migrations.
* Uninstall now removes plugin options as well as the data table.

= 2.0.0 =
* Improved data sanitization and security.
* Added separate admin JS/CSS assets.
* Added suggested product support for the frontend.
* Added uninstall cleanup and a WordPress readme file.

== Upgrade Notice ==

= 2.1.0 =
Major rename, security, and i18n update. Configure your branding under Estimations → Settings after upgrading.
