=== Myrk — Enterprise Feature Flags ===
Contributors: bjorn2404
Tags: feature flags, trunk-based development, progressive rollout, devops
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress-native feature flags for enterprise teams. Per-environment control, percentage rollout, user targeting, and circuit breaker protection — no external dependencies.

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
* **Stale flag detection** — visual badge for flags inactive 30+ days
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

== Changelog ==

= 1.0.0 =
* Initial release.
