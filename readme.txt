=== AI Shopping Assistant for WooCommerce — Trill AI ===
Contributors: trillai
Tags: ai assistant, product search, customer support, sales, chat
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The WooCommerce AI Shopping Assistant that reads your catalogue. Managed, GDPR-ready. No API keys, no runaway bills. Free forever.

== Description ==

**The WooCommerce AI Shopping Assistant built for store owners who want AI that just works.**

Trill AI Chat Lite adds a friendly, product-aware AI chat to your WooCommerce store in under 5 minutes. Shoppers ask questions in natural language — "do you have this jacket in blue, medium?", "what's on sale?", "got anything under £50?" — and Robin, the AI assistant, answers using your real catalogue: stock, variations, prices, categories and attributes. No training, no keyword lists, no scripts.

Unlike generic WordPress AI chatbots, Trill AI Chat is **WooCommerce-native**: it understands products, variations, stock status, categories and attributes out of the box. Every feature is designed for e-commerce — from interactive product cards with one-click add-to-cart to smart de-pluralisation that matches "t-shirts" with "T-Shirt".

Unlike BYOK (bring-your-own-key) plugins, Trill AI Chat is **managed**: we handle the AI infrastructure on the Trill AI API, so you get a predictable monthly conversation quota — no OpenAI account required, no surprise bills when a viral post sends you traffic. This is a managed AI chatbot for WooCommerce, built for WooCommerce support automation and product recommendation AI.

= Why store owners switch to Trill AI Chat =

* **5-minute install.** No API keys. No external account signup required — your WordPress admin is the only login you need.
* **Reads your real catalogue.** Real-time product search across name, description, categories and tags. Variations, stock and prices all native — true AI chat for WooCommerce.
* **Managed AI, predictable cost.** Lite includes a generous monthly conversation allowance managed server-side. Track usage from the admin dashboard.
* **Topic-safe.** Built-in guardrails auto-generated from your store metadata keep conversations about shopping, politely declining off-topic queries (homework, code generation, medical or legal advice).
* **Prompt injection protection.** The assistant will not reveal system instructions or adopt different personas.
* **GDPR-ready.** UK-registered company (Greensolutions Pioneers Limited, Companies House 15693716). HTTPS end-to-end. Chat data is never used to train AI models.
* **Fast.** Powered by GPT-5.4 Nano for sub-second responses on typical product questions.

= What Lite includes (free, forever) =

* AI shopping assistant widget on every page of your store
* Generous monthly managed conversation quota (tracked in dashboard)
* Real-time product search across your WooCommerce catalogue
* Interactive product cards with one-click AJAX add-to-cart
* Natural language understanding — over 50 shopping phrases recognised
* Smart English de-pluralisation ("t-shirts" → "T-Shirt", "accessories" → "Accessory")
* Topic enforcement and prompt injection protection (guardrails)
* Non-product query handling for shipping, returns, payment and order status (AI customer service for WooCommerce)
* Customisable widget colour, position and welcome message
* Basic conversation analytics dashboard
* WordPress 6.0+ and WooCommerce 8.0+ compatible
* HPOS (High-Performance Order Storage) compatible
* Shortcode `[trill_chat]` for embedding the chat trigger on any page or post
* Translation-ready with `.pot` file (works with Loco Translate, WPML and similar)

= What Lite doesn't include =

For stores that need more, these features live in Starter, Pro and Business plans:

* Order lookup and order status conversations
* Extended conversation history (30+ days)
* White-label branding and removal of the "Powered by Trill AI" attribution
* Advanced analytics and funnel reports
* Higher or unlimited conversation quotas
* Priority support

