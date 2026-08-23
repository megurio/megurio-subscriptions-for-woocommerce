=== Megurio Subscriptions for WooCommerce ===
Contributors: wapai222
Tags: subscriptions, recurring payments, woocommerce subscriptions, stripe, bank transfer
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
WC requires at least: 8.2
WC tested up to: 10.6.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds subscription product sales and management features to WooCommerce stores.

== Description ==

Megurio Subscriptions for WooCommerce helps WooCommerce stores sell and manage subscription products. It adds the core subscription workflow, including product-level billing intervals, sign-up fees, renewal orders, automatic recurring payments, customer subscription management, and admin subscription tracking.

The plugin supports card-based automatic recurring payments with the official Stripe payment plugin for WooCommerce, plus manual subscription renewals using WooCommerce Direct Bank Transfer (BACS). Bank transfer renewal orders remain on hold until a store manager confirms receipt, while the original billing schedule is preserved.

Key features include:

* Subscription product settings, including daily, weekly, monthly, or yearly billing intervals, sign-up fees, and variable product support
* Automatic card renewal payments with the official Stripe payment plugin for WooCommerce
* Manual renewal orders and payment confirmation with WooCommerce Direct Bank Transfer (BACS)
* Automatic renewal order creation and status management based on payment results
* Customer self-service actions for cancellation, pause, resume, and payment method changes
* Admin subscription list, status tracking, and editable notification email templates
* HPOS support for WooCommerce custom order tables

For detailed usage, setup instructions, and support information, please visit the [official website](https://megurio.jp/).

For the full changelog of all versions, please see the GitHub releases page.

[GitHub releases page](https://github.com/megurio/megurio-subscriptions-for-woocommerce/releases)

== Changelog ==

= 1.1.0 =
* Fixed Stripe.js Elements API compatibility issue where wallet payment types caused an IntegrationError and blank credit card form during checkout.
* Redesigned My Account subscription details page with a minimalist, native WooCommerce table layout for seamless third-party theme compatibility.
* Streamlined subscription status workflow and removed redundant pause functionality for a cleaner customer experience.
* Enhanced admin subscription management interface with state-adaptive badges and indicators following NiceUI design standards.
* Improved WooCommerce direct bank transfer (BACS) manual renewal and payment confirmation flows.

= 1.0.2 =
* Added manual subscription renewals with WooCommerce Direct Bank Transfer (BACS), including payment-confirmation handling.
* Improved renewal safeguards, retry scheduling, refund and cancellation handling, and subscription status consistency.
* Redesigned subscription management screens with clearer statuses, actions, histories, and responsive mobile layouts.
* Added source-subscription links to WooCommerce renewal orders.
* Updated Japanese translations.

= 1.0.1 =
* Translated readme.txt to English.
* Bumped "Tested up to" to WordPress 7.0.

= 1.0.0 =
* Initial stable release.
