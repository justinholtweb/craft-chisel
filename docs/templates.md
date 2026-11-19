---
title: Twig and Blade
slug: templates
order: 60
summary: The one read-only template helper.
---

> **Craft 6 only, and still in beta.** Chisel is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Chisel (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

Chisel has almost no front-end surface. One helper, for an admin-only status line:

```twig
{% set run = chisel.lastRun('craft:clear-caches:all') %}
{% if run %}Last cache clear: {{ run.status }} ({{ run.finishedAt }}){% endif %}
```

```blade
@php($run = $chisel->lastRun('craft:clear-caches:all'))
@if ($run) Last cache clear: {{ $run['status'] }} @endif
```

`lastRun()` returns `command`, `status`, `exitCode`, `queuedAt`, `finishedAt` and `durationMs` — never
arguments or output — and `null` for anyone who can't view Chisel's history (including every site
visitor). `chisel.canView()` tells you whether to render the markup at all.
