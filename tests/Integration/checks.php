<?php

// FreeLink 6 live checks against the plugin-testing-craft6 harness. Needs fixtures.php first.
// Run: docker exec -w /var/www/html ddev-plugin-testing-craft6-web php artisan tinker --execute "require '/var/www/ports/craft-freelink/tests/Integration/checks.php';"
// Prints one line per check and a summary; nothing here fetches a URL (see checks-http.php).

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Validation\ElementRules;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Mcp\McpOutputContext;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Ui\Controls\Table;
use CraftCms\Cms\User\Models\User as UserModel;
use CraftCms\Cms\View\TemplateMode;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\FreeLink\Base\ElementLink;
use JustinHoltWeb\FreeLink\Cp\ReverseLookupPanel;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Fields\LinkControl;
use JustinHoltWeb\FreeLink\Links\Email;
use JustinHoltWeb\FreeLink\Links\Site;
use JustinHoltWeb\FreeLink\Plugin;
use JustinHoltWeb\FreeLink\Services\LinkIndex;
use JustinHoltWeb\FreeLink\Services\Links;

use function CraftCms\Cms\craftAuth;
use function CraftCms\Cms\template;

$GLOBALS['fl6'] = ['pass' => 0, 'fail' => 0];
$check = function (string $name, bool $ok, mixed $detail = null): void {
    $GLOBALS['fl6'][$ok ? 'pass' : 'fail']++;
    echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === null ? '' : ' — '.json_encode($detail)).PHP_EOL;
};
$as = function (?int $userId): void {
    $userId ? craftAuth()->setUser(UserModel::query()->find($userId)) : craftAuth()->forgetUser();
};
$get = fn (string $slug, string $section = 'fl6') => Entry::find()->section($section)->slug($slug)->status(null)->one();
$fresh = fn (Entry $e) => Entry::find()->id($e->id)->status(null)->one();
$save = function (Entry $e, array $values) {
    $e->setFieldValues($values);
    $ok = Elements::saveElement($e);

    return [$ok, $e->errors()->all()];
};

$adminId = 1;
$editorId = (int) DB::table('users')->where('username', 'fl6editor')->value('id');
$live = $get('fl6-target-live');
$disabled = $get('fl6-target-disabled');
$pending = $get('fl6-target-pending');
$expired = $get('fl6-target-expired');
$secret = $get('fl6-secret-target', 'fl6Secret');
$cta = Fields::getFieldByHandle('fl6Cta');
$multi = Fields::getFieldByHandle('fl6Links');
$asset = Asset::find()->one();
$as($adminId);

// A source entry, reset on every run.
$source = $get('fl6-source') ?? new Entry(['sectionId' => $live->sectionId, 'typeId' => $live->typeId]);
$source->title = 'FL6 Source';
$source->slug = 'fl6-source';
$source->setFieldValues(['fl6Cta' => null, 'fl6Links' => null]);
Elements::saveElement($source);

// ---- Every link type, single mode --------------------------------------------------------------
$cases = [
    'url' => [['type' => 'url', 'value' => 'example.com/a', 'label' => 'Ex', 'newWindow' => true, 'urlSuffix' => '#top'], 'https://example.com/a#top'],
    'email' => [['type' => 'email', 'value' => 'hi@example.com', 'subject' => 'Q & A'], 'mailto:hi@example.com?subject=Q%20%26%20A'],
    'phone' => [['type' => 'phone', 'value' => '+1 (555) 123-4567'], 'tel:+15551234567'],
    'sms' => [['type' => 'sms', 'value' => '555-1234'], 'sms:5551234'],
    'custom' => [['type' => 'custom', 'value' => '#anchor'], '#anchor'],
    'site' => [['type' => 'site', 'value' => '/about', 'siteId' => 1], rtrim(Sites::getPrimarySite()->getBaseUrl(), '/').'/about'],
    'entry' => [['type' => 'entry', 'targetId' => $live->id, 'targetSiteId' => 1], $live->getUrl()],
    'user' => [['type' => 'user', 'targetId' => $adminId], null],
];
if ($asset) {
    $cases['asset'] = [['type' => 'asset', 'targetId' => $asset->id], $asset->getUrl()];
}

