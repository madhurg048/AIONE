<?php
/**
 * Document Management System.
 *
 * Every employee gets a personal folder automatically. Any folder can be
 * shared: with EVERYONE (visibility 'all'), with GROUPS (all members inherit),
 * or with INDIVIDUALS — each grant read-only or read+write. The ADMIN sees and
 * controls everything.
 *
 *   GET  documents.php?tree=1              -> folders visible to me (+my personal, auto-created)
 *   GET  documents.php?files=FOLDER_ID     -> files in a folder
 *   GET  documents.php?access=FOLDER_ID    -> grants + pickers (owner/admin)
 *   GET  documents.php?download=FILE_ID    -> stream the file (access-checked)
 *   POST documents.php                     -> create folder {name}
 *   POST documents.php?upload=FOLDER_ID    -> multipart files[] (drag & drop)
 *   POST documents.php?share=FOLDER_ID     -> {visibility?, add:[{type,id,can_edit}], remove:[grant_ids]}
 *   PATCH documents.php?folder=ID          -> rename {name}
 *   DELETE documents.php?file=ID           -> soft-delete (uploader/owner/admin)
 *   DELETE documents.php?folder=ID         -> delete empty non-personal folder (owner/admin)
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
require_module('documents');
$db = db();
$me = current_employee_id();
$isAdmin = current_role() === 'admin';

const DOC_MAX_BYTES = 26214400; // 25 MB per file
const DOC_BLOCKED_EXT = ['php','php3','php4','php5','phtml','phar','exe','bat','cmd','sh','ps1','com','scr','msi','js','vbs','htaccess'];

function doc_root(): string
{
    // Files here are only ever served back through this endpoint (login-gated),
    // never by a direct URL — so the directory does not have to live under the
    // web root. On Azure App Service set DOC_STORAGE_PATH=/home/data/documents:
    // /home is the only persistent, writable mount, and wwwroot is read-only
    // whenever the app is deployed with WEBSITE_RUN_FROM_PACKAGE.
    $dir = env('DOC_STORAGE_PATH') ?: (__DIR__ . '/../../data/documents');
    $dir = rtrim($dir, '/\\');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        // belt & braces for Apache deployments: never serve or execute from here directly
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

function doc_my_group_ids(PDO $db, int $me): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $s = $db->prepare("SELECT m.group_id FROM emp_group_members m JOIN emp_groups g ON g.id=m.group_id
                        WHERE m.employee_id=? AND g.is_active=1");
    $s->execute([$me]);
    return $cache = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

function doc_fetch_folder(PDO $db, int $id): array
{
    $s = $db->prepare("SELECT f.*, e.full_name AS owner_name FROM doc_folders f
                  LEFT JOIN employees e ON e.id=f.owner_employee_id WHERE f.id=?");
    $s->execute([$id]);
    $f = $s->fetch();
    if (!$f) fail('Folder not found', 404);
    return $f;
}

/** [canView, canEdit] for the current user on $folder. Admin: full control everywhere. */
function doc_rights(PDO $db, array $folder, int $me, bool $isAdmin): array
{
    if ($isAdmin || (int)$folder['owner_employee_id'] === $me) return [true, true];
    $view = $folder['visibility'] === 'all';
    $edit = false;
    $gids = doc_my_group_ids($db, $me);
    $q = "SELECT grantee_type, grantee_id, can_edit FROM doc_folder_access WHERE folder_id=?";
    $s = $db->prepare($q); $s->execute([(int)$folder['id']]);
    foreach ($s->fetchAll() as $g) {
        $hit = ($g['grantee_type'] === 'employee' && (int)$g['grantee_id'] === $me)
            || ($g['grantee_type'] === 'group' && in_array((int)$g['grantee_id'], $gids, true));
        if ($hit) { $view = true; if ((int)$g['can_edit'] === 1) $edit = true; }
    }
    return [$view, $edit];
}

