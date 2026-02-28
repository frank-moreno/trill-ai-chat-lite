=== Trill AI Chat — Lite ===
Contributors: trillai
Tags: woocommerce, ai, chat, customer service, chatbot
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered customer service chat for WooCommerce. Answer product questions,
recommend items, and boost conversions — automatically.

== Description ==

Trill AI Chat adds an intelligent customer service chatbot to your WooCommerce
store. It understands your products, answers customer questions, and helps
shoppers find what they're looking for.

**Features:**

* AI-powered chat widget on your storefront
* Automatic product search and recommendations
* Natural language understanding of customer queries
* Easy setup — works in minutes

**How it works:**

1. Install and activate the plugin
2. The AI automatically indexes your WooCommerce products
3. Customers can chat with the AI assistant on your store
4. The AI searches your products and answers questions naturally

**Need more?**

[Upgrade to Trill AI Chat Pro](https://trillai.io/pricing/) for unlimited
conversations, order tracking, analytics, and priority support.

== Installation ==

1. Upload `trill-chat-lite` to `/wp-content/plugins/`
2. Activate through 'Plugins' menu
3. Go to Trill AI Chat > Dashboard to see your chat status
4. The chat widget appears automatically on your store

== Frequently Asked Questions ==

= Are there any conversation limits? =

The Lite version connects to the Trill AI service which manages usage
server-side. Upgrade to a paid plan for higher limits and premium features.

= Does this work with any WooCommerce theme? =

Yes, the chat widget works with any properly coded WooCommerce theme.

= Is my data secure? =

Yes. Messages are processed securely via encrypted HTTPS connections. We do not
store or use your data for AI training. See our Privacy Policy at
https://trillai.io/privacy/.

= Can I remove the "Powered by Trill AI" badge? =

The badge is opt-in and off by default. You can enable it in Settings if you wish to show it.

== Screenshots ==

1. Chat widget on the storefront
2. AI answering a product question
3. Admin dashboard with usage stats
4. Settings page

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of Trill Chat Lite.

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
