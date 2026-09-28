<?php
use Align\Api\Keys;
use Align\Api\Spec;

/**
 * Settings -> API -> reference, generated from the same route table and validation rules the API uses.
 * @var array $spec, $routes; string $baseUrl; bool $enabled
 */
$tab = 'api';
require __DIR__ . '/_tabs.php';
$md = fn(string $t) => preg_replace(['/\*\*(.+?)\*\*/', '/`([^`]+)`/'], ['<b>$1</b>', '<code>$1</code>'], e($t));
$byTag = [];
foreach ($routes as $r) {
    $byTag[$r['tag']][] = $r;
}
$methodColor = ['GET' => 'success', 'POST' => 'primary', 'PATCH' => 'warning', 'PUT' => 'info', 'DELETE' => 'danger'];
$example = function (array $r) use ($baseUrl): string {
    $path = preg_replace(['#\{(\w+):str\}#', '#\{(\w+)\}#'], ['<$1>', '<$1>'], $r['path']);
    $cmd = 'curl -s' . ($r['method'] !== 'GET' ? ' -X ' . $r['method'] : '') . ' -H "Authorization: Bearer $ALIGN_KEY"';
    if ($r['body'] !== null) {
        $sample = [];
        foreach (Spec::rules($r) as $f => $rule) {
            [$t, $o] = $rule + [1 => []];
            if (!empty($o['required']) || count($sample) < 2) {
                $sample[$f] = match ($t) { 'int' => $f === 'client_id' ? 12 : 1, 'number' => 1500, 'bool' => true, 'date' => '2026-11-01', 'datetime' => '2026-11-04T09:00:00-08:00',
                    'quarter' => '2027-Q1', 'email_list' => ['jane@client.example'], 'array' => [['id' => 101, 'status' => 'met']], default => isset($o['enum']) ? $o['enum'][0] : 'text' };
            }
        }
        $cmd .= " -H \"Content-Type: application/json\"" . ($r['method'] === 'POST' ? ' -H "Idempotency-Key: $(uuidgen)"' : '') . " \\\n  -d '" . json_encode($sample, JSON_UNESCAPED_SLASHES) . "'";
    }
    return $cmd . " \\\n  " . $baseUrl . rtrim($path, '/');
};
?>
<div class="row">
  <div class="col-lg-3 d-none d-lg-block">
    <div class="card sticky-top" style="top:1rem"><div class="card-body small">
      <a class="d-block mb-1 font-weight-bold" href="#start">Getting started</a>
      <a class="d-block mb-1 font-weight-bold" href="#scopes">Permissions</a>
      <a class="d-block mb-2 font-weight-bold" href="#errors">Errors</a>
      <?php foreach ($byTag as $tag => $rs): ?>
        <a class="d-block font-weight-bold mt-2" href="#tag-<?= e(strtolower(str_replace(' ', '-', $tag))) ?>"><?= e($tag) ?></a>
        <?php foreach ($rs as $r): ?><a class="d-block text-muted text-truncate" href="#<?= e(Spec::opId($r)) ?>"><span class="text-<?= $methodColor[$r['method']] ?>"><?= $r['method'] ?></span> <?= e($r['path']) ?></a><?php endforeach; ?>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="col-lg-9">
    <div class="card" id="start"><div class="card-body">
      <div class="d-flex flex-wrap align-items-center mb-2"><h2 class="h4 mb-0 mr-auto"><?= e($spec['info']['title']) ?> <span class="badge badge-light border">v<?= e($spec['info']['version']) ?></span></h2>
        <a class="btn btn-sm btn-default" href="/settings/api/openapi.json"><i class="fas fa-download mr-1"></i>OpenAPI (JSON)</a></div>
      <?php if (!$enabled): ?><div class="alert alert-warning py-2 small">The API is off. Turn it on under <a href="/settings/api">Settings → API</a> before calling it.</div><?php endif; ?>
      <?php foreach (explode("\n\n", Spec::intro()) as $para): ?><p><?= $md($para) ?></p><?php endforeach; ?>
      <h3 class="h6 mt-3">Quick test</h3>
      <pre class="bg-light border rounded p-2 small mb-2">export ALIGN_KEY="msa_…"
curl -s -H "Authorization: Bearer $ALIGN_KEY" <?= e($baseUrl) ?>

