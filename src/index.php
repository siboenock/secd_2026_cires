<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

try {
    $db = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $error) {
    http_response_code(503);
    exit('Database unavailable. Import database/fyp_workspace.sql and check config.php against config.example.php.');
}

$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(32));
$account = isset($_SESSION['account']) ? query('SELECT id, display_name, role FROM accounts WHERE id=?', [$_SESSION['account']])->fetch() : false;
$student = $account && $account['role'] === 'student';
$roleColumn = $student ? 'student_id' : 'supervisor_id';
$projects = $account ? query("SELECT * FROM projects WHERE $roleColumn=? ORDER BY id", [$account['id']])->fetchAll() : [];
$projectId = (int)($_GET['project'] ?? ($projects[0]['id'] ?? 0));
$project = $account ? query("SELECT * FROM projects WHERE id=? AND $roleColumn=?", [$projectId, $account['id']])->fetch() : false;
$chapters = $project ? query('SELECT * FROM chapters WHERE project_id=? ORDER BY chapter_no', [$projectId])->fetchAll() : [];
$chapterId = (int)($_GET['chapter'] ?? ($chapters[0]['id'] ?? 0));
$chapter = $project ? query('SELECT * FROM chapters WHERE id=? AND project_id=?', [$chapterId, $projectId])->fetch() : false;
$here = '?project=' . $projectId . '&chapter=' . $chapterId;

if (isset($_GET['download'])) {
    $file = $account ? query("SELECT v.file_key,v.filename FROM chapter_versions v
        JOIN chapters c ON c.id=v.chapter_id JOIN projects p ON p.id=c.project_id
        WHERE v.id=? AND p.$roleColumn=?", [(int)$_GET['download'], $account['id']])->fetch() : false;
    if (!$file) { http_response_code(404); exit('File unavailable.'); }
    $path = privateStorage() . DIRECTORY_SEPARATOR . $file['file_key'];
    $stream = is_file($path) ? fopen($path, 'rb') : false;
    if (!$stream) { http_response_code(404); exit('File unavailable.'); }
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($file['filename']));
    fpassthru($stream);
    fclose($stream);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $writtenFile = null;
    try {
        if (!hash_equals($_SESSION['csrf'], field('csrf'))) {
            throw new DomainException('This form expired. Reload the page and try again.');
        }
        $action = field('action');
        if ($action === 'login' && !$account) {
            $record = query('SELECT id,password_hash FROM accounts WHERE email=?', [field('email')])->fetch();
            if (!$record || !password_verify(field('password'), $record['password_hash'])) {
                throw new DomainException('The email or password is incorrect.');
            }
            session_regenerate_id(true);
            $_SESSION = ['account' => $record['id']];
            go();
        }
        if ($action === 'logout' && $account) {
            $_SESSION = [];
            session_regenerate_id(true);
            go();
        }
        if (!$project || !$account) throw new DomainException('This project is unavailable.');
        if ($action === 'progress' && $student) {
            $url = field('github_url');
            $summary = field('summary');
            if (strlen($url) > 500 || !preg_match('~\Ahttps://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/(?:pull/[1-9][0-9]*|commit/[a-fA-F0-9]{7,40})/?\z~', $url) || !$summary || strlen($summary) > 500) {
                throw new DomainException('Enter a GitHub commit or pull request URL and a short summary.');
            }
            query('INSERT INTO progress_updates (project_id,github_url,summary) VALUES (?,?,?)', [$projectId,$url,$summary]);
            $_SESSION['notice'] = 'Progress link shared.';
            go($here . '#progress');
        }
        if (!$chapter || !in_array($action, ['submit','review'], true) || ($action === 'submit') !== (bool)$student) {
            throw new DomainException('This action is unavailable.');
        }

        // Lock the chapter before checking its latest version to serialize submissions and decisions.
        $db->beginTransaction();
        query('SELECT id FROM chapters WHERE id=? FOR UPDATE', [$chapterId])->fetch();
        $latest = versionList($chapterId)[0] ?? null;
        if ((int)field('expected_version') !== (int)($latest['id'] ?? 0)) {
            throw new DomainException('The chapter changed. Review the latest version and try again.');
        }
        if ($action === 'submit') {
            if ($latest && $latest['decision'] !== 'changes') throw new DomainException('Wait for a supervisor to request a revision.');
            [$temporary, $name] = validatedUpload();
            $key = bin2hex(random_bytes(20));
            $path = privateStorage() . DIRECTORY_SEPARATOR . $key;
            if (!move_uploaded_file($temporary, $path)) throw new RuntimeException('Upload could not be stored.');
            $writtenFile = $path;
            query('INSERT INTO chapter_versions (chapter_id,version_no,file_key,filename) VALUES (?,?,?,?)',
                [$chapterId, ($latest['version_no'] ?? 0) + 1, $key, $name]);
            $notice = 'Chapter submitted for review.';
        } else {
            $decision = field('decision');
            $feedback = field('feedback');
            if (!$latest || $latest['decision'] !== null) throw new DomainException('This version is no longer awaiting review.');
            if (!in_array($decision, ['changes','reviewed'], true) || !$feedback || strlen($feedback) > 4000) {
                throw new DomainException('Enter feedback and choose a review decision.');
            }
            query('INSERT INTO version_reviews (version_id,decision,feedback) VALUES (?,?,?)', [$latest['id'],$decision,$feedback]);
            $notice = 'Review saved. The student can now see the decision.';
        }
        $db->commit();
        $_SESSION['notice'] = $notice;
        go($here);
    } catch (Throwable $failure) {
        if ($db->inTransaction()) $db->rollBack();
        if ($writtenFile !== null && is_file($writtenFile)) unlink($writtenFile);
        if ($failure instanceof DomainException) {
            $error = $failure->getMessage();
        } else {
            error_log((string)$failure);
            $error = 'The request could not be saved. Check the server configuration and try again.';
        }
    }
}

