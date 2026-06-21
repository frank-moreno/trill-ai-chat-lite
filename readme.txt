=== Trill AI Product Chat for WooCommerce ===
Contributors: trillai
Tags: woocommerce, ai chatbot, product search, order tracking, cart recovery
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chatbot for WooCommerce: cart-aware shopping, verified order tracking, smart lead capture, revenue analytics. GDPR-ready. Free forever.

== Description ==

**Trill AI Product Chat for WooCommerce — the AI shopping assistant that turns chat into measurable revenue.**

Trill AI Chat Lite adds a friendly, store-aware AI chat to your WooCommerce site in under 5 minutes. Robin — the AI assistant — answers shoppers in natural language using your real catalogue, your real page content, the current cart, and verified order data. The merchant gets a dashboard that proves revenue from chat. Free forever, open source, GDPR-ready.

= New in 2.2 — see every conversation =

* **New Conversations admin page.** Browse every chat from a new **Trill Chat → Conversations** screen, with message count, average rating, conversion status and attributed revenue on every row.
* **Filter and search.** Narrow by date range, status and star rating, and search across message content to find any conversation fast.
* **Read full transcripts.** Open any conversation in a popup to read the complete back-and-forth, rendered as plain text.
* **Export to CSV.** Download the whole filtered list, or a single conversation's transcript — exports are hardened against spreadsheet formula injection.
* **Tidy up.** Permanently delete conversations you no longer need, one at a time or in bulk.

= New in 2.1 — make the widget yours =

* **Full appearance control.** New **Settings → Appearance** tab: 7-colour palette, 4 corner positions, width / height / corner radius sliders, custom assistant name, custom avatar from your media library, and a curated font picker — with a live preview that updates as you type.
* **Expandable chat on desktop.** Shoppers can grow the chat window with one click for longer conversations.
* **Two launcher styles.** Keep the floating Trill logo or switch to a classic round chat bubble tinted with your brand colour.

= New in 2.0 — five user-facing features =

* **Page content indexing.** Robin reads your FAQ, shipping, returns, contact and policy pages and answers questions about them — not just products. Pick which pages to index from the Settings → Content tab.
* **Cart-aware shopping assistant.** Robin sees the current basket on every message. Answers "what's in my cart", "what's my total", "help me checkout" and recommends products that complement what is already added.
* **Verified order tracking.** "Where's my order?" answered safely. Identity is verified by WordPress login or by matching the email used at checkout before any order detail is shared. No leaks.
* **Smart lead capture.** When a product is out of stock or a shopper hesitates on price, Robin offers to take their email — with explicit, audited consent. Manage every lead from a dedicated Trill Chat → Leads admin page with CSV export.
* **Revenue analytics dashboard.** Four KPI cards on the Trill Chat dashboard: chats started, orders completed, orders attributed to chat, revenue from chat. Choose 7 / 30 / 90 day windows. Prove ROI at a glance.

Plus **GDPR conversation management** wired into WordPress's native Tools → Personal Data flow: DSAR export, right-to-erasure, configurable retention, audited consent snapshots. No IP addresses are ever persisted.

= Why store owners pick Trill AI Chat over generic AI plugins =

