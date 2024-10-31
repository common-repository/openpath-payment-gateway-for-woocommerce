=== OpenPath for WooCommerce ===
Contributors: openpathinc
Tags: openpath, payments, payment gateway, woocommerce, credit card, ecommerce, e-commerce, banking
Requires at least: 5.6
Tested up to: 6.4.1
Stable tag: 3.8.4
Requires PHP: 7.0
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

OpenPath for WooCommerce allows you to connect to one or multiple payment processing accounts and manage them effortlessly.

== Description ==

OpenPath for WooCommerce should be used if you want to connect any number of payment processors to your WooCommerce store and automatically control the flow of transactions; optimize acceptance rates, prevent fraudsters and much more.

- Control multiple processing accounts seamlessly.
- Utilize payment provider backups during any downtime to keep your sales completing 100% of the time.
- Get alerts when your payments system needs your attention.
- Prevent fraudulent “card testing” attacks with blacklisting and velocity filters
- Improve acceptance rates with intelligent cascading.
- Avoid exceeding processing account limits on declines, chargebacks and revenue.
- Stay compliant with regulations (e.g. only sell to certain states or countries) with gee-fencing rules.

Let us grow your business by taking all the pain out of payments.

Please go to our [signup page](https://openpath.io/signup) to create a new OpenPath account and get your processing protected and optimized.

= Features =
**Automatic Routing**
Route your transactions to the correct processors based on products, currency, and other various parameters. 

**Failover Processing**
Allow alternate MIDs to take over when you reach limits or when processing accounts go offline. Automatically share your traffic among your most suitable MIDs.

**Critical Alerts**
Know when items need attention. From MID caps to critical decline percentage, you can stay on top of those problems. 

**Fraud Filtering**
Identify potentially fraudulent transactions, and automatically filter them out before they reach your processors.

**Chargeback Management**
Prevention, Avoidance, and Remediation.
We have the features that can help define your chargeback strategy.

**Deep Data on Every Transaction**
Get supplied with more information on every sale to provide to your gateways or processors so that your business flow can improve.

== Installation ==

= Minimum Requirements =

* PHP version 7.0 or greater
* WordPress 5.6 or greater
* WooCommerce 3.5 or greater

= Manual Installation =

1. With our `openpath-payment-gateway-for-woocommerce` zip file, go on the admin dashboard's `Plugins` menu.
2. Click `Add New`, then `Upload Plugin`. Click `Choose File`, select the zip file you have.
3. Click `Install now` and `Activate` the extension.
4. Go to `WooCommerce` menu then `Settings`.
5. Click the `Payments` tab, and click `Set up` for the method, OpenPath.
6. On this page you wil find options to configure the plugin for use with WooCommerce
7. Enter the UserName and Password details corresponding to your OpenPath account.

- **Title** - This controls the title which the user sees during checkout.

- **Description** - This controls the description which the user sees during checkout.

- **UserName** - This is the API Login ID provided from OpenPath's site configuration. This is needed in order to take payment.

- **Password** - This is the Transaction Key provided from OpenPath's site configuration. This is needed in order to take payment.

- **Enable/Disable Customer Receipt** - If enabled, the customer will be sent a receipt after being charged.

- **Saved Cards** - If enabled, users will be able to pay with a saved card during checkout. Card details are saved on OpenPath servers, not on your store.

- **Transaction Type** - Set this to `Sale` if you want OpenPath to capture the payment after authorization, else `Authorize Only`

- **Accepted Cards** - Select credit card types to accept

Please contact support@openpath.io if you need help installing the OpenPath for WooCommerce plugin.

== Frequently Asked Questions ==

= Where can I find more answers? =

If you have any questions, get in touch with our support team at support@openpath.io to find help with your issue or concerns.

== Changelog ==
= 3.8.4 2023-12-05 =
* Added device's fingerprint to sale request

= 3.8.3 2022-05-16 =
* Fixed adding processing values when store has subscription items

= 3.8.2 2022-01-14 =
* Changed versioning on js files

= 3.8.1 2021-12-09 =
* Fixed moving order status from 'refunded' on decline or errored payment response to 'failed'

= 3.8.0 2021-10-20 =
* Added InterPayments surcharging support
* Added fee 'Transaction Fee' when surcharging 

= 3.7.2 2021-10-01 =
* Fixed sanitization and escaping

= 3.7.1 2020-11-10 =
* Initial Release
