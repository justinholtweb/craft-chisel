<?php

// Chisel harness: a second site, `chiselfr` (French, <primary>/chiselfr/), owned by Chisel's checks.
// Other agents add and remove their own sites; never touch theirs. Idempotent.
// Remove with multisite-cleanup.php (under bin/harness-lock).
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;

$fr = Sites::getSiteByHandle('chiselfr');
if (! $fr) {
    $primary = Sites::getPrimarySite();
    $fr = new Site;
    $fr->groupId = $primary->groupId;
    $fr->name = 'Chisel Français';
    $fr->handle = 'chiselfr';
    $fr->language = 'fr-FR';
    $fr->hasUrls = true;
    // Absolute: Craft 6 beta.1 doesn't resolve `@web` in site base URLs.
    $fr->baseUrl = rtrim((string) $primary->getBaseUrl(), '/').'/chiselfr/';
    $fr->enabled = true;
    Sites::saveSite($fr) || throw new RuntimeException('site: '.json_encode($fr->errors()->all()));
}
echo json_encode(['site' => $fr->id]);
