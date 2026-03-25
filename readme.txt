=== Podcast Analytics for OP3 ===
Contributors: materron
Tags: podcast, analytics, statistics, op3, feed
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrate OP3 open podcast analytics with WordPress: prefix your feed automatically and view download stats in your dashboard.

== Description ==

[OP3](https://op3.dev) (Open Podcast Prefix Project) is a **free, open-source podcast analytics service** committed to open data and listener privacy. This plugin integrates OP3 with any WordPress podcast site in minutes.

= What it does =

* **Automatic feed prefix** — Adds `https://op3.dev/e/` before every audio enclosure URL in your RSS feed. Works with PowerPress (Blubrry), Seriously Simple Podcasting, Podlove, and any plugin that generates a standard RSS2 podcast feed.
* **Dashboard widget** — Shows your podcast's downloads for the last 7 days directly on the WordPress dashboard.
* **Statistics page** — A dedicated admin page with download counts per episode, switchable between last 24 hours, 7 days, and 30 days.

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

1. Upload the `op3-podcast-analytics` folder to `/wp-content/plugins/`, or install via **Plugins → Add New → Upload Plugin**.
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

= Will this affect how my podcast sounds or plays? =

No. OP3 redirects are instant (HTTP 302) and completely transparent to listeners and podcast apps.

= Does OP3 store my listeners' IP addresses? =

No. OP3 never stores raw IP addresses. It stores a rotating, salted hash of the IP that cannot be reversed, ensuring listener privacy.

= My stats page shows "No download data available yet". Why? =

OP3 data is updated daily. If you just enabled the prefix, wait 24 hours for the first data to appear.

= Can I use the same bearer token on multiple WordPress sites? =

Yes, if you own all the podcasts. The bearer token is tied to your OP3 identity, not to a specific show. Each site needs its own Show UUID configured.

== Privacy Policy ==

This plugin sends data to the external service **op3.dev** in two ways:

1. **Feed prefix** — When a listener downloads a podcast episode, their request passes through `op3.dev` before reaching your audio file. OP3 logs anonymised request data (no raw IP addresses). See [OP3 Privacy Policy](https://op3.dev/privacy).
2. **Statistics API** — When you view the Statistics page or Dashboard widget, the plugin makes an authenticated request to the OP3 API (`op3.dev/api/1/`) to retrieve download counts for your show. No user data from your WordPress site is sent to OP3.

No data is collected from your site's visitors beyond what OP3 records as part of the redirect.

== Changelog ==

= 1.0.1 =
* Renamed plugin to "Podcast Analytics for OP3" to clarify it is a community integration, not an official OP3 product.

= 1.0.0 =
* Initial release.
* Feed prefix: automatically adds `https://op3.dev/e/` to all audio enclosures in the RSS feed.
* Dashboard widget: shows last 7 days of downloads.
* Statistics page: per-episode download counts with 1/7/30 day periods.
* Compatible with PowerPress, Seriously Simple Podcasting, and any RSS2 podcast feed.
