=== Uniwire Payment Gateway for WooCommerce ===
Contributors: Uniwire
Tags: merchant, woo, woocommerce, ecommerce, bitcoin, litecoin, blockchain, commerce, crypto, cryptocurrency
Requires at least: 3.0
Requires PHP: 5.6
Tested up to: 5.2
Stable tag: 0.0.5
License: GPLv2 or later

== Description ==

Accept cryptocurrencies through Uniwire such as Bitcoin, Litecoin on your WooCommerce store.

== Installation ==

= From your WordPress dashboard =

1. Visit 'Plugins > Add New'
2. Search for 'merchant gateway'
3. Activate Uniwire from your Plugins page.

= From WordPress.org =

1. Download Uniwire Payment Gateway plugin.
2. Upload to your '/wp-content/plugins/' directory, using your favorite method (ftp, sftp, scp, etc...)
3. Activate Uniwire Payment Gateway from your Plugins page.

= Once Activated =

1. Go to WooCommerce > Settings > Payments
2. Configure the plugin for your store

= Configuring Uniwire Payment Gateway =

* You will need to set up an account on merchant provider site
* Within the WordPress administration area, go to the WooCommerce > Settings > Payments page, and you will see Uniwire Payment Gateway in the table of payment gateways.
* Clicking the Manage button on the right-hand side will take you into the settings page, where you can configure the plugin for your store.

**Note: If you are running version of WooCommerce older than 3.4.x your Uniwire Payment Gateway tab will be underneath the WooCommerce > Settings > Checkout tab**

= Enable / Disable =

Turn the Uniwire payment method on / off for visitors at checkout.

= Title =

Title of the payment method on the checkout page

= Description =

Description of the payment method on the checkout page

= Uniwire URL =

Uniwire gateway URL

= Account ID =

Your Uniwire account ID

= Profile ID =

Your Uniwire profile ID. In profile you must set your callback URL to `https://uniwire.com/?wc-api=wc_uniwire_gateway`

= API callback token =

Your Uniwire profile API callback token. Using webhooks allows Uniwire to send payment confirmation messages to the website.

= Payment view placement =

Where the payment view should be placed on the checkout page. Options are: `inline` and `modal`

= Debug log =

Whether or not to store debug logs.

If this is checked, these are saved within your `wp-content/uploads/wc-logs/` folder in a .log file.

== Frequently Asked Questions ==

= What cryptocurrencies does the plugin support?

The plugin supports all cryptocurrencies available at https://uniwire.com/

= Prerequisites=

To use this plugin with your WooCommerce store you will need:
* WooCommerce plugin


== Screenshots ==

1. Admin panel
2. Uniwire Payment Gateway on checkout page
3. Cryptocurrency payment screen


== Changelog ==

= 0.0.0 =
* Uniwire
