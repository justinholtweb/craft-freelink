#!/usr/bin/env python3
"""FreeLink 6 MCP checks over stdio (`php artisan craft:mcp:serve`). Run from the host:
   python3 -I tests/Integration/mcp.py   (after fixtures.php; uses the harness container)"""
import json, subprocess, sys, time

CONTAINER = 'ddev-plugin-testing-craft6-web'
passed = failed = 0

def check(name, ok, detail=None):
    global passed, failed
    passed += ok; failed += (not ok)
    print(('PASS ' if ok else 'FAIL ') + name + ('' if ok or detail is None else ' — ' + json.dumps(detail)[:600]))

def tinker(code):
    out = subprocess.run(['docker', 'exec', '-w', '/var/www/html', CONTAINER, 'php', 'artisan', 'tinker', '--execute', code], capture_output=True, text=True).stdout
    return out.strip().splitlines()[-1] if out.strip() else ''

def mcp(user, calls):
    msgs = [{"jsonrpc": "2.0", "id": 0, "method": "initialize", "params": {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "fl6", "version": "1"}}},
            {"jsonrpc": "2.0", "method": "notifications/initialized"}]
    for i, (name, args) in enumerate(calls, 1):
        msgs.append({"jsonrpc": "2.0", "id": i, "method": "tools/call", "params": {"name": name, "arguments": args}})
    p = subprocess.Popen(['docker', 'exec', '-i', '-w', '/var/www/html', CONTAINER, 'php', 'artisan', 'craft:mcp:serve', '--user=' + user],
                         stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
    for m in msgs:
        p.stdin.write(json.dumps(m) + '\n'); p.stdin.flush()
    results = {}
    deadline = time.time() + 60
    while len(results) < len(calls) and time.time() < deadline:
        line = p.stdout.readline()
        if not line:
            break
        try:
            d = json.loads(line)
        except ValueError:
            continue
        if d.get('id'):
            results[d['id']] = d
    p.stdin.close(); p.terminate()
    return [results.get(i, {}) for i in range(1, len(calls) + 1)]

def body(r):
    res = r.get('result', {})
    if 'structuredContent' in res:
        return res['structuredContent']
    try:
        return json.loads(res.get('content', [{}])[0].get('text', 'null'))
    except Exception:
        return res

def is_error(r):
    return bool(r.get('error')) or bool(r.get('result', {}).get('isError'))

ids = json.loads(tinker(r'''
use CraftCms\Cms\Entry\Elements\Entry; use CraftCms\Cms\Support\Facades\Elements;
$g = fn ($s, $sec = "fl6") => Entry::find()->section($sec)->slug($s)->status(null)->one();
$src = $g("fl6-source");
$src->setFieldValues([
  "fl6Cta" => ["type" => "custom", "value" => "javascript:alert(1)"],
  "fl6Links" => [["type" => "entry", "targetId" => $g("fl6-target-live")->id], ["type" => "entry", "targetId" => $g("fl6-target-disabled")->id], ["type" => "entry", "targetId" => $g("fl6-target-pending")->id]],
]);
Elements::saveElement($src, false);
echo json_encode(["source" => $src->id, "live" => $g("fl6-target-live")->id, "secret" => $g("fl6-secret-target", "fl6Secret")->id]);
'''))

r = mcp('admin', [
    ('freelink_find_links_to', {'targetId': ids['live']}),
    ('freelink_find_links_to', {'targetId': ids['live'], 'includeDrafts': True, 'limit': 1}),
    ('freelink_list_links', {'field': 'fl6Links', 'linkType': ['entry']}),
    ('freelink_list_links', {'ownerType': 'entry', 'limit': 100}),
    ('freelink_list_links', {'domain': 'no such domain!'}),
    ('freelink_check_links', {'ownerId': ids['source']}),
    ('freelink_check_links', {'ownerId': ids['source'], 'checks': ['unsafeUrl']}),
    ('freelink_migration_status', {}),
    ('freelink_migrate', {'source': 'craftlink', 'field': 'fl6Native'}),
    ('freelink_migrate', {'source': 'craftlink', 'field': 'fl6Native', 'dryRun': False}),
    ('freelink_migrate', {'source': 'hyper'}),
    ('elements_get', {'type': 'entry', 'id': ids['source'], 'fields': ['fl6Links', 'fl6Cta']}),
])
b = body(r[0])
check('freelink_find_links_to', b.get('total') == 1 and b['links'][0]['ownerId'] == ids['source'] and b['links'][0]['field'] == 'fl6Links' and b['target']['id'] == ids['live'], b)
check('freelink_find_links_to limit/nextOffset', body(r[1]).get('count', 0) >= 1, body(r[1]))
b = body(r[2])
check('freelink_list_links by field and type', b.get('total') == 3 and all(l['type'] == 'entry' for l in b['links']) and {l['status'] for l in b['links']} == {'live', 'disabled', 'pending'}, b)
check('freelink_list_links by owner type', body(r[3]).get('total', 0) >= 4, body(r[3]))
check('freelink_list_links invalid domain is a tool error', is_error(r[4]), r[4])
b = body(r[5])
checks = sorted(f['check'] for f in b.get('findings', []))
check('freelink_check_links findings', checks == ['disabledTarget', 'pendingTarget', 'unsafeUrl'], b)
check('freelink_check_links filtered', [f['check'] for f in body(r[6]).get('findings', [])] == ['unsafeUrl'], body(r[6]))
b = body(r[7])
# fl6Native is a Craft Link field until checks-migrate.php converts it.
check('freelink_migration_status', isinstance(b.get('available'), list) and isinstance(b.get('history'), list) and all(a['source'] == 'craftlink' for a in b['available']), b)
b = body(r[8])
check('freelink_migrate dry run', b.get('success') is True and b.get('dryRun') is True and any('DRY RUN' in l or 'No matching fields' in l for l in b.get('log', [])), b)
check('freelink_migrate real run needs confirm', is_error(r[9]), r[9])
check('freelink_migrate hyper explains Craft 5', 'Craft 5' in json.dumps(r[10]), r[10])
g = body(r[11])
fields = g.get('element', {})
links = fields.get('fl6Links') if isinstance(fields, dict) else None
check('elements_get returns FreeLink values', isinstance(links, list) and len(links) == 3 and links[0].get('targetId') == ids['live'] and links[0].get('type') == 'entry', fields)

# Round trip: elements_get's values back into elements_update.
keys = ['type', 'value', 'targetId', 'targetSiteId', 'siteId', 'subject', 'label']
update = [{k: v for k, v in l.items() if k in keys and not (k == 'value' and v is None)} for l in (links or [])][:2]
update.append({'type': 'url', 'value': 'https://mcp.example.com/'})
r = mcp('admin', [
    ('elements_update', {'type': 'entry', 'id': ids['source'], 'fields': {'fl6Links': update, 'fl6Cta': {'type': 'url', 'value': 'https://cta.example.com/', 'icon': 'house'}}}),
    ('elements_get', {'type': 'entry', 'id': ids['source'], 'fields': ['fl6Links', 'fl6Cta']}),
    ('elements_update', {'type': 'entry', 'id': ids['source'], 'fields': {'fl6Links': [{'type': 'email', 'value': 'x@y.test'}]}}),
    ('elements_update', {'type': 'entry', 'id': ids['source'], 'fields': {'fl6Links': [{'type': 'url', 'bogus': 1}]}}),
])
check('elements_update with values from elements_get', not is_error(r[0]), r[0])
g = body(r[1]); f = g.get('element', {})
check('round trip stored', [l.get('type') for l in f.get('fl6Links', [])] == ['entry', 'entry', 'url'] and f['fl6Links'][2].get('url') == 'https://mcp.example.com/' and f.get('fl6Cta', {}).get('icon') == 'house', g)
check('elements_update refuses a type the field doesn’t enable', is_error(r[2]), r[2])
check('elements_update rejects keys outside the input schema', is_error(r[3]), r[3])

# As the editor: no access to the secret section, no admin tools.
r = mcp('fl6editor', [
    ('freelink_find_links_to', {'targetId': ids['secret']}),
    ('elements_update', {'type': 'entry', 'id': ids['source'], 'fields': {'fl6Links': [{'type': 'entry', 'targetId': ids['secret']}]}}),
    ('freelink_migration_status', {}),
    ('freelink_list_links', {'field': 'fl6Links'}),
])
check('editor: find_links_to an unviewable target = "Element not found."', is_error(r[0]) and 'not found' in json.dumps(r[0]), r[0])
check('editor: elements_update to an unviewable target refused', is_error(r[1]), r[1])
check('editor: migration tools are admin only', is_error(r[2]), r[2])
check('editor: list_links works', body(r[3]).get('total', 0) >= 1, body(r[3]))

print(f'{passed} passed, {failed} failed')
