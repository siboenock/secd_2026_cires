<?php
declare(strict_types=1);

$config = require is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/config.example.php';
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
$storage = $config['storage'] ?? dirname($documentRoot ?: __DIR__) . '/fyp-private-' . substr(hash('sha256', __DIR__), 0, 12);

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function field(string $name): string {
    return is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
}

function go(string $location = './'): void {
    header('Location: ' . $location, true, 303);
    exit;
}

function query(string $sql, array $params = []): PDOStatement {
    global $db;
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function versionList(int $chapter): array {
    return query('SELECT v.*, r.decision, r.feedback, r.reviewed_at
        FROM chapter_versions v LEFT JOIN version_reviews r ON r.version_id=v.id
        WHERE v.chapter_id=? ORDER BY v.version_no DESC', [$chapter])->fetchAll();
}

function statusLabel(?array $version): string {
    if (!$version) return 'Not submitted';
    return ['changes' => 'Changes requested', 'reviewed' => 'Reviewed · Continue'][$version['decision'] ?? ''] ?? 'Submitted';
}

function privateStorage(): string {
    global $storage, $documentRoot;
    if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
        throw new RuntimeException('Private storage could not be created.');
    }
    $resolved = realpath($storage);
    $root = rtrim(str_replace('\\', '/', (string)$documentRoot), '/');
    $directory = rtrim(str_replace('\\', '/', (string)$resolved), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $root = strtolower($root);
        $directory = strtolower($directory);
    }
    if (!$resolved || !$documentRoot || $directory === $root || strpos($directory, $root . '/') === 0) {
        throw new RuntimeException('Set private storage to a folder outside the web root.');
    }
    return $resolved;
}

function validatedUpload(): array {
    $file = $_FILES['chapter_file'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new DomainException('Choose a PDF or DOCX file and check the server upload limit.');
    }
    if ($file['size'] < 1 || $file['size'] > 10 * 1024 * 1024) {
        throw new DomainException('The file must be between 1 byte and 10 MB.');
    }
    $name = basename(str_replace('\\', '/', $file['name']));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $valid = false;
    if ($extension === 'pdf') {
        $valid = file_get_contents($file['tmp_name'], false, null, 0, 5) === '%PDF-';
    }
    if ($extension === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) === true) {
            $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
            $zip->close();
        }
    }
    if (!$valid || strlen($name) > 175 || preg_match('/[[:cntrl:]]/', $name) || !preg_match('//u', $name)) {
        throw new DomainException('Select a valid PDF or DOCX with a shorter file name.');
    }
    return [$file['tmp_name'], $name];
}
