<?php
/**
 * actions.php — handles all POST/GET actions.
 * SAFETY: All IMAP connections use OP_READONLY. No write operations are performed.
 * Allowed IMAP functions: imap_open, imap_close, imap_list, imap_search,
 *   imap_fetch_overview, imap_fetchstructure, imap_fetchbody, imap_utf8.
 * Forbidden: imap_delete, imap_expunge, imap_move, imap_mail_move, imap_setflag_full.
 */

session_start();

$action = $_GET['action'] ?? '';

// ─────────────────────────────────────────────────────────────── DISCONNECT
if ($action === 'disconnect') {
    session_destroy();
    header('Location: index.php?page=connect');
    exit;
}

// ─────────────────────────────────────────────────────────────── CONNECT
if ($action === 'connect') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect_back(); }

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        flash('error', 'Please enter your email and app password.');
        redirect_connect();
    }

    if (!function_exists('imap_open')) {
        flash('error', 'PHP IMAP extension is not enabled.');
        redirect_connect();
    }

    // Suppress warnings; we check return value
    $mbox = @imap_open(
        '{imap.gmail.com:993/imap/ssl}INBOX',
        $email,
        $password,
        OP_READONLY,
        1
    );

    if (!$mbox) {
        $err = imap_last_error();
        flash('error', 'Connection failed: ' . ($err ?: 'Invalid credentials or app password.'));
        redirect_connect();
    }

    imap_close($mbox);

    $_SESSION['imap_email'] = $email;
    $_SESSION['imap_pass']  = $password;

    flash('success', 'Connected to ' . $email);
    header('Location: index.php?page=search');
    exit;
}

// ─────────────────────────────────────────────────────────────── SEARCH
if ($action === 'search') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect_back(); }
    require_session();

    $from           = trim($_POST['from'] ?? '');
    $subject        = trim($_POST['subject'] ?? '');
    $folder         = $_POST['folder'] ?? 'INBOX';
    $date_from      = $_POST['date_from'] ?? '';
    $date_to        = $_POST['date_to'] ?? '';
    $has_attachment = !empty($_POST['has_attachment']);

    // Save search params for form re-population
    $_SESSION['last_search'] = compact('from', 'subject', 'folder', 'date_from', 'date_to', 'has_attachment');

    // Build IMAP search criteria string
    $criteria = 'ALL';
    $parts = [];
    if ($from)      $parts[] = 'FROM "' . addslashes($from) . '"';
    if ($subject)   $parts[] = 'SUBJECT "' . addslashes($subject) . '"';
    if ($date_from) $parts[] = 'SINCE "' . date('d-M-Y', strtotime($date_from)) . '"';
    if ($date_to)   $parts[] = 'BEFORE "' . date('d-M-Y', strtotime($date_to . ' +1 day')) . '"';
    if (!empty($parts)) $criteria = implode(' ', $parts);

    $mailbox = '{imap.gmail.com:993/imap/ssl}' . $folder;
    $mbox = @imap_open($mailbox, $_SESSION['imap_email'], $_SESSION['imap_pass'], OP_READONLY, 1);

    if (!$mbox) {
        flash('error', 'Could not open folder "' . htmlspecialchars($folder) . '": ' . imap_last_error());
        header('Location: index.php?page=search');
        exit;
    }

    $msg_nums = @imap_search($mbox, $criteria, SE_UID);
    $results = [];

    if ($msg_nums) {
        // Process newest first, cap at 200 for performance
        rsort($msg_nums);
        $msg_nums = array_slice($msg_nums, 0, 200);

        foreach ($msg_nums as $uid) {
            $overview = imap_fetch_overview($mbox, $uid, FT_UID);
            if (!$overview) continue;
            $ov = $overview[0];

            $from_str = '';
            if (!empty($ov->from)) {
                $from_str = imap_utf8($ov->from);
            }
            $subject_str = !empty($ov->subject) ? imap_utf8($ov->subject) : '(no subject)';
            $date_str    = !empty($ov->date) ? date('Y-m-d H:i', strtotime($ov->date)) : '';

            // Get attachment list
            $attachments = get_attachments($mbox, $uid);

            if ($has_attachment && empty($attachments)) continue;

            $results[] = [
                'uid'         => $uid,
                'from'        => $from_str,
                'subject'     => $subject_str,
                'date'        => $date_str,
                'attachments' => $attachments,
            ];
        }
    }

    imap_close($mbox);

    $_SESSION['search_results'] = $results;
    header('Location: index.php?page=search');
    exit;
}