foreach ($cases as $type => [$input, $url]) {
    $e = $fresh($source);
    [$ok, $errors] = $save($e, ['fl6Cta' => $input]);
    $loaded = $fresh($source)->getFieldValue('fl6Cta');
    $link = $loaded->first();
    $check("save $type link", $ok && $link?->type === $type, $errors);
    $check("$type link URL", $link?->getUrl() === $url, [$link?->getUrl(), $url]);

    if ($link instanceof ElementLink) {
        $rel = DB::table('freelink_links')->where(['fieldId' => $cta->id, 'ownerId' => $source->id])->first();
        $check("$type link relation row", $rel && (int) $rel->targetId === $input['targetId'], $rel);
    }
    $rows = DB::table('freelink_index')->where(['fieldId' => $cta->id, 'ownerId' => $source->id])->get();
    $check("$type link indexed", $rows->count() === 1 && $rows[0]->type === $type, $rows);
}

$e = $fresh($source);
$save($e, ['fl6Cta' => $cases['email'][0]]);
$email = $fresh($source)->getFieldValue('fl6Cta')->first();
$check('email subject stored', $email instanceof Email && $email->subject === 'Q & A');
$save($e, ['fl6Cta' => $cases['site'][0]]);
$check('site link keeps siteId', ($s = $fresh($source)->getFieldValue('fl6Cta')->first()) instanceof Site && $s->siteId === 1);
$save($e, ['fl6Cta' => $cases['url'][0]]);
$u = $fresh($source)->getFieldValue('fl6Cta')->first();
$check('URL without scheme saved with https', $u->value === 'https://example.com/a', $u->value);
$check('stored JSON is FreeLink 5 shape', ($raw = $fresh($source)->getFieldValue('fl6Cta')->first()->toArray()) && $raw['type'] === 'url' && array_key_exists('customAttributes', $raw) && ! array_key_exists('icon', $raw), $raw);

// ---- Validation ---------------------------------------------------------------------------------
$e = $fresh($source);
[$ok, $errors] = $save($e, ['fl6Cta' => ['type' => 'url', 'value' => 'javascript:alert(1)']]);
$check('javascript: URL refused', ! $ok, $errors);
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => ['type' => 'custom', 'value' => 'java	script:alert(1)']]);
$check('obfuscated javascript: custom refused', ! $ok, $errors);
[$ok] = $save($fresh($source), ['fl6Cta' => ['type' => 'custom', 'value' => 'javascript:void(0)']]);
$check('javascript:void(0) custom allowed', $ok);
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => ['type' => 'url', 'value' => 'not a url']]);
$check('malformed URL refused', ! $ok, $errors);
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => ['type' => 'email', 'value' => 'nope']]);
$check('bad email refused', ! $ok, $errors);
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => ['type' => 'entry', 'targetId' => 99999999]]);
$check('missing target refused', ! $ok && str_contains(implode(' ', $errors), 'doesn’t exist'), $errors);
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => ['type' => 'user', 'targetId' => $live->id]]);
$check('target of the wrong element type refused', ! $ok, $errors);
[$ok, $errors] = $save($fresh($source), ['fl6Links' => [['type' => 'email', 'value' => 'a@b.test']]]);
$check('type not enabled on the field refused', ! $ok && str_contains(implode(' ', $errors), 'doesn’t allow'), $errors);
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => ['type' => 'url', 'value' => 'https://x.test', 'customAttributes' => [['attribute' => 'onclick', 'value' => 'x']]]]);
$check('event-handler custom attribute refused', ! $ok, $errors);

// ---- Multi mode ---------------------------------------------------------------------------------
$three = [
    ['type' => 'url', 'value' => 'https://one.example.com/'],
    ['type' => 'entry', 'targetId' => $live->id],
    ['type' => 'entry', 'targetId' => $disabled->id],
];
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => null, 'fl6Links' => $three]);
$m = $fresh($source)->getFieldValue('fl6Links');
$check('multi mode saves 3 links', $ok && $m->count() === 3 && $m->getAll()[2]->targetId === $disabled->id, $errors);
$check('multi mode stores a list', is_array($multi->serializeValue($m, null)) && array_is_list($multi->serializeValue($m, null)));
[$ok, $errors] = $save($fresh($source), ['fl6Links' => array_fill(0, 6, ['type' => 'url', 'value' => 'https://x.example.com/'])]);
$check('max links enforced', ! $ok && str_contains(implode(' ', $errors), 'no more than 5'), $errors);
$save($fresh($source), ['fl6Links' => $three]);

