=== Emailit ===
Contributors: emailit
Tags: email, woocommerce, smtp, email log, transactional email
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress email through the Emailit API with throttling, retries and an email log, and connect WooCommerce and your forms to Emailit.

== Description ==

The official [Emailit](https://emailit.com) plugin. It sends every WordPress email through the Emailit API, keeps a log with delivery status, and brings your shop and forms into Emailit, so you can run campaigns and automations on real customer data.

= Reliable sending =

* **Emailit API by default.** Faster than SMTP and works on hosts that block SMTP ports. The SMTP relay is one click away if you need it.
* **Throttling based on Emailit's responses.** Every send reports your workspace's per-second limit and how much of today's limit is left. The plugin paces bursts to the per-second limit, queues emails that wouldn't fit in today's limit instead of sending them into an error, and honors `retry-after` when Emailit asks it to wait. A page load sends up to 10 emails right away and queues the rest, so bulk notifications never time out.
* **Retries that never send twice.** Timeouts, network errors, 429 and 5xx responses, a rejected key or an empty credit balance put the email in a queue that retries for about 30 hours. Every retry carries the same idempotency key, so Emailit delivers it once.
* **Smart From addresses.** Emails from domains that aren't verified in Emailit, like a contact form using the visitor's address, go out from your address, and replies still reach the visitor.

= Email log =

* Every email, with its recipients, subject, the plugin that sent it, and its status: queued, sent, delivered, opened, clicked, bounced or marked as spam.
* Delivery status arrives through a signed webhook that the plugin sets up for you.
* Read an email's content and send it again, to the same or another address.
* Filter by status, by sender plugin, or search by recipient, subject or Emailit ID.
* Every email carries metadata (wp_site, wp_source and, for WooCommerce, wc_email and wc_order_id), so you can find WordPress email in the Emailit dashboard too.

= WooCommerce =

* **Orders, products and carts in Emailit Commerce,** for purchase history, revenue per campaign and automation, product blocks and recommendations in emails, and abandoned cart automations. Order changes, refunds and stock levels stay in sync.
* **Abandoned cart recovery** for signed-in customers and for visitors who typed their email at checkout. The email's link refills their cart.
* **Newsletter checkbox at checkout,** for the classic and the block checkout. Buyers who tick it are subscribed to the audience you choose.
* **Import your catalog and order history** in the background.
* Optional Emailit tracking script for product views and visitors who arrive from your emails.
* On plans without Emailit Commerce, buyers become contacts tagged "customer", and orders become c:order_placed and c:order_completed events you can start automations with.

= Forms =

Save people who fill in your forms as Emailit contacts, subscribe them to an audience (always, or only when they tick a newsletter or consent box), tag them, and start automations with the c:form_submitted event. Works with:

* Contact Form 7
* WPForms
* Gravity Forms
* Elementor Pro forms
* Ninja Forms
* Fluent Forms
* Formidable Forms
* Forminator

The plugin finds the email address, names and consent checkbox in each form by field type and label, so there's nothing to map.

= WordPress users =

Add new users as contacts, tagged with their role, keep names and email addresses in sync, and add your existing users in one click.

= Privacy =

The email log is part of WordPress's personal data export and erasure tools (Tools > Export Personal Data and Erase Personal Data), and the plugin suggests text for your privacy policy. Emails with login, password reset or confirmation links never keep their content in the log. On multisite, only network administrators can read logged emails and send them again.

= Built for developers =

* Add metadata to any email with an `X-Emailit-Meta-<Name>: value` header. It's sent to Emailit as metadata and never reaches the recipient.
* Actions: `emailit_email_sent`, `emailit_email_queued`, `emailit_webhook_event` and `emailit_webhook_<type>` (for example `emailit_webhook_email.bounced`).
* Filters: `emailit_email_payload`, `emailit_contact`, `emailit_woocommerce_order`, `emailit_woocommerce_product`, `emailit_retry_delays`, `emailit_inline_limit`, `emailit_inline_wait` and more.
* Keep the API key in wp-config.php with `EMAILIT_API_KEY`. A key saved in the settings is stored encrypted.

= Requirements =

* An Emailit account: [sign up](https://dash.emailit.com/register).
* A sending domain verified in Emailit.
* An API key. A Sending Only key sends email; a Full Access key also enables contacts, audiences, WooCommerce sync and automatic delivery tracking.

Just want SMTP? [Emailit SMTP](https://wordpress.org/plugins/emailit-smtp/) is a smaller plugin with the same throttling and retries.

== Installation ==

1. Install and activate Emailit from Plugins > Add New.
2. In Emailit, go to Email API > API Keys and create a key. Choose Full Access to use the integrations.
3. In WordPress, go to Emailit > Settings, paste the key, set the From email to an address on your verified domain, and save.
4. Send a test email from the same screen.
5. On Emailit > Integrations, turn on delivery tracking and connect WooCommerce and your forms.

== Frequently Asked Questions ==

= What happens when I reach my sending limit? =

Emailit tells the plugin your limits with every email. Bursts are paced to your per-second limit. When today's limit is used up, new emails wait in the queue and are sent automatically after it resets at 00:00 UTC. Nothing is lost, and you can raise your limits from the Emailit dashboard.

= Can an email be sent twice when it's retried? =

No. Each email gets an idempotency key the first time it's sent, and every retry uses the same key, so Emailit accepts it once.

= Does wp_mail() return true for a queued email? =

Yes. A queued email has been accepted for delivery, so plugins don't show an error to your visitors. If it fails for good later, the wp_mail_failed action fires, so logging and alerting plugins still hear about it.

= How does delivery tracking work? =

With a Full Access key, Turn on delivery tracking creates a webhook in your Emailit workspace that only receives this site's emails, and stores its signing secret. Emailit then calls /wp-json/emailit/v1/webhook when an email is delivered, bounces, is opened or clicked, and every request's signature is checked. Your site has to be reachable over HTTPS. With a Sending Only key, create the webhook in Emailit yourself and paste its signing secret.

= Do I need Emailit Commerce for WooCommerce? =

Orders, products, carts and revenue reporting use Emailit Commerce, which is included with the Pro and Business plans. On other plans, buyers are saved as contacts and orders are sent as events, and every WooCommerce email is still sent, logged and tagged.

= Is the tracking script loaded on my site? =

Only if you turn it on under Integrations > WooCommerce. It's off by default. If you use it, mention Emailit in your privacy policy and cookie notice.

= How fast does the queue run? =

The queue runs on WP-Cron every minute while it has emails or sync jobs, and the plugin starts WP-Cron right after something is queued. If your site disables WP-Cron, run wp-cron.php from a system cron job every minute.

= Can I use it with another SMTP plugin? =

No, only one plugin can send WordPress email. Deactivate other SMTP and email plugins, including Emailit SMTP.

= Does it work on multisite? =

Yes. Each site has its own settings, log and integrations.

== Screenshots ==

1. Settings: status with your limits, connection, sender and delivery options, and a test email.
2. The email log with delivery status and the plugin that sent each email.
3. One email: details, delivery events, content, and Send again.
4. Integrations: delivery tracking, WooCommerce, forms and WordPress users.

== External services ==

This plugin connects to Emailit, an email delivery and marketing service. Without it, the plugin does nothing.

* **Sending email** (api.emailit.com, or smtp.emailit.com when you choose SMTP): every email WordPress sends, with its recipients, sender, subject, content, headers, attachments and metadata (this site's address, the plugin that sent it, and for WooCommerce emails the email type and order number). Sent when WordPress sends an email, after you saved an API key.
* **Account checks** (api.emailit.com): which domains are verified and which audiences exist, when you open the plugin's screens (cached for 12 hours and 10 minutes).
* **Delivery tracking** (api.emailit.com): when you turn it on, the plugin creates a webhook in your Emailit workspace with this site's webhook address, and Emailit then sends delivery events to your site. Turning it off or deleting the plugin deletes that webhook.
* **Contacts and events** (api.emailit.com): only for the integrations you turn on. Form submissions (email address, names, the form's fields), new and updated WordPress users (email address, names, role), and WooCommerce buyers, orders, products and carts.
* **Tracking script** (js.emailit.com): only if you turn it on. Loads a script on your shop's pages that sends product views, and optionally the signed-in customer's email address, to Emailit.

Emailit [Terms of Service](https://emailit.com/terms-of-service/) and [Privacy Policy](https://emailit.com/privacy-policy/).

== Changelog ==

= 1.0.0 =
* First release: sending through the Emailit API or SMTP, throttling from Emailit's rate limit headers, queue and retries with idempotency keys, email log with delivery status, WooCommerce, form and user integrations.

== Upgrade Notice ==

= 1.0.0 =
First release.
