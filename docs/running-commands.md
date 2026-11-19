---
title: Running commands
slug: running-commands
order: 30
summary: The command form, the run page, live output and exit codes.
---

> **Craft 6 only, and still in beta.** Chisel is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Chisel (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

## The form

Each command's form is built from its signature: one field per argument (a list for array arguments, one
value per line), a switch per flag, and a field per allowed value option, with the command's own
descriptions and defaults. `--site` is a menu of your sites.

## Running

Enter your password (if you haven't in the last few minutes) and choose **Run on the queue**. Chisel
queues the run and opens its page, which shows the status (Waiting, Running, Succeeded, Failed or Refused),
the exit code, how long it took and the output as it arrives. If it stays on *Waiting*, no worker is
running the `chisel` queue.

A second submit while your run of the same command is waiting or running opens that run instead.

## Limits

- **Time limit** (Settings, default 600 s): the worker stops a longer run. Keep it under your worker's
  own `--timeout`.
- **Output kept per run** (default 512 KB): anything beyond is dropped and the run is marked truncated.
