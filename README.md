# Simple CSV & Cloudflare Redirects

By [Khairil Zhafri](https://khrlzh.fr). Version 1.0.1. Requires WordPress 6.4+ and PHP 7.4+. Folder, main file and text domain: `simple-csv-cloudflare-redirects`.

Manage redirects from **Tools → Redirects** in two ways:

- **CSV Rules:** unlimited redirects kept in a CSV file and applied by WordPress. Edit them in WordPress or as a spreadsheet. Works without any setup.
- **Cloudflare Rules:** Cloudflare Single Redirect rules, applied at Cloudflare's edge before a request reaches the server. Optional, configured in `wp-config.php`. Limited by plan (Free 10, Pro 25, Business 50, Enterprise 300 per domain).

## Installing

**From GitHub (gets updates straight away):** download `simple-csv-cloudflare-redirects.zip` from the [latest release](https://github.com/khrlzhfr/simple-csv-cloudflare-redirects/releases/latest), then upload it under Plugins → Add New → Upload Plugin. New versions appear under Dashboard → Updates as soon as they are released on GitHub; select **Enable auto-updates** next to the plugin to install them automatically.

**From WordPress.org:** once approved in the Plugin Directory, install **Simple CSV & Cloudflare Redirects** from Plugins → Add New. Updates arrive through WordPress.org.

Both are the same plugin. The GitHub copy also contains an updater (`includes/github-updater.php` and `lib/plugin-update-checker`, [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), MIT). WordPress.org doesn't allow updaters, so its copy leaves out everything listed in `.distignore`.

### Releasing a new version

1. Bump `Version:` in `simple-csv-cloudflare-redirects.php`, `Stable tag:` in `readme.txt`, and add a changelog entry to both readmes.
2. Commit, then publish a release with a matching tag (for example `v1.0.1`) on GitHub's Releases page, or push the tag.
3. The Release workflow checks the version and attaches `simple-csv-cloudflare-redirects.zip`. Sites installed from GitHub see the update straight away.
4. Once the plugin is approved on WordPress.org, copy the same version to its SVN repository, leaving out the files in `.distignore`.

## Tabs

- **CSV Rules:** Add New Rule and Download CSV at the top, the rules as a table (Edit, Move up/down, Enable/Disable, Delete), Upload CSV at the bottom.
- **Cloudflare Rules:** list, add, edit, enable/disable, delete and reorder rules for each configured domain, with a count against the plan's limit. Shown only when Cloudflare is configured.
- **Change log:** who changed what and when, with before/after values. Covers Cloudflare rule changes, CSV rule changes and CSV uploads. Kept per site, last 500 entries.
- **Setup:** CSV format and step-by-step Cloudflare setup.

## CSV Rules

```csv
description,source,target,status,preserve_query_string,enabled
Old page,/old-page,/new-page,301,no,yes
Blog moved,/blog/*,https://news.example.org/${1},301,yes,yes
```

| Column | Required | Notes |
|---|---|---|
| `description` | No | A note for people; not used for matching. |
| `source` | Yes | A path on this site starting with `/`. `*` matches anything. Matching ignores letter case, trailing slashes and the query string. |
| `target` | Yes | A path on this site or a full URL. `${1}`, `${2}`… insert what each `*` matched. |
| `status` | No | 301 (default), 302, 303, 307 or 308. |
| `preserve_query_string` | No | `yes` or `no` (default `no`). Passes the visitor's query string on to the target. |
| `enabled` | No | `yes` or `no` (default `yes`). Disabled rules stay in the file but are skipped. |

- Rules are checked top to bottom; the first enabled match wins. They apply even when a page exists at that path.
- Edit in WordPress (add, edit, move up/down, enable/disable, delete) or download the CSV, edit it in a spreadsheet and upload it. Uploading replaces all CSV rules.
- If someone else changed the rules after you opened the page, your change is refused with a message rather than overwriting theirs.
- On multisite subdirectory sites, paths are relative to the site's home (`/old-page` on `example.org/campaigns/` matches `/campaigns/old-page`).

### Storage

One file per site in the uploads folder, `wp-content/uploads/redirects/` (`uploads/sites/{ID}/redirects/` on multisite), named with the time of the last change in ISO 8601 basic format, for example `redirects-20261122T123700Z.csv`. Every change (an upload or an edit in WordPress) writes a new file and removes the old one, so there is only ever one. Uploads are validated first; if any row has a problem, nothing changes. The folder gets an `index.php` and an `.htaccess` that blocks direct downloads on Apache (Nginx ignores `.htaccess`, but the timestamped name is hard to guess).

Limits: 2 MB and 10,000 rows per file. The parsed list is cached until the file changes.

### Limitation

CSV redirects run inside WordPress, so they don't apply to pages served from a cache (Cloudflare, a caching plugin) or to static files such as `/wp-content/uploads/report.pdf`. Use Cloudflare Rules for those.

## Who can use it

Administrators and editors by default. Override with `roles` in `RFCF_CONFIG` (see below). On multisite, network super admins always have access.

## Configuration (`RFCF_CONFIG` in wp-config.php)

Optional for CSV Rules (only `roles` or `false` matter there); required for Cloudflare Rules.

### Single site

```php
define( 'RFCF_CONFIG', [
	'token'   => 'cfut_your-api-token',
	'domains' => [ 'example.com', 'another.example.net' ],
	'roles'   => [ 'administrator', 'editor' ], // optional, this is the default
] );
```

Numbered entries are ignored on a single site.

### Multisite

Top-level keys are the defaults for every site. An entry keyed by **site ID** replaces any of those keys it sets, for that site only. `false` switches the whole plugin off for a site (no menu, no CSV redirects).

```php
define( 'RFCF_CONFIG', [
	'token'   => 'cfut_your-api-token',
	'domains' => [ 'example.org', 'example.net' ],

	1 => [
		'token'   => 'cfut_token-for-main-site',
		'domains' => [ 'example.org' ],
	],
	3 => [
		'roles' => [ 'administrator' ],
	],
	5 => false,
] );
```

| Key | Notes |
|---|---|
| `token` | Cloudflare API token (`cfut_…` personal, or `cfat_…` account). |
| `domains` | Cloudflare zone names or 32-character zone IDs. Looked up through the API and cached for 12 hours. |
| `roles` | Roles that can use Tools → Redirects. Default `[ 'administrator', 'editor' ]`. |

### Cloudflare API token

My Profile → API Tokens → Create Token → Create Custom Token:

- Zone → Single Redirect → Edit
- Zone → Zone → Read
- Zone Resources: Include → Specific zone, one row per domain
- Optional: Client IP Address Filtering to the web server's IP

## Uninstalling

Deleting the plugin removes the change log, its caches, and each site's `uploads/redirects` folder. Cloudflare rules stay in Cloudflare.

## Changelog

### 1.0.1
- Renamed to **Simple CSV & Cloudflare Redirects**.

### 1.0.0
- First public release, submitted to the WordPress.org Plugin Directory as **Simple CSV & Cloudflare Redirects**.
- CSV Rules: add, edit, reorder, enable, disable and delete in WordPress, or download, edit and upload the CSV. Columns: `description, source, target, status, preserve_query_string, enabled`.
- Cloudflare Rules: manage Cloudflare Single Redirect rules, configured with `RFCF_CONFIG` in wp-config.php.
- Change log of every rule change.
- CSV files are stored in the uploads folder.
