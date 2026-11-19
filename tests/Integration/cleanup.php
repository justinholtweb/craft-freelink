<?php

// Removes everything fixtures.php and the checks created (all prefixed `fl6`).
// Run: docker exec -w /var/www/html ddev-plugin-testing-craft6-web php artisan tinker --execute "require '/var/www/ports/craft-freelink/tests/Integration/cleanup.php';"

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\DB;

$removed = [];

foreach (['fl6', 'fl6Secret'] as $handle) {
    foreach (Entry::find()->section($handle)->status(null)->drafts(null)->revisions(null)->trashed(null)->all() as $e) {
        Elements::deleteElement($e, true);
        $removed['entries'] = ($removed['entries'] ?? 0) + 1;
    }
}
foreach (['fl6', 'fl6Secret'] as $handle) {
    if ($s = Sections::getSectionByHandle($handle)) {
        Sections::deleteSection($s);
        $removed['sections'][] = $handle;
    }
}
foreach (['fl6Page', 'fl6NativePage'] as $handle) {
    if ($t = EntryTypes::getEntryTypeByHandle($handle)) {
        EntryTypes::deleteEntryType($t);
        $removed['entryTypes'][] = $handle;
    }
}
foreach (['fl6Cta', 'fl6Links', 'fl6Native'] as $handle) {
    if ($f = Fields::getFieldByHandle($handle)) {
        Fields::deleteField($f);
        $removed['fields'][] = $handle;
    }
}
if ($u = User::find()->username('fl6editor')->status(null)->one()) {
    Elements::deleteElement($u, true);
    $removed['users'][] = 'fl6editor';
}

// Derived rows for anything gone (FKs cascade on hard deletes; this catches soft-deleted owners).
$removed['index'] = DB::table('freelink_index')->whereNotIn('ownerId', DB::table('elements')->select('id'))->delete();
$removed['checks'] = DB::table('freelink_checks')->where('url', 'like', '%plugin-testing-craft6.ddev.site/fl6/%')->orWhere('url', 'like', '%example.com%')->orWhere('url', 'like', 'http://%')->delete();
$removed['migrations'] = DB::table('freelink_migrations')->delete();

echo json_encode($removed).PHP_EOL;
