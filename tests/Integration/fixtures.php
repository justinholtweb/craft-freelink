<?php

// FreeLink harness fixtures (idempotent). Everything is prefixed `fl6`; cleanup.php removes it.
// Run: docker exec -w /var/www/html ddev-plugin-testing-craft6-web php artisan tinker --execute "require '/var/www/ports/craft-freelink/tests/Integration/fixtures.php';"

use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Link;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\LayoutElements\TitleField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserPermissions;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;

$fail = fn (string $what, $model) => throw new RuntimeException($what.': '.json_encode(method_exists($model, 'errors') ? $model->errors()->all() : $model));

$mk = function (string $type, string $handle, array $extra = []) use ($fail) {
    if ($f = Fields::getFieldByHandle($handle)) {
        return $f;
    }
    $f = Fields::createField(['type' => $type, 'name' => ucfirst(preg_replace('/([A-Z])/', ' $1', $handle)), 'handle' => $handle] + $extra);
    Fields::saveField($f) || $fail("field $handle", $f);

    return $f;
};
$entryType = function (string $handle, array $fields) use ($fail) {
    if ($t = EntryTypes::getEntryTypeByHandle($handle)) {
        return $t;
    }
    $t = new EntryType(['name' => ucfirst($handle), 'handle' => $handle]);
    $t->setFieldLayout(FieldLayout::createFromConfig(['type' => Entry::class, 'tabs' => [['name' => 'Content', 'elements' => [
        ['type' => TitleField::class],
        ...array_map(fn ($f) => ['type' => CustomField::class, 'fieldUid' => $f->uid], $fields),
    ]]]]));
    EntryTypes::saveEntryType($t) || $fail("entry type $handle", $t);

    return EntryTypes::getEntryTypeByHandle($handle);
};
$section = function (string $handle, array $types) use ($fail) {
    if ($s = Sections::getSectionByHandle($handle)) {
        return $s;
    }
    $s = new Section(['name' => ucfirst($handle), 'handle' => $handle, 'type' => SectionType::Channel]);
    $ss = new SectionSiteSettings;
    $ss->siteId = Sites::getPrimarySite()->id;
    $ss->hasUrls = true;
    $ss->uriFormat = "$handle/{slug}";
    $ss->template = 'fl6/entry';
    $ss->enabledByDefault = true;
    $s->setSiteSettings([$ss->siteId => $ss]);
    $s->setEntryTypes($types);
    Sections::saveSection($s) || $fail("section $handle", $s);

    return Sections::getSectionByHandle($handle);
};

$all = ['enabled' => true];
$cta = $mk(FreeLinkField::class, 'fl6Cta', [
    'linkTypes' => [
        'url' => $all + ['sortOrder' => 0], 'entry' => $all + ['sortOrder' => 1, 'sources' => '*'], 'email' => $all + ['sortOrder' => 2],
        'phone' => $all + ['sortOrder' => 3], 'sms' => $all + ['sortOrder' => 4], 'custom' => $all + ['sortOrder' => 5],
        'site' => $all + ['sortOrder' => 6], 'asset' => $all + ['sortOrder' => 7, 'sources' => '*'], 'user' => $all + ['sortOrder' => 8, 'sources' => '*'],
    ],
    'showAdvanced' => true, 'allowIcons' => true, 'showLabel' => true, 'showNewWindow' => true,
]);
$links = $mk(FreeLinkField::class, 'fl6Links', [
    'linkTypes' => ['url' => $all + ['sortOrder' => 0], 'entry' => $all + ['sortOrder' => 1]],
    'multipleLinks' => true, 'maxLinks' => 5,
]);
$native = $mk(Link::class, 'fl6Native', ['types' => ['url', 'entry', 'email'], 'showLabelField' => true, 'advancedFields' => ['target', 'class']]);

$page = $entryType('fl6Page', [$cta, $links]);
$nativePage = $entryType('fl6NativePage', [$native]);
$public = $section('fl6', [$page, $nativePage]);
$secret = $section('fl6Secret', [$page]);

$entry = function (Section $s, EntryType $t, string $slug, array $attrs = [], array $values = []) use ($fail) {
    $e = Entry::find()->section($s->handle)->slug($slug)->status(null)->one() ?? new Entry(['sectionId' => $s->id, 'typeId' => $t->id]);
    $e->slug = $slug;
    $e->title = $attrs['title'] ?? ucwords(str_replace('-', ' ', $slug));
    foreach ($attrs as $k => $v) {
        $e->$k = $v;
    }
    $e->setFieldValues($values);
    Elements::saveElement($e) || $fail("entry $slug", $e);

    return $e;
};

$live = $entry($public, $page, 'fl6-target-live');
$disabled = $entry($public, $page, 'fl6-target-disabled', ['enabled' => false]);
$pending = $entry($public, $page, 'fl6-target-pending', ['postDate' => new DateTime('+30 days')]);
$expired = $entry($public, $page, 'fl6-target-expired', ['postDate' => new DateTime('-30 days'), 'expiryDate' => new DateTime('-1 day')]);
$secretEntry = $entry($secret, $page, 'fl6-secret-target');

// A non-admin editor who can see and edit fl6 but not fl6Secret.
$user = User::find()->username('fl6editor')->status(null)->one() ?? new User;
$user->username = 'fl6editor';
$user->email = 'fl6editor@freelink.test';
$user->newPassword = 'fl6-editor-password';
$user->active = true;
$user->pending = false;
Elements::saveElement($user) || $fail('user', $user);
UserPermissions::saveUserPermissions($user->id, [
    'accessCp', 'accessPlugin-freelink', 'freelink:viewLinks',
    "viewEntries:{$public->uid}", "saveEntries:{$public->uid}", "createEntries:{$public->uid}",
    "viewPeerEntries:{$public->uid}", "savePeerEntries:{$public->uid}",
]);

echo json_encode([
    'fields' => ['cta' => $cta->id, 'links' => $links->id, 'native' => $native->id],
    'sections' => ['fl6' => $public->id, 'fl6Secret' => $secret->id],
    'types' => ['page' => $page->id, 'nativePage' => $nativePage->id],
    'entries' => ['live' => $live->id, 'disabled' => $disabled->id, 'pending' => $pending->id, 'expired' => $expired->id, 'secret' => $secretEntry->id],
    'user' => $user->id,
]).PHP_EOL;
