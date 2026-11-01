<?php require_once __DIR__ . '/includes/brand.php'; ?>
<?php
require_once __DIR__ . '/includes/login_required.php';
require_once __DIR__ . '/includes/action_security.php';
require_once __DIR__ . '/includes/container_poc.php';

$db = billing_get_db();
if (!$db) {
    die('Database connection failed.');
}
container_poc_ensure_schema($db);

$userId = (int)$_SESSION['website_user_id'];
$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!billing_action_validate_csrf($_POST['csrf'] ?? '', 'container_poc_csrf')) {
        $errors[] = 'Security token expired. Reload the page and try again.';
    } else {
        $appId = (int)($_POST['application_id'] ?? 0);
        $appRows = container_poc_fetch_all($db, "SELECT * FROM `" . container_poc_table('container_applications') . "` WHERE `application_id` = {$appId} AND `status` = 'active' LIMIT 1");
        if (!$appRows) {
            $errors[] = 'Selected application is unavailable.';
        } else {
            $result = container_poc_provision($db, $userId, $appRows[0], [
                'container_name' => $_POST['container_name'] ?? '',
                'platform' => $_POST['platform'] ?? 'linux',
                'cpu' => $_POST['cpu'] ?? 0,
                'memory_mb' => $_POST['memory_mb'] ?? 0,
                'storage_gb' => $_POST['storage_gb'] ?? 0,
                'location_id' => $_POST['location_id'] ?? 0,
                'port_mode' => $_POST['port_mode'] ?? 'none',
                'requested_port_block_size' => $_POST['requested_port_block_size'] ?? 0,
            ]);
            if (!empty($result['success'])) {
                $_SESSION['container_access_flash'] = [
                    'container_id' => (int)$result['container_id'],
                    'profile' => $result['access_profile'] ?? [],
                    'password' => $result['access_password'] ?? '',
                ];
                header('Location: my_servers.php?container_provisioned=1');
                exit;
            } else {
                $errors = $result['errors'] ?? ['Provisioning failed.'];
            }
        }
    }
}

$apps = container_poc_applications($db);
$locations = container_poc_fetch_all($db, "SELECT * FROM `" . container_poc_table('container_locations') . "` WHERE `enabled` = 1 ORDER BY `name`");
$selected = $apps[0] ?? null;
$csrf = billing_action_csrf_token('container_poc_csrf');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Application Catalog - <?php echo billing_brand_e('name'); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include __DIR__ . '/includes/top.php'; ?>
<?php include __DIR__ . '/includes/menu.php'; ?>
<main class="container-wide panel gsw-container-poc">
  <h1>Application Catalog</h1>
  <p>Select Server Content or keep the default empty managed container, then provision through the registered container agent.</p>
  <?php if ($message): ?><div class="gsw-alert-success"><?php echo container_poc_e($message); ?></div><?php endif; ?>
  <?php foreach ($errors as $error): ?><div class="gsw-alert-danger"><?php echo container_poc_e($error); ?></div><?php endforeach; ?>

  <form method="post" id="container-poc-form">
    <input type="hidden" name="csrf" value="<?php echo container_poc_e($csrf); ?>">
    <section class="gsw-app-grid" aria-label="Available applications">
      <?php foreach ($apps as $index => $app): ?>
        <?php $requiredPorts = container_poc_json_decode($app['required_ports_json'] ?? '[]', []); ?>
        <label class="gsw-app-card <?php echo $index === 0 ? 'is-selected' : ''; ?>" data-app='<?php echo container_poc_e(json_encode($app)); ?>'>
          <input type="radio" name="application_id" value="<?php echo (int)$app['application_id']; ?>" <?php echo $index === 0 ? 'checked' : ''; ?>>
          <span class="gsw-app-icon"><?php echo $app['icon'] ? '<img src="' . container_poc_e($app['icon']) . '" alt="">' : '[ ]'; ?></span>
          <strong><?php echo container_poc_e($app['name']); ?></strong>
          <span><?php echo container_poc_e($app['short_description']); ?></span>
          <small>v<?php echo container_poc_e($app['version']); ?> · <?php echo container_poc_e($app['supported_platform']); ?></small>
          <small><?php echo container_poc_e((string)$app['recommended_cpu']); ?> CPU · <?php echo (int)$app['recommended_memory_mb']; ?> MB · <?php echo (int)$app['recommended_storage_gb']; ?> GB</small>
          <small><?php echo count($requiredPorts); ?> required port(s) · <?php echo container_poc_e($app['status']); ?></small>
        </label>
      <?php endforeach; ?>
    </section>

    <section class="gsw-wizard-grid">
      <div class="gsw-form-card">
        <h2>Configure Container</h2>
        <label>Container Name <input name="container_name" required value="demo-container"></label>
        <label>Platform
          <select name="platform" id="platform">
            <option value="linux">Linux</option>
            <option value="windows">Windows</option>
          </select>
        </label>
        <label>CPU <input name="cpu" id="cpu" type="number" min="0.1" step="0.1" value="<?php echo container_poc_e($selected['recommended_cpu'] ?? '1'); ?>"></label>
        <label>Memory MB <input name="memory_mb" id="memory_mb" type="number" min="1" value="<?php echo (int)($selected['recommended_memory_mb'] ?? 1024); ?>"></label>
        <label>Storage GB <input name="storage_gb" id="storage_gb" type="number" min="1" value="<?php echo (int)($selected['recommended_storage_gb'] ?? 10); ?>"></label>
        <label>Location
          <select name="location_id">
            <?php foreach ($locations as $location): ?>
              <option value="<?php echo (int)$location['location_id']; ?>"><?php echo container_poc_e($location['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="gsw-form-card">
        <h2>Ports And Review</h2>
        <div id="app-details"></div>
        <label>Port Mode
          <select name="port_mode" id="port_mode">
            <option value="none">No ports</option>
            <option value="automatic">Automatic allocation</option>
            <option value="block">Port block</option>
          </select>
        </label>
        <label>Requested Block Size <input name="requested_port_block_size" id="requested_port_block_size" type="number" min="0" value="0"></label>
        <button class="gsw-btn" type="submit">Provision Container</button>
      </div>
    </section>
  </form>
</main>
<script>
(function () {
  var cards = Array.prototype.slice.call(document.querySelectorAll('.gsw-app-card'));
  var detail = document.getElementById('app-details');
  function render(card) {
    cards.forEach(function (item) { item.classList.toggle('is-selected', item === card); });
    var app = JSON.parse(card.getAttribute('data-app') || '{}');
    document.getElementById('cpu').value = app.recommended_cpu || 1;
    document.getElementById('memory_mb').value = app.recommended_memory_mb || 1024;
    document.getElementById('storage_gb').value = app.recommended_storage_gb || 10;
    document.getElementById('platform').value = app.supported_platform || 'linux';
    var required = [];
    try { required = JSON.parse(app.required_ports_json || '[]'); } catch (e) {}
    document.getElementById('port_mode').value = required.length ? 'automatic' : 'none';
    document.getElementById('requested_port_block_size').value = app.default_port_block_size || 0;
    detail.innerHTML = '<p><strong>' + app.name + '</strong></p><p>' + (app.compatibility_notes || '') + '</p><p>Required ports: ' + (required.length ? required.map(function (p) { return (p.label || 'port') + ' ' + p.container_port + '/' + p.protocol; }).join(', ') : 'none') + '</p>';
  }
  cards.forEach(function (card) { card.addEventListener('click', function () { render(card); }); });
  if (cards[0]) render(cards[0]);
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
