<?php
/**
 * Roster — the club player list and their official DUPR ratings.
 */

require __DIR__ . '/ui/bootstrap.php';

$user = require_login();
$clubId = $user['club_id'];
$session = session_active($clubId);
$importErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = param('action');

    if ($action === 'add') {
        $name = trim((string) param('name'));
        $dupr = (float) param('dupr', DUPR_DEFAULT);
        if ($name === '') {
            flash('A player needs a name.', 'bad');
        } elseif ($dupr < DUPR_MIN || $dupr > DUPR_MAX) {
            flash('DUPR must be between ' . fmt_dupr(DUPR_MIN) . ' and ' . fmt_dupr(DUPR_MAX) . '.', 'bad');
        } else {
            $pid = player_create($clubId, $name, $dupr);
            if ($session) {
                roster_add($session['id'], $pid, true);
            }
            flash($name . ' added' . ($session ? ' and joined the live session.' : '.'));
        }
        redirect('roster.php');
    }

    if ($action === 'import') {
        $parsed = parse_roster_block((string) param('block'));
        $importErrors = $parsed['errors'];
        $added = 0;
        foreach ($parsed['valid'] as $row) {
            $pid = player_create($clubId, $row['name'], $row['dupr']);
            if ($session) {
                roster_add($session['id'], $pid, true);
            }
            $added++;
        }
        if ($added) {
            flash($added . ' player' . ($added === 1 ? '' : 's') . ' imported.');
        }
        if (!$importErrors) {
            redirect('roster.php');
        }
    }

    if ($action === 'update') {
        player_update((string) param('id'), (string) param('name'), (float) param('dupr'));
        flash('Player updated. Ratings already frozen onto played matches are unaffected.');
        redirect('roster.php');
    }

    if ($action === 'archive') {
        player_set_archived((string) param('id'), true);
        flash('Player archived — their history is kept.', 'warn');
        redirect('roster.php');
    }

    if ($action === 'restore') {
        player_set_archived((string) param('id'), false);
        flash('Player restored.');
        redirect('roster.php');
    }
}

$players = players_for_club($clubId);
$archived = array_values(array_filter(
    players_for_club($clubId, true),
    fn($p) => (int) $p['archived'] === 1
));

page_head('Roster', ['nav' => true, 'active' => 'roster']);
?>
<p class="eyebrow">Club list</p>
<h1>Roster</h1>
<p class="sub"><?= count($players) ?> active player<?= count($players) === 1 ? '' : 's' ?>. DUPR here is the official rating — session adjustments never overwrite it.</p>

<h2>Add a player</h2>
<form method="post" class="card tight">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <div class="field-row">
    <div class="field" style="flex:3">
      <label for="name">Name</label>
      <input type="text" id="name" name="name" required placeholder="Dana Whitfield">
    </div>
    <div class="field">
      <label for="dupr">DUPR</label>
      <input type="number" id="dupr" name="dupr" step="0.01"
             min="<?= DUPR_MIN ?>" max="<?= DUPR_MAX ?>" value="<?= DUPR_DEFAULT ?>" required inputmode="decimal">
    </div>
  </div>
  <button class="btn btn-primary btn-block" type="submit">Add player</button>
</form>

<details class="card tight">
  <summary style="cursor:pointer;font-weight:700;font-size:14px">Paste a list</summary>
  <?php foreach ($importErrors as $err): ?>
    <div class="flash flash-warn" style="margin:10px 0 0"><?= e($err) ?></div>
  <?php endforeach; ?>
  <form method="post" style="margin-top:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <div class="field">
      <label for="block">One player per line, "Name DUPR"</label>
      <textarea id="block" name="block" placeholder="Dana Whitfield 3.25&#10;Marco Reyes 2.80&#10;Priya Raman 4.10"></textarea>
    </div>
    <button class="btn btn-block" type="submit">Import</button>
  </form>
</details>

<h2>Players</h2>
<?php if (!$players): ?>
  <div class="empty">No players yet. Add a few above to get started.</div>
<?php else: ?>
  <div class="plist">
  <?php foreach ($players as $p): ?>
    <details class="prow" style="display:block">
      <summary style="display:flex;align-items:center;gap:10px;cursor:pointer;list-style:none">
        <span class="grow">
          <span class="nm"><?= e($p['name']) ?></span>
          <span class="meta"><?= e(bracket_of((float) $p['dupr'])) ?></span>
        </span>
        <span class="dupr"><?= e(fmt_dupr((float) $p['dupr'])) ?></span>
      </summary>
      <form method="post" style="margin-top:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= e($p['id']) ?>">
        <div class="field-row">
          <div class="field" style="flex:3">
            <label>Name</label>
            <input type="text" name="name" value="<?= e($p['name']) ?>" required>
          </div>
          <div class="field">
            <label>DUPR</label>
            <input type="number" name="dupr" step="0.01" min="<?= DUPR_MIN ?>" max="<?= DUPR_MAX ?>"
                   value="<?= e(fmt_dupr((float) $p['dupr'])) ?>" required inputmode="decimal">
          </div>
        </div>
        <div class="btn-row">
          <button class="btn btn-primary" type="submit">Save</button>
        </div>
      </form>
      <form method="post" style="margin-top:8px"
            onsubmit="return confirm('Archive <?= e(addslashes($p['name'])) ?>? Their game history is kept.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="id" value="<?= e($p['id']) ?>">
        <button class="btn btn-ghost btn-sm" type="submit">Archive player</button>
      </form>
    </details>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($archived): ?>
  <h2>Archived</h2>
  <div class="plist">
  <?php foreach ($archived as $p): ?>
    <div class="prow is-done">
      <div class="grow"><div class="nm"><?= e($p['name']) ?></div></div>
      <span class="dupr"><?= e(fmt_dupr((float) $p['dupr'])) ?></span>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="id" value="<?= e($p['id']) ?>">
        <button class="btn btn-ghost btn-sm" type="submit">Restore</button>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php page_foot(); ?>
