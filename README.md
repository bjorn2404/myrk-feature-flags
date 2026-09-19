# Myrk Feature Flags

Feature flags and toggles for WordPress. Define flags in PHP, control state from the admin. No external services, no Redis, no third-party accounts required.

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/myrk-feature-flags)](https://wordpress.org/plugins/myrk-feature-flags/)
[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/myrk-feature-flags)](https://wordpress.org/plugins/myrk-feature-flags/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

## Install

**From WordPress.org:**
[wordpress.org/plugins/myrk-feature-flags](https://wordpress.org/plugins/myrk-feature-flags/)

**Via Composer (wpackagist):**
```bash
composer require wpackagist-plugin/myrk-feature-flags
```

## Quick start

Register a flag in your plugin or theme:

```php
Myrk::register( 'new_checkout', [
    'label'       => 'New Checkout Flow',
    'description' => 'Redesigned checkout with progressive rollout.',
    'lifecycle'   => 'release',
] );
```

Check the flag in PHP:

```php
if ( Myrk::is_enabled( 'new_checkout' ) ) {
    // show new experience
}
```

Or in JavaScript — state is bootstrapped from PHP automatically, no extra request needed:

```javascript
if ( myrkIsEnabled( 'new_checkout' ) ) {
    document.body.classList.add( 'checkout-v2' );
}
```

Flags registered in code appear in the WordPress admin automatically. Enable, disable, and configure rollout from there without touching code.

## Features

- Code-registered flags in PHP, reviewed in Git
- Per-environment state via `wp_get_environment_type()`
- Progressive rollout with deterministic percentage hashing
- User targeting by role, capability, user ID, or email domain
- A/B testing support via deterministic variant assignment
- Circuit breaker with `Myrk::attempt()` for automatic fallback
- Stale flag detection with visual badges after 90 days of inactivity
- Full REST API for CI/CD pipeline integration
- WP-CLI commands for flag management
- No external dependencies

## Requirements

- WordPress 7.0+
- PHP 8.1+

## Contributing

Bug reports and pull requests are welcome. Please review the [open issues](https://github.com/bjorn2404/myrk-feature-flags/issues) before submitting a new one.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.
