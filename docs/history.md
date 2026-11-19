---
title: History and audit log
slug: history
order: 40
summary: Who ran what, when, with which inputs and outcome; export and retention.
---

> **Craft 6 only, and still in beta.** Chisel is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Chisel (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

Chisel → History lists every run: the command line (sensitive values masked), status, exit code, who ran
it and when. Filter by status or command, open a run for its output, or **Export CSV** for the audit log
(no output bodies; cells that look like spreadsheet formulas are neutralised).

People without **View Chisel run history** see only their own runs.

Runs older than **Keep runs for** (default 90 days; 0 keeps them) are deleted daily by
`craft:chisel:prune`, which also closes runs left “running” by a worker that died.
