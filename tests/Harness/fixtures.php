<?php

// Chisel harness fixtures (idempotent). Users (password "chisel-pass-1"):
//   chisel_runner  CP + Chisel, may run chisel:probe, may NOT view history
//   chisel_viewer  CP + Chisel, may view history, may run nothing
// GraphQL schemas/tokens: chisel_full (run history component), chisel_none (no Chisel component).
// Token = "chisel{name}" padded with 0 to 32 chars. Prints the user IDs as JSON.
// Run with: ddev artisan tinker --execute "require 'storage/chisel-fixtures.php';"

use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Data\GqlToken;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Gql;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Users;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\User\UserPermissions;

$people = [
    'runner' => ['accessCp', 'accessPlugin-chisel', 'chisel:runCommands', 'chisel:run:chisel:probe'],
    'viewer' => ['accessCp', 'accessPlugin-chisel', 'chisel:viewHistory'],
];
$ids = [];

foreach ($people as $name => $permissions) {
    $username = "chisel_{$name}";
    $user = User::find()->username($username)->status(null)->one() ?? new User;
    $user->username = $username;
    $user->email = "{$username}@chisel.test";
    $user->fullName = ucfirst($name).' Chisel';
    $user->newPassword = 'chisel-pass-1';
    $user->active = true;
    $user->pending = false;
    $user->admin = false;
    Elements::saveElement($user) || throw new RuntimeException(json_encode($user->errors()->all()));
    Users::assignUserToGroups($user->id, []);
    app(UserPermissions::class)->saveUserPermissions($user->id, $permissions);
    $ids[$name] = $user->id;
}

$site = 'sites.'.Sites::getPrimarySite()->uid.':read';
foreach (['full' => [$site, 'chisel.runs:read'], 'none' => [$site]] as $name => $scope) {
    $schema = collect(Gql::getSchemas())->first(fn ($s) => $s->name === "chisel_{$name}") ?? new GqlSchema;
    $schema->name = "chisel_{$name}";
    $schema->scope = $scope;
    Gql::saveSchema($schema) || throw new RuntimeException(json_encode($schema->errors()->all()));

    $token = collect(Gql::getTokens())->first(fn ($t) => $t->name === "chisel_{$name}") ?? new GqlToken;
    $token->name = "chisel_{$name}";
    $token->schemaId = $schema->id;
    $token->accessToken = str_pad("chisel{$name}", 32, '0');
    Gql::saveToken($token) || throw new RuntimeException(json_encode($token->errors()->all()));
}
Gql::invalidateCaches();

echo json_encode($ids);