Compare plans at [https://trillai.io/pricing/](https://trillai.io/pricing/?utm_source=lite_plugin&utm_medium=readme&utm_campaign=upgrade).

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
2. Search for "Trill AI Chat"
3. Click "Install Now" and then "Activate"
4. Visit **Trill AI Chat → Dashboard** to confirm the chat is active
5. Open your store frontend — the chat widget is already live

= Manual Installation =

1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/trill-ai-chat-lite/`
3. Activate through the **Plugins** menu in WordPress
4. Go to **Trill AI Chat → Dashboard** to see your status

= Requirements =

* WordPress 6.0 or higher
* WooCommerce 8.0 or higher
* PHP 8.0 or higher

No OpenAI, Anthropic or Google API key is required. Trill AI manages the AI provider for you.

== Frequently Asked Questions ==

= Do I need a WooCommerce store to use this plugin? =

Yes. Trill AI Chat Lite is a WooCommerce AI shopping assistant — it reads your WooCommerce product catalogue to answer shopper questions about stock, variations, prices and categories. It will not do anything useful on a WordPress site without WooCommerce installed and active.

= Do I need an OpenAI or Anthropic API key? =

No. This is the key difference between Trill AI Chat and BYOK (bring-your-own-key) plugins. We manage the AI infrastructure via the Trill AI API — you do not need to create an account with OpenAI, Anthropic or any other provider. Just install, activate and go.

= How many conversations does the free Lite tier include? =

The Lite version includes a generous monthly conversation allowance, enforced server-side. Your admin dashboard shows a usage counter so you can track how many conversations you have used each calendar month. If you need higher limits, [upgrade to a paid plan](https://trillai.io/pricing/?utm_source=lite_plugin&utm_medium=readme_faq&utm_campaign=upgrade) for higher or unlimited quotas.

= What happens when I exceed the Lite conversation quota? =

The chat widget remains visible but new shopper conversations are paused until the quota resets at the start of the next calendar month. You can upgrade to a higher tier at any time to lift the limit immediately.

= How do I add AI chat to my WooCommerce store? =

Install Trill AI Chat Lite from the WordPress plugin directory, activate it, and open **Trill AI Chat → Dashboard**. The chat widget appears automatically on every page of your store. No API keys, no OpenAI account, no prompt engineering required — the plugin is ready in under 5 minutes.

= Is Trill AI Chat Lite GDPR-compliant? =

Yes. Greensolutions Pioneers Limited is a UK-registered company (Companies House 15693716) subject to UK GDPR and EU GDPR. All messages are transmitted over encrypted HTTPS connections. Chat data is never used to train AI models. See our [Privacy Policy](https://trillai.io/privacy/) for full details.

= Does it work with HPOS (High-Performance Order Storage)? =

Yes. Trill AI Chat Lite fully declares compatibility with WooCommerce HPOS / Custom Order Tables.

= Does it work with variable and grouped products? =

Yes. Product cards display variable products with a price range and a "View" button that links to the product page, where shoppers can select their variation before adding to cart. Simple products use direct AJAX "Add to Cart".

= Does it work with any WooCommerce theme? =

Yes. The chat widget is rendered as a fixed-position overlay and works with any properly coded WooCommerce theme. It has been tested with Storefront, Astra, Flatsome, OceanWP and Kadence.

= Can I customise the chat widget appearance? =

Yes. Go to **Trill AI Chat → Settings** to change the widget colour (any hex value), position (bottom-right or bottom-left) and welcome message. The widget adapts to your chosen brand colour automatically.

= Does the AI see my customer data? =

The AI processes the chat messages a shopper sends and the product catalogue context needed to answer (product names, prices, descriptions, stock). No personal visitor data is collected or stored by the external service beyond what is strictly necessary to process each individual message. Chat data is never used for AI training.

= Does it support multiple languages? =

The widget interface is in UK English by default. The AI can understand and respond in many languages (Spanish, French, German, Italian, Portuguese and others). The plugin is fully translation-ready with a `.pot` file and works with Loco Translate, WPML and other translation tools.

= Can I embed the chat on a specific page instead of the whole site? =

Yes. Use the `[trill_chat]` shortcode to place a chat trigger on any page or post. Supports attributes: `[trill_chat style="button" button_text="Ask Robin"]`.

= Can I remove the "Powered by Trill AI" badge? =

The badge is **opt-in and off by default**. It only appears if you explicitly enable it in Settings, fully complying with WordPress.org plugin guidelines. White-label removal of the optional badge is available on paid tiers.

= What happens if I already have the paid version installed? =

The Lite plugin automatically detects the paid version and deactivates itself to prevent conflicts. You only need one version active at a time.

= I found a bug or have a feature request. =

Use the [WordPress.org support forum](https://wordpress.org/support/plugin/trill-ai-chat-lite/) or email hello@trillai.io. We read every message.

== Screenshots ==

1. WooCommerce AI Shopping Assistant widget answering a product question on a storefront — interactive product cards with prices, stock and one-click add-to-cart
2. AI chat for WooCommerce understanding variations and stock ("do you have this in blue, medium?") — real-time catalogue search in action
3. Admin dashboard with conversation analytics, monthly quota usage and product index status
4. Settings page — customise widget colour, position, welcome message and attribution badge
5. Topic enforcement (guardrails) keeping conversations focused on shopping — polite decline of off-topic requests
6. Mobile chat — Robin answering product questions with cards and add-to-cart buttons
7. Desktop storefront with the minimal chat toggle in the bottom-right corner

== Changelog ==

= 1.2.1 =
* Added automatic usage guardrails — Robin now stays focused on your store's products and services, politely declining off-topic requests (homework, code generation, medical/legal advice, etc.)
* Guardrails are auto-generated from your store metadata (name, description, categories) — no configuration needed
* Added prompt injection protection — the assistant will not reveal system instructions or adopt different personas
* Redesigned the floating launcher icon with the Trill AI brand mark (SVG, respects your widget colour)
* Reduced launcher footprint by ~19% for a lighter visual presence on the page
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

= 1.2.1 =
Adds automatic guardrails (topic enforcement + prompt injection protection), a refreshed brand launcher icon, and hardens admin output escaping. Recommended upgrade for all users.

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
