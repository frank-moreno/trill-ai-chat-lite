=== Trill AI Chat — Lite ===
Contributors: trillai
Tags: woocommerce, chatbot, ai-chat, live-chat, shopping-assistant
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered shopping assistant for WooCommerce. Answers product questions, recommends items, and adds to cart — no API key needed, works in minutes.

== Description ==

**Trill AI Chat adds an intelligent shopping assistant to your WooCommerce store — completely free.** Your customers can ask questions about products, get personalised recommendations, and add items to their cart, all through a natural chat conversation. No API key required, no complex setup, no coding.

Meet **Robin**, your AI-powered shop assistant. Robin understands your product catalogue in real time, searches by name, category, price, and availability, and presents results as interactive product cards with one-click add-to-cart buttons.

= Why Trill AI Chat? =

Unlike generic chatbot plugins that require you to bring your own API key and configure prompts manually, Trill AI Chat works out of the box. Install, activate, and your store has a fully functional AI assistant in under two minutes. The AI service is managed for you — no OpenAI account needed, no token costs to worry about, no prompt engineering required.

= Key Features =

* **Instant AI chat widget** — A clean, modern chat bubble appears on your storefront. Fully responsive on mobile and desktop. Customers can start chatting immediately.
* **Real-time product search** — Robin searches your WooCommerce products by name, description, category, and tags. Results appear as rich product cards with images, prices, and stock status.
* **Natural language understanding** — Customers can ask questions the way they naturally would: "do you have any red dresses under £50?", "what's on sale?", "show me accessories for hiking". Over 50 common shopping phrases are recognised automatically.
* **Smart de-pluralisation** — Searching for "t-shirts" correctly matches "T-Shirt", "accessories" matches "Accessory". English plural forms are handled intelligently.
* **One-click add to cart** — Product cards include an "Add to Cart" button that works via AJAX, so customers never leave the conversation.
* **Quick reply buttons** — Contextual suggestions like "Browse products", "What's on sale?", and "Shipping info" help guide the conversation naturally.
* **Non-product query handling** — Questions about shipping, returns, payment methods, and order status are recognised and answered without triggering unnecessary product searches.
* **Customisable appearance** — Choose your brand colour, widget position (bottom-left or bottom-right), and set a custom welcome message. The widget adapts to your store's look and feel.
* **No API key required** — The AI is powered by the Trill AI managed service. No need to create accounts with OpenAI, Anthropic, or any other provider. Just activate and go.
* **Lightweight and fast** — No external JavaScript libraries, no Composer dependencies, no bloat. Built with vanilla JavaScript and native WordPress APIs for maximum performance.
* **WooCommerce HPOS compatible** — Fully compatible with WooCommerce High-Performance Order Storage.
* **GDPR-friendly** — No personal data is collected beyond what is strictly needed to process each chat message. Messages are not used for AI training. Full details in our Privacy Policy.
* **Shortcode support** — Use `[trill_chat]` to embed a chat trigger button on any page or post. Supports `style`, `title`, and `button_text` attributes.
* **Developer-friendly** — Clean PSR-4 architecture, WordPress coding standards, filter hooks for customisation (`trcl_localize_script_data`), and debug logging via WP_DEBUG.

= How It Works =

1. **Install and activate** — Upload from the WordPress plugin directory or search "Trill AI Chat" in Plugins > Add New.
2. **Automatic product indexing** — Robin scans your WooCommerce catalogue automatically. No manual configuration needed.
3. **Customers start chatting** — The chat widget appears on your storefront. Visitors ask questions, Robin searches your products in real time and responds with relevant results.
4. **You track usage** — The admin dashboard shows your monthly conversation count and product index status.

= Built for WooCommerce Store Owners =

Trill AI Chat is purpose-built for e-commerce. Unlike general-purpose chatbot plugins that try to do everything, Robin focuses on what matters most for online stores: helping shoppers find and buy products. Every feature — from product card rendering to cart integration — is designed specifically for WooCommerce.

= Need More? =

