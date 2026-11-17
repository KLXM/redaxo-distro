<?php

/**
 * Installer der Distro – hilft bei der Konfiguration, ohne Shell und ohne Composer.
 *
 * 1. prüft das System, 2. fragt Datenbank und Website ab, 3. schreibt die .env.local und leitet zur Migration des
 * Cores weiter (dort wird der erste Admin angelegt), 4. räumt danach auf: Migrations-Token entfernen, sich selbst
 * löschen. Läuft ohne REDAXO, damit er auch dann funktioniert, wenn noch nichts konfiguriert ist.
 */

declare(strict_types=1);

if ('' === (string) ini_get('date.timezone')) {
    date_default_timezone_set('Europe/Berlin');
}

const MIN_PHP = '8.5.0';
const EXTENSIONS = ['ctype', 'fileinfo', 'iconv', 'intl', 'mbstring', 'pdo_mysql', 'session', 'tokenizer', 'zip'];

$root = dirname(__DIR__);
$envLocal = $root . '/.env.local';

session_name('redaxo_installer');
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$env = readEnv($envLocal);
$configured = '' !== ($env['DATABASE_URL'] ?? '');
$step = $configured ? 'finish' : ($_POST['step'] ?? 'check');
$errors = [];
$values = [
    'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_password' => '',
    'db_create' => '1', 'name' => 'REDAXO', 'base_url' => guessBaseUrl(), 'mode' => 'live',
];

if ('POST' === $_SERVER['REQUEST_METHOD'] && !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    $errors[] = 'Die Sitzung ist abgelaufen. Bitte noch einmal absenden.';
    $step = $configured ? 'finish' : 'form';
}

// ---------- Konfiguration schreiben ----------
if (!$errors && 'save' === $step && !$configured) {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $values['db_create'] = isset($_POST['db_create']) ? '1' : '';
    if ('' === $values['db_name'] || !preg_match('/^[A-Za-z0-9_\-$]{1,64}$/', $values['db_name'])) {
        $errors[] = 'Bitte einen gültigen Datenbanknamen angeben (Buchstaben, Ziffern, _ und -).';
    }
    if ('' === $values['db_user']) {
        $errors[] = 'Bitte den Datenbank-Benutzer angeben.';
    }
    if (!in_array($values['mode'], ['live', 'dev'], true)) {
        $values['mode'] = 'live';
    }
    if ('' !== $values['base_url'] && !preg_match('~^https?://~', $values['base_url'])) {
        $errors[] = 'Die Adresse der Website muss mit http:// oder https:// beginnen.';
    }

    if (!$errors) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $values['db_host'], (int) $values['db_port']);
            $pdo = new PDO($dsn, $values['db_user'], $values['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            if ($values['db_create']) {
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $values['db_name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            }
            $pdo->exec('USE `' . str_replace('`', '', $values['db_name']) . '`');
            $tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'rex\\_%'")->fetchColumn();
        } catch (PDOException $e) {
            $errors[] = 'Keine Verbindung zur Datenbank: ' . $e->getMessage();
        }
    }

    if (!$errors) {
        $token = bin2hex(random_bytes(24));
        $url = sprintf(
            'mysql://%s:%s@%s:%d/%s',
            rawurlencode($values['db_user']),
            rawurlencode($values['db_password']),
            $values['db_host'],
            (int) $values['db_port'],
            rawurlencode($values['db_name']),
        );
        $lines = [
            '# Geschrieben vom Installer der Distro am ' . date('Y-m-d H:i') . '. Geheim halten – nicht ins Repository!',
            'DATABASE_URL=' . envQuote($url),
            'REX_INSTANCE_ID=' . bin2hex(random_bytes(6)),
            'REX_INSTANCE_NAME=' . envQuote($values['name'] ?: 'REDAXO'),
            'REX_MODE=' . $values['mode'],
            'REX_BASE_URL=' . envQuote($values['base_url']),
            '# Nur für die Installation – der Installer entfernt den Token beim Abschließen.',
            'REX_MIGRATE_TOKEN=' . $token,
        ];
        if (false === @file_put_contents($envLocal, implode("\n", $lines) . "\n", LOCK_EX)) {
            $errors[] = 'Die Datei .env.local konnte nicht geschrieben werden. Bitte Schreibrechte im Hauptordner prüfen.';
        } else {
            @chmod($envLocal, 0o640);
            $_SESSION['token'] = $token;
            $_SESSION['existing'] = ($tables ?? 0) > 0;
            header('Location: ' . backendUrl() . '?rex_migrate=' . $token);
            exit;
        }
    }
    $step = 'form';
}

