---
title: GraphQL
slug: graphql
order: 70
summary: A thin, read-only run-history query; commands can never be run over GraphQL.
---

> **Craft 6 only, and still in beta.** Chisel is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Chisel (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

Tick **View Chisel run history** on a schema to add:

```graphql
{
  chiselRuns(command: "craft:clear-caches:all", status: "failed", limit: 5) {
    uid command status exitCode queuedAt startedAt finishedAt durationMs
  }
}
```

It's deliberately thin: good for monitoring (“did last night's run fail?”), nothing more. There are no
mutations, and runs expose no arguments (they can hold secrets), no output, no error text and no people.
`limit` defaults to 20 and is capped at 100. Without the component the query doesn't exist. Signed-in
GraphQL requests get it only if the user can view Chisel's history.