// ---- Icons ----------------------------------------------------------------------------------------
$proIcon = collect(require base_path('vendor/craftcms/cms-assets/resources/icons/index.php'))->filter(fn ($i, $k) => ($i['pro'] ?? false) && is_file(base_path("vendor/craftcms/cms-assets/resources/icons/solid/$k.svg")))->keys()->first();
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => ['type' => 'url', 'value' => 'https://icon.example.com/', 'icon' => 'house']]);
$i = $fresh($source)->getFieldValue('fl6Cta')->first();
$check('free icon saved', $ok && $i->icon === 'house', $errors);
$check('icon SVG rendered', str_contains((string) $i->getIconSvg(['class' => 'w-4']), 'aria-hidden="true"') && str_contains((string) $i->getIconSvg(['class' => 'w-4']), 'class="w-4"'));
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => ['type' => 'url', 'value' => 'https://icon.example.com/', 'icon' => $proIcon]]);
$check("Pro icon ($proIcon) refused without includeProIcons", ! $ok, $errors);
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => ['type' => 'url', 'value' => 'https://icon.example.com/', 'icon' => 'no-such-icon-xyz']]);
$check('unknown icon refused', ! $ok, $errors);
$check('icons dropped when the field disallows them', $multi->normalizeValue(['type' => 'url', 'value' => 'https://x.test', 'icon' => 'house'], null)->first()->icon === null);
$save($fresh($source), ['fl6Cta' => ['type' => 'url', 'value' => 'https://icon.example.com/', 'icon' => 'house', 'label' => 'Iconic']]);

// ---- CP control round trip (what the Table of Link controls posts) ----------------------------------
$control = new LinkControl($cta);
$rows = $control->rows($fresh($source)->getFieldValue('fl6Cta'));
$check('control row for a stored link', ($rows[0]['link']['type'] ?? null) === 'url' && $rows[0]['icon'] === 'house' && $rows[0]['link']['label'] === 'Iconic', $rows);
$posted = [['link' => ['type' => 'entry', 'value' => '{entry:'.$live->id.'@1:url}', 'label' => 'Posted', 'target' => '_blank'], 'icon' => 'star', 'attributes' => 'data-x=1']];
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => $posted]);
$p = $fresh($source)->getFieldValue('fl6Cta')->first();
$check('control POST saves an entry link', $ok && $p->targetId === $live->id && $p->newWindow && $p->icon === 'star' && ($p->customAttributes[0]['attribute'] ?? null) === 'data-x' && ($p->customAttributes[0]['value'] ?? null) === '1', [$errors, $p?->toArray()]);
$ui = $cta->uiControl(new FieldContext(path: ['fields', 'fl6Cta'], value: $fresh($source)->getFieldValue('fl6Cta'), element: $fresh($source)));
$payload = $ui->props($ui->getValue() ?? null);
$check('uiControl is a Table with a Link column', $ui instanceof Table, get_class($ui));

// ---- Twig output and reverse lookups -----------------------------------------------------------------
$save($fresh($source), [
    'fl6Cta' => ['type' => 'url', 'value' => 'https://out.example.com/', 'label' => 'Out <b>', 'newWindow' => true, 'icon' => 'house'],
    'fl6Links' => [['type' => 'entry', 'targetId' => $live->id], ['type' => 'url', 'value' => 'https://two.example.com/']],
]);
$html = template('fl6/output', ['entry' => $fresh($source), 'target' => $live], TemplateMode::Site);
$check('Twig url/text', str_contains($html, 'URL=https://out.example.com/|TEXT=Out &lt;b&gt;'), $html);
$check('Twig link tag escapes text, adds noopener', str_contains($html, 'target="_blank"') && str_contains($html, 'noopener noreferrer') && str_contains($html, '>Out &lt;b&gt;</a>'), $html);
$check('Twig iconSvg', str_contains($html, 'ICON=svg'), $html);
$check('Twig multi iteration', str_contains($html, '|M1=entry:'.$live->getUrl()) && str_contains($html, '|M2=url:https://two.example.com/'), $html);
$check('Twig linkCountTo (craft.freelink + global)', str_contains($html, '|COUNT=1|GLOBAL=1'), $html);
$check('Twig linksTo', str_contains($html, '|REF=FL6 Source/fl6Links/'), $html);
// getRelatedElements() reads freelink_links, which holds drafts' and revisions' rows too (5.x behaviour).
$check('Twig getRelatedElements', (bool) preg_match('/\|REL=[1-9]/', $html), $html);