[Upgrade to Trill AI Chat Pro](https://trillai.io/pricing/?utm_source=lite_plugin&utm_medium=readme&utm_campaign=upgrade) for unlimited conversations, order tracking, advanced analytics, white-label branding, and priority support.

== Installation ==

= From the WordPress Plugin Directory =

1. Go to Plugins > Add New in your WordPress admin
2. Search for "Trill AI Chat"
3. Click "Install Now" and then "Activate"
4. Visit Trill AI Chat > Dashboard to confirm the chat is active
5. Open your store frontend — the chat widget is already live

= Manual Installation =

1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/trill-ai-chat-lite/`
3. Activate through the Plugins menu in WordPress
4. Go to Trill AI Chat > Dashboard to see your status

= Requirements =

* WordPress 6.0 or higher
* WooCommerce 8.0 or higher
* PHP 8.0 or higher

No API keys, no external accounts, no additional configuration required.

== Frequently Asked Questions ==

= Do I need an API key or OpenAI account? =

No. Trill AI Chat uses a fully managed AI service. The AI processing is handled by the Trill AI API — you do not need to create an account with OpenAI, Anthropic, or any other provider. Just install, activate, and the chatbot works immediately.

= How many conversations can I have per month? =

The Lite version includes a generous monthly allowance managed server-side. Your admin dashboard shows a usage counter so you can track your conversations. If you need higher limits, [upgrade to a paid plan](https://trillai.io/pricing/?utm_source=lite_plugin&utm_medium=readme_faq&utm_campaign=upgrade) for unlimited conversations.

= Does it work with any WooCommerce theme? =

Yes. The chat widget is rendered as a fixed-position overlay and works with any properly coded WooCommerce theme. It has been tested with popular themes including Storefront, Astra, Flatsome, OceanWP, and Kadence.

= Does it work with variable products? =

Robin can search and display variable products. Product cards show the price range and link to the product page where customers can select their preferred variation before adding to cart.

= Does it support multiple languages? =

The chat widget interface is in English by default, but the AI can understand and respond in many languages. The plugin is fully translation-ready with a `.pot` file included — you can translate the interface strings using any standard WordPress translation tool such as Loco Translate or WPML.

= How does the product search work? =

When a customer sends a message, the plugin analyses whether the question is product-related. If so, it extracts the search terms, generates plural/singular variants, and searches your WooCommerce products by title, description, categories, and tags. Results are returned as interactive product cards directly in the chat.

= Is it compatible with WooCommerce HPOS (High-Performance Order Storage)? =

Yes. The plugin declares full compatibility with WooCommerce HPOS / Custom Order Tables.

= Is my customer data secure? =

Yes. All messages are transmitted over encrypted HTTPS connections to the Trill AI API. No personal visitor data is stored by the external service beyond what is strictly necessary to process each individual message. Chat data is never used for AI model training. See our [Privacy Policy](https://trillai.io/privacy/) for full details.

= Can I customise the chat widget appearance? =

Yes. Go to Trill AI Chat > Settings to change the widget colour (any hex colour), position (bottom-right or bottom-left), and welcome message. The widget automatically adapts to your chosen brand colour.

= Can I remove the "Powered by Trill AI" badge? =

The badge is **opt-in and off by default**. There is nothing to remove — it only appears if you explicitly enable it in Settings. This complies fully with WordPress.org plugin guidelines.

= Can I embed the chat on a specific page instead of the whole site? =

Yes. Use the `[trill_chat]` shortcode to place a chat trigger button on any page or post. You can customise it with attributes: `[trill_chat style="button" button_text="Ask Robin"]`.

= What happens if I already have the paid version installed? =

The Lite plugin automatically detects the paid version and deactivates itself to prevent conflicts. You only need one version active at a time.

= Where can I get support? =

For free support, use the [WordPress.org support forum](https://wordpress.org/support/plugin/trill-ai-chat-lite/). You can also browse our [documentation](https://trillai.io/documentation/) for setup guides and troubleshooting. For priority support and advanced features, [upgrade to a paid plan](https://trillai.io/pricing/?utm_source=lite_plugin&utm_medium=readme_faq&utm_campaign=upgrade).

== Screenshots ==

1. Chat widget open on a WooCommerce storefront — product cards with prices and quick reply buttons
2. Admin dashboard with chat status, monthly usage tracker, and plan comparison table
3. Settings page — customise widget colour, position, welcome message, and attribution badge
4. Product index status showing WooCommerce products synced and ready for AI search
5. Mobile chat — Robin answering product questions with cards and add-to-cart buttons
6. Mobile storefront with the chat toggle button — minimal, non-intrusive design
7. Desktop storefront showing the chat toggle button in the bottom-right corner

== Changelog ==

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
* Added non-product query filters for shipping, payment, order status, and cancellation questions to reduce unnecessary product searches
* Improved PHP 7.4 compatibility
* Added monthly conversation usage widget to the admin dashboard

= 1.0.0 =
* Initial release

== Upgrade Notice ==

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
