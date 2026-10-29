<?php
session_name("gameserverpanel_web");
session_start();
require_once(__DIR__ . '/bootstrap.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_POST['username']) && !empty($_POST['password'])) {
    $db = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null);
    if ($db) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $email = trim($_POST['email']);

        if ($username === '' || $password === '' || $email === '') {
            $error = 'All fields are required.';
        } else {
            $md5pw = md5($password);
            $modern = password_hash($password, PASSWORD_DEFAULT);

            $has_shadow = false;
            $res = $db->query("SHOW COLUMNS FROM {$table_prefix}users LIKE 'users_pass_hash'");
            if ($res && $res->num_rows > 0) {
                $has_shadow = true;
            }

            if ($has_shadow) {
                $stmt = $db->prepare("INSERT INTO {$table_prefix}users (users_login, users_passwd, users_pass_hash, users_email, users_role) VALUES (?, ?, ?, ?, 'user')");
                $stmt->bind_param('ssss', $username, $md5pw, $modern, $email);
            } else {
                $stmt = $db->prepare("INSERT INTO {$table_prefix}users (users_login, users_passwd, users_email, users_role) VALUES (?, ?, ?, 'user')");
                $stmt->bind_param('sss', $username, $md5pw, $email);
            }

            if ($stmt && $stmt->execute()) {
                header('Location: ' . billing_url('login.php') . '?registered=1');
                exit;
            }
            $error = 'Could not create user. Maybe the name is taken.';
        }
    } else {
        $error = 'Registration is temporarily unavailable because the storefront database is not configured.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register - GameServers.World</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars(billing_url('images/brand/favicon-32x32.png'), ENT_QUOTES, 'UTF-8'); ?>">
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
<?php include(__DIR__ . '/includes/top.php'); include(__DIR__ . '/includes/menu.php'); ?>
<main class="container-wide pad-40">
  <p class="gsw-section-eyebrow">Create Account</p>
  <h1>Register</h1>
  <p>Create a GameServers.World account to order servers, manage invoices, and access the control panel workflow.</p>

  <section class="panel">
    <?php if (!empty($error)) echo '<div class="alert alert-error">'.htmlspecialchars($error, ENT_QUOTES, 'UTF-8').'</div>'; ?>
    <form method="post" action="<?php echo htmlspecialchars(billing_url('register.php'), ENT_QUOTES, 'UTF-8'); ?>">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required>
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="gsw-btn">Register</button>
    </form>
  </section>
</main>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