$refs = Plugin::getInstance()->links->findLinksTo($live);
$check('service findLinksTo (Plugin::getInstance()->links)', count($refs) === 1 && $refs[0]->sourceId === $source->id && $refs[0]->getFieldHandle() === 'fl6Links' && $refs[0]->getStatus() === 'live', array_map(fn ($r) => $r->toApiArray(), $refs));

// ---- Element link status in SQL --------------------------------------------------------------------
$save($fresh($source), ['fl6Links' => [
    ['type' => 'entry', 'targetId' => $live->id], ['type' => 'entry', 'targetId' => $disabled->id],
    ['type' => 'entry', 'targetId' => $pending->id], ['type' => 'entry', 'targetId' => $expired->id],
]]);
$statuses = collect(app(LinkIndex::class)->find(['sourceId' => $source->id, 'field' => 'fl6Links'])['references'])->mapWithKeys(fn ($r) => [$r->targetId => $r->getStatus()])->all();
$check('element statuses live/disabled/pending/expired', $statuses == [$live->id => 'live', $disabled->id => 'disabled', $pending->id => 'pending', $expired->id => 'expired'], $statuses);
$problems = app(LinkIndex::class)->find(['sourceId' => $source->id, 'status' => 'problems'])['total'];
$check('problems filter', $problems === 3, $problems);

// Trash and hard-delete a throwaway target.
$tmp = new Entry(['sectionId' => $live->sectionId, 'typeId' => $live->typeId, 'title' => 'FL6 Temp', 'slug' => 'fl6-temp']);
Elements::saveElement($tmp);
$save($fresh($source), ['fl6Links' => [['type' => 'entry', 'targetId' => $tmp->id]]]);
Elements::deleteElement($tmp);
$check('trashed target status', (app(LinkIndex::class)->find(['sourceId' => $source->id, 'field' => 'fl6Links'])['references'][0] ?? null)?->getStatus() === 'trashed');
Elements::deleteElement(Entry::find()->id($tmp->id)->trashed()->status(null)->one(), true);
$ref = app(LinkIndex::class)->find(['sourceId' => $source->id, 'field' => 'fl6Links'])['references'][0] ?? null;
$check('hard-deleted target status = missing (FK SET NULL)', $ref?->getStatus() === 'missing' && $ref->targetId === null, $ref?->toApiArray());
[$ok, $errors] = $save($fresh($source), []);
$check('owner with a deleted target still saves', $ok, $errors);

// ---- Grandfathering, drafts and visibility ----------------------------------------------------------
$save($fresh($source), ['fl6Links' => null, 'fl6Cta' => ['type' => 'entry', 'targetId' => $secret->id, 'targetSiteId' => 1]]);
$check('admin may link to the secret entry', $fresh($source)->getFieldValue('fl6Cta')->first()?->targetId === $secret->id);

$as($editorId);
[$ok, $errors] = $save($fresh($source), ['fl6Links' => [['type' => 'url', 'value' => 'https://editor.example.com/']]]);
$check('editor saves with an unchanged live link to an unviewable target (grandfathered)', $ok, $errors);
[$ok, $errors] = $save($fresh($source), ['fl6Links' => [['type' => 'entry', 'targetId' => $secret->id]]]);
$check('editor refused a new link to an unviewable target', ! $ok && str_contains(implode(' ', $errors), 'can’t link'), $errors);
[$ok, $errors] = $save($fresh($source), ['fl6Cta' => ['type' => 'entry', 'targetId' => $secret->id, 'targetSiteId' => null]]);
$check('changing targetSiteId makes it a new link (refused)', ! $ok, $errors);

