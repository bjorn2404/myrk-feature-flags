=== Myrk — Enterprise Feature Flags ===
Contributors: bjorn2404
Tags: feature flags, trunk-based development, progressive rollout, devops
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress-native feature flags for enterprise teams — per-environment control, percentage rollout, user targeting, and circuit breaker protection.

== Description ==

Myrk gives enterprise WordPress development teams the feature flag infrastructure they need to ship safely on complex sites.

**Define flags in code. Control state from the database.**

Register flags in your plugin or theme — version controlled, peer reviewable. Enable, disable, and roll out per environment without code changes or redeploys.

= Key features =

* **Code-registered flags** — `Myrk::register()` in PHP, reviewed in Git
* **Per-environment state** — uses `wp_get_environment_type()` automatically
* **Percentage rollout** — deterministic IP-based hashing, no cookies
* **Targeting rules** — role, capability, user ID, email domain
* **Circuit breaker** — `Myrk::attempt()` wraps risky code with automatic fallback
* **Stale flag detection** — visual badge for flags inactive 90+ days
* **REST API** — full CRUD, powers admin UI and pipeline automation
* **WP-CLI** — complete flag management from the command line
* **No external dependencies** — no Redis, no Node, no third-party service

== Installation ==

1. Upload the `myrk` directory to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins > Installed Plugins**.
3. Navigate to **Myrk** in the admin menu.
4. Register your first flag in code: `Myrk::register( 'my_flag', [ 'label' => 'My Flag' ] );`

== Frequently Asked Questions ==

= Does Myrk require any external services? =

No. Myrk stores all data in your WordPress database and requires no external infrastructure.

= What PHP version is required? =

PHP 8.1 or higher. Myrk targets enterprise WordPress teams on modern hosting stacks.

= How does percentage rollout work? =

Myrk uses a deterministic CRC32 hash of the flag key and user identifier. The same user always gets the same flag state for a given percentage — no cookies required.

= Can I use Myrk for A/B testing? =

Yes. The percentage rollout uses deterministic hashing — the same user always sees the same variant without cookies. Pair it with your existing analytics to track outcomes. Myrk controls the split; you measure the results.

== Screenshots ==

1. Flags list — group badges, enabled/disabled status pills, rollout percentage bars, target counts, and stale badges across a realistic set of flags.
2. Groups screen — feature-area groups and sprint groups each showing flag counts and optional external reference links.
3. Edit flag (top) — flag definition card alongside the rollout panel (enabled toggle and percentage slider) and auto-generated PHP/JS code snippets.
4. Edit flag (bottom) — behavior settings (lifecycle, default state, rewind strategy) and a targeting rule locking the flag on for administrators.
5. Stale flag detection — three flags backdated past the 90-day threshold each displaying a STALE badge, demonstrating the built-in hygiene signal.

== Changelog ==

= 1.0.0 =
* Initial release.
