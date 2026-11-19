<?php

// FreeLink 6 HTTP link checks against the harness itself, plus the SSRF refusals.
// Needs config/craft/freelink.php with checkAllowedHosts => ['plugin-testing-craft6.ddev.site'] and
// resources/views/fl6/{ok,redirect}.twig in the harness (redirect.twig redirects to http://127.0.0.1/).
// Run: docker exec -w /var/www/html ddev-plugin-testing-craft6-web php artisan tinker --execute "require '/var/www/ports/craft-freelink/tests/Integration/checks-http.php';"

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\FreeLink\Jobs\CheckLinks;
use JustinHoltWeb\FreeLink\Models\Settings;
use JustinHoltWeb\FreeLink\Plugin;
use JustinHoltWeb\FreeLink\Services\Checker;
use JustinHoltWeb\FreeLink\Services\LinkIndex;

$pass = 0;
$fail = 0;
$check = function (string $name, bool $ok, mixed $detail = null) use (&$pass, &$fail): void {
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === null ? '' : ' — '.json_encode($detail)).PHP_EOL;
};

$base = 'https://plugin-testing-craft6.ddev.site';
$urls = [
    'ok' => "$base/fl6/ok",
    'broken' => "$base/fl6/nope",
    'redirectInternal' => "$base/fl6/redirect",
    'loopback' => 'http://127.0.0.1/',
    'private10' => 'http://10.1.2.3/',
    'metadata' => 'http://169.254.169.254/latest/meta-data/',
    'v6loopback' => 'http://[::1]/',
    'localhost' => 'http://localhost/',
    'decimal' => 'http://2130706433/',
    'octal' => 'http://0177.0.0.1/',
    'mapped' => 'http://[::ffff:127.0.0.1]/',
    'port' => 'http://example.com:22/',
    'credentials' => 'http://user:pass@example.com/',
];

$live = Entry::find()->slug('fl6-target-live')->status(null)->one();
$e = Entry::find()->section('fl6')->slug('fl6-http')->status(null)->one() ?? new Entry(['sectionId' => $live->sectionId, 'typeId' => $live->typeId]);
$e->title = 'FL6 HTTP';
$e->slug = 'fl6-http';
// Custom links, saved without validation: what a migration or an old value could hold.
$e->setFieldValues(['fl6Links' => array_map(fn ($u) => ['type' => 'custom', 'value' => $u], array_values($urls))]);
Elements::saveElement($e, false);

$hosts = DB::table('freelink_index')->where('ownerId', $e->id)->whereNotNull('host')->count();
$check('every URL is indexed as checkable', $hosts === count($urls), $hosts);

$checker = app(Checker::class);
$checker->hostDelayMs = 0;
$byUrl = fn () => DB::table('freelink_checks')->whereIn('url', array_values($urls))->get()->keyBy('url');

// The job, run synchronously (the harness queue is shared, so nothing is queued).
CheckLinks::dispatchSync(true);
$rows = $byUrl();
$status = fn (string $k) => $rows[$urls[$k]]->status ?? null;

$check('200 page → ok', $status('ok') === 'ok' && (int) $rows[$urls['ok']]->httpCode === 200, $rows[$urls['ok']] ?? null);
$check('404 page → broken', $status('broken') === 'broken' && (int) $rows[$urls['broken']]->httpCode === 404, $rows[$urls['broken']] ?? null);
$check('redirect to 127.0.0.1 → blocked', $status('redirectInternal') === 'blocked' && str_contains((string) $rows[$urls['redirectInternal']]->error, 'Redirects to a refused address'), $rows[$urls['redirectInternal']] ?? null);
foreach (['loopback', 'private10', 'metadata', 'v6loopback', 'localhost', 'decimal', 'octal', 'mapped', 'port', 'credentials'] as $k) {
    $check("$k refused ({$urls[$k]})", $status($k) === 'blocked', $rows[$urls[$k]] ?? null);
}

// The allow-list comes from the config file only; without it the harness host is private.
config(['craft.freelink.checkAllowedHosts' => []]);
$fresh = new Checker;
$fresh->hostDelayMs = 0;
$r = $fresh->checkUrl($urls['ok']);
$check('without the config allow-list the harness host (private) is refused', $r['status'] === 'blocked', $r);
$settings = Plugin::settings();
$settings->checkAllowedHosts = ['plugin-testing-craft6.ddev.site'];
$fresh = new Checker;
$check('the allow-list set on the settings model (a CP save) is ignored', $fresh->checkUrl($urls['ok'])['status'] === 'blocked');
$settings->checkAllowedHosts = [];

// Not due again until recheckAfter; disabled checks fetch nothing.
$check('nothing is due right after a run', app(LinkIndex::class)->urlsDueForCheck(100) === [] || ! array_intersect(array_column(app(LinkIndex::class)->urlsDueForCheck(100), 'url'), $urls));
$off = new Settings;
$off->enableExternalChecks = false;
$c = new Checker;
$c->settings = $off;
$check('checks off → skipped', $c->run(true)['skipped'] === true);

// Status filter on the index.
$problems = app(LinkIndex::class)->find(['sourceId' => $e->id, 'status' => 'problems'])['total'];
$check('problems = broken + blocked URLs', $problems === count($urls) - 1, $problems);

// Orphaned results are swept.
$e->setFieldValues(['fl6Links' => [['type' => 'custom', 'value' => $urls['ok']]]]);
Elements::saveElement($e, false);
$swept = $checker->deleteOrphans();
$check('orphaned check rows deleted', $swept >= count($urls) - 1 && $byUrl()->count() === 1, [$swept, $byUrl()->keys()]);

echo "$pass passed, $fail failed".PHP_EOL;