// ---------- Abschließen ----------
$finished = false;
if ($configured && !$errors && 'cleanup' === ($_POST['step'] ?? '')) {
    if (!hasUser($env['DATABASE_URL'])) {
        $errors[] = 'Es gibt noch keinen Benutzer. Bitte zuerst die Installation abschließen.';
    } else {
        $content = (string) file_get_contents($envLocal);
        $content = (string) preg_replace('/^(# Nur für die Installation.*\n)?REX_MIGRATE_TOKEN=.*\n?/m', '', $content);
        file_put_contents($envLocal, $content, LOCK_EX);
        $finished = true;
        session_destroy();
        // sich selbst entfernen – gelingt das nicht (Rechte), sagt die Seite es
        $selfDeleted = @unlink(__FILE__);
    }
}

$checks = systemChecks($root);
$checksOk = !in_array(false, array_column($checks, 'ok'), true);
if ('form' === ($_POST['step'] ?? '') && $checksOk) {
    $step = 'form';
}
if (!$checksOk && !$configured) {
    $step = 'check';
}

// ---------- Ausgabe ----------
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>REDAXO installieren</title>
<style>
:root { --bg: #f3f6fb; --panel: #fff; --border: #dfe3e9; --text: #324050; --muted: #6b7785; --accent: #1f6fb2; --ok: #2e7d32; --fail: #c9302c; }
@media (prefers-color-scheme: dark) { :root { --bg: #151c22; --panel: #202b35; --border: #2e3b4a; --text: #e6e9ef; --muted: #9aa5b1; --accent: #4b9ad9; --ok: #6cc070; --fail: #e77; } }
* { box-sizing: border-box; }
body { margin: 0; padding: 2rem 1rem; background: var(--bg); color: var(--text); font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
main { max-width: 44rem; margin: 0 auto; }
h1 { font-size: 1.6rem; font-weight: 600; margin: 0 0 .25rem; }
.lead { color: var(--muted); margin: 0 0 1.5rem; }
.panel { background: var(--panel); border: 1px solid var(--border); border-radius: 8px; padding: 1.5rem; margin-bottom: 1rem; }
.steps { display: flex; gap: .5rem; margin: 0 0 1.25rem; padding: 0; list-style: none; flex-wrap: wrap; }
.steps li { padding: .2rem .7rem; border-radius: 999px; border: 1px solid var(--border); color: var(--muted); font-size: .85rem; }
.steps li[aria-current] { border-color: var(--accent); color: var(--accent); font-weight: 600; }
table { width: 100%; border-collapse: collapse; }
td { padding: .35rem 0; border-bottom: 1px solid var(--border); }
td:last-child { text-align: right; font-weight: 600; }
.ok { color: var(--ok); } .fail { color: var(--fail); }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 0 1rem; }
label { display: block; font-weight: 600; margin: .8rem 0 .25rem; }
input, select { width: 100%; padding: .55rem .65rem; border: 1px solid var(--border); border-radius: 6px; background: var(--bg); color: inherit; font: inherit; }
small { color: var(--muted); display: block; margin-top: .25rem; }
.check { display: flex; gap: .5rem; align-items: center; font-weight: normal; }
.check input { width: auto; }
button, .button { display: inline-block; margin-top: 1.25rem; padding: .65rem 1.4rem; border: 0; border-radius: 6px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
.errors { border-color: var(--fail); color: var(--fail); }
.errors ul { margin: 0; padding-left: 1.2rem; }
code { background: var(--bg); padding: .1em .35em; border-radius: 4px; }
fieldset { border: 0; padding: 0; margin: 0 0 1rem; }
legend { font-size: 1.05rem; font-weight: 600; padding: 0; margin-bottom: .25rem; }
</style>
</head>
<body>
<main>
    <h1>REDAXO installieren</h1>
    <p class="lead"><?= e(distroTitle($root)) ?></p>
    <ol class="steps" aria-label="Schritte">
        <li <?= 'check' === $step ? 'aria-current="step"' : '' ?>>1 · System</li>
        <li <?= 'form' === $step ? 'aria-current="step"' : '' ?>>2 · Konfiguration</li>
        <li>3 · Datenbank &amp; Admin</li>
        <li <?= 'finish' === $step ? 'aria-current="step"' : '' ?>>4 · Abschließen</li>
    </ol>

    <?php if ($errors): ?>
        <div class="panel errors" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>

    <?php if ($finished): ?>
        <div class="panel">
            <h2>Fertig</h2>
            <p class="ok">Der Migrations-Token ist entfernt<?= !empty($selfDeleted) ? ' und der Installer hat sich gelöscht' : '' ?>.</p>
            <?php if (empty($selfDeleted)): ?><p class="fail">Bitte <code>public/install.php</code> von Hand löschen.</p><?php endif ?>
            <a class="button" href="<?= e(backendUrl()) ?>">Zum Backend</a>
        </div>
    <?php elseif ('finish' === $step): ?>
        <div class="panel">
            <h2>Konfiguration vorhanden</h2>
            <?php if (!empty($env['REX_MIGRATE_TOKEN'])): ?>
                <p>Wurde der erste Admin schon angelegt? Dann hier die Installation abschließen: Der Migrations-Token wird aus der <code>.env.local</code> entfernt und der Installer gelöscht.</p>
                <p><a href="<?= e(backendUrl() . '?rex_migrate=' . $env['REX_MIGRATE_TOKEN']) ?>">Noch nicht? Zur Installation (Datenbank &amp; Admin)</a></p>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="step" value="cleanup">
                    <button type="submit">Installation abschließen</button>
                </form>
            <?php else: ?>
                <p>REDAXO ist eingerichtet. Diese Datei wird nicht mehr gebraucht – bitte <code>public/install.php</code> löschen.</p>
                <a class="button" href="<?= e(backendUrl()) ?>">Zum Backend</a>
            <?php endif ?>
        </div>
    <?php elseif ('check' === $step): ?>
        <div class="panel">
            <h2>Systemprüfung</h2>
            <table>
                <?php foreach ($checks as $check): ?>
                    <tr><td><?= e($check['label']) ?></td><td class="<?= $check['ok'] ? 'ok' : 'fail' ?>"><?= e($check['value']) ?></td></tr>
                <?php endforeach ?>
            </table>
            <?php if ($checksOk): ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="step" value="form">
                    <button type="submit">Weiter</button>
                </form>
            <?php else: ?>
                <p class="fail">Bitte die rot markierten Punkte beim Hoster beheben (PHP-Version, Erweiterungen, Schreibrechte) und die Seite neu laden.</p>
            <?php endif ?>
        </div>
    <?php else: ?>
        <form class="panel" method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="step" value="save">
            <fieldset>
                <legend>Datenbank</legend>
                <small>Die Zugangsdaten stehen meist in der Verwaltung deines Hosters.</small>
                <div class="grid">
                    <div><label for="db_host">Server</label><input id="db_host" name="db_host" value="<?= e($values['db_host']) ?>" required></div>
                    <div><label for="db_port">Port</label><input id="db_port" name="db_port" value="<?= e($values['db_port']) ?>" inputmode="numeric" required></div>
                    <div><label for="db_name">Name der Datenbank</label><input id="db_name" name="db_name" value="<?= e($values['db_name']) ?>" required></div>
                    <div><label for="db_user">Benutzer</label><input id="db_user" name="db_user" value="<?= e($values['db_user']) ?>" required></div>
                    <div><label for="db_password">Passwort</label><input id="db_password" name="db_password" type="password" autocomplete="new-password"></div>
                </div>
                <label class="check"><input type="checkbox" name="db_create" value="1" <?= $values['db_create'] ? 'checked' : '' ?>> Datenbank anlegen, falls sie fehlt</label>
            </fieldset>
            <fieldset>
                <legend>Website</legend>
                <div class="grid">
                    <div><label for="name">Name</label><input id="name" name="name" value="<?= e($values['name']) ?>"><small>Erscheint im Backend und in E-Mails.</small></div>
                    <div><label for="base_url">Adresse</label><input id="base_url" name="base_url" value="<?= e($values['base_url']) ?>" placeholder="https://example.org/"><small>Für Links in E-Mails und Konsole.</small></div>
                </div>
                <label for="mode">Modus</label>
                <select id="mode" name="mode">
                    <option value="live" <?= 'live' === $values['mode'] ? 'selected' : '' ?>>live – öffentliche Website (empfohlen)</option>
                    <option value="dev" <?= 'dev' === $values['mode'] ? 'selected' : '' ?>>dev – Entwicklung, ausführliche Fehler</option>
                </select>
            </fieldset>
            <button type="submit">Konfiguration speichern und installieren</button>
            <small>Danach legt REDAXO die Tabellen an und fragt nach dem ersten Admin.</small>
        </form>
    <?php endif ?>
</main>
</body>
</html>
<?php

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array<string, string> */
function readEnv(string $file): array
{
    $values = [];
    if (!is_file($file)) {
        return $values;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
            $values[$m[1]] = trim($m[2], " \t'\"");
        }
    }

    return $values;
}

function envQuote(string $value): string
{
    return "'" . str_replace("'", "'\\''", $value) . "'";
}

function guessBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS']) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');

    return $scheme . '://' . $host . $path . '/';
}

function backendUrl(): string
{
    return rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/redaxo/';
}

function distroTitle(string $root): string
{
    $file = $root . '/distro-manifest.json';
    $manifest = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    return is_array($manifest) ? ($manifest['title'] ?? 'REDAXO 6') . ' · ' . ($manifest['version'] ?? '') : 'REDAXO 6';
}

/** @return list<array{label: string, value: string, ok: bool}> */
function systemChecks(string $root): array
{
    $checks = [['label' => 'PHP ' . MIN_PHP . ' oder neuer', 'value' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, MIN_PHP, '>=')]];
    foreach (EXTENSIONS as $extension) {
        $loaded = extension_loaded($extension);
        $checks[] = ['label' => 'Erweiterung ' . $extension, 'value' => $loaded ? 'vorhanden' : 'fehlt', 'ok' => $loaded];
    }
    foreach (['' => 'Hauptordner (für .env.local)', 'var' => 'var/', 'public/assets' => 'public/assets/', 'public/media' => 'public/media/'] as $dir => $label) {
        $path = rtrim($root . '/' . $dir, '/');
        if (!is_dir($path)) {
            @mkdir($path, 0o775, true);
        }
        $writable = is_dir($path) && is_writable($path);
        $checks[] = ['label' => 'Schreibrechte ' . $label, 'value' => $writable ? 'ok' : 'fehlen', 'ok' => $writable];
    }

    return $checks;
}

function hasUser(string $databaseUrl): bool
{
    $parts = parse_url($databaseUrl);
    if (!$parts) {
        return false;
    }
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $parts['host'] ?? '127.0.0.1', $parts['port'] ?? 3306, rawurldecode(ltrim($parts['path'] ?? '', '/'))),
            rawurldecode($parts['user'] ?? ''),
            rawurldecode($parts['pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return (int) $pdo->query('SELECT COUNT(*) FROM rex_user')->fetchColumn() > 0;
    } catch (PDOException) {
        return false;
    }
}