* **WooCommerce-native, not bolted on.** Understands products, variations, stock, categories, carts and orders out of the box — every code path is designed for e-commerce.
* **5-minute install.** No API keys. No external account signup — your WordPress admin is the only login you need.
* **Reads your real catalogue + your real pages.** Real-time product search **and** page content indexing. Robin can answer "do you have red dresses?" *and* "what's your return policy?" from the same conversation.
* **Knows the cart.** Robin sees what shoppers already added and helps them checkout instead of forgetting state.
* **Privacy-first order lookup.** Identity verified before any order detail is shared. WordPress login for registered customers, email-match for guests, silent fall-through on mismatch.
* **Revenue attribution built in.** Orders placed within 24h of a chat are tagged as "from chat" and surface on the dashboard with attributed revenue.
* **Managed AI, predictable cost.** Generous monthly conversation allowance on Trill Cloud. Track usage from the admin dashboard. No OpenAI account required.
* **Topic-safe.** Built-in guardrails auto-generated from your store metadata keep conversations about shopping, politely declining homework, code generation, medical or legal advice.
* **Prompt-injection-protected.** The assistant will not reveal system instructions or adopt different personas.
* **GDPR-ready.** UK-registered company (Greensolutions Pioneers Limited, Companies House 15693716). HTTPS end-to-end. Chat data is never used to train AI models. DSAR + erasure via WP Privacy Tools.
* **Fast.** Sub-second responses on typical product questions.

= What Lite includes (free, forever) =

* AI shopping assistant widget on every page of your store
* **Page content indexing** for FAQ, shipping, returns, contact and policy pages
* **Cart-aware chat** that sees the current basket and guides to checkout
* **Verified order tracking** with login or email-match identity check
* **Smart lead capture** for out-of-stock and price-drop opt-ins, with audited consent
* **Revenue analytics dashboard** — orders and revenue attributed to chat, 7/30/90-day windows
* **GDPR conversation management** — DSAR + erasure via WP Privacy Tools, configurable retention
* Real-time product search across your WooCommerce catalogue
* Interactive product cards with one-click AJAX add-to-cart
* Natural language understanding — over 50 shopping phrases recognised
* Smart English de-pluralisation ("t-shirts" → "T-Shirt", "accessories" → "Accessory")
* Topic enforcement and prompt-injection protection (guardrails)
* Generous monthly managed conversation quota on Trill Cloud (tracked in dashboard)
* Customisable widget colour, position, welcome message, starter chips, privacy notice
* WordPress 6.0+ and WooCommerce 8.0+ compatible
* HPOS (High-Performance Order Storage) compatible
* Shortcode `[trill_chat]` for embedding the chat trigger on any page or post
* Translation-ready with `.pot` file (works with Loco Translate, WPML and similar)
* WordPress 7.0 Abilities API integration (optional) — exposes product search, store context, and conversation summary to AI agents and MCP adapters

= Built for WooCommerce, by a UK SME =

Trill AI is built by Greensolutions Pioneers Limited, a UK-registered company (Companies House 15693716). We focus exclusively on AI products for WooCommerce — not a generic chatbot with a Woo plugin bolted on.

If you're a UK, US or EU store owner looking for AI chat that respects your data, your time and your budget, we'd love to hear what you think.

Read more on our blog:

* [Complete guide to AI chat for WooCommerce](https://trillai.io/complete-guide-ai-chat-woocommerce/)
* [Reduce WooCommerce support tickets with AI](https://trillai.io/reduce-woocommerce-support-tickets-ai/)
* [WooCommerce AI chat vs live chat](https://trillai.io/woocommerce-ai-chat-vs-live-chat/)

= Developer-friendly =

Clean PSR-4 architecture, WordPress coding standards, filter hooks for customisation (`trcl_localize_script_data`), and debug logging via `WP_DEBUG`. No Composer dependencies, no external JavaScript libraries.

== Installation ==

= From the WordPress Plugin Directory =

1. Go to **Plugins → Add New** in your WordPress admin
2. Search for "Trill AI Product Chat"
3. Click "Install Now" and then "Activate"
4. Visit **Trill Chat → Dashboard** to confirm the chat is active
5. Open your store frontend — the chat widget is already live

= Manual Installation =

1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/trill-ai-chat-lite/`
3. Activate through the **Plugins** menu in WordPress
4. Go to **Trill Chat → Dashboard** to see your status

= Requirements =

* WordPress 6.0 or higher
* WooCommerce 8.0 or higher
* PHP 8.0 or higher

No OpenAI, Anthropic or Google API key is required. Trill AI manages the AI provider for you.

== Frequently Asked Questions ==

= Do I need a WooCommerce store to use this plugin? =

Yes. Trill AI Chat Lite is a WooCommerce AI shopping assistant — it reads your WooCommerce product catalogue, your published pages, the current cart and the order history to answer shopper questions. It will not do anything useful on a WordPress site without WooCommerce installed and active.

= Do I need an OpenAI or Anthropic API key? =

No. We manage the AI infrastructure via Trill Cloud — you do not need to create an account with OpenAI, Anthropic or any other provider. Just install, activate and go.

= How does the cart-aware feature work? =

On every chat turn the plugin reads the visitor's WooCommerce cart (items, quantities, line totals, cart subtotal and total) and injects a compact summary into the AI's context. Robin can then answer "what's in my cart", "what's my total", or "help me checkout" using real cart state — no hallucination, no asking the shopper to repeat what they already added. The cart context is sent fresh on every message, so it stays accurate even if the visitor adds or removes items mid-conversation.

= How is order tracking kept private? =

For registered customers, identity is verified through their WordPress login — they only see orders bound to their `wp_users.ID`. For guests, the plugin requires both the order number **and** an email that matches the order's billing email. A mismatch silently falls through to "I need to verify your email" instead of confirming or denying the order's existence, so the plugin never leaks order data to an unverified caller.

= How is the lead capture consent stored? =

When Robin offers to take the visitor's email (out-of-stock notification, price-drop alert, etc.) the exact consent line shown to the visitor is snapshotted into the `wp_trcl_leads` row alongside the email. If a future DSAR challenges the legitimate-interest basis the merchant can show what the visitor agreed to, verbatim, at capture time. Leads are managed from the Trill Chat → Leads admin page with CSV export.

= How are orders attributed to chat conversations? =

When `woocommerce_thankyou` fires, the plugin records an `order_completed` event and looks up any `chat_started` events that share the same WooCommerce session customer ID within the last 24 hours. On a match, an `order_attributed` event is written linking the order to the chat session. The dashboard then surfaces "Orders from chat" and "Revenue from chat" KPIs over a configurable 7/30/90-day window.

= How many conversations does the plugin include? =

The plugin includes a generous monthly conversation allowance, enforced server-side. Your admin dashboard shows a usage counter so you can track how many conversations you have used each calendar month.

= What happens when I exceed the monthly conversation quota? =

The chat widget remains visible but new shopper conversations are paused until the quota resets at the start of the next calendar month.

= How do I add AI chat to my WooCommerce store? =

Install Trill AI Chat Lite from the WordPress plugin directory, activate it, and open **Trill Chat → Dashboard**. The chat widget appears automatically on every page of your store. No API keys, no OpenAI account, no prompt engineering required — the plugin is ready in under 5 minutes.

= Is Trill AI Chat Lite GDPR-compliant? =

Yes, fully wired in. Greensolutions Pioneers Limited is a UK-registered company (Companies House 15693716) subject to UK GDPR and EU GDPR. The plugin auto-registers with WordPress's native **Tools → Export Personal Data** (DSAR) and **Tools → Erase Personal Data** (right to be forgotten) — so any data subject request from a visitor flows through the standard WP admin, with conversations + messages + leads cascaded automatically. Retention is configurable (default 365 days, clamped 7-3650). All messages are transmitted over HTTPS. No IP addresses are ever persisted. Chat data is never used to train AI models. See our [Privacy Policy](https://trillai.io/privacy/) for full details.

= Does it work with HPOS (High-Performance Order Storage)? =

Yes. Trill AI Chat Lite fully declares compatibility with WooCommerce HPOS / Custom Order Tables.

= Does it work with variable and grouped products? =

Yes. Product cards display variable products with a price range and a "View" button that links to the product page, where shoppers can select their variation before adding to cart. Simple products use direct AJAX "Add to Cart".

= Does it work with any WooCommerce theme? =

Yes. The chat widget is rendered as a fixed-position overlay and works with any properly coded WooCommerce theme. It has been tested with Storefront, Astra, Flatsome, OceanWP and Kadence.

= Can I customise the chat widget appearance? =

Yes. Go to **Trill Chat → Settings** to change the widget colour (any hex value), position (bottom-right or bottom-left) and welcome message. The widget adapts to your chosen brand colour automatically.

= Does the AI see my customer data? =

The AI processes the chat messages a shopper sends and the product catalogue context needed to answer (product names, prices, descriptions, stock). No personal visitor data is collected or stored by the external service beyond what is strictly necessary to process each individual message. Chat data is never used for AI training.

= Does it support multiple languages? =

The widget interface is in UK English by default. The AI can understand and respond in many languages (Spanish, French, German, Italian, Portuguese and others). The plugin is fully translation-ready with a `.pot` file and works with Loco Translate, WPML and other translation tools.

= Can I embed the chat on a specific page instead of the whole site? =

Yes. Use the `[trill_chat]` shortcode to place a chat trigger on any page or post. Supports attributes: `[trill_chat style="button" button_text="Ask Robin"]`.

= Can I remove the "Powered by Trill AI" badge? =

The badge is **opt-in and off by default**. It only appears if you explicitly enable it in Settings, fully complying with WordPress.org plugin guidelines. There is nothing to remove unless you have turned it on.

= What happens if I have another Trill AI plugin installed? =

This plugin automatically detects a conflicting Trill AI build (e.g. a legacy full-featured edition) and deactivates itself to prevent conflicts. You only need one version active at a time.

= Does the plugin integrate with the WordPress Abilities API? =

Yes, on WordPress 7.0 and higher. The plugin registers three abilities under the `trill-ai/` namespace in the `ecommerce` category:

* `trill-ai/search-products` — search the WooCommerce catalogue (input: `query`, optional `limit` 1-10). Public read access (mirrors `wc_get_products()`).
* `trill-ai/get-store-context` — store metadata: name, currency, product count, top categories. Public read access (mirrors `get_bloginfo()`).
* `trill-ai/get-conversation-summary` — last N messages of a chat session by UUID (input: `session_id`, optional `limit` 1-50). Administrators (`manage_options`) always allowed; visitors must pass a valid UUID, matching the existing `/wp-json/trcl/v1/conversation/{session_id}` REST endpoint posture.

Example usage from another plugin or theme:

`$ability = wp_get_ability( 'trill-ai/search-products' );`
`if ( $ability ) {`
`    $results = $ability->execute( [ 'query' => 'blue t-shirt', 'limit' => 5 ] );`
`}`

On WordPress 6.x the abilities simply do not register and the rest of the plugin works as normal.

= I found a bug or have a feature request. =

Use the [WordPress.org support forum](https://wordpress.org/support/plugin/trill-ai-chat-lite/) or email hello@trillai.io. We read every message.

== Screenshots ==

1. Cart-aware chat — Robin sees the basket on every message. The visitor asks "what's in my cart?" and gets an accurate, real-time summary with totals and a checkout shortcut.
2. Revenue analytics dashboard — four KPI cards on the Trill Chat dashboard: chats started, orders completed, orders attributed to chat, revenue from chat. Switch between 7 / 30 / 90 day windows.
3. Page content indexing — the new Settings → Content tab. Pick which pages Robin reads (FAQ, shipping, returns, contact, custom). Auto-reindex on save, plus a manual "Reindex now" button.
4. Verified order tracking — Robin answers "Where's my order?" only after verifying identity (WordPress login or email match) and returns plain-language status with a view-order link.
5. Smart lead capture — when a product is out of stock or a shopper hesitates on price, Robin offers to take their email with an audited consent line.
6. Leads admin page — every captured email with intent, status, captured-at, mark-contacted and erase actions. Includes a one-click CSV export for downstream tools.
7. Settings → Privacy tab — configurable retention (default 365 days), privacy policy URL, custom notice text. DSAR + erasure links to WordPress's native Tools → Personal Data screens.
8. Storefront chat widget answering a product query — interactive product cards with prices, stock and one-click AJAX add-to-cart. Mobile-friendly, theme-agnostic.

== Changelog ==

= 2.2.0 =
**Conversations release — see, search, export and manage every chat.**

* **New Trill Chat → Conversations admin page** listing every conversation with message count, average rating, conversion status and attributed revenue per row.
* **Filters + full-text search** — narrow by date range, status and rating, and search message content. Search uses a FULLTEXT index where available, with a safe LIKE fallback on hosts without it.
* **Transcript viewer** — read the full conversation in an accessible popup, rendered exclusively as text so nothing in a chat message can execute in wp-admin.
* **CSV export** — export the whole filtered list or a single transcript. Exports are hardened against spreadsheet formula / CSV injection.
* **Delete conversations** — remove conversations you no longer need, individually or in bulk, through the same audited cascade used by the GDPR tools (feedback → messages → conversations). Nonce- and capability-protected.
* **Database:** schema upgraded to 1.4.0 — adds a FULLTEXT index on message content for fast search. The migration is additive and idempotent via dbDelta.

= 2.1.0 =
**Appearance release — full visual control over the chat widget.**

* **New Settings → Appearance tab** with a live preview that updates as you type.
* **7-colour palette** — primary, primary hover, user bubble, AI bubble, header text, body text and window background, each with a colour picker.
* **4 corner positions** — the widget can now also sit top-left or top-right.
* **Width, height and corner radius sliders** (280–600px / 350–800px / 0–50px).
* **Custom assistant name** — rename Robin to match your brand; the greeting and "is typing..." follow automatically.
* **Custom avatar** — pick any image from your media library (falls back safely to the default if the image is later deleted).
* **Curated font picker** — system font stacks only; the plugin never makes external font requests.
* **Expandable chat window on desktop** — a new header button grows the chat for longer conversations; hidden on mobile where the chat is already full-screen.
* **Two launcher styles** — the floating Trill logo (default) or a classic round bubble tinted with your primary colour.
* **Reset to Defaults** button — one click restores the original look; your welcome message is kept.
* Upgrades are visually silent: every new setting defaults to the exact pre-2.1 look until you change it.
* Fixed: the chat window could snap back to the right edge after the full widget bundle lazy-loaded on bottom-left installs.
* Hardened: assistant name is re-sanitised on output; avatar alt text is now attribute-escaped.

= 2.0.0 =
**Major release — five new user-facing features + a new managed AI backend. Free, open source, GDPR-ready.**

This release turns Trill AI Chat from a product-search chatbot into a full-stack store assistant that the merchant can prove drives revenue.

**New features for shoppers:**

* **Page content indexing** — Robin can now answer questions about your FAQ, shipping, returns, contact and policy pages, not just products. Pick which pages to index from the new **Settings → Content** tab.
* **Cart-aware chat** — Robin sees the current basket on every message and can answer "what's in my cart", "how much is my total" and guide the shopper straight to checkout.
* **Verified order tracking** — "Where's my order?" answered safely. Identity is verified by WordPress login or by matching the email used at checkout before any order detail is shared. No leaks.
* **Smart lead capture** — when a product is out of stock or a shopper hesitates on price, Robin offers to take their email with explicit, audited consent. Manage every lead from a dedicated **Trill Chat → Leads** admin page with CSV export.

**New features for the merchant:**

* **Revenue analytics dashboard** — four KPI cards on the Trill Chat dashboard: chats started, orders completed, orders attributed to chat, revenue attributed to chat. Choose 7 / 30 / 90 day windows. Prove ROI at a glance.
* **GDPR conversation management** — DSAR exports and right-to-erasure now work end-to-end through WordPress's native Tools → Personal Data screens (the plugin registers itself automatically). New **Settings → Privacy** tab: configurable retention (default 365 days), privacy-policy URL, audited consent text. No IP addresses are ever persisted.
* **About & Help page** — new submenu with the v2.0 changelog, support links and developer credit.

**Backend + infrastructure (from the May 2026 pivot):**

* Migrated to the new **Trill Cloud** backend at `api-v2.trillai.io` with per-site Bearer authentication and a clearer free-trial policy (50 conversations / month / site).
* Fresh trial registration on activate — no setup wizard, no account creation.
* Bearer token model with hashed-at-rest storage, replacing the legacy site-hash transport.
* **Stateful conversation context** — Robin remembers the last 20 turns of the same session.
* `X-Trill-Trial-Remaining` response header surfaces the live monthly allowance to the dashboard.

**Database:**

* Schema upgraded from 1.0.0 to 1.3.0. Three new tables: `wp_trcl_content_index` (page chunks with FULLTEXT), `wp_trcl_analytics_events` (chats, orders, attribution), `wp_trcl_leads` (opt-ins). Migrations are additive and idempotent via dbDelta.

**Upgrade path:**

* Existing v1.x sites that auto-update will see a one-time admin notice explaining the change. No data loss; conversations and message history persist intact.
* Sites still running v1.x (and not auto-updating) continue to be served by the legacy `api.trillai.io` proxy indefinitely.

= 1.2.6 =
* Housekeeping release: bumped "Tested up to: 7.0", hardened `.distignore` (recursive `.DS_Store` + defensive `/bin` exclusion). No code changes.

= 1.2.5 =
* Transitional release while preparing the 2.0 OSS pivot. No user-facing changes.

= 1.2.4 =
* Renamed plugin display name to "Trill AI Product Chat for WooCommerce" in accordance with WordPress.org Plugin Directory guidelines on distinctive plugin naming
* Updated admin page headings, activation notices and accessibility labels to reflect the new name
* Regenerated translation template (.pot) with the new strings — no functional changes

= 1.2.3 =
* Lazy-loads the chat widget — only a ~4 KB launcher is shipped on first paint; the full chat bundle is fetched on hover, focus or click, improving Core Web Vitals on storefront and product pages
* Smarter asset loading — the widget no longer enqueues on wp-login, feeds, REST, AJAX or cron, with optional opt-outs for WooCommerce checkout and My Account pages
* Added `trcl_should_enqueue_widget` filter so developers can force-load or skip the widget on specific pages
* Ships pre-minified JavaScript and CSS with per-file content-hash cache-busting — browsers only re-download assets whose bytes actually changed
* Conversation memory across reloads — the visible transcript persists for the current browser tab, so refreshing the page or reopening the widget no longer restarts the conversation
* Added configurable starter suggestions — show up to three clickable prompt chips (e.g. "What's on sale?", "Help me choose a product") when the chat opens
* Honours SCRIPT_DEBUG — the unminified source is served automatically for developers with debug mode on

= 1.2.2 =
* Added automatic usage guardrails — Robin now stays focused on your store's products and services, politely declining off-topic requests (homework, code generation, medical/legal advice, etc.)
* Guardrails are auto-generated from your store metadata (name, description, categories) — no configuration needed
* Added prompt injection protection — the assistant will not reveal system instructions or adopt different personas
* Redesigned the floating launcher icon with the Trill AI brand mark (SVG, respects your widget colour)
* Reduced launcher footprint by ~19% for a lighter visual presence on the page
* Renamed admin pages to "AI Shopping Assistant — Dashboard / Settings / Products" for clearer branding
* Improved PHPCS compliance across database queries for WordPress.org Plugin Check standards
* Improved output escaping in admin dashboard for enhanced security

= 1.1.1 =
* Upgraded AI model to GPT-5.4 Nano for faster, more accurate product recommendations
* Fixed add-to-cart button for variable and grouped products — now correctly shows "View" instead of a non-functional "Add to Cart"
* Fixed welcome message losing line breaks when saved from the settings page
* Fixed chat widget ignoring the "bottom-left" position setting
* Improved AI response quality — Robin no longer repeats raw product URLs in text replies
* Updated dashboard plan comparison table with current pricing tiers and added Business plan column
* Updated documentation link to https://trillai.io/documentation/

= 1.1.0 =
* Improved product search accuracy with smarter English de-pluralisation (e.g. "t-shirts" now matches "T-Shirt", "accessories" matches "Accessory")
* Added taxonomy-based fallback search across product categories and tags when the native search returns no results
* Expanded conversational query extraction to recognise over 50 common shopping phrases (e.g. "tell me about", "what's the price of", "got any", "do you stock")
* Fixed chatbot incorrectly responding "I don't have access to the store catalogue" — the AI now always acknowledges product search capability
* Added intelligent empty-search handling that suggests alternative terms and store categories
* Fixed currency symbol encoding issue when sending store context to the AI service
* Added non-product query filters for shipping, payment, order status and cancellation questions to reduce unnecessary product searches
* Improved PHP 7.4 compatibility
* Added monthly conversation usage widget to the admin dashboard

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.2.0 =
Conversations release. A new Trill Chat → Conversations page to browse, filter, full-text search, read, export (CSV) and delete chats. Adds a FULLTEXT index (schema 1.4.0); the migration is additive and runs automatically. Safe update for all users.

= 2.1.0 =
Appearance release. Full visual control: 7-colour palette, 4 positions, size sliders, custom assistant name + avatar, font picker, live preview, desktop expand button and a configurable launcher. Your widget looks exactly the same until you change something — safe update for all users.

= 2.0.0 =
Major release. Five new user-facing features: page content indexing, cart-aware chat, verified order tracking, smart lead capture, and a revenue analytics dashboard. GDPR-ready (DSAR + erasure via WP Privacy Tools). New Trill Cloud backend with stateful conversations and a clearer 50 conversations/month free trial. Trial is re-registered automatically on update — no merchant action required.

= 1.2.4 =
Housekeeping release. The plugin is now listed as "Trill AI Product Chat for WooCommerce" to comply with WordPress.org naming guidelines. No functional changes — safe update for all users.

= 1.2.3 =
Performance release. Lazy-loads the widget for faster first paint, adds content-hash cache-busting, keeps the conversation alive across page reloads, and lets you configure three starter suggestion chips. Recommended upgrade for all users.

= 1.2.2 =
Adds automatic guardrails (topic enforcement + prompt injection protection), a refreshed brand launcher icon, clearer admin page names, and hardens admin output escaping. Recommended upgrade for all users.

= 1.1.1 =
Upgraded AI model, fixed variable product add-to-cart, improved widget positioning and AI response quality.

= 1.1.0 =
Significantly improved product search — the AI chatbot now finds products much more reliably across a wide range of customer queries.

= 1.0.0 =
Initial release of Trill AI Chat Lite.

== External Services ==

This plugin relies on the **Trill AI API** (`https://api.trillai.io`) as its
sole AI processing back-end. The service is required for the plugin to function.

**What data is sent and when:**

* When a store visitor sends a chat message, the message text and relevant
  WooCommerce product context (names, prices, descriptions) are transmitted to
  the Trill AI API over HTTPS for AI processing.
* The API also enforces per-site usage limits server-side (no local trialware).

**Service links:**

* Service URL: https://api.trillai.io
* Terms of Service: https://trillai.io/terms/
* Privacy Policy: https://trillai.io/privacy/

No personal visitor data is collected or stored by the external service beyond
what is strictly necessary to process the individual chat message. Messages are
not used for training AI models.
