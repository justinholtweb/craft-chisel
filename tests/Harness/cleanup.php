<?php

// Removes Chisel's harness fixtures: chisel_* users, GraphQL schemas/tokens, and the run history.
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Gql;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\Chisel\Models\Run;

foreach (User::find()->username(['chisel_runner', 'chisel_viewer'])->status(null)->all() as $user) {
    Elements::deleteElement($user, true);
}
foreach (Gql::getTokens() as $t) {
    if (str_starts_with((string) $t->name, 'chisel_')) {
        Gql::deleteTokenById($t->id);
    }
}
foreach (Gql::getSchemas() as $s) {
    if (str_starts_with((string) $s->name, 'chisel_')) {
        Gql::deleteSchemaById($s->id);
    }
}
Gql::invalidateCaches();
Run::query()->delete();
echo 'ok';
