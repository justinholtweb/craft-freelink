<?php

// FreeLink 6 GraphQL checks with in-memory schemas (no schema or token is saved).
// Run after fixtures.php + checks.php: docker exec -w /var/www/html ddev-plugin-testing-craft6-web php artisan tinker --execute "require '/var/www/ports/craft-freelink/tests/Integration/checks-gql.php';"

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use JustinHoltWeb\FreeLink\Gql\GqlScope;

use function CraftCms\Cms\craftAuth;

$pass = 0;
$fail = 0;
$check = function (string $name, bool $ok, mixed $detail = null) use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === null ? '' : ' — '.json_encode($detail)).PHP_EOL;
};
craftAuth()->forgetUser();
$gql = app(Gql::class);
$fl6 = Sections::getSectionByHandle('fl6');
$secretSection = Sections::getSectionByHandle('fl6Secret');
$site = Sites::getPrimarySite();
$get = fn (string $slug, string $section = 'fl6') => Entry::find()->section($section)->slug($slug)->status(null)->one();
$live = $get('fl6-target-live');
$secret = $get('fl6-secret-target', 'fl6Secret');
$source = $get('fl6-source');

$schema = fn (array $scope) => new GqlSchema(['name' => 'fl6', 'uid' => 'fl6-'.md5(json_encode($scope)), 'scope' => $scope]);
$run = function (array $scope, string $query, ?array $vars = null) use ($gql, $schema) {
    // GraphQL results are cached per schema + query; every run here must hit the resolvers.
    $gql->invalidateCaches();
    $result = $gql->executeQuery($schema($scope), $query, $vars);
    $gql->setActiveSchema(null);

    return json_decode(json_encode($result), true);
};

$base = ["sites.{$site->uid}:read", "sections.{$fl6->uid}:read", "sections.{$fl6->uid}:save"];
$withLookups = [...$base, 'freelinkLinks:read'];

// Field output.
$r = $run($base, '{ entries(section: "fl6", slug: "fl6-source") { ... on fl6Page_Entry { fl6Links { type url text elementId isElement } fl6Cta { type url } } } }');
$check('field output is a FreeLinkInterface list', isset($r['data']['entries'][0]['fl6Links']) && is_array($r['data']['entries'][0]['fl6Cta']), $r);

// Mutation with every input field.
$m = 'mutation($id: ID, $cta: FreeLinkInput, $links: [FreeLinkInput]) { save_fl6_fl6Page_Entry(id: $id, fl6Cta: $cta, fl6Links: $links) { id ... on fl6Page_Entry { fl6Cta { type url text label newWindow htmlId icon iconSvg subject } fl6Links { type elementId url } } } }';
$r = $run($base, $m, ['id' => (string) $source->id,
    'cta' => ['type' => 'email', 'value' => 'gql@example.com', 'subject' => 'From GQL', 'label' => 'Mail', 'newWindow' => false, 'htmlId' => 'm1', 'icon' => 'envelope', 'customAttributes' => [['attribute' => 'data-g', 'value' => '1']]],
    'links' => [['type' => 'entry', 'targetId' => $live->id], ['type' => 'url', 'value' => 'https://gql.example.com/']],
]);
$out = $r['data']['save_fl6_fl6Page_Entry'] ?? null;
$check('mutation saves FreeLinkInput values', ($out['fl6Cta'][0]['url'] ?? null) === 'mailto:gql@example.com?subject=From%20GQL' && ($out['fl6Cta'][0]['htmlId'] ?? null) === 'm1' && str_contains((string) ($out['fl6Cta'][0]['iconSvg'] ?? ''), '<svg'), $r);
$check('mutation list input (multi mode)', ($out['fl6Links'][0]['elementId'] ?? null) === $live->id && count($out['fl6Links'] ?? []) === 2, $r);

