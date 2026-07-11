=== Podcast Analytics for OP3 ===
Contributors: materron
Tags: podcast, analytics, statistics, op3, feed
Requires at least: 6.3
Tested up to: 7.0.1
Requires PHP: 8.0
Stable tag: 2.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrate OP3 open podcast analytics with WordPress: prefix your feed automatically and view download stats in your dashboard.

== Description ==

[OP3](https://op3.dev) (Open Podcast Prefix Project) is a **free, open-source podcast analytics service** committed to open data and listener privacy. This plugin integrates OP3 with any WordPress podcast site in minutes.

= What it does =

* **Automatic feed prefix** — Adds `https://op3.dev/e/` before every audio enclosure URL in your RSS feed. Works with PowerPress (Blubrry), Seriously Simple Podcasting, Podlove, and any plugin that generates a standard RSS2 podcast feed.
* **Dashboard widget** — Shows your podcast's downloads for the last 7 days directly on the WordPress dashboard.
* **Statistics page** — A dedicated admin page with download counts per episode, switchable between last 24 hours, 7 days, and 30 days.
* **Private podcast support** — Podcasts behind a restricted/password-protected feed (e.g. with Restrict Content Pro) get their own self-hosted download-tracking endpoint instead of the OP3 prefix, since OP3 cannot access authenticated feeds by design. Statistics for private podcasts are calculated from your own database and shown alongside your public podcasts.
* **Apps & devices breakdown** — See which podcast apps (Spotify, Apple Podcasts, Overcast...) your listeners use, for both public and private podcasts.
* **Country map** — A world map colored by download volume, plus a ranked country list, for both public and private podcasts (private podcasts require a free MaxMind GeoLite2 license key).
* **Custom date range** — Pick any "from/to" range for your stats, in addition to the quick 24h/7d/30d tabs.
* **Best time to publish** — Charts showing downloads by hour of day and by weekday, in your site's own timezone.
* **Unique listeners** — A deduplicated listener count, for both public and private podcasts.
* **Audience overlap** — When two or more podcasts are shown together, see how many listeners they share, as a matrix and a ranked list. Public podcasts are compared against public podcasts, and private against private — see the FAQ below for why they're never mixed.

= How the OP3 prefix works =

OP3 is a transparent redirect service. When a listener downloads an episode:

1. Their app requests `https://op3.dev/e/yoursite.com/episode.mp3`
2. OP3 logs the download anonymously and immediately redirects to `https://yoursite.com/episode.mp3`
3. The audio file is served normally from your server

Your audio files are not modified or hosted anywhere else. Only the URL in the RSS feed changes. Listener privacy is protected — OP3 never stores raw IP addresses.

= Why OP3? =

* **100% free and open source** — no subscription, no lock-in
* **No signup required** to start measuring — just add the prefix
* **Open data** — your stats are publicly accessible to anyone, including app developers
* **Privacy-first** — no raw IPs stored, no tracking pixels
* Every show automatically gets a free public stats page at `op3.dev/show/{uuid}`

= Requirements =

* A WordPress site with a podcast RSS feed
* A bearer token from [op3.dev/api/keys](https://op3.dev/api/keys) for statistics (the feed prefix works without one)

== Installation ==

1. Upload the `podcast-analytics-for-op3` folder to `/wp-content/plugins/`, or install via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **OP3 Analytics → Settings** and:
   * Enable the OP3 prefix
   * Paste your bearer token from [op3.dev/api/keys](https://op3.dev/api/keys)
   * Enter your Show UUID (visible in your OP3 stats page URL: `op3.dev/show/{uuid}`)
4. Open your RSS feed in a browser and confirm that audio `<enclosure>` URLs start with `https://op3.dev/e/`.

== Frequently Asked Questions ==

= Does this work with PowerPress / Blubrry? =

Yes. The plugin hooks into WordPress's RSS feed output buffer, so it is completely independent of whichever podcast plugin you use to manage your episodes.

= Can I use the prefix without an OP3 account? =

Yes. The feed prefix starts working as soon as you enable it — no token needed. The statistics section (dashboard widget and stats page) requires a bearer token from op3.dev to retrieve download data.

= What is the difference between the API Key and the bearer token? =

On op3.dev, your API Key is your identity, but the credential used in API calls is the **bearer token** associated with that key. You can generate or regenerate your bearer token at [op3.dev/api/keys](https://op3.dev/api/keys). Paste the bearer token (not the API Key itself) into the plugin settings.

= Will this slow down my RSS feed? =

No. The URL rewriting is a regex string replacement done in PHP memory before the feed is sent to the client. It adds no network latency.

= Does OP3 store my listeners' IP addresses? =

No. OP3 never stores raw IP addresses. It stores a rotating, salted hash of the IP that cannot be reversed, ensuring listener privacy.

= My stats page shows "No download data available yet". Why? =

OP3 data is updated daily. If you just enabled the prefix, wait 24 hours for the first data to appear.

= Can I use the same bearer token on multiple WordPress sites? =

Yes, if you own all the podcasts. The bearer token is tied to your OP3 identity, not to a specific show. Each site needs its own Show UUID configured.

= I have a private/restricted podcast feed. Can I still get statistics? =

Yes, since v2.1.0. Mark the podcast as "Privado" in the settings and set its Feed slug (the `/feed/{slug}/` part of your restricted feed's URL). The plugin will route its downloads through a self-hosted tracking endpoint on your own site instead of the OP3 prefix, and its statistics will appear alongside your public podcasts. Downloads are logged without ever storing raw IP addresses (only a daily-rotating salted hash).

= Does the country map work for private podcasts too? =

Yes, but it requires a free MaxMind GeoLite2 license key (sign up at [maxmind.com/en/geolite2/signup](https://www.maxmind.com/en/geolite2/signup)), added in **OP3 Analytics → Settings**. Public podcasts get country data directly from the OP3 API and don't need this. The plugin never stores raw IP addresses — it resolves the country at the moment of the download and discards the IP immediately.

= How does audience overlap work, and why are public and private podcasts never compared to each other? =

The Statistics page's network view (2+ podcasts shown together) includes an audience-overlap section: a matrix and a ranked list showing how many listeners each pair of podcasts has in common.

This requires a way to recognise "the same listener" across two shows, and public and private podcasts identify listeners very differently:

* **Public podcasts** use OP3's `audienceId`, a stable identifier assigned by OP3 itself, consistent across the whole period you're viewing.
* **Private podcasts** use this plugin's own privacy-preserving IP hash, which **rotates daily** (a new random salt each day) so the raw IP is never retained. Two different private podcasts *on the same WordPress site* still produce the *same* hash for the same listener on the same day, because the salt is site-wide — so private-vs-private comparison works correctly. But this hash has no relationship whatsoever to OP3's `audienceId`, so a public podcast can never be meaningfully compared against a private one.

Because of this, the plugin always keeps public and private podcasts in **separate groups** for audience overlap: it computes a public-vs-public matrix and a private-vs-private matrix independently, and only shows each one when that group actually has 2 or more comparable podcasts with data. It never attempts to compare across the two groups.

If you select only one podcast (or only one podcast per group), the audience-overlap section doesn't appear at all — it needs at least two comparable shows to say anything meaningful.

== External Services ==

This plugin connects to two external services. Both are optional in the sense that the plugin's core feature (the feed prefix) works without any account, but statistics require them.

= OP3 (op3.dev) =

Used for public podcasts: the feed prefix and the statistics API.

* **What it's for:** Adds an anonymous download-tracking prefix to your public podcast feed enclosures, and retrieves the resulting download counts, per-episode data, apps/devices, and country/region breakdown for the Statistics page and Dashboard widget.
* **What is sent and when:** When a listener downloads a podcast episode, their request passes through `op3.dev` before reaching your audio file — OP3 logs anonymised request data (no raw IP addresses are stored by OP3). Separately, when you view the Statistics page or Dashboard widget, the plugin makes an authenticated request to the OP3 API (`op3.dev/api/1/`) using your bearer token to retrieve this data. No personal data from your WordPress site (user accounts, post content, etc.) is sent to OP3.
* **Service URL:** [https://op3.dev](https://op3.dev)
* **Terms of Service:** [https://op3.dev/terms](https://op3.dev/terms)
* **Privacy Policy:** [https://op3.dev/privacy](https://op3.dev/privacy)

= MaxMind GeoLite2 (optional, private podcasts only) =

Only used if you mark a podcast as private AND enter a MaxMind license key in the plugin settings. Public podcasts never use this service (they already get country data from OP3).

* **What it's for:** Resolves the country of a private podcast's listeners from their IP address, using a local country-ranges database built from MaxMind's free GeoLite2 data.
* **What is sent and when:** Once (and periodically, weekly, to keep the data current), the plugin downloads the GeoLite2 Country CSV database from MaxMind's servers using your license key. No visitor data is ever sent to MaxMind — the lookup happens entirely on your own server against the locally stored database, and the raw IP address is never stored, only a country code and a daily-rotating salted hash used to deduplicate listeners.
* **Service URL:** [https://www.maxmind.com](https://www.maxmind.com)
* **Terms of Service:** [https://www.maxmind.com/en/end-user-license-agreement](https://www.maxmind.com/en/end-user-license-agreement)
* **Privacy Policy:** [https://www.maxmind.com/en/privacy-policy](https://www.maxmind.com/en/privacy-policy)

== Privacy Policy ==

No data is collected from your site's visitors beyond what is described in the External Services section above. IP addresses are never stored in raw form by this plugin — only a daily-rotating salted hash (for private-podcast unique-listener deduplication) and, when GeoLite2 is configured, a resolved country code.

== Credits ==

The world map used in the country statistics (`admin/img/world-map.svg`) is based on ["Simple SVG World Map"](https://github.com/flekschas/simple-world-map) by Fritz Lekschas, editing original artwork by Al MacDonald, licensed under [CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/).

== Changelog ==

= 2.3.1 (2026-07-10) =
* New: audience overlap report (matrix + ranked list) showing how many listeners two or more podcasts have in common, when 2+ podcasts are shown together. Public and private podcasts are always kept in separate comparisons — see the FAQ for why.

= 2.3.0 (2026-07-10) =
* New: downloads by hour-of-day and by weekday charts ("best time to publish"), converted to your site's own timezone, for both public and private podcasts.
* New: unique listeners count, shown next to the total downloads. Exact for public podcasts (OP3's stable audience identifier) and for private podcasts within a 24h period; approximate for private podcasts over longer periods, since the privacy-preserving IP hash rotates daily.
* Changed: the episode list now shows the top 10 by default, with a "Ver todos" button to expand the rest — much less scrolling to reach the charts below.
* Fixed: the country map's floating tooltip could get covered by the bundled SVG's own native browser tooltip ("Simple World Map") on small countries.

= 2.2.0 (2026-07-10) =
* New: apps & devices breakdown, for both public podcasts (from OP3's per-download data) and private ones (detected from the User-Agent).
* New: country map and ranked list, colored by download volume. Public podcasts get country data directly from OP3; private podcasts need a free MaxMind GeoLite2 license key (added in Settings).
* New: custom date range picker (desde/hasta) alongside the 24h/7d/30d tabs, for both public and private podcasts.
* Changed: default statistics period is now 24h instead of 30 days, so the page loads faster by default.
* Fixed: an AJAX race condition where rapidly toggling the podcast selector could show a stale result if an earlier request resolved after a newer one.

= 2.1.0 (2026-07-10) =
* New: private podcast support. Podcasts behind a restricted/password-protected feed (e.g. Restrict Content Pro) can now be marked as private with a Feed slug, routing their downloads through a self-hosted tracking endpoint instead of the OP3 prefix (which cannot access authenticated feeds). Statistics for private podcasts are calculated from your own database, with episode titles resolved from your own posts, and shown alongside your public podcasts in the same network view.
* Fixed: the OP3 prefix rewrite is now scoped per feed (via the new Feed slug setting) instead of applying site-wide, preventing a public podcast's prefix from leaking into other feeds on multi-podcast sites.
* Settings page: fields not relevant to a podcast's public/private status (Show UUID/GUID vs. Feed slug) are now visually disabled to reduce confusion.

= 2.0.8 (2026-04-30) =
* Fixed: feed prefix now applies immediately after activation, even before configuring a Show UUID. The prefix only stops when all configured podcasts are explicitly marked as private.

= 2.0.6 (2026-04-07) =
* Episode publish date now shown in statistics tables (formatted according to WordPress date settings).
* Episode titles now resolved universally for all podcast hosts using the episodeId field from OP3 download data.
* Fallback title resolution by itemGuid for PrestoCast-style hosts.

= 2.0.5 (2026-04-07) =
* Improved episode title resolution: cross-references OP3 episodeId from download rows with episode list from show endpoint, making titles work reliably for all podcast hosts.

= 2.0.4 (2026-04-07) =
* Episode titles now fetched from OP3 show endpoint and matched against audio filenames (itemGuid strategy for PrestoCast).

= 2.0.3 (2026-04-07) =
* Fixed dashboard widget pagination (left/right arrows between podcasts).
* Added episode title enrichment from OP3 show info endpoint.

= 2.0.2 (2026-04-06) =
* Fixed statistics page rendering: initial table now renders inside #op3pa-stats-container so period/podcast changes correctly replace it via AJAX.

= 2.0.1 (2026-04-06) =
* Network view: episodes from all podcasts now shown in a single merged table sorted by downloads, with a Podcast column identifying each episode's show.
* Network header now shows all podcast names with individual links to their OP3 stats pages.
* Settings page texts improved with clearer Spanish descriptions for all fields.

= 2.0.0 (2026-04-06) =
* Multi-podcast support: configure any number of podcasts, each with name, Show UUID and optional Podcast GUID.
* Global bearer token: a single token covers all configured podcasts.
* Private podcast flag: private podcasts are excluded from the OP3 prefix and from statistics.
* Automatic migration from v1.x settings (bearer token and podcast data preserved on update).
* Network view: statistics page aggregates downloads across all podcasts or a custom selection.
* Network ranking table: shows which podcast in the network gets the most downloads.
* Dashboard widget with left/right pagination between podcasts when multiple are configured.
* Print-friendly statistics page (Ctrl+P / Cmd+P generates a clean PDF report).

= 1.0.2 (2026-04-02) =
* Fixed output buffer handling: ob_start() is now explicitly closed with ob_get_clean() on the shutdown action.

= 1.0.1 (2026-03-25) =
* Renamed plugin to "Podcast Analytics for OP3" to clarify it is a community integration, not an official OP3 product.

= 1.0.0 (2026-03-18) =
* Initial release: feed prefix, dashboard widget and statistics page with 1/7/30 day periods.