$versions = $chapter ? versionList($chapterId) : [];
$latest = $versions[0] ?? null;
$updates = $project ? query('SELECT * FROM progress_updates WHERE project_id=? ORDER BY id DESC', [$projectId])->fetchAll() : [];
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);
$csrf = e($_SESSION['csrf']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Chapter Desk | Thesis review</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="top">
    <a class="brand" href="./"><span class="mark" aria-hidden="true">▤</span> Chapter Desk</a>
    <?php if ($account): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="logout"><button class="quiet">Sign out</button></form>
    <?php endif ?>
</header>
<?php if (!$account): ?>
<main class="welcome">
    <section class="intro"><h1>Thesis review workspace</h1><p>Submit chapters, review drafts and keep feedback attached to the right version.</p></section>
    <section class="card sign"><h2>Sign in</h2>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif ?>
    <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="login">
        <label>Email<input name="email" type="email" autocomplete="username" value="<?= e(field('email')) ?>" required></label>
        <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
        <button>Sign in</button>
    </form></section>
</main>
<?php else: ?>
<div class="desk">
<aside>
    <p class="role"><?= $student ? 'Student' : 'Supervisor' ?></p>
    <h2><?= e($account['display_name']) ?></h2>
    <nav aria-label="Projects">
    <?php foreach ($projects as $item): ?>
        <a href="?project=<?= (int)$item['id'] ?>" <?= (int)$item['id'] === $projectId ? 'aria-current="page"' : '' ?>><?= e($item['title']) ?></a>
    <?php endforeach ?>
    </nav>
    <?php if ($chapters): ?><nav aria-label="Chapters"><h3>Chapters</h3>
    <?php foreach ($chapters as $item): ?>
        <a href="?project=<?= $projectId ?>&amp;chapter=<?= (int)$item['id'] ?>" <?= (int)$item['id'] === $chapterId ? 'aria-current="page"' : '' ?>>
        <span><?= str_pad((string)$item['chapter_no'], 2, '0', STR_PAD_LEFT) ?></span><?= e($item['title']) ?></a>
    <?php endforeach ?></nav><?php endif ?>
</aside>
<main class="content">
    <?php if ($notice): ?><p class="notice" role="status"><?= e($notice) ?></p><?php endif ?>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif ?>
    <?php if ($chapter): ?>
        <div class="chapter-head"><div><p class="role">Chapter <?= (int)$chapter['chapter_no'] ?></p><h1><?= e($chapter['title']) ?></h1></div><span class="status"><?= e(statusLabel($latest)) ?></span></div>
        <div class="columns">
        <section class="card">
            <h2><?= $student ? 'Chapter submission' : 'Review chapter' ?></h2>
            <?php if ($latest): ?><p class="file"><a href="?download=<?= (int)$latest['id'] ?>"><?= e($latest['filename']) ?></a><small>Version <?= (int)$latest['version_no'] ?></small></p><?php endif ?>
            <?php if ($student && (!$latest || $latest['decision'] === 'changes')): ?>
            <form method="post" enctype="multipart/form-data" class="stack">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="submit">
                <input type="hidden" name="expected_version" value="<?= (int)($latest['id'] ?? 0) ?>">
                <label>Chapter file<input type="file" name="chapter_file" accept=".pdf,.docx" aria-describedby="file-hint" required></label>
                <small id="file-hint">PDF or DOCX, up to 10 MB.</small>
                <button><?= $latest ? 'Submit revision' : 'Submit chapter' ?></button>
            </form>
            <?php elseif (!$student && $latest && $latest['decision'] === null): ?>
            <form method="post" class="stack">
                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="review">
                <input type="hidden" name="expected_version" value="<?= (int)$latest['id'] ?>">
                <label>Feedback<textarea name="feedback" rows="4" maxlength="1000" required><?= e(field('feedback')) ?></textarea></label>
                <label>Decision<select name="decision" required><option value="">Choose a decision</option><option value="changes">Request changes</option><option value="reviewed">Mark reviewed · Allow student to continue</option></select></label>
                <button>Save review</button>
            </form>
            <?php else: ?>
            <p><?= !$latest ? 'No file has been submitted.' : ($latest['decision'] === 'reviewed' ? 'This chapter is cleared to continue.' : ($student ? 'Your supervisor can now review this version.' : 'Waiting for the student’s revision.')) ?></p>
            <?php endif ?>
        </section>
        <section class="card history">
            <h2>Version history</h2>
            <?php if (!$versions): ?><p>Submitted files and supervisor feedback will appear here.</p><?php endif ?>
            <?php foreach ($versions as $version): ?>
            <article>
                <div class="version"><strong>Version <?= (int)$version['version_no'] ?></strong><span><?= e(statusLabel($version)) ?></span></div>
                <small>Submitted <?= e($version['submitted_at']) ?></small>
                <a class="download" href="?download=<?= (int)$version['id'] ?>"><?= e($version['filename']) ?></a>
                <?php if ($version['feedback'] !== null): ?>
                <small>Supervisor feedback · <?= e($version['reviewed_at']) ?></small><p class="feedback"><?= e($version['feedback']) ?></p>
                <?php endif ?>
            </article>
            <?php endforeach ?>
        </section>
        </div>
    <?php else: ?>
        <h1><?= $project ? 'Choose a chapter' : 'No project available' ?></h1>
    <?php endif ?>
    <?php if ($project): ?>
    <section class="card progress" id="progress">
        <h2>Software progress</h2>
        <?php if ($student): ?>
        <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="progress">
            <label>GitHub commit or pull request URL<input type="url" name="github_url" maxlength="500" required></label>
            <label>Summary<input name="summary" maxlength="125" required></label>
            <button>Share update</button>
        </form>
        <?php endif ?>
        <?php if (!$updates): ?><p>No updates shared yet.</p><?php endif ?>
        <?php foreach ($updates as $update): ?>
        <article><strong><?= e($update['summary']) ?></strong><br><small><?= e($update['shared_at']) ?></small><br><a href="<?= e($update['github_url']) ?>">Open on GitHub</a></article>
        <?php endforeach ?>
    </section>
    <?php endif ?>
</main>
</div>
<?php endif ?>
<footer>Group 3 · SIBOMANA ENOCK and Jackson D. SAM · UNILAK Rwamagana</footer>
</body>
</html>
