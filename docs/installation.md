---
title: Installation
slug: installation
order: 10
summary: Requirements, the Craft 6 beta caution, install, the queue worker and your first command.
---

> **Craft 6 only, and still in beta.** Chisel is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Chisel (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

## Requirements

- Craft CMS 6.0.0-beta.1 or later, PHP 8.5.
- A queue worker that runs the `chisel` queue. Chisel never runs commands in a web request: Craft
  registers its `craft:*` commands only on the command line, and a worker keeps long commands away from
  your web server.

## Install

```bash
composer require justinholtweb/craft-chisel:^6.0.0-beta.1
php artisan craft:plugin:install chisel
```

## Start a worker for the `chisel` queue

```bash
php artisan queue:work --queue=chisel
# or, if you already run a worker:
php artisan queue:work --queue=default,chisel
```

Keep the queue Chisel's own: Craft's “run the queue from web requests” feature processes the default
queue, and commands can't run there. Chisel warns you if its queue is shared with it.

## Your first command

1. `php artisan craft:chisel:refresh` — or Chisel → Commands → **Refresh**, which asks the worker.
2. Chisel → Settings: tick a command (the **Common** badge marks read-only reports and cache clears),
   enter your password, **Save**.
3. Chisel → Commands → the command → fill in the form → your password → **Run on the queue**.
