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

// Sprache: ?lang=…, sonst gemerkt, sonst Browser
if (isset($_GET['lang']) && in_array($_GET['lang'], ['de', 'en'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}
$_SESSION['lang'] ??= str_starts_with(strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'de')), 'de') ? 'de' : 'en';
$lang = $_SESSION['lang'];

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
    $errors[] = t('err_session');
    $step = $configured ? 'finish' : 'form';
}

// ---------- Konfiguration schreiben ----------
if (!$errors && 'save' === $step && !$configured) {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $values['db_create'] = isset($_POST['db_create']) ? '1' : '';
    if ('' === $values['db_name'] || !preg_match('/^[A-Za-z0-9_\-$]{1,64}$/', $values['db_name'])) {
        $errors[] = t('err_db_name');
    }
    if ('' === $values['db_user']) {
        $errors[] = t('err_db_user');
    }
    if (!in_array($values['mode'], ['live', 'dev'], true)) {
        $values['mode'] = 'live';
    }
    if ('' !== $values['base_url'] && !preg_match('~^https?://~', $values['base_url'])) {
        $errors[] = t('err_base_url');
    }

    if (!$errors) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $values['db_host'], (int) $values['db_port']);
            $pdo = new PDO($dsn, $values['db_user'], $values['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            if ($values['db_create']) {
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $values['db_name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            }
            $pdo->exec('USE `' . str_replace('`', '', $values['db_name']) . '`');
        } catch (PDOException $e) {
            $errors[] = t('err_db_connect') . ' ' . $e->getMessage();
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
            $errors[] = t('err_write_env');
        } else {
            @chmod($envLocal, 0o640);
            header('Location: ' . backendUrl() . '?rex_migrate=' . $token);
            exit;
        }
    }
    $step = 'form';
}

