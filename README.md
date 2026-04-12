# FisHotel Misc Plugin

A modular container plugin for WordPress with a dark theme admin interface.

## Overview

FisHotel Misc Plugin provides a framework for managing independent feature "sections" through a unified dark-themed dashboard. Each section is a self-contained module that can be enabled or disabled independently.

## Requirements

- WordPress 5.8+
- PHP 7.4+

## Installation

1. Upload the `fishotel-misc-plugin` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **FisHotel Tools → Dashboard** to manage sections.

## Architecture

```
fishotel-misc-plugin/
├── fishotel-misc-plugin.php        # Bootstrap & autoloader
├── includes/
│   ├── class-plugin.php            # Main plugin orchestrator
│   ├── class-section-manager.php   # Section discovery & toggling
│   └── sections/
│       └── announcer/              # Example section (placeholder)
├── admin/
│   ├── css/admin-style.css         # Dark theme styles
│   ├── js/admin-script.js          # Toggle & AJAX handling
│   └── views/dashboard.php         # Dashboard template
└── README.md
```

## Creating a Section

1. Create a directory under `includes/sections/` (e.g. `my-feature/`).
2. Add a class file named `class-my-feature.php`.
3. Implement the required static methods:

```php
namespace FisHotel\Misc\Sections\My_feature;

class My_feature {

    public static function get_section_info() {
        return [
            'name'        => 'My Feature',
            'description' => 'A brief description.',
            'icon'        => 'dashicons-star-filled',
        ];
    }

    public static function boot() {
        // Initialise your feature hooks here.
    }

    public static function register_menu() {
        // Optional: add a submenu page.
    }
}
```

The Section Manager will automatically discover and register the section.

## Version

0.22 — Replace redirect with wp_send_json_success in Performance save handler; add AJAX form submit.

0.21 — Fix Performance save handler: switch to wp_ajax hook and admin-ajax.php endpoint.

0.20 — Fix Performance toggle saving error; scope dashboard AJAX handler, add try/catch and AJAX guard.

0.19 — Add CSS/JS file combiner to Performance section.

0.18 — Add Performance Optimization section (script cleanup, cache headers, gzip, query-string removal).

0.17 — Defer plugin_dir_url() to plugins_loaded hook to fix early-load notice.

0.16 — Hide theme skip links when announcement bar is present.

0.15 — Raise z-index to 9999999 to fix theme skip links overlapping bar.

0.14 — Fix dark theme: white gap, white editor, TinyMCE iframe styling.

0.13 — Fix 3800px bar: replace the_content filter, add max-height constraint.

0.12 — Force position:fixed via JS inline styles to override Elementor CSS.

0.11 — Bulletproof rendering: wp_footer + JS DOM relocation + position:fixed.

0.10 — Complete rewrite: static positioning with CSS sticky, no body padding hacks.

0.9 — Fix bar stretching to full viewport; inline critical styles, explicit constraints.

0.8 — Switch to wp_body_open for rendering, with wp_footer fallback.

0.7 — Fix announcement bars not displaying (position: fixed for all bars).

0.6 — Move admin menu to position 4 (below Theme Panel, above Posts).

0.5 — Force dark theme with !important on whitelisted plugin pages.

0.4 — Fix update checker to read version from main branch (no releases needed).

0.3 — Redesign announcer admin: sidebar nav, list table toggles, dark theme, preview.

0.2 — Add Announcer section and GitHub update checker.

0.1 — Initial dark theme plugin framework.
