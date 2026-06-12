<?php
/**
 * ZealPulse — /middleware introspection page (Phase 5 B7).
 * Renders App::describeRoutes() truthfully: {global, aliases, when, routes}
 * plus the deliberately-not-mounted record. HTML only (CSS in
 * public/css/middleware.css); data shaped by ZealPulse\MiddlewareInfo.
 *
 * @var array $info       {global, aliases, when, routes}
 * @var array $notMounted [{name, reason}]
 */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Middleware — ZealPulse</title>
  <link rel="stylesheet" href="/css/middleware.css">
</head>
<body>
  <h1>Middleware topology</h1>
  <p class="sub">Live <code>App::describeRoutes()</code> — global band, alias registry, <code>when()</code> path scopes, per-route chains.</p>

  <section>
    <h2>Global stack <span class="hint">(first = outermost)</span></h2>
    <ol class="chain">
      <?php foreach ($info['global'] as $mw): ?>
        <li><?= htmlspecialchars((string) $mw, ENT_QUOTES) ?></li>
      <?php endforeach; ?>
    </ol>
  </section>

  <section>
    <h2>Aliases</h2>
    <ul class="aliases">
      <?php foreach ($info['aliases'] as $alias): ?>
        <li><code><?= htmlspecialchars((string) $alias, ENT_QUOTES) ?></code></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section>
    <h2><code>when()</code> path scopes <span class="hint">(registration order = outermost first)</span></h2>
    <table>
      <thead><tr><th>Scope</th><th>Middleware chain</th></tr></thead>
      <tbody>
        <?php foreach ($info['when'] as $scope): ?>
          <tr>
            <td><code><?= htmlspecialchars((string) $scope['scope'], ENT_QUOTES) ?></code></td>
            <td><?= htmlspecialchars(implode(' → ', $scope['middleware']), ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section>
    <h2>Routes</h2>
    <table>
      <thead><tr><th>Methods</th><th>Path</th><th>Route middleware</th><th>Handler</th></tr></thead>
      <tbody>
        <?php foreach ($info['routes'] as $route): ?>
          <tr>
            <td><?= htmlspecialchars(implode(',', $route['methods']), ENT_QUOTES) ?></td>
            <td><code><?= htmlspecialchars((string) $route['path'], ENT_QUOTES) ?></code></td>
            <td><?= htmlspecialchars(implode(' → ', $route['middleware']), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars((string) $route['handler'], ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section>
    <h2>Deliberately not mounted</h2>
    <ul>
      <?php foreach ($notMounted as $item): ?>
        <li><strong><?= htmlspecialchars((string) $item['name'], ENT_QUOTES) ?></strong> —
            <?= htmlspecialchars((string) $item['reason'], ENT_QUOTES) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
</body>
</html>