/** Everyone gets a personal folder the first time they touch the DMS. */
function doc_ensure_personal(PDO $db, int $me): void
{
    if (!$me) return;
    $s = $db->prepare("SELECT id FROM doc_folders WHERE owner_employee_id=? AND is_personal=1");
    $s->execute([$me]);
    if ($s->fetchColumn()) return;
    $n = $db->prepare("SELECT full_name FROM employees WHERE id=?"); $n->execute([$me]);
    $name = trim((string)$n->fetchColumn()) ?: ('Employee #' . $me);
    $db->prepare("INSERT INTO doc_folders (name, owner_employee_id, visibility, is_personal) VALUES (?,?, 'private', 1)")
       ->execute([$name . ' — My Documents', $me]);
}

/* --------------------------------- reads -------------------------------- */

if (method() === 'GET' && isset($_GET['tree'])) {
    doc_ensure_personal($db, $me);
    $gids = doc_my_group_ids($db, $me);
    $rows = $db->query("SELECT f.*, e.full_name AS owner_name,
                (SELECT COUNT(*) FROM doc_files df WHERE df.folder_id=f.id AND df.is_deleted=0) AS file_count,
                (SELECT COALESCE(SUM(df.size_bytes),0) FROM doc_files df WHERE df.folder_id=f.id AND df.is_deleted=0) AS total_bytes
              FROM doc_folders f LEFT JOIN employees e ON e.id=f.owner_employee_id
              ORDER BY f.is_personal DESC, f.name")->fetchAll();
    $out = [];
    foreach ($rows as $f) {
        [$view, $edit] = doc_rights($db, $f, $me, $isAdmin);
        if (!$view) continue;
        $f['can_edit'] = $edit;
        $f['is_mine'] = (int)$f['owner_employee_id'] === $me;
        unset($f['created_at']);
        $out[] = $f;
    }
    ok($out);
}

if (method() === 'GET' && isset($_GET['files'])) {
    $folder = doc_fetch_folder($db, (int)$_GET['files']);
    [$view, $edit] = doc_rights($db, $folder, $me, $isAdmin);
    if (!$view) fail('Not authorized for this folder', 403);
    $s = $db->prepare("SELECT df.id, df.orig_name, df.mime, df.size_bytes, df.created_at,
                              e.full_name AS uploaded_by_name, df.uploaded_by
                         FROM doc_files df LEFT JOIN employees e ON e.id=df.uploaded_by
                        WHERE df.folder_id=? AND df.is_deleted=0 ORDER BY df.id DESC");
    $s->execute([(int)$folder['id']]);
    ok(['folder' => ['id' => (int)$folder['id'], 'name' => $folder['name'], 'can_edit' => $edit,
                     'is_mine' => (int)$folder['owner_employee_id'] === $me, 'visibility' => $folder['visibility'],
                     'owner_name' => $folder['owner_name']],
        'files' => $s->fetchAll()]);
}

if (method() === 'GET' && isset($_GET['access'])) {
    $folder = doc_fetch_folder($db, (int)$_GET['access']);
    if (!$isAdmin && (int)$folder['owner_employee_id'] !== $me) fail('Only the owner or admin can manage sharing', 403);
    $g = $db->prepare("SELECT a.id, a.grantee_type, a.grantee_id, a.can_edit,
                              CASE a.grantee_type WHEN 'employee' THEN e.full_name ELSE gr.name END AS grantee_name
                         FROM doc_folder_access a
                    LEFT JOIN employees e ON a.grantee_type='employee' AND e.id=a.grantee_id
                    LEFT JOIN emp_groups gr ON a.grantee_type='group' AND gr.id=a.grantee_id
                        WHERE a.folder_id=? ORDER BY a.grantee_type, grantee_name");
    $g->execute([(int)$folder['id']]);
    ok(['folder' => ['id' => (int)$folder['id'], 'name' => $folder['name'], 'visibility' => $folder['visibility']],
        'grants' => $g->fetchAll(),
        'employees' => $db->query("SELECT id, full_name FROM employees WHERE id<>" . (int)$folder['owner_employee_id'] . " ORDER BY full_name")->fetchAll(),
        'groups' => $db->query("SELECT id, name FROM emp_groups WHERE is_active=1 ORDER BY name")->fetchAll()]);
}

if (method() === 'GET' && isset($_GET['download'])) {
    $s = $db->prepare("SELECT * FROM doc_files WHERE id=? AND is_deleted=0");
    $s->execute([(int)$_GET['download']]);
    $file = $s->fetch();
    if (!$file) fail('File not found', 404);
    $folder = doc_fetch_folder($db, (int)$file['folder_id']);
    [$view] = doc_rights($db, $folder, $me, $isAdmin);
    if (!$view) fail('Not authorized for this file', 403);
    $path = doc_root() . '/' . $file['folder_id'] . '/' . $file['stored_name'];
    if (!is_file($path)) fail('File missing on disk', 410);
    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . rawurlencode($file['orig_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* -------------------------------- writes -------------------------------- */

if (method() === 'POST' && isset($_GET['upload'])) {
    $folder = doc_fetch_folder($db, (int)$_GET['upload']);
    [, $edit] = doc_rights($db, $folder, $me, $isAdmin);
    if (!$edit) fail('You have view-only access to this folder', 403);
    if (empty($_FILES['files'])) fail('No files received (field name: files[])');

    $dir = doc_root() . '/' . (int)$folder['id'];
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $names = (array)$_FILES['files']['name'];
    $tmps  = (array)$_FILES['files']['tmp_name'];
    $errs  = (array)$_FILES['files']['error'];
    $sizes = (array)$_FILES['files']['size'];
    $types = (array)$_FILES['files']['type'];
    $saved = []; $rejected = [];
    foreach ($names as $i => $orig) {
        $orig = trim((string)$orig);
        if ($orig === '' || ($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { if ($orig !== '') $rejected[] = "$orig (upload error " . ($errs[$i] ?? '?') . ")"; continue; }
        if ((int)$sizes[$i] > DOC_MAX_BYTES) { $rejected[] = "$orig (over 25 MB)"; continue; }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (in_array($ext, DOC_BLOCKED_EXT, true)) { $rejected[] = "$orig (.$ext not allowed)"; continue; }
        $stored = bin2hex(random_bytes(12)) . ($ext ? '.' . preg_replace('/[^a-z0-9]/', '', $ext) : '');
        if (!move_uploaded_file($tmps[$i], $dir . '/' . $stored)) { $rejected[] = "$orig (disk write failed)"; continue; }
        $db->prepare("INSERT INTO doc_files (folder_id, orig_name, stored_name, mime, size_bytes, uploaded_by)
                      VALUES (?,?,?,?,?,?)")
           ->execute([(int)$folder['id'], mb_substr($orig, 0, 255), $stored,
                      substr((string)($types[$i] ?? ''), 0, 120) ?: null, (int)$sizes[$i], $me]);
        $saved[] = $orig;
    }
    ok(['saved' => $saved, 'rejected' => $rejected]);
}

if (method() === 'POST' && isset($_GET['share'])) {
    $folder = doc_fetch_folder($db, (int)$_GET['share']);
    if (!$isAdmin && (int)$folder['owner_employee_id'] !== $me) fail('Only the owner or admin can manage sharing', 403);
    $b = body();
    if (isset($b['visibility']) && in_array($b['visibility'], ['private', 'all'], true)) {
        $db->prepare("UPDATE doc_folders SET visibility=? WHERE id=?")->execute([$b['visibility'], (int)$folder['id']]);
    }
    foreach ((array)($b['add'] ?? []) as $g) {
        $type = ($g['type'] ?? '') === 'group' ? 'group' : 'employee';
        $gid = (int)($g['id'] ?? 0);
        if (!$gid) continue;
        if ($type === 'employee' && $gid === (int)$folder['owner_employee_id']) continue;
        $db->prepare("INSERT INTO doc_folder_access (folder_id, grantee_type, grantee_id, can_edit, granted_by)
                      VALUES (?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE can_edit=VALUES(can_edit)")
           ->execute([(int)$folder['id'], $type, $gid, (int)!empty($g['can_edit']), $me ?: null]);
        // tell the person (or group members) a folder was shared with them
        try {
            $targets = $type === 'employee' ? [$gid]
                : array_map('intval', $db->query("SELECT employee_id FROM emp_group_members WHERE group_id=" . $gid)->fetchAll(PDO::FETCH_COLUMN));
            $ins = $db->prepare("INSERT INTO notifications (user_id,type,title,message,link) VALUES (?,?,?,?,?)");
            foreach ($targets as $t) {
                if ($t !== $me) $ins->execute([$t, 'doc_shared', 'Folder shared with you: ' . $folder['name'],
                    (!empty($g['can_edit']) ? 'You can view and upload files.' : 'You can view files.'), 'sales-documents.php']);
            }
        } catch (Throwable $e) {}
    }
    foreach (array_map('intval', (array)($b['remove'] ?? [])) as $aid) {
        if ($aid) $db->prepare("DELETE FROM doc_folder_access WHERE id=? AND folder_id=?")->execute([$aid, (int)$folder['id']]);
    }
    ok();
}

if (method() === 'POST') { // create folder
    $b = body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') fail('Folder name required');
    doc_ensure_personal($db, $me);
    $db->prepare("INSERT INTO doc_folders (name, owner_employee_id, visibility, is_personal) VALUES (?,?, 'private', 0)")
       ->execute([mb_substr($name, 0, 150), $me]);
    ok(['id' => (int)$db->lastInsertId()]);
}

if ((method() === 'PATCH' || method() === 'PUT') && isset($_GET['folder'])) {
    $folder = doc_fetch_folder($db, (int)$_GET['folder']);
    if (!$isAdmin && (int)$folder['owner_employee_id'] !== $me) fail('Only the owner or admin can rename', 403);
    $b = body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') fail('Folder name required');
    $db->prepare("UPDATE doc_folders SET name=? WHERE id=?")->execute([mb_substr($name, 0, 150), (int)$folder['id']]);
    ok();
}

if (method() === 'DELETE' && isset($_GET['file'])) {
    $s = $db->prepare("SELECT * FROM doc_files WHERE id=? AND is_deleted=0");
    $s->execute([(int)$_GET['file']]);
    $file = $s->fetch();
    if (!$file) fail('File not found', 404);
    $folder = doc_fetch_folder($db, (int)$file['folder_id']);
    $may = $isAdmin || (int)$folder['owner_employee_id'] === $me || (int)$file['uploaded_by'] === $me;
    if (!$may) fail('Only the uploader, the folder owner or admin can delete this file', 403);
    $db->prepare("UPDATE doc_files SET is_deleted=1 WHERE id=?")->execute([(int)$file['id']]);
    ok();
}

if (method() === 'DELETE' && isset($_GET['folder'])) {
    $folder = doc_fetch_folder($db, (int)$_GET['folder']);
    if (!$isAdmin && (int)$folder['owner_employee_id'] !== $me) fail('Only the owner or admin can delete', 403);
    if ((int)$folder['is_personal'] === 1) fail('Personal folders cannot be deleted');
    $c = $db->prepare("SELECT COUNT(*) FROM doc_files WHERE folder_id=? AND is_deleted=0");
    $c->execute([(int)$folder['id']]);
    if ((int)$c->fetchColumn() > 0) fail('Folder is not empty — delete or move its files first');
    $db->prepare("DELETE FROM doc_folder_access WHERE folder_id=?")->execute([(int)$folder['id']]);
    $db->prepare("DELETE FROM doc_folders WHERE id=?")->execute([(int)$folder['id']]);
    ok();
}

fail('Unsupported request', 405);
