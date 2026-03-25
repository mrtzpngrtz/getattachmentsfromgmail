<?php
session_start();

$page = $_GET['page'] ?? 'connect';

// Redirect to connect if no session
if ($page !== 'connect' && empty($_SESSION['imap_email'])) {
    header('Location: index.php?page=connect');
    exit;
}

// Flash messages
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function imap_ext_ok(): bool {
    return function_exists('imap_open');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mail Attachment Downloader</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="container">
  <header>
    <div class="inner">
      <h1>Mail Attachment Downloader</h1>
      <?php if (!empty($_SESSION['imap_email'])): ?>
      <nav>
        <span><?= h($_SESSION['imap_email']) ?></span>
        <a href="actions.php?action=disconnect" class="btn btn-ghost btn-sm">Disconnect</a>
      </nav>
      <?php endif; ?>
    </div>
  </header>

  <?php if (!imap_ext_ok()): ?>
  <div class="alert alert-error">
    PHP IMAP extension is not loaded. Open <code>php.ini</code>, uncomment <code>extension=imap</code>, and restart Apache.
  </div>
  <?php endif; ?>

  <?php if ($flash): ?>
  <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

<?php if ($page === 'connect'): ?>
<!-- ═══════════════════════════════════════════════════════ CONNECT PAGE -->
  <div class="center-wrap">
    <div class="card connect-card">
      <h2>Connect to Gmail</h2>

      <div class="info-box">
        <strong>You need a Gmail App Password.</strong><br>
        Regular passwords won't work — Gmail requires an App Password when 2-Step Verification is on.
        <ol>
          <li>Go to <strong>myaccount.google.com → Security</strong></li>
          <li>Under "How you sign in to Google", click <strong>2-Step Verification</strong></li>
          <li>Scroll down and click <strong>App Passwords</strong></li>
          <li>Create one (name it anything), copy the 16-char password</li>
        </ol>
      </div>

      <form method="POST" action="actions.php?action=connect">
        <div class="field" style="margin-bottom:1rem">
          <label>Gmail Address</label>
          <input type="email" name="email" placeholder="you@gmail.com" required autofocus
                 value="<?= h($_SESSION['imap_email'] ?? '') ?>">
        </div>
        <div class="field" style="margin-bottom:1.25rem">
          <label>App Password</label>
          <input type="password" name="password" placeholder="xxxx xxxx xxxx xxxx" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%" <?= !imap_ext_ok() ? 'disabled' : '' ?>>
          Connect
        </button>
      </form>
    </div>
  </div>

<?php elseif ($page === 'search'): ?>
<!-- ═══════════════════════════════════════════════════════ SEARCH PAGE -->
<?php
  // Load folder list for dropdown (best-effort)
  $folders = [];
  if (imap_ext_ok()) {
      $mbox = @imap_open(
          '{imap.gmail.com:993/imap/ssl}',
          $_SESSION['imap_email'],
          $_SESSION['imap_pass'],
          OP_HALFOPEN | OP_READONLY,
          1
      );
      if ($mbox) {
          $raw = imap_list($mbox, '{imap.gmail.com:993/imap/ssl}', '*');
          if ($raw) {
              foreach ($raw as $f) {
                  // Strip server prefix
                  $name = preg_replace('/^\{[^}]+\}/', '', $f);
                  $folders[] = $name;
              }
              sort($folders);
          }
          imap_close($mbox);
      }
  }
  if (empty($folders)) {
      $folders = ['INBOX', '[Gmail]/All Mail', '[Gmail]/Sent Mail', '[Gmail]/Starred'];
  }

  // Current search params (preserved after search)
  $q = $_SESSION['last_search'] ?? [];
  $results = $_SESSION['search_results'] ?? null;
  unset($_SESSION['search_results']);
?>

  <!-- Search form -->
  <div class="card">
    <form method="POST" action="actions.php?action=search" id="searchForm">
      <div class="form-row">
        <div class="field">
          <label>From</label>
          <input type="text" name="from" placeholder="sender@example.com"
                 value="<?= h($q['from'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Subject contains</label>
          <input type="text" name="subject" placeholder="Invoice"
                 value="<?= h($q['subject'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Folder</label>
          <select name="folder">
            <?php foreach ($folders as $f): ?>
            <option value="<?= h($f) ?>" <?= ($q['folder'] ?? 'INBOX') === $f ? 'selected' : '' ?>>
              <?= h($f) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label>Date from</label>
          <input type="date" name="date_from" value="<?= h($q['date_from'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Date to</label>
          <input type="date" name="date_to" value="<?= h($q['date_to'] ?? '') ?>">
        </div>
        <div class="field" style="justify-content:flex-end">
          <label>&nbsp;</label>
          <button type="submit" class="btn btn-primary" id="searchBtn">Search</button>
        </div>
      </div>
      <div class="check-row">
        <input type="checkbox" name="has_attachment" id="hasAtt" value="1"
               <?= !isset($q['has_attachment']) || $q['has_attachment'] ? 'checked' : '' ?>>
        <label for="hasAtt" style="text-transform:none;letter-spacing:0">Only emails with attachments</label>
      </div>
    </form>
  </div>

  <?php if ($results !== null): ?>
  <!-- Results -->
  <?php if (empty($results)): ?>
    <div class="alert alert-info">No emails found matching your search.</div>
  <?php else: ?>
  <form method="POST" action="actions.php?action=download" id="dlForm">
    <div class="toolbar">
      <label class="check-row" style="margin:0">
        <input type="checkbox" id="selectAll"> Select all
      </label>
      <span class="count"><?= count($results) ?> email<?= count($results) !== 1 ? 's' : '' ?> found</span>
      <button type="submit" class="btn btn-primary btn-sm" id="dlBtn" disabled>
        Download selected as ZIP
      </button>
      <span id="selCount" class="count"></span>
    </div>

    <div class="card" style="padding:0;overflow:hidden">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th class="check"></th>
              <th>From</th>
              <th>Subject</th>
              <th>Date</th>
              <th>Attachments</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $r): ?>
            <tr>
              <td class="check">
                <?php if (!empty($r['attachments'])): ?>
                <input type="checkbox" name="uids[]" value="<?= h($r['uid']) ?>"
                       data-folder="<?= h($q['folder'] ?? 'INBOX') ?>" class="row-check">
                <?php endif; ?>
              </td>
              <td class="from" title="<?= h($r['from']) ?>"><?= h($r['from']) ?></td>
              <td class="subject" title="<?= h($r['subject']) ?>"><?= h($r['subject']) ?></td>
              <td class="date"><?= h($r['date']) ?></td>
              <td class="files">
                <?php foreach ($r['attachments'] as $att): ?>
                  <span class="chip" title="<?= h($att['name']) ?>">
                    <?= h(strlen($att['name']) > 28 ? substr($att['name'], 0, 25).'…' : $att['name']) ?>
                    <span style="color:#555"><?= h(format_bytes($att['size'])) ?></span>
                  </span>
                <?php endforeach; ?>
                <?php if (empty($r['attachments'])): ?>
                  <span style="color:#444">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pass folder for download handler -->
    <input type="hidden" name="folder" value="<?= h($q['folder'] ?? 'INBOX') ?>">
  </form>
  <?php endif; ?>
  <?php endif; ?>

