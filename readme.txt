=== Myrk Feature Flags ===
Contributors: bjorn2404
Tags: feature flags, feature toggles, progressive rollout, developer tools, a/b testing
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress feature flags and feature toggles with progressive rollout, per-environment control, user targeting, and circuit breaker protection. No external services required.

== Description ==

WordPress feature flags and feature toggles built for development teams shipping on complex sites. No external feature flag service, no Redis, no third-party account — all state lives in your WordPress database.

**Define flags in code. Control state from the admin.**

Register feature flags in your plugin or theme — version controlled and peer reviewable. Enable, disable, and roll out per environment without code changes or redeploys.

Designed for teams practicing trunk-based development and continuous delivery — merge to main confidently and control what users see from the database.

= Coming from a SaaS feature flag service? =

Myrk works differently: your codebase is the source of truth. Flags are declared with `Myrk::register()` in PHP and committed to your repo — they appear in the admin automatically. The admin controls state only; it does not create flags. This keeps your flag inventory version controlled and peer reviewed, the same as everything else in your codebase.

= Key features =

* **Code-registered flags** — `Myrk::register()` in PHP, reviewed in Git
* **Per-environment state** — uses `wp_get_environment_type()` automatically
* **Progressive rollout** — deterministic percentage rollout, no cookies required
* **Targeting rules** — role, capability, user ID, email domain
* **Circuit breaker** — `Myrk::attempt()` wraps risky code with automatic fallback
* **Stale flag detection** — visual badge for feature toggles inactive 90+ days
* **REST API** — full CRUD, powers admin UI and CI/CD pipeline automation
* **WP-CLI** — complete feature flag management from the command line
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
