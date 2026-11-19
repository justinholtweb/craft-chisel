<?php

// Removes the `chiselfr` site (and only that one). Run under bin/harness-lock.
use CraftCms\Cms\Support\Facades\Sites;

if ($fr = Sites::getSiteByHandle('chiselfr')) {
    Sites::deleteSite($fr);
}
echo Sites::getSiteByHandle('chiselfr') ? 'still there' : 'removed';
