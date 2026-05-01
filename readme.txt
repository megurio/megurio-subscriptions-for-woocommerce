=== Megurio 定期購入 for WooCommerce ===
Contributors: wapai222
Tags: subscriptions, recurring payments, woocommerce subscriptions, stripe, woocommerce payments
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
WC requires at least: 8.2
WC tested up to: 10.6.2
Stable tag: 0.9.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds subscription product sales and management features to WooCommerce stores.

== Description ==

Megurio Subscriptions for WooCommerce helps WooCommerce stores sell and manage subscription products. It adds the core subscription workflow, including product-level billing intervals, sign-up fees, renewal orders, automatic recurring payments, customer subscription management, and admin subscription tracking.

The plugin supports card-based automatic recurring payments with Stripe and WooCommerce Payments. After the first purchase, renewal order creation, payment processing, status updates, and customer notifications can be managed from WooCommerce.

Key features include:

* Subscription product settings, including daily, weekly, monthly, or yearly billing intervals, sign-up fees, and variable product support
* Automatic card renewal payments with Stripe and WooCommerce Payments
* Automatic renewal order creation and status management based on payment results
* Customer self-service actions for cancellation, pause, resume, and payment method changes
* Admin subscription list, status tracking, and editable notification email templates
* HPOS support for WooCommerce custom order tables

For detailed usage, setup instructions, and support information, please visit the official website.

https://megurio.jp/

== Changelog ==

= 0.9.5 =

**New Features**

* Added a clearer retry-state label for active subscriptions after a renewal payment failure.
* Simplified the WordPress.org readme into a concise English overview with key features and official links.
* Updated the plugin version and readme stable tag to 0.9.5.

**Bug Fixes**

* Improved the status display so subscriptions in the renewal retry grace period can be distinguished from normal active subscriptions.
* Updated and regenerated the Japanese translation files for the new retry-state label.

For the full changelog of all versions, please see the GitHub releases page.

https://github.com/megurio/megurio-subscriptions-for-woocommerce/releases
