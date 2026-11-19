<?php

// Simulates a FreeLink 5.3 install's stored state on the fl6Cta test field, for the Craft 6 upgrade
// migration: the 5.x field class in fields.type and project config, category links enabled, and a
// category link (whose element is now an entry) plus one whose target is gone.
// Step 1: php artisan tinker --execute "\$step = 'seed'; require '…/upgrade-sim.php';"
// Step 2 (new process): \$step = 'migrate'; Step 3: \$step = 'verify'.

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\ProjectConfig\ProjectConfigHelper;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Json;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;

$old = 'justinholtweb\\freelink\\fields\\FreeLinkField';
$uid = DB::table('fields')->where('handle', 'fl6Cta')->value('uid');
$fieldId = (int) DB::table('fields')->where('handle', 'fl6Cta')->value('id');
$source = Entry::find()->slug('fl6-source')->status(null)->one();
$bulk = Entry::find()->slug('fl6-bulk-1')->status(null)->one();
$live = Entry::find()->slug('fl6-target-live')->status(null)->one();
$pc = app(ProjectConfig::class);
$layoutUid = collect(Json::decode((string) DB::table('fieldlayouts')->where('id', DB::table('elements')->where('id', $source->id)->value('fieldLayoutId'))->value('config'))['tabs'][0]['elements'])->firstWhere('fieldUid', $uid)['uid'];
$file = '/var/www/ports/craft-freelink/database/migrations/2026_11_19_000000_upgrade_to_craft_6.php';
$snapshot = fn () => json_encode([
    DB::table('fields')->where('id', $fieldId)->first(['type', 'settings']),
    $pc->get("fields.$uid"),
    DB::table('elements_sites')->whereIn('elementId', [$source->id, $bulk->id])->orderBy('elementId')->pluck('content'),
    DB::table('freelink_links')->where('fieldId', $fieldId)->orderBy('id')->get(['ownerId', 'targetId']),
    DB::table('freelink_index')->where('fieldId', $fieldId)->orderBy('ownerId')->get(['ownerId', 'type', 'targetId']),
]);

if ($step === 'seed') {
    // A category link to an element that is now an entry, and one whose category is gone.
    foreach ([[$source->id, $live->id], [$bulk->id, null]] as [$ownerId, $targetId]) {
        $row = DB::table('elements_sites')->where(['elementId' => $ownerId, 'siteId' => 1])->first();
        $content = Json::decode((string) $row->content) ?: [];
        unset($content['']);
        $content[$layoutUid] = ['type' => 'category', 'value' => null, 'label' => 'Topic', 'newWindow' => false, 'customAttributes' => []];
        DB::table('elements_sites')->where('id', $row->id)->update(['content' => Json::encode($content)]);
        DB::table('freelink_links')->where(['fieldId' => $fieldId, 'ownerId' => $ownerId])->delete();
        DB::table('freelink_links')->insert(['fieldId' => $fieldId, 'ownerId' => $ownerId, 'ownerSiteId' => 1, 'sortOrder' => 0, 'targetId' => $targetId, 'targetSiteId' => null, 'dateCreated' => now(), 'dateUpdated' => now(), 'uid' => (string) Str::uuid()]);
        DB::table('freelink_index')->where(['fieldId' => $fieldId, 'ownerId' => $ownerId])->delete();
        DB::table('freelink_index')->insert(['fieldId' => $fieldId, 'ownerId' => $ownerId, 'ownerSiteId' => 1, 'rootOwnerId' => $ownerId, 'sortOrder' => 0, 'type' => 'category', 'isElement' => true, 'targetId' => $targetId, 'dateCreated' => now(), 'dateUpdated' => now(), 'uid' => (string) Str::uuid()]);
    }

    $config = $pc->get("fields.$uid");
    $config['type'] = $old;
    // As FreeLink 5 stored it: packed associative arrays, categories enabled, entries not.
    $settings = ProjectConfigHelper::unpackAssociativeArrays($config['settings']);
    $settings['linkTypes']['category'] = ['enabled' => true, 'label' => '', 'sources' => '*', 'sortOrder' => 1];
    $settings['linkTypes']['entry']['enabled'] = false;
    $config['settings'] = ProjectConfigHelper::packAssociativeArrays($settings);
    $mute = $pc->muteEvents;
    $pc->muteEvents = true;
    $pc->set("fields.$uid", $config, 'FreeLink 6 upgrade simulation');
    $pc->saveModifiedConfigData();
    $pc->muteEvents = $mute;
    DB::table('fields')->where('id', $fieldId)->update(['type' => $old, 'settings' => Json::encode($settings)]);
    echo 'seeded: '.$snapshot().PHP_EOL;
}

if ($step === 'migrate') {
    $before = $snapshot();
    (require $file)->up();
    $mid = $snapshot();
    (require $file)->up();
    echo json_encode(['changed' => $before !== $mid, 'secondRunNoop' => $mid === $snapshot()]).PHP_EOL;
}

if ($step === 'verify') {
    $pass = 0;
    $fail = 0;
    $check = function (string $name, bool $ok, mixed $detail = null) use (&$pass, &$fail): void {
        $ok ? $pass++ : $fail++;
        echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === null ? '' : ' — '.json_encode($detail)).PHP_EOL;
    };
    $field = Fields::getFieldById($fieldId);
    $check('fields.type renamed', DB::table('fields')->where('id', $fieldId)->value('type') === FreeLinkField::class);
    $check('project config type renamed', $pc->get("fields.$uid.type") === FreeLinkField::class, $pc->get("fields.$uid.type"));
    $check('field loads as FreeLinkField', $field instanceof FreeLinkField, $field ? $field::class : null);
    $check('entry links offered where categories were', $field instanceof FreeLinkField && ! empty($field->linkTypes['entry']['enabled']) && empty($field->linkTypes['category']['enabled']) && ! isset($field->linkTypes['__assoc__']), $field?->linkTypes);
    $check('other link types kept', $field instanceof FreeLinkField && in_array('url', $field->getEnabledTypeHandles(), true) && in_array('email', $field->getEnabledTypeHandles(), true), $field?->getEnabledTypeHandles());
    $link = Entry::find()->id($source->id)->status(null)->one()->getFieldValue('fl6Cta')->first();
    $check('category link → entry link (target kept)', $link?->type === 'entry' && $link->targetId === $live->id && $link->label === 'Topic' && $link->getUrl() === $live->getUrl(), $link?->toArray());
    $check('index row type follows', DB::table('freelink_index')->where(['fieldId' => $fieldId, 'ownerId' => $source->id])->value('type') === 'entry');
    $gone = Entry::find()->id($bulk->id)->status(null)->one()->getFieldValue('fl6Cta')->first();
    $check('category link whose target is gone stays (empty)', $gone?->type === 'category' && $gone->isEmpty(), $gone?->toArray());
    echo "$pass passed, $fail failed".PHP_EOL;
}
