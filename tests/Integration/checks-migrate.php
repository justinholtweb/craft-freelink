<?php

// FreeLink 6: migrating Craft 6's own Link field (fl6Native) to FreeLink, dry run then real.
// Run: docker exec -w /var/www/html ddev-plugin-testing-craft6-web php artisan tinker --execute "require '/var/www/ports/craft-freelink/tests/Integration/checks-migrate.php';"

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Link;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Services\Migrate;

$pass = 0;
$fail = 0;
$check = function (string $name, bool $ok, mixed $detail = null) use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === null ? '' : ' — '.json_encode($detail)).PHP_EOL;
};

$live = Entry::find()->slug('fl6-target-live')->status(null)->one();
$type = EntryTypes::getEntryTypeByHandle('fl6NativePage');
$values = [
    'fl6-native-url' => ['type' => 'url', 'value' => 'https://native.example.com/a', 'label' => 'Native', 'target' => '_blank', 'class' => 'btn'],
    'fl6-native-entry' => ['type' => 'entry', 'value' => $live->id],
    'fl6-native-email' => ['type' => 'email', 'value' => 'native@example.com'],
];
$ids = [];
$field = Fields::getFieldByHandle('fl6Native');

if ($field instanceof Link) {
    foreach ($values as $slug => $value) {
        $e = Entry::find()->section('fl6')->slug($slug)->status(null)->one() ?? new Entry(['sectionId' => $live->sectionId, 'typeId' => $type->id]);
        $e->title = $slug;
        $e->slug = $slug;
        $e->setFieldValue('fl6Native', $value);
        Elements::saveElement($e) || throw new RuntimeException(json_encode($e->errors()->all()));
        $ids[$slug] = $e->id;
    }
    $raw = DB::table('elements_sites')->where('elementId', $ids['fl6-native-entry'])->value('content');
    $check('native Link value stored as a reference tag', str_contains((string) $raw, '{entry:'.$live->id), $raw);

    $migrate = app(Migrate::class);
    $before = DB::table('elements_sites')->whereIn('elementId', $ids)->pluck('content', 'elementId')->all();
    $dry = $migrate->runMigration('craftlink', 'fl6Native', true);
    $after = DB::table('elements_sites')->whereIn('elementId', $ids)->pluck('content', 'elementId')->all();
    $check('dry run succeeds', $dry['success'] && (bool) preg_match('/\[DRY RUN\] Would convert \d+ value\(s\), \d+ of them element links/', implode("\n", $dry['log'])), $dry['log']);
    $check('dry run changes nothing', $before === $after && Fields::getFieldByHandle('fl6Native') instanceof Link && DB::table('freelink_migrations')->count() === 0);

    $real = $migrate->runMigration('craftlink', 'fl6Native', false, true);
    $check('real run succeeds (with a backup)', $real['success'] && str_contains(implode("\n", $real['log']), 'Database backed up'), $real['log']);
    echo implode("\n", array_map(fn ($l) => "    $l", $real['log'])).PHP_EOL;
    echo "Field layouts are cached for this request; run again to check the converted values.\n";
    echo "$pass passed, $fail failed".PHP_EOL;

    return;
} else {
    echo "(fl6Native is already a FreeLink field — checking the result only)\n";
    foreach (array_keys($values) as $slug) {
        $ids[$slug] = Entry::find()->section('fl6')->slug($slug)->status(null)->one()?->id;
    }
}

Fields::refreshFields();
$field = Fields::getFieldByHandle('fl6Native');
$check('field is now a FreeLink field, same ID/handle', $field instanceof FreeLinkField && $field->handle === 'fl6Native', $field ? $field::class : null);
$check('settings converted', $field instanceof FreeLinkField && $field->getEnabledTypeHandles() === ['url', 'entry', 'email'] && $field->showLabel && $field->showNewWindow && $field->showAdvanced);

$load = fn (string $slug) => Entry::find()->id($ids[$slug])->status(null)->one()?->getFieldValue('fl6Native')?->first();
$u = $load('fl6-native-url');
$check('URL link converted', $u?->type === 'url' && $u->value === 'https://native.example.com/a' && $u->label === 'Native' && $u->newWindow && $u->classes === 'btn', $u?->toArray());
$en = $load('fl6-native-entry');
$check('entry link converted (relation row)', $en?->type === 'entry' && $en->targetId === $live->id && $en->getUrl() === $live->getUrl(), $en?->toArray());
$em = $load('fl6-native-email');
$check('email link converted (mailto: stripped)', $em?->type === 'email' && $em->value === 'native@example.com' && $em->getUrl() === 'mailto:native@example.com', $em?->toArray());
$check('converted links indexed', DB::table('freelink_index')->where('fieldId', $field?->id)->count() === 3);
$check('history recorded', DB::table('freelink_migrations')->where(['sourcePlugin' => 'craftlink', 'status' => 'complete'])->exists());
$entryOwner = Entry::find()->id($ids['fl6-native-entry'])->status(null)->one();
$check('converted entry saves through FreeLink validation', Elements::saveElement($entryOwner), $entryOwner->errors()->all());
$again = app(Migrate::class)->runMigration('craftlink', 'fl6Native', true);
$check('second run finds nothing', $again['success'] && in_array('No matching fields found.', $again['log'], true), $again['log']);

echo "$pass passed, $fail failed".PHP_EOL;
