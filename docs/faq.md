---
title: FAQ and troubleshooting
slug: faq
order: 80
summary: Waiting runs, missing commands, refused runs.
---

> **Craft 6 only, and still in beta.** Chisel is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Chisel (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

**My run says “Waiting”.** No worker is running the `chisel` queue: `php artisan queue:work --queue=chisel`.

**A command I allowlisted is under “Allowlisted but not available”.** The reason is shown next to it: it
isn't on this install (or the list needs **Refresh**), it's sensitive and not in `sensitiveCommands`, or
it needs an input Chisel never accepts.

**An option is missing from the form.** It takes a value that isn't on the safe list. If it's safe for
your command, add it with `allowedOptions` in the config file. Options that evaluate code, take paths or
reach the network can't be added.

**Why can't I allowlist `tinker` (or `about`)?** `tinker` is forbidden outright; `about` reveals
configuration, so it's allowed only through `sensitiveCommands` in the config file.

**A run was “Refused”.** Something changed between queueing and running: Chisel was switched off, the
command left the allowlist, you lost the permission, or the command's inputs are no longer accepted.
The run page says which.

**Does it work in production?** Only with `enableInProduction => true` in the config file, and Craft 6 is
in beta: use caution on production.