curl -s -H "Authorization: Bearer $ALIGN_KEY" "<?= e($baseUrl) ?>/devices?attention=true&amp;per_page=20"</pre>
      <p class="small text-muted mb-0"><b>n8n / Zapier / Power Automate:</b> use an HTTP Request step with header <code>Authorization</code> = <code>Bearer msa_…</code>. <b>AI agents:</b> give them the OpenAPI file (or <code><?= e($baseUrl) ?>/openapi.json</code>, readable without a key while the API is on) and a key with only the scopes they need.</p>
    </div></div>

    <div class="card" id="scopes"><div class="card-header py-2"><h3 class="card-title">Permissions (scopes)</h3></div>
      <div class="card-body p-0"><table class="table table-sm mb-0 small">
        <thead class="thead-light"><tr><th>Area</th><th>Read (<code>area:read</code>)</th><th>Write (<code>area:write</code>, includes read)</th></tr></thead>
        <tbody><?php foreach (Keys::AREAS as $a => [$l, $rd, $wd]): ?><tr><td><b><?= e($l) ?></b><div class="text-monospace text-muted"><?= $a ?></div></td><td><?= e($rd) ?></td><td><?= $wd ? e($wd) : '<span class="text-muted">read only</span>' ?></td></tr><?php endforeach; ?></tbody>
      </table></div></div>

    <div class="card" id="errors"><div class="card-header py-2"><h3 class="card-title">Errors</h3></div>
      <div class="card-body p-0"><table class="table table-sm mb-0 small">
        <thead class="thead-light"><tr><th>Status</th><th>error.code</th><th>Meaning</th></tr></thead>
        <tbody><?php foreach (Spec::ERRORS as $st => [$codes, $what]): ?><tr><td><?= $st ?></td><td class="text-monospace"><?= e($codes) ?></td><td><?= e($what) ?></td></tr><?php endforeach; ?></tbody>
      </table>
      <pre class="bg-light border-top p-2 small mb-0">{"error": {"code": "validation_failed", "message": "Some fields are not valid.", "fields": {"cost": "Must be a number."}}, "request_id": "9f2c41d0a7b3e815"}</pre></div></div>

    <?php foreach ($byTag as $tag => $rs): ?>
      <h3 class="h5 mt-4 mb-2" id="tag-<?= e(strtolower(str_replace(' ', '-', $tag))) ?>"><?= e($tag) ?></h3>
      <?php foreach ($rs as $r): $rules = $r['body'] !== null ? Spec::rules($r) : []; $schema = $r['returns'] ? (Spec::SCHEMAS[$r['returns']] ?? null) : null; ?>
        <div class="card mb-2" id="<?= e(Spec::opId($r)) ?>">
          <div class="card-header py-2 d-flex align-items-center flex-wrap">
            <span class="badge badge-<?= $methodColor[$r['method']] ?> mr-2" style="min-width:56px"><?= $r['method'] ?></span>
            <code class="mr-auto">/api/v1<?= e(rtrim($r['path'], '/')) ?></code>
            <?php if ($r['scope']): ?><span class="badge badge-light border text-monospace"><?= e($r['scope']) ?></span><?php endif; ?>
          </div>
          <div class="card-body small">
            <p class="mb-1 font-weight-bold"><?= e($r['summary']) ?></p>
            <?php if ($r['description']): ?><p class="text-muted"><?= $md($r['description']) ?></p><?php endif; ?>
            <?php if ($r['query']): ?>
              <div class="font-weight-bold mt-2">Query parameters</div>
              <table class="table table-sm table-borderless mb-1"><?php foreach ($r['query'] as $q => [$t, $d]): ?><tr><td class="text-monospace" style="width:30%"><?= e($q) ?> <span class="text-muted">(<?= e($t) ?>)</span></td><td><?= e($d) ?></td></tr><?php endforeach; ?></table>
            <?php endif; ?>
            <?php if ($rules): ?>
              <div class="font-weight-bold mt-2">Body (JSON)<?= $r['creating'] ? '' : ' — send only what changes' ?></div>
              <table class="table table-sm table-borderless mb-1">
              <?php foreach ($rules as $f => $rule): [$t, $o] = $rule + [1 => []]; ?>
                <tr><td class="text-monospace" style="width:30%"><?= e($f) ?><?= !empty($o['required']) && $r['creating'] ? ' <span class="text-danger">*</span>' : '' ?> <span class="text-muted">(<?= e($t) ?>)</span></td>
                  <td><?= e($o['desc'] ?? '') ?><?= isset($o['enum']) ? ' <span class="text-muted">One of: ' . e(implode(', ', $o['enum'])) . '.</span>' : '' ?><?= isset($o['max']) && $t === 'string' ? ' <span class="text-muted">Max ' . (int) $o['max'] . ' chars.</span>' : '' ?></td></tr>
              <?php endforeach; ?>
              </table>
            <?php endif; ?>
            <?php if ($schema): ?>
              <details class="mt-1"><summary class="text-primary">Response: <?= $r['list'] ? 'list of ' : '' ?><?= e($r['returns']) ?></summary>
                <p class="text-muted mb-1 mt-1"><?= e($schema[0]) ?></p>
                <table class="table table-sm table-borderless mb-0"><?php foreach ($schema[1] as $f => [$t, $d]): ?><tr><td class="text-monospace" style="width:30%"><?= e($f) ?> <span class="text-muted">(<?= e($t) ?>)</span></td><td><?= e($d) ?></td></tr><?php endforeach; ?></table>
              </details>
            <?php elseif ($r['status'] === 204): ?><p class="text-muted mb-1">Returns 204 No Content.</p><?php endif; ?>
            <pre class="bg-light border rounded p-2 mt-2 mb-0"><?= e($example($r)) ?></pre>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>