// ---------- Abschließen ----------
$finished = false;
$selfDeleted = false;
if ($configured && !$errors && 'cleanup' === ($_POST['step'] ?? '')) {
    if (!hasUser($env['DATABASE_URL'])) {
        $errors[] = t('err_no_user');
    } else {
        $content = (string) file_get_contents($envLocal);
        $content = (string) preg_replace('/^(# Nur für die Installation.*\n)?REX_MIGRATE_TOKEN=.*\n?/m', '', $content);
        file_put_contents($envLocal, $content, LOCK_EX);
        $finished = true;
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
$stepIndex = $finished ? 5 : ['check' => 1, 'form' => 2, 'save' => 2, 'finish' => 4][$step] ?? 1;

// ---------- Ausgabe ----------
?><!doctype html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<link rel="icon" href="data:,">
<title><?= e(t('title')) ?></title>
<script>
// Theme vor dem ersten Zeichnen setzen (kein Aufblitzen)
try { const t = localStorage.getItem('redaxo-installer-theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
</script>
<style>
/* Tokens des REDAXO-6-Backends (assets/css/src/tokens.css) */
:root {
    color-scheme: light dark;
    --rex-color-bg: light-dark(#f3f6fb, #1b232c);
    --rex-color-surface: light-dark(#fff, #202b35);
    --rex-color-surface-subtle: light-dark(#f3f6fb, #1b232c);
    --rex-color-surface-strong: light-dark(#d0d6df, #2e3b4a);
    --rex-color-control: light-dark(#fff, #2e3b4a);
    --rex-color-border: light-dark(#dfe3e9, #151c22);
    --rex-color-border-strong: light-dark(#d0d6df, #2e3b4a);
    --rex-color-border-input: light-dark(#7a8797, #66778a);
    --rex-color-text: light-dark(#324050, #bfc5cc);
    --rex-color-text-muted: light-dark(#566270, #a4acb5);
    --rex-color-link: light-dark(#1f6fb2, #7abaec);
    --rex-color-accent: light-dark(#1f6fb2, #1867a6);
    --rex-color-accent-hover: light-dark(#185a91, #1a5d94);
    --rex-color-accent-subtle: light-dark(#dbedf7, #213c51);
    --rex-color-success-text: light-dark(#1e7a4a, #4cc787);
    --rex-color-success-subtle: light-dark(#e9f5ef, #1f3d3c);
    --rex-color-danger-text: light-dark(#b52b27, #f08a87);
    --rex-color-danger-subtle: light-dark(#fbeaea, #43292d);
    --rex-color-focus: light-dark(#1f6fb2, #7abaec);
    --rex-color-brand: #4b9ad9;
    --rex-logo-dark: light-dark(#324050, rgba(255, 255, 255, .85));
    --rex-radius: 4px;
    --rex-shadow: light-dark(0 1px 2px rgb(0 0 0 / .06), 0 1px 2px rgb(0 0 0 / .4));
    --rex-font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    --rex-font-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
:root[data-theme="light"] { color-scheme: light; }
:root[data-theme="dark"] { color-scheme: dark; }

* { box-sizing: border-box; }
body {
    margin: 0; min-height: 100vh; background: var(--rex-color-bg); color: var(--rex-color-text);
    font: 15px/1.6 var(--rex-font-sans); transition: background .3s, color .3s;
}

/* Kopfleiste wie im Backend */
.bar {
    position: sticky; top: 0; z-index: 2; display: flex; align-items: center; gap: 1rem;
    padding: .75rem 1.25rem; background: var(--rex-color-surface); border-bottom: 1px solid var(--rex-color-border);
}
.bar svg { height: 22px; width: auto; display: block; }
.rex-redaxo-logo-r, .rex-redaxo-logo-e, .rex-redaxo-logo-d, .rex-redaxo-logo-cms { fill: var(--rex-color-brand); }
.rex-redaxo-logo-a, .rex-redaxo-logo-x, .rex-redaxo-logo-o, .rex-redaxo-logo-reg { fill: var(--rex-logo-dark); }
.bar .spacer { flex: 1; }
.switch { display: inline-flex; padding: 2px; border: 1px solid var(--rex-color-border-strong); border-radius: 999px; }
.switch a, .switch button {
    padding: .2rem .65rem; border: 0; border-radius: 999px; background: none; color: var(--rex-color-text-muted);
    font: 600 12px/1.6 var(--rex-font-sans); text-decoration: none; cursor: pointer;
}
.switch [aria-current="true"], .switch [aria-pressed="true"] { background: var(--rex-color-accent); color: #fff; }

main { max-width: 46rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
h1 { margin: 0; font-size: 1.75rem; font-weight: 500; }
.lead { margin: .25rem 0 1.5rem; color: var(--rex-color-text-muted); }

/* Fortschritt */
.progress { position: relative; display: grid; grid-template-columns: repeat(4, 1fr); gap: .5rem; margin: 0 0 1.5rem; padding: 0; list-style: none; counter-reset: step; }
.progress::before {
    content: ""; position: absolute; left: 1rem; right: 1rem; top: 15px; height: 2px; background: var(--rex-color-border-strong);
}
.progress::after {
    content: ""; position: absolute; left: 1rem; top: 15px; height: 2px; background: var(--rex-color-accent);
    width: calc((100% - 2rem) * var(--done, 0)); transition: width .8s cubic-bezier(.2, .8, .2, 1);
}
.progress li { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: center; gap: .35rem; color: var(--rex-color-text-muted); font-size: .8rem; text-align: center; counter-increment: step; }
.progress li::before {
    content: counter(step); display: grid; place-items: center; width: 32px; height: 32px; border-radius: 50%;
    background: var(--rex-color-surface); border: 2px solid var(--rex-color-border-strong); font-weight: 700; transition: all .4s;
}
.progress li.done::before { content: "✓"; background: var(--rex-color-accent); border-color: var(--rex-color-accent); color: #fff; }
.progress li.current { color: var(--rex-color-text); font-weight: 600; }
.progress li.current::before { border-color: var(--rex-color-accent); color: var(--rex-color-accent); box-shadow: 0 0 0 4px var(--rex-color-accent-subtle); }

/* Panels wie .rex-section */
.panel {
    margin-bottom: 1rem; background: var(--rex-color-surface); border: 1px solid var(--rex-color-border);
    border-radius: var(--rex-radius); box-shadow: var(--rex-shadow); animation: rise .5s cubic-bezier(.2, .8, .2, 1) both;
}
.panel > header { padding: .75rem 1.25rem; border-bottom: 1px solid var(--rex-color-border); background: var(--rex-color-surface-subtle); font-weight: 500; font-size: 1.05rem; }
.panel > .body { padding: 1.25rem; }
@keyframes rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }

/* Prüfliste */
.checks { margin: 0; padding: 0; list-style: none; }
.checks li {
    display: flex; justify-content: space-between; gap: 1rem; padding: .45rem 0; border-bottom: 1px solid var(--rex-color-border);
    animation: rise .4s both; animation-delay: calc(var(--i) * 45ms);
}
.checks li:last-child { border-bottom: 0; }
.state { font-weight: 600; white-space: nowrap; }
.state.ok { color: var(--rex-color-success-text); } .state.fail { color: var(--rex-color-danger-text); }
.state.ok::before { content: "✓ "; } .state.fail::before { content: "✕ "; }

/* Formular wie .rex-field / .rex-input */
fieldset { margin: 0 0 1.25rem; padding: 0; border: 0; }
legend { margin-bottom: .25rem; padding: 0; font-weight: 500; font-size: 1.05rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 0 1rem; }
label { display: block; margin: .8rem 0 .3rem; font-weight: 700; }
input, select {
    width: 100%; min-height: 36px; padding: .45rem .65rem; border: 1px solid var(--rex-color-border-input); border-radius: var(--rex-radius);
    background: var(--rex-color-control); color: inherit; font: inherit; transition: border-color .15s, box-shadow .15s;
}
input:focus, select:focus { outline: none; border-color: var(--rex-color-focus); box-shadow: 0 0 0 3px var(--rex-color-accent-subtle); }
.note { display: block; margin-top: .3rem; color: var(--rex-color-text-muted); font-size: .85rem; }
.check { display: flex; align-items: center; gap: .5rem; margin-top: 1rem; font-weight: normal; }
.check input { width: auto; min-height: 0; }
.button {
    display: inline-flex; align-items: center; gap: .5rem; margin-top: 1rem; padding: .55rem 1.25rem; border: 1px solid transparent;
    border-radius: var(--rex-radius); background: var(--rex-color-accent); color: #fff; font: 600 15px/1.4 var(--rex-font-sans);
    text-decoration: none; cursor: pointer; transition: background .15s, transform .15s;
}
.button:hover { background: var(--rex-color-accent-hover); transform: translateY(-1px); }
.button:focus-visible, .switch :focus-visible { outline: 2px solid var(--rex-color-focus); outline-offset: 2px; }
.button.secondary { background: var(--rex-color-control); color: var(--rex-color-text); border-color: var(--rex-color-border-strong); }
.button[aria-busy="true"] { pointer-events: none; opacity: .8; }
.button[aria-busy="true"]::after { content: ""; width: 14px; height: 14px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
a { color: var(--rex-color-link); }
code { padding: .1em .35em; border-radius: 3px; background: var(--rex-color-surface-subtle); font: .9em var(--rex-font-mono); }

.alert { padding: .9rem 1.25rem; border-radius: var(--rex-radius); margin-bottom: 1rem; animation: shake .4s both; }
.alert.error { background: var(--rex-color-danger-subtle); color: var(--rex-color-danger-text); }
.alert ul { margin: 0; padding-left: 1.1rem; }
@keyframes shake { 0%, 100% { transform: none; } 25% { transform: translateX(-6px); } 75% { transform: translateX(6px); } }

/* Erfolg */
.success { text-align: center; }
.success svg { width: 84px; height: 84px; margin: .5rem auto 1rem; display: block; }
.success circle { fill: none; stroke: var(--rex-color-success-text); stroke-width: 3; stroke-dasharray: 160; stroke-dashoffset: 160; animation: draw .7s .1s forwards; }
.success path { fill: none; stroke: var(--rex-color-success-text); stroke-width: 4; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 40; stroke-dashoffset: 40; animation: draw .4s .7s forwards; }
@keyframes draw { to { stroke-dashoffset: 0; } }

footer { margin-top: 2rem; color: var(--rex-color-text-muted); font-size: .8rem; text-align: center; }

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; }
    .success circle, .success path { stroke-dashoffset: 0; }
}
</style>
</head>
<body>
<header class="bar">
    <?= logo($root) ?>
    <span class="spacer"></span>
    <nav class="switch" aria-label="<?= e(t('language')) ?>">
        <a href="?lang=de" hreflang="de" lang="de" aria-current="<?= 'de' === $lang ? 'true' : 'false' ?>">DE</a>
        <a href="?lang=en" hreflang="en" lang="en" aria-current="<?= 'en' === $lang ? 'true' : 'false' ?>">EN</a>
    </nav>
    <div class="switch" role="group" aria-label="<?= e(t('theme')) ?>">
        <button type="button" data-theme-set="light" aria-pressed="false" title="<?= e(t('theme_light')) ?>">☀︎</button>
        <button type="button" data-theme-set="auto" aria-pressed="false" title="<?= e(t('theme_auto')) ?>">A</button>
        <button type="button" data-theme-set="dark" aria-pressed="false" title="<?= e(t('theme_dark')) ?>">☾</button>
    </div>
</header>

<main>
    <h1><?= e(t('title')) ?></h1>
    <p class="lead"><?= e(distroTitle($root)) ?></p>

    <ol class="progress" style="--done: <?= min(1, max(0, $stepIndex - 1) / 3) ?>" aria-label="<?= e(t('steps')) ?>">
        <?php foreach (['step_system', 'step_config', 'step_admin', 'step_finish'] as $i => $key): ?>
            <li class="<?= $i + 1 < $stepIndex ? 'done' : ($i + 1 === $stepIndex ? 'current' : '') ?>"<?= $i + 1 === $stepIndex ? ' aria-current="step"' : '' ?>><?= e(t($key)) ?></li>
        <?php endforeach ?>
    </ol>

    <?php if ($errors): ?>
        <div class="alert error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>

    <?php if ($finished): ?>
        <section class="panel success">
            <div class="body">
                <svg viewBox="0 0 60 60" aria-hidden="true"><circle cx="30" cy="30" r="25"/><path d="M19 31l7 7 15-16"/></svg>
                <h2><?= e(t('done_title')) ?></h2>
                <p><?= e(t($selfDeleted ? 'done_text' : 'done_text_keep')) ?></p>
                <?php if (!$selfDeleted): ?><p><code>public/install.php</code></p><?php endif ?>
                <a class="button" href="<?= e(backendUrl()) ?>"><?= e(t('to_backend')) ?> →</a>
            </div>
        </section>
    <?php elseif ('finish' === $step): ?>
        <section class="panel">
            <header><?= e(t('configured_title')) ?></header>
            <div class="body">
                <?php if (!empty($env['REX_MIGRATE_TOKEN'])): ?>
                    <p><?= e(t('configured_text')) ?></p>
                    <a class="button secondary" href="<?= e(backendUrl() . '?rex_migrate=' . $env['REX_MIGRATE_TOKEN']) ?>"><?= e(t('to_migration')) ?></a>
                    <form method="post" data-busy>
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="step" value="cleanup">
                        <button class="button" type="submit"><?= e(t('finish')) ?></button>
                    </form>
                <?php else: ?>
                    <p><?= e(t('already_done')) ?></p>
                    <a class="button" href="<?= e(backendUrl()) ?>"><?= e(t('to_backend')) ?> →</a>
                <?php endif ?>
            </div>
        </section>
    <?php elseif ('check' === $step): ?>
        <section class="panel">
            <header><?= e(t('check_title')) ?></header>
            <div class="body">
                <ul class="checks">
                    <?php foreach ($checks as $i => $check): ?>
                        <li style="--i: <?= $i ?>"><span><?= e($check['label']) ?></span><span class="state <?= $check['ok'] ? 'ok' : 'fail' ?>"><?= e($check['value']) ?></span></li>
                    <?php endforeach ?>
                </ul>
                <?php if ($checksOk): ?>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="step" value="form">
                        <button class="button" type="submit"><?= e(t('next')) ?> →</button>
                    </form>
                <?php else: ?>
                    <p class="state fail"><?= e(t('check_fail')) ?></p>
                <?php endif ?>
            </div>
        </section>
    <?php else: ?>
        <form class="panel" method="post" autocomplete="off" data-busy>
            <header><?= e(t('config_title')) ?></header>
            <div class="body">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="step" value="save">
                <fieldset>
                    <legend><?= e(t('database')) ?></legend>
                    <span class="note"><?= e(t('database_note')) ?></span>
                    <div class="grid">
                        <div><label for="db_host"><?= e(t('db_host')) ?></label><input id="db_host" name="db_host" value="<?= e($values['db_host']) ?>" required></div>
                        <div><label for="db_port"><?= e(t('db_port')) ?></label><input id="db_port" name="db_port" value="<?= e($values['db_port']) ?>" inputmode="numeric" required></div>
                        <div><label for="db_name"><?= e(t('db_name')) ?></label><input id="db_name" name="db_name" value="<?= e($values['db_name']) ?>" required></div>
                        <div><label for="db_user"><?= e(t('db_user')) ?></label><input id="db_user" name="db_user" value="<?= e($values['db_user']) ?>" required></div>
                        <div><label for="db_password"><?= e(t('db_password')) ?></label><input id="db_password" name="db_password" type="password" autocomplete="new-password"></div>
                    </div>
                    <label class="check"><input type="checkbox" name="db_create" value="1" <?= $values['db_create'] ? 'checked' : '' ?>> <?= e(t('db_create')) ?></label>
                </fieldset>
                <fieldset>
                    <legend><?= e(t('website')) ?></legend>
                    <div class="grid">
                        <div><label for="name"><?= e(t('name')) ?></label><input id="name" name="name" value="<?= e($values['name']) ?>"><span class="note"><?= e(t('name_note')) ?></span></div>
                        <div><label for="base_url"><?= e(t('base_url')) ?></label><input id="base_url" name="base_url" value="<?= e($values['base_url']) ?>" placeholder="https://example.org/"><span class="note"><?= e(t('base_url_note')) ?></span></div>
                    </div>
                    <label for="mode"><?= e(t('mode')) ?></label>
                    <select id="mode" name="mode">
                        <option value="live" <?= 'live' === $values['mode'] ? 'selected' : '' ?>><?= e(t('mode_live')) ?></option>
                        <option value="dev" <?= 'dev' === $values['mode'] ? 'selected' : '' ?>><?= e(t('mode_dev')) ?></option>
                    </select>
                </fieldset>
                <button class="button" type="submit"><?= e(t('save')) ?> →</button>
                <span class="note"><?= e(t('save_note')) ?></span>
            </div>
        </form>
    <?php endif ?>

    <footer>REDAXO 6 · <?= e(t('footer')) ?></footer>
</main>

<script>
// Theme-Umschalter: hell, automatisch (System), dunkel – gemerkt im Browser
(() => {
    const root = document.documentElement;
    const buttons = document.querySelectorAll('[data-theme-set]');
    const sync = () => {
        const current = root.dataset.theme || 'auto';
        buttons.forEach(b => b.setAttribute('aria-pressed', String(b.dataset.themeSet === current)));
    };
    buttons.forEach(b => b.addEventListener('click', () => {
        const value = b.dataset.themeSet;
        if ('auto' === value) {
            delete root.dataset.theme;
        } else {
            root.dataset.theme = value;
        }
        try { 'auto' === value ? localStorage.removeItem('redaxo-installer-theme') : localStorage.setItem('redaxo-installer-theme', value); } catch (e) {}
        sync();
    }));
    sync();
    // Rückmeldung beim Absenden (Datenbanktest kann dauern)
    document.querySelectorAll('form[data-busy]').forEach(f => f.addEventListener('submit', () => f.querySelector('button[type=submit]')?.setAttribute('aria-busy', 'true')));
})();
</script>
</body>
</html>
<?php

function t(string $key): string
{
    static $texts = [
        'de' => [
            'title' => 'REDAXO installieren', 'language' => 'Sprache', 'theme' => 'Darstellung',
            'theme_light' => 'Hell', 'theme_auto' => 'Wie das System', 'theme_dark' => 'Dunkel', 'steps' => 'Schritte',
            'step_system' => 'System', 'step_config' => 'Konfiguration', 'step_admin' => 'Datenbank & Admin', 'step_finish' => 'Abschließen',
            'check_title' => 'Systemprüfung', 'next' => 'Weiter',
            'check_fail' => 'Bitte die rot markierten Punkte beim Hoster beheben (PHP-Version, Erweiterungen, Schreibrechte) und die Seite neu laden.',
            'php' => 'PHP %s oder neuer', 'ext' => 'Erweiterung %s', 'present' => 'vorhanden', 'missing' => 'fehlt',
            'writable' => 'Schreibrechte %s', 'ok' => 'ok', 'no' => 'fehlen', 'root_dir' => 'Hauptordner (.env.local)',
            'config_title' => 'Konfiguration', 'database' => 'Datenbank', 'database_note' => 'Die Zugangsdaten stehen meist in der Verwaltung deines Hosters.',
            'db_host' => 'Server', 'db_port' => 'Port', 'db_name' => 'Name der Datenbank', 'db_user' => 'Benutzer', 'db_password' => 'Passwort',
            'db_create' => 'Datenbank anlegen, falls sie fehlt', 'website' => 'Website', 'name' => 'Name', 'name_note' => 'Erscheint im Backend und in E-Mails.',
            'base_url' => 'Adresse', 'base_url_note' => 'Für Links in E-Mails und auf der Konsole.', 'mode' => 'Modus',
            'mode_live' => 'live – öffentliche Website (empfohlen)', 'mode_dev' => 'dev – Entwicklung, ausführliche Fehler',
            'save' => 'Speichern und installieren', 'save_note' => 'Danach legt REDAXO die Tabellen an und fragt nach dem ersten Admin.',
            'configured_title' => 'Konfiguration vorhanden',
            'configured_text' => 'Ist der erste Admin schon angelegt? Dann hier abschließen: Der Migrations-Token wird aus der .env.local entfernt und der Installer gelöscht.',
            'to_migration' => 'Noch nicht? Zu Datenbank & Admin', 'finish' => 'Installation abschließen',
            'already_done' => 'REDAXO ist eingerichtet. Diese Datei wird nicht mehr gebraucht – bitte public/install.php löschen.',
            'done_title' => 'REDAXO ist bereit', 'done_text' => 'Der Migrations-Token ist entfernt und der Installer hat sich gelöscht.',
            'done_text_keep' => 'Der Migrations-Token ist entfernt. Bitte diese Datei noch von Hand löschen:', 'to_backend' => 'Zum Backend',
            'err_session' => 'Die Sitzung ist abgelaufen. Bitte noch einmal absenden.', 'err_db_name' => 'Bitte einen gültigen Datenbanknamen angeben (Buchstaben, Ziffern, _ und -).',
            'err_db_user' => 'Bitte den Datenbank-Benutzer angeben.', 'err_base_url' => 'Die Adresse der Website muss mit http:// oder https:// beginnen.',
            'err_db_connect' => 'Keine Verbindung zur Datenbank:', 'err_write_env' => 'Die Datei .env.local konnte nicht geschrieben werden. Bitte Schreibrechte im Hauptordner prüfen.',
            'err_no_user' => 'Es gibt noch keinen Benutzer. Bitte zuerst Datenbank & Admin abschließen.', 'footer' => 'Installer der Distro',
        ],
        'en' => [
            'title' => 'Install REDAXO', 'language' => 'Language', 'theme' => 'Appearance',
            'theme_light' => 'Light', 'theme_auto' => 'Like the system', 'theme_dark' => 'Dark', 'steps' => 'Steps',
            'step_system' => 'System', 'step_config' => 'Configuration', 'step_admin' => 'Database & admin', 'step_finish' => 'Finish',
            'check_title' => 'System check', 'next' => 'Continue',
            'check_fail' => 'Please fix the items marked red with your hosting provider (PHP version, extensions, write permissions) and reload the page.',
            'php' => 'PHP %s or newer', 'ext' => 'Extension %s', 'present' => 'present', 'missing' => 'missing',
            'writable' => 'Write permission %s', 'ok' => 'ok', 'no' => 'missing', 'root_dir' => 'main folder (.env.local)',
            'config_title' => 'Configuration', 'database' => 'Database', 'database_note' => 'The credentials are usually in the control panel of your hosting provider.',
            'db_host' => 'Server', 'db_port' => 'Port', 'db_name' => 'Database name', 'db_user' => 'User', 'db_password' => 'Password',
            'db_create' => 'Create the database if it is missing', 'website' => 'Website', 'name' => 'Name', 'name_note' => 'Shown in the backend and in e-mails.',
            'base_url' => 'Address', 'base_url_note' => 'For links in e-mails and on the console.', 'mode' => 'Mode',
            'mode_live' => 'live – public website (recommended)', 'mode_dev' => 'dev – development, detailed errors',
            'save' => 'Save and install', 'save_note' => 'REDAXO then creates the tables and asks for the first admin.',
            'configured_title' => 'Configuration found',
            'configured_text' => 'Has the first admin been created? Then finish here: the migrate token is removed from .env.local and the installer deleted.',
            'to_migration' => 'Not yet? Go to database & admin', 'finish' => 'Finish installation',
            'already_done' => 'REDAXO is set up. This file is no longer needed – please delete public/install.php.',
            'done_title' => 'REDAXO is ready', 'done_text' => 'The migrate token is removed and the installer has deleted itself.',
            'done_text_keep' => 'The migrate token is removed. Please delete this file by hand:', 'to_backend' => 'Go to the backend',
            'err_session' => 'The session has expired. Please submit again.', 'err_db_name' => 'Please enter a valid database name (letters, digits, _ and -).',
            'err_db_user' => 'Please enter the database user.', 'err_base_url' => 'The website address must start with http:// or https://.',
            'err_db_connect' => 'No connection to the database:', 'err_write_env' => 'The file .env.local could not be written. Please check the write permissions of the main folder.',
            'err_no_user' => 'There is no user yet. Please complete database & admin first.', 'footer' => 'Distro installer',
        ],
    ];

    return $texts[$GLOBALS['lang'] ?? 'de'][$key] ?? $texts['de'][$key] ?? $key;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** REDAXO-Logo aus dem Core (Farben per CSS, passend zu hell und dunkel) */
function logo(string $root): string
{
    $file = $root . '/vendor/redaxo/core/assets/redaxo-logo.svg';
    $svg = is_file($file) ? (string) file_get_contents($file) : '';
    if (!str_starts_with(ltrim($svg), '<svg')) {
        return '<strong>REDAXO</strong>';
    }
    $svg = (string) preg_replace('/\sfill="[^"]*"/', '', $svg);

    return (string) preg_replace('/<svg\b/', '<svg role="img" aria-label="REDAXO"', $svg, 1);
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
    $checks = [['label' => sprintf(t('php'), MIN_PHP), 'value' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, MIN_PHP, '>=')]];
    foreach (EXTENSIONS as $extension) {
        $loaded = extension_loaded($extension);
        $checks[] = ['label' => sprintf(t('ext'), $extension), 'value' => t($loaded ? 'present' : 'missing'), 'ok' => $loaded];
    }
    foreach (['' => t('root_dir'), 'var' => 'var/', 'public/assets' => 'public/assets/', 'public/media' => 'public/media/'] as $dir => $label) {
        $path = rtrim($root . '/' . $dir, '/');
        if (!is_dir($path)) {
            @mkdir($path, 0o775, true);
        }
        $writable = is_dir($path) && is_writable($path);
        $checks[] = ['label' => sprintf(t('writable'), $label), 'value' => t($writable ? 'ok' : 'no'), 'ok' => $writable];
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