<?php endif; ?>
</div><!-- /container -->

<footer style="text-align:center;padding:2rem 0 1.5rem;color:#444;font-size:0.78rem;letter-spacing:0.04em">
  made by <a href="https://aop.studio" style="color:#555;text-decoration:none">aop.studio</a>
</footer>

<script>
// Select-all toggle
const selectAll = document.getElementById('selectAll');
const dlBtn = document.getElementById('dlBtn');
const selCount = document.getElementById('selCount');

function updateDlBtn() {
  const checked = document.querySelectorAll('.row-check:checked');
  if (dlBtn) {
    dlBtn.disabled = checked.length === 0;
    selCount.textContent = checked.length > 0 ? checked.length + ' selected' : '';
  }
}

if (selectAll) {
  selectAll.addEventListener('change', () => {
    document.querySelectorAll('.row-check').forEach(cb => { cb.checked = selectAll.checked; });
    updateDlBtn();
  });
}

document.querySelectorAll('.row-check').forEach(cb => {
  cb.addEventListener('change', updateDlBtn);
});

// Show spinner on search
const searchForm = document.getElementById('searchForm');
const searchBtn = document.getElementById('searchBtn');
if (searchForm) {
  searchForm.addEventListener('submit', () => {
    if (searchBtn) {
      searchBtn.innerHTML = '<span class="spinner"></span>Searching…';
      searchBtn.disabled = true;
    }
  });
}
</script>
</body>
</html>

<?php
function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}
?>
