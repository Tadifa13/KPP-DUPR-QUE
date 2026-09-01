<?php
/**
 * First-run setup. Creates the club and the first organizer account, then
 * disables itself — once a user exists this page redirects to the login.
 */

require __DIR__ . '/ui/bootstrap.php';

if (user_count() > 0) {
    redirect('login.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $club = trim((string) param('club'));
    $name = trim((string) param('display_name'));
    $user = trim((string) param('username'));
    $pass = (string) param('password');
    $pass2 = (string) param('password2');

    if ($club === '')                 { $errors[] = 'Give the club a name.'; }
    if ($name === '')                 { $errors[] = 'Give yourself a display name.'; }
    if (!preg_match('/^[a-z0-9_.-]{3,32}$/i', $user)) {
        $errors[] = 'Username must be 3–32 characters: letters, numbers, dot, dash or underscore.';
    }
    if (strlen($pass) < 10)           { $errors[] = 'Password must be at least 10 characters.'; }
    if ($pass !== $pass2)             { $errors[] = 'The two passwords do not match.'; }

    if (!$errors) {
        $clubId = club_create($club);
        user_create($clubId, $user, $pass, $name);
        auth_login($user, $pass);
        flash('Welcome. Add your players, then start a session.');
        redirect('roster.php');
    }
}

page_head('Setup');
?>
<p class="eyebrow">First run</p>
<h1>Set up <?= e(APP_NAME) ?></h1>
<p class="sub">One club, one organizer account. Everything is stored on this server — nothing is sent anywhere else.</p>

<?php foreach ($errors as $err): ?>
  <div class="flash flash-bad" style="margin:0 0 10px"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="card" autocomplete="off">
  <?= csrf_field() ?>
  <div class="field">
    <label for="club">Club name</label>
    <input type="text" id="club" name="club" value="<?= e((string) param('club', '')) ?>" required>
  </div>
  <div class="field">
    <label for="display_name">Your name</label>
    <input type="text" id="display_name" name="display_name" value="<?= e((string) param('display_name', '')) ?>" required>
  </div>
  <hr class="divider">
  <div class="field">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= e((string) param('username', '')) ?>" required autocapitalize="none">
  </div>
  <div class="field">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password">
    <p class="hint">At least 10 characters.</p>
  </div>
  <div class="field">
    <label for="password2">Confirm password</label>
    <input type="password" id="password2" name="password2" required autocomplete="new-password">
  </div>
  <button class="btn btn-primary btn-block" type="submit">Create club</button>
</form>
<?php page_foot(); ?>
