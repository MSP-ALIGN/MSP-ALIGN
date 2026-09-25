<?php
use Align\Auth;

$u = Auth::user();
$nav = $nav ?? '';
$items = [
    ['dashboard', '/', 'Dashboard', 'viewer'],
    ['clients', '/clients', 'Clients', 'viewer'],
    ['mapping', '/mapping', 'Client mapping', 'tech'],
    ['sync', '/sync', 'Sync', 'viewer'],
    ['settings', '/settings', 'Settings', 'admin'],
    ['users', '/users', 'Users', 'admin'],
    ['audit', '/audit', 'Audit log', 'admin'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!empty($refresh)): ?><meta http-equiv="refresh" content="5"><?php endif; ?>
<title><?= e($title ?? '') ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/app.css?v=<?= e(APP_VERSION) ?>">
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<script src="/assets/app.js?v=<?= e(APP_VERSION) ?>" defer></script>
</head>
<body>
<div class="shell">
  <aside class="side">
    <a class="brand" href="/"><img src="/assets/icon.svg" alt="" width="26" height="26"><span>Mountaineer<b>Align</b></span></a>
    <nav>
      <?php foreach ($items as [$key, $href, $label, $role]): if (!Auth::can($role)) continue; ?>
        <a href="<?= e($href) ?>" class="<?= $nav === $key ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <a href="/account" class="<?= $nav === 'account' ? 'active' : '' ?>"><?= e($u['name'] ?? '') ?></a>
      <form method="post" action="/logout"><?= csrf_field() ?><button class="linklike">Sign out</button></form>
      <div class="ver">v<?= e(APP_VERSION) ?></div>
    </div>
  </aside>
  <main class="main">
    <?php foreach (take_flashes() as $f): ?>
      <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
