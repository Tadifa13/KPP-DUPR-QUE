<?php
require __DIR__ . '/ui/bootstrap.php';

if (user_count() === 0) {
    redirect('setup.php');
}
if (auth_user()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (auth_login((string) param('username'), (string) param('password'))) {
        redirect('index.php');
    }
    // Deliberately vague: do not reveal whether the username exists.
    $error = 'That username and password do not match.';
    usleep(400000);
}

page_head('Sign in');
?>
<p class="eyebrow">Organizer access</p>
<h1>Sign in</h1>
<p class="sub">Spectators do not need an account — they use the board link.</p>

<?php if ($error): ?>
  <div class="flash flash-bad" style="margin:0 0 10px"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="field">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" required autocapitalize="none" autocomplete="username" autofocus>
  </div>
  <div class="field">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
  </div>
  <button class="btn btn-primary btn-block" type="submit">Sign in</button>
</form>
<?php page_foot(); ?>
