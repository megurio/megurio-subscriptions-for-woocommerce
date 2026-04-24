=== Megurio 定期購入(サブスク) For WooCommerce（日本向け） ===
Contributors: megurio
Tags: subscriptions, recurring payments, woocommerce subscriptions, manual renewal, bacs
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.0
WC requires at least: 8.2
WC tested up to: 10.6.2
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight reference plugin for selling manual recurring orders with WooCommerce.


== Description ==

Megurio Subscriptions for WooCommerce is a lightweight subscription-style plugin for WooCommerce stores that want a simple recurring order flow without complex gateway automation.

This plugin focuses on the core lifecycle:

* mark products as recurring products
* create a recurring record after checkout
* activate the recurring record when the initial order is paid
* create renewal orders on schedule
* update recurring status from renewal order results
* expire recurring records when the configured end date is reached


== Features ==

* Add a recurring product checkbox to WooCommerce products
* Set billing interval and optional end period
* Create a custom recurring order record using WooCommerce order CRUD
* Support HPOS-aware order handling
* Create renewal orders automatically on schedule
* Mark recurring records as active, on-hold, cancelled, or expired
* Show recurring information on shop, product, cart, and checkout pages
* Add a My Account area for recurring list and recurring detail pages
* Allow customers to cancel their own recurring records from My Account
* Add an admin page to review recurring records and status flow
* Send notification emails for cancellation, expiration, and reactivation
* Restrict recurring products to bank transfer payment only

== Basic Flow ==

1. Mark a product as a recurring product.
2. A customer places an initial order.
3. A recurring record is created after checkout.
4. When the initial order is paid, the recurring record becomes active.
5. A scheduled task creates the next renewal order when the next billing date arrives.
6. Renewal order results update the recurring status.
7. The recurring record expires when the configured end date is reached.

== Statuses ==

The plugin manages recurring status with its own meta value instead of relying only on WooCommerce order status.

* `pending` - the recurring record exists but is not active yet
* `active` - the recurring record is active
* `on-hold` - the recurring record is temporarily paused
* `cancelled` - the recurring record was cancelled
* `expired` - the recurring record reached its end date

== Limitations ==

This version does not include:

* automatic gateway charging APIs
* payment token storage
* free trials
* sign-up fees
* automatic retry for failed payments
* full variable-product subscription support
* REST API endpoints
* advanced email settings UI

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate the plugin through the WordPress plugins screen.
3. Enable bank transfer in WooCommerce payments if you want to sell recurring products.
4. Edit a product and enable the recurring product option.

== Frequently Asked Questions ==

= Is this plugin ready for production use? =

This is currently a beta version and is not recommended for production use. It is designed for learning, internal testing, and projects that prefer manual payment handling such as bank transfer.

= Does this plugin support automatic charging? =

No. The current version is designed for manual payment operations, especially bank transfer workflows.

= Can customers cancel from My Account? =

Yes. Customers can cancel eligible recurring records from the My Account recurring detail page.

= Does it support HPOS? =

The plugin uses WooCommerce order CRUD and is written to work with HPOS-aware order storage.

== Changelog ==

= 0.2.1 =

* Added recurring product flow
* Added admin recurring management page
* Added My Account recurring pages
* Added recurring notification emails
* Restricted recurring checkout to bank transfer

