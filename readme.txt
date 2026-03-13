=== Alorbach AI Subscription Gateway ===
Contributors: andre-lorbach
Tags: ai, credits, subscription, openai, billing, ledger
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Precise credit-based AI API billing layer for WordPress. Unified Credit (UC) system, BPE tokenization, immutable ledger, OpenAI/Azure/Google support.

== Description ==

Alorbach AI Subscription Gateway is a billing and API access plugin for WordPress that bridges fixed-price subscriptions with variable AI API costs. It provides:

* Unified Credit (UC) system - 1 UC = 0.000001 USD
* Pre-flight token estimation with BPE (tiktoken)
* Post-flight reconciliation with cached token support
* Immutable SQL transaction ledger
* Admin panel: API keys, cost matrix, plans, user balance, usage, demo defaults
* User-facing: balance/usage display, shortcodes, hooks
* Demo pages: Chat, Image Generator, Audio Transcription (shortcodes)
* WooCommerce Subscriptions and Stripe webhook support

== Installation ==

1. Upload the plugin to wp-content/plugins/
2. Run `composer install` in the plugin directory
3. Activate the plugin
4. Go to Alorbach AI Gateway in the admin to configure API keys and plans

== Frequently Asked Questions ==

= What is a Unified Credit (UC)? =
1 UC = 0.000001 USD. Users see "Credits" (1000 UC = 1 Credit displayed).

= Which AI providers are supported? =
OpenAI, Azure OpenAI, Google (Gemini). API keys are configured in the admin.

= How do I create demo pages for testing? =
Go to AI Gateway > Demo Defaults. Set default models and optionally allow users to select models. Click "Create sample pages" to create three demo pages: AI Chat Demo, Image Generator, and Audio Transcription. You can also add shortcodes manually: `[alorbach_demo_chat]`, `[alorbach_demo_image]`, `[alorbach_demo_transcribe]`.

== Changelog ==

= 1.0.0 =
* Initial release
* Demo pages: Chat, Image Generator, Audio Transcription shortcodes
* Demo Defaults admin: default models, allow model selection, create sample pages
* REST /me/models endpoint for demo configuration
* Transcribe prompt parameter for Whisper context
