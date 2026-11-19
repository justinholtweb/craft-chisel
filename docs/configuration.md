---
title: Configuration
slug: configuration
order: 50
summary: Settings, the config file, and the switches only the config file can change.
---

> **Craft 6 only, and still in beta.** Chisel is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Chisel (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

**Chisel → Settings** (admins, password required): the allowlist, how long runs are kept, the output
limit, the time limit, and the sidebar label and icon. Settings → Plugins → Chisel opens the same page.

`config/craft/chisel.php` overrides any of those, and holds switches the control panel can't change:

```php
return [
    'enabled' => true,
    'enableInProduction' => false,
    'allowedCommands' => null,        // a list replaces the CP allowlist (shown read-only there)
    'sensitiveCommands' => [],
    'allowedOptions' => [             // value options beyond the built-in safe list
        'my:report' => ['format'],
    ],
    'requireAdmin' => true,
    'elevationSeconds' => 300,
    'queue' => 'chisel',
    'connection' => null,
    'historyRetentionDays' => 90,
    'maxOutputKb' => 512,
    'timeout' => 600,
];
```