// Coordinator's draft rules: a draft is checked for new unviewable targets too.
$draft = Drafts::createDraft($fresh($source), $editorId, 'fl6 draft');
$draft->setFieldValues(['fl6Links' => [['type' => 'entry', 'targetId' => $secret->id]]]);
$draft->ruleset->useScenario(ElementRules::SCENARIO_ESSENTIALS);
$ok = Elements::saveElement($draft);
$check('editor draft with a new unviewable target refused', ! $ok, $draft->errors()->all());
$draft = Entry::find()->id($draft->id)->drafts()->status(null)->one();
$draft->setFieldValues(['fl6Links' => [['type' => 'url', 'value' => 'https://draft.example.com/']]]);
$draft->ruleset->useScenario(ElementRules::SCENARIO_ESSENTIALS);
$ok = Elements::saveElement($draft);
$check('editor draft keeping the unchanged live link to an unviewable target saves', $ok, $draft->errors()->all());
$draftValue = Entry::find()->id($draft->id)->drafts()->status(null)->one()->getFieldValue('fl6Cta');
$rowsForEditor = (new LinkControl($cta))->rows($draftValue);
$check('editor sees no title for the unviewable target (control label)', ! isset($rowsForEditor[0]['link']['defaultLabel']), $rowsForEditor);
$mcp = $cta->getMcpValue($draftValue, $draft, new McpOutputContext(public: false));
$check('MCP value hides url/title/element of an unviewable target', $mcp['url'] === null && $mcp['target'] === null && ($mcp['text'] ?? null) === null, $mcp);
$check('draft not indexed', DB::table('freelink_index')->where('ownerId', $draft->id)->count() === 0);
Elements::deleteElement($draft, true);

// A link only present in an admin's draft is not a baseline: publishing it as the editor is refused.
$as($adminId);
$adminDraft = Drafts::createDraft($fresh($source), $adminId, 'fl6 admin draft');
$adminDraft->setFieldValues(['fl6Links' => [['type' => 'entry', 'targetId' => $secret->id]]]);
$adminDraft->ruleset->useScenario(ElementRules::SCENARIO_ESSENTIALS);
Elements::saveElement($adminDraft);
$check('admin draft with the secret link saves', DB::table('freelink_links')->where(['ownerId' => $adminDraft->id, 'targetId' => $secret->id])->exists());
$as($editorId);
$fromDraft = Entry::find()->id($adminDraft->id)->drafts()->status(null)->one();
$check('draft is not a grandfathering baseline', ! isset($cta->storedLinks($fromDraft)[FreeLinkField::linkKey(app(Links::class)->createLink(['type' => 'entry', 'targetId' => $secret->id]))]) && $multi->linkErrors($fromDraft) !== [], $multi->linkErrors($fromDraft));
$as($adminId);
Elements::deleteElement($adminDraft, true);

// ---- Sources the user can't view are left out in the query (honest totals) -----------------------
$as($adminId);
$secretSource = $get('fl6-secret-source', 'fl6Secret') ?? new Entry(['sectionId' => $secret->sectionId, 'typeId' => $secret->typeId]);
$secretSource->title = 'FL6 Secret Source';
$secretSource->slug = 'fl6-secret-source';
$secretSource->setFieldValues(['fl6Cta' => ['type' => 'url', 'value' => 'https://secret-only.example.com/', 'label' => 'SECRET-LABEL'], 'fl6Links' => [['type' => 'entry', 'targetId' => $live->id]]]);
Elements::saveElement($secretSource);
$save($fresh($source), ['fl6Cta' => null, 'fl6Links' => [['type' => 'entry', 'targetId' => $live->id]]]);
$index = app(LinkIndex::class);
$adminUser = UserModel::query()->find($adminId);
$editorUser = UserModel::query()->find($editorId);
$check('admin sees both links to the target', $index->countTo($live->id, $adminUser) === 2 && $index->find(['targetId' => $live->id, 'viewer' => $adminUser])['total'] === 2);
$check('editor: count excludes the unviewable source', $index->countTo($live->id, $editorUser) === 1, $index->countTo($live->id, $editorUser));
$found = $index->find(['targetId' => $live->id, 'viewer' => $editorUser]);
$check('editor: total and rows exclude it', $found['total'] === 1 && count($found['references']) === 1 && $found['references'][0]->sourceId === $source->id, $found['total']);
$check('editor: the secret source’s URL/label never appear', $index->find(['domain' => 'secret-only.example.com', 'viewer' => $editorUser])['total'] === 0 && $index->find(['domain' => 'secret-only.example.com', 'viewer' => $adminUser])['total'] === 1);
$as($editorId);
request()->headers->set('X-Test', '1');
$panel = (string) ReverseLookupPanel::html($live);
$check('editor: Linked from counts only viewable sources', str_contains($panel, 'View all 1 link') && ! str_contains($panel, 'FL6 Secret Source') && ! str_contains($panel, 'can’t view'), $panel);
$as($adminId);
$panel = (string) ReverseLookupPanel::html($live);
$check('admin: Linked from counts both', str_contains($panel, 'View all 2 links'), $panel);

echo sprintf('%d passed, %d failed', $GLOBALS['fl6']['pass'], $GLOBALS['fl6']['fail']).PHP_EOL;
