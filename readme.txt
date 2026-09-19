=== Myrk Feature Flags ===
Contributors: bjorn2404
Tags: feature flags, feature toggles, progressive rollout, developer tools, a/b testing
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress feature flags and feature toggles with progressive rollout, per-environment control, user targeting, and circuit breaker protection.

== Description ==

Test new features safely and roll out changes to a percentage of users without touching external services or setting up extra infrastructure. Everything runs inside WordPress. No third-party accounts, no Redis, no additional servers required.

**Define flags in code. Control state from the admin.**

Register feature flags directly in your plugin or theme so they are version controlled and reviewable in code. Enable, disable, or gradually deploy to users from the WordPress admin without pushing new code. Progressive rollout, user targeting, and gradual deployment are all built in.

Works well for teams using trunk-based development and continuous delivery. Merge to main and control what users see from the database rather than through separate deploys.

= Coming from an external feature flag service? =

Myrk takes a different approach. Your codebase is the source of truth. Flags are declared with `Myrk::register()` in PHP and committed to your repo, and they show up in the admin automatically. The admin controls state only and does not create flags. Your flag inventory stays version controlled and peer reviewed like everything else in your codebase.

= Key features =

* **Code-registered flags** — declare with `Myrk::register()` in PHP, reviewed in Git
* **Per-environment state** — reads `wp_get_environment_type()` automatically
* **Progressive rollout** — deterministic percentage rollout, no cookies needed
* **User targeting** — by role, capability, user ID, or email domain
* **A/B testing** — deterministic hashing so the same user always sees the same variant
* **Circuit breaker** — `Myrk::attempt()` wraps risky code with automatic fallback
* **Stale flag detection** — visual badge for feature toggles inactive for 90 or more days
* **REST API** — full CRUD that powers the admin UI and CI/CD pipeline automation
* **WP-CLI** — manage feature flags from the command line
* **No external dependencies** — no Redis, no Node, no third-party service

== Source Code ==

The compiled JavaScript in `build/` is generated from source files in `assets/js/` using `@wordpress/scripts` (webpack). Both the source and compiled files are included in the plugin zip. To rebuild the compiled assets, install Node.js dependencies with `pnpm install` and run `pnpm build`.

== Installation ==

1. Upload the `myrk-feature-flags` directory to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins > Installed Plugins**.
3. Navigate to **Myrk** in the admin menu.
4. Register your first flag in code: `Myrk::register( 'my_flag', [ 'label' => 'My Flag' ] );`

== Frequently Asked Questions ==

= Does Myrk require any external services? =

No. Myrk stores all data in your WordPress database and requires no external infrastructure.

= What PHP version is required? =

PHP 8.1 or higher. Myrk targets enterprise WordPress teams on modern hosting stacks.

= How does percentage rollout work? =

Myrk uses a deterministic CRC32 hash of the flag key and user identifier. The same user always gets the same flag state for a given percentage, with no cookies required.

= Can I use Myrk for A/B testing? =

Yes. The percentage rollout uses deterministic hashing so the same user always sees the same variant without cookies. Pair it with your existing analytics to track outcomes. Myrk controls the split and you measure the results.

== Screenshots ==

1. Flags list showing group badges, enabled/disabled status pills, rollout percentage bars, target counts, and stale badges across a realistic set of flags.
2. Groups screen with feature-area groups and sprint groups each showing flag counts and optional external reference links.
3. Edit flag (top) showing the flag definition card alongside the rollout panel with an enabled toggle, percentage slider, and auto-generated PHP/JS code snippets.
4. Edit flag (bottom) showing behavior settings for lifecycle, default state, and rewind strategy, plus a targeting rule locking the flag on for administrators.
5. Stale flag detection with three flags backdated past the 90-day threshold each displaying a STALE badge.

== Development ==

* [GitHub Repository](https://github.com/bjorn2404/myrk-feature-flags)
* [Report a Bug or Suggest a Feature](https://github.com/bjorn2404/myrk-feature-flags/issues)
* [Releases & Changelog](https://github.com/bjorn2404/myrk-feature-flags/releases)

== Changelog ==

= 1.0.0 =
* Initial release.
