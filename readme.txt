=== Simple CSV Redirects with Cloudflare ===
Contributors: khrlzhfr
Tags: redirects, redirect, csv, cloudflare, 301
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage redirects as CSV rules in WordPress, and optionally as Cloudflare Single Redirect rules, from Tools → Redirects.

== Description ==

Simple CSV Redirects with Cloudflare gives you two ways to manage redirects from one screen under **Tools → Redirects**:

* **CSV Rules** – unlimited redirects kept in a single CSV file and applied by WordPress. Add, edit, reorder, enable, disable and delete rules in WordPress, or download the CSV, edit it in a spreadsheet and upload it again.
* **Cloudflare Rules** (optional) – manage Cloudflare Single Redirect rules for your domains, which redirect at Cloudflare's edge before a request reaches your server.

Every change is recorded in a **Change log** with who made it, when, and the before and after values.

= CSV Rules =

* Wildcards: `/blog/*` with `${1}` in the target to reuse what matched.
* Status codes 301, 302, 303, 307 and 308.
* Optionally pass the visitor's query string on to the target.
* Rules are checked from top to bottom; the first match wins.
* Uploads are checked row by row before anything changes.
* The file is kept in `wp-content/uploads/redirects/` (each site's own uploads folder on multisite), protected from direct downloads on Apache.

= Cloudflare Rules =

Add a Cloudflare API token and your domains to `wp-config.php` and a Cloudflare Rules tab appears, where you can add, edit, reorder, enable, disable and delete Single Redirect rules. The screen shows how many rules each domain's plan allows.

= Who can use it =

Administrators and editors by default. You can change the roles per site in `wp-config.php`.

== External services ==

This plugin can connect to the **Cloudflare API** (`https://api.cloudflare.com/client/v4`) to list and change Single Redirect rules for your domains. This only happens if you add a Cloudflare API token to `wp-config.php`, and only when someone with access opens the Cloudflare Rules tab or changes a rule there.

It sends your API token, your Cloudflare zone (domain) names or IDs, and the redirect rules you create or change. Nothing is sent if no token is configured.

Cloudflare's [Terms of Use](https://www.cloudflare.com/website-terms/) and [Privacy Policy](https://www.cloudflare.com/privacypolicy/) apply.

== Installation ==

1. Install and activate the plugin.
2. Go to **Tools → Redirects**. CSV Rules work straight away.
3. Optional: to manage Cloudflare rules, follow the steps on the **Setup** tab to create a Cloudflare API token and add it to `wp-config.php`:

`define( 'RFCF_CONFIG', [
	'token'   => 'cfut_your-api-token',
	'domains' => [ 'example.com' ],
] );`

== Frequently Asked Questions ==

= What CSV columns can I use? =

`description, source, target, status, preserve_query_string, enabled`. Only `source` and `target` are required. Use the **Setup** tab or download the template for an example.

= Do CSV rules work on cached pages? =

No. CSV rules run inside WordPress, so they don't apply to pages served from a page cache or to files such as PDFs. Use Cloudflare Rules for those.

= Does it work on multisite? =

Yes. Each site has its own CSV file and change log. In `wp-config.php`, top-level settings apply to every site, and an entry keyed by site ID overrides them for that site.

= What permissions does the Cloudflare token need? =

Zone → Single Redirect → Edit, and Zone → Zone → Read, limited to the domains the site should manage.

== Changelog ==

= 1.1.3 =
* Column names, setting names, file names and values on the Setup and CSV Rules tabs are shown as code.

= 1.1.2 =
* The example CSV on the Setup tab no longer ends with an empty line.

= 1.1.1 =
* CSV redirects with preserve_query_string now put the visitor's query string before any #fragment in the target (for example /new?a=1#section, not /new#section?a=1).
* The X-Redirect-By header on CSV redirects now names the plugin.

= 1.1.0 =
* Renamed to Simple CSV Redirects with Cloudflare. The plugin folder is now simple-csv-redirects-with-cloudflare, so WordPress treats it as a new plugin. Download your CSV from the old copy first: deleting the old copy removes its CSV and change log.

= 1.0.2 =
* Reads and writes CSV text without temporary streams.
* Each action checks its security token before reading any input.

= 1.0.1 =
* Renamed to Simple CSV & Cloudflare Redirects.

= 1.0.0 =
* First public release.
* CSV Rules: add, edit, reorder, enable, disable and delete rules in WordPress, or download, edit and upload the CSV.
* Cloudflare Rules: manage Single Redirect rules for your domains, with reordering and a rule count per plan.
* Change log of every rule change.

== Upgrade Notice ==

= 1.1.3 =
Fixes where the query string goes when a CSV redirect target has a #fragment, and tidies the Setup tab.

= 1.1.0 =
New name and plugin folder. Download your CSV before deleting the old copy.