$r = $run($base, $m, ['id' => (string) $source->id, 'links' => [['type' => 'entry', 'targetId' => $secret->id]]]);
$check('mutation refuses a target outside the schema', ! empty($r['errors']), $r);
$r = $run($base, $m, ['id' => (string) $source->id, 'links' => [['type' => 'email', 'value' => 'x@y.test']]]);
$check('mutation refuses a type the field doesn’t enable', ! empty($r['errors']), $r);
$r = $run($base, $m, ['id' => (string) $source->id, 'cta' => ['type' => 'url', 'value' => 'javascript:alert(1)']]);
$check('mutation refuses javascript: URLs', ! empty($r['errors']), $r);

// Site scope: the same section, but no readable site → the target isn't readable.
$noSite = ["sections.{$fl6->uid}:read", "sections.{$fl6->uid}:save"];
$gql->setActiveSchema($schema($noSite));
$check('GqlScope: target readable on a site in the schema', (function () use ($gql, $schema, $base, $live) {
    $gql->setActiveSchema($schema($base));
    $ok = GqlScope::canQuery($live->id, 1) && GqlScope::canQuery($live->id);
    $gql->setActiveSchema(null);

    return $ok;
})());
$gql->setActiveSchema($schema($noSite));
$check('GqlScope: target refused on a site the schema can’t read', ! GqlScope::canQuery($live->id, 1) && ! GqlScope::canQuery($live->id));
$gql->setActiveSchema($schema($base));
$check('GqlScope: target refused on a site ID outside the schema', ! GqlScope::canQuery($live->id, 999999));
$gql->setActiveSchema(null);

// Reverse lookups.
$q = '{ freelinkLinksTo(elementId: '.$live->id.') { sourceId rootSourceId field type position status source { id title } link { type url elementId } } }';
$r = $run($base, $q);
$check('freelinkLinksTo absent without the schema component', ! isset($r['data']['freelinkLinksTo']), $r);
$r = $run($withLookups, $q);
$refs = $r['data']['freelinkLinksTo'] ?? null;
$check('freelinkLinksTo with the component', is_array($refs) && count($refs) === 1 && $refs[0]['sourceId'] === $source->id && $refs[0]['field'] === 'fl6Links' && $refs[0]['link']['elementId'] === $live->id && $refs[0]['source']['title'] === 'FL6 Source', $r);
$r = $run($withLookups, '{ freelinkLinksTo(elementId: '.$secret->id.') { sourceId } }');
$check('freelinkLinksTo is empty for a target the schema can’t read', ($r['data']['freelinkLinksTo'] ?? null) === [], $r);
$r = $run(["sections.{$fl6->uid}:read", 'freelinkLinks:read'], $q);
$check('freelinkLinksTo is empty when the schema can’t read the target’s site', ($r['data']['freelinkLinksTo'] ?? null) === [], $r);

// A stored link to a target outside the schema resolves no URL or title.
$s = Entry::find()->id($source->id)->status(null)->one();
$s->setFieldValues(['fl6Links' => [['type' => 'entry', 'targetId' => $secret->id]]]);
Elements::saveElement($s, false);
$r = $run($base, '{ entries(section: "fl6", slug: "fl6-source") { ... on fl6Page_Entry { fl6Links { type url text elementId } } } }');
$l = $r['data']['entries'][0]['fl6Links'][0] ?? null;
$check('stored link to an unreadable target: no url/text', $l !== null && $l['url'] === null && $l['text'] === null, $r);

$secretOnly = ["sites.{$site->uid}:read", "sections.{$secretSection->uid}:read", 'freelinkLinks:read'];
$r = $run($secretOnly, '{ freelinkLinksTo(elementId: '.$secret->id.') { sourceId } }');
$check('freelinkLinksTo hides sources the schema can’t read', ($r['data']['freelinkLinksTo'] ?? null) === [], $r);
$r = $run([...$withLookups, "sections.{$secretSection->uid}:read"], '{ freelinkLinksTo(elementId: '.$secret->id.') { sourceId } }');
$check('… and shows them when it can', ($r['data']['freelinkLinksTo'][0]['sourceId'] ?? null) === $source->id, $r);

echo "$pass passed, $fail failed".PHP_EOL;