// ─────────────────────────────────────────────────────────────── DOWNLOAD
if ($action === 'download') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect_back(); }
    require_session();

    $uids   = $_POST['uids'] ?? [];
    $folder = $_POST['folder'] ?? 'INBOX';

    if (empty($uids)) {
        flash('error', 'No emails selected.');
        header('Location: index.php?page=search');
        exit;
    }

    $mailbox = '{imap.gmail.com:993/imap/ssl}' . $folder;
    $mbox = @imap_open($mailbox, $_SESSION['imap_email'], $_SESSION['imap_pass'], OP_READONLY, 1);

    if (!$mbox) {
        flash('error', 'Could not open mailbox: ' . imap_last_error());
        header('Location: index.php?page=search');
        exit;
    }

    // Create temp ZIP file
    $tmpfile = tempnam(sys_get_temp_dir(), 'mail_att_');
    $zip = new ZipArchive();
    if ($zip->open($tmpfile, ZipArchive::OVERWRITE) !== true) {
        imap_close($mbox);
        flash('error', 'Could not create ZIP file.');
        header('Location: index.php?page=search');
        exit;
    }

    $file_count = 0;
    $name_counts = []; // track duplicate filenames

    foreach ($uids as $uid) {
        $uid = (int)$uid;
        if ($uid <= 0) continue;

        $structure = @imap_fetchstructure($mbox, $uid, FT_UID);
        if (!$structure) continue;

        $att_parts = [];
        collect_attachment_parts($structure, $att_parts);

        foreach ($att_parts as $part_info) {
            $part_num = $part_info['part'];
            $filename = $part_info['name'];
            $encoding = $part_info['encoding'];

            if (!$filename) $filename = 'attachment_' . $uid . '_' . $part_num;

            // Make filename unique in ZIP
            $base_name = $filename;
            if (isset($name_counts[$base_name])) {
                $name_counts[$base_name]++;
                $ext  = pathinfo($base_name, PATHINFO_EXTENSION);
                $name = pathinfo($base_name, PATHINFO_FILENAME);
                $filename = $name . '_' . $name_counts[$base_name] . ($ext ? '.' . $ext : '');
            } else {
                $name_counts[$base_name] = 1;
            }

            // Fetch the raw body part
            $raw = @imap_fetchbody($mbox, $uid, $part_num, FT_UID);
            if ($raw === false || $raw === '') continue;

            // Decode
            $data = decode_body($raw, $encoding);

            $zip->addFromString($filename, $data);
            $file_count++;
        }
    }

    imap_close($mbox);
    $zip->close();

    if ($file_count === 0) {
        unlink($tmpfile);
        flash('error', 'No attachments found in the selected emails.');
        header('Location: index.php?page=search');
        exit;
    }

    // Stream ZIP to browser
    $download_name = 'attachments_' . date('Y-m-d') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Content-Length: ' . filesize($tmpfile));
    header('Cache-Control: no-cache, no-store');
    readfile($tmpfile);
    unlink($tmpfile);
    exit;
}

// ─────────────────────────────────────────────────────────────── 404
header('Location: index.php');
exit;


// ═══════════════════════════════════════════════════════════════ HELPERS

function require_session(): void {
    if (empty($_SESSION['imap_email'])) {
        header('Location: index.php?page=connect');
        exit;
    }
}

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = compact('type', 'msg');
}

function redirect_connect(): never {
    header('Location: index.php?page=connect');
    exit;
}

function redirect_back(): never {
    header('Location: index.php');
    exit;
}

/**
 * Returns list of attachments for a message (uid).
 * Each item: ['name' => string, 'size' => int (bytes estimate)]
 */
function get_attachments($mbox, int $uid): array {
    $structure = @imap_fetchstructure($mbox, $uid, FT_UID);
    if (!$structure) return [];

    $parts = [];
    collect_attachment_parts($structure, $parts);

    $result = [];
    foreach ($parts as $p) {
        $result[] = [
            'name' => $p['name'],
            'size' => $p['size'],
        ];
    }
    return $result;
}

/**
 * Recursively walk message structure and collect attachment parts.
 * Fills $out with ['part' => string, 'name' => string, 'size' => int, 'encoding' => int]
 */
function collect_attachment_parts($structure, array &$out, string $prefix = ''): void {
    // Single-part message
    if (!isset($structure->parts)) {
        $name = get_part_filename($structure);
        if ($name) {
            $out[] = [
                'part'     => $prefix ?: '1',
                'name'     => $name,
                'size'     => (int)($structure->bytes ?? 0),
                'encoding' => $structure->encoding ?? 0,
            ];
        }
        return;
    }

    foreach ($structure->parts as $i => $part) {
        $part_num = ($prefix ? $prefix . '.' : '') . ($i + 1);
        $name = get_part_filename($part);

        $is_attachment = (
            (isset($part->disposition) && strtolower($part->disposition) === 'attachment') ||
            $name !== null
        );

        if ($is_attachment && $name) {
            $out[] = [
                'part'     => $part_num,
                'name'     => $name,
                'size'     => (int)($part->bytes ?? 0),
                'encoding' => $part->encoding ?? 0,
            ];
        } elseif (isset($part->parts)) {
            // Recurse into multipart
            collect_attachment_parts($part, $out, $part_num);
        }
    }
}

/**
 * Extract filename from a message part, decoding MIME encoding if needed.
 */
function get_part_filename($part): ?string {
    // Check dparameters (Content-Disposition params) first
    if (!empty($part->dparameters)) {
        foreach ($part->dparameters as $dp) {
            if (strtolower($dp->attribute) === 'filename') {
                return imap_utf8(trim($dp->value));
            }
        }
    }
    // Then parameters (Content-Type params)
    if (!empty($part->parameters)) {
        foreach ($part->parameters as $p) {
            if (strtolower($p->attribute) === 'name') {
                return imap_utf8(trim($p->value));
            }
        }
    }
    return null;
}

/**
 * Decode body data based on IMAP encoding type.
 * 0=7BIT, 1=8BIT, 2=BINARY, 3=BASE64, 4=QUOTED-PRINTABLE, 5=OTHER
 */
function decode_body(string $raw, int $encoding): string {
    switch ($encoding) {
        case 3: // BASE64
            return base64_decode(str_replace(["\r", "\n"], '', $raw));
        case 4: // QUOTED-PRINTABLE
            return quoted_printable_decode($raw);
        default:
            return $raw;
    }
}
