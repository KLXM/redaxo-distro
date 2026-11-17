<?php

/**
 * Baut die Distro: Vollpaket (REDAXO zum Installieren) und Update-Paket (nur vendor/ + Composer-Dateien).
 *
 *     php tools/build.php [--build=42] [--out=dist]
 *
 * Läuft lokal und in GitHub Actions gleich. Braucht PHP 8.5 mit zip und Composer im PATH.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$options = getopt('', ['build::', 'out::']);
$buildNumber = (string) ($options['build'] ?? getenv('GITHUB_RUN_NUMBER') ?: 'local');
$out = $root . '/' . trim((string) ($options['out'] ?? 'dist'), '/');

$distro = json_decode((string) file_get_contents($root . '/distro.json'), true, flags: JSON_THROW_ON_ERROR);
$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);

$packages = [];
foreach ($lock['packages'] as $package) {
    $ref = $package['dist']['reference'] ?? $package['source']['reference'] ?? '';
    $packages[$package['name']] = $package['version'] . (str_starts_with($package['version'], 'dev-') || str_ends_with($package['version'], '-dev') ? '@' . substr($ref, 0, 7) : '');
}
$core = $packages['redaxo/core'] ?? 'unbekannt';
$version = date('Y.m.d') . '-' . $buildNumber;
$name = $distro['id'] . '-' . $version;

step("Distro {$distro['title']} · Build {$version} · Core {$core}");

// ---------- 1. Projekt zusammenstellen ----------
$work = $root . '/build/redaxo';
removeDir($root . '/build');
mkdir($work, 0o777, true);
copyDir($root . '/skeleton', $work);
copy($root . '/composer.json', $work . '/composer.json');
copy($root . '/composer.lock', $work . '/composer.lock');
foreach (['var', 'public/media'] as $dir) {
    @mkdir($work . '/' . $dir, 0o777, true);
    file_put_contents($work . '/' . $dir . '/.keep', '');
}

// ---------- 2. Abhängigkeiten installieren (ohne Entwicklungspakete) ----------
step('composer install');
run('composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --working-dir=' . escapeshellarg($work));

// ---------- 3. Manifest ----------
$requirePhp = $lock['platform']['php'] ?? '^8.5';
$manifest = [
    'distro' => $distro['id'],
    'title' => $distro['title'],
    'channel' => $distro['channel'] ?? 'stable',
    'version' => $version,
    'build' => $buildNumber,
    'created' => date('c'),
    'requires' => [
        'php' => $requirePhp,
        'extensions' => array_values(array_filter(array_map(
            static fn (string $k) => str_starts_with($k, 'ext-') ? substr($k, 4) : null,
            array_keys(requirements($lock)),
        ))),
    ],
    'core' => $core,
    'packages' => $packages,
];
file_put_contents($work . '/distro-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// ---------- 4. ZIPs ----------
@mkdir($out, 0o777, true);
$full = "{$out}/{$name}.zip";
$update = "{$out}/{$name}-update.zip";
step('Vollpaket ' . basename($full));
zipDir($work, $full, static fn (string $rel) => true);
step('Update-Paket ' . basename($update));
// Nur, was REDAXO und den AddOns gehört – nichts vom Projekt (src/, .env, var/, Medien bleiben unberührt)
zipDir($work, $update, static fn (string $rel) => str_starts_with($rel, 'vendor/')
    || in_array($rel, ['composer.json', 'composer.lock', 'distro-manifest.json'], true));

$manifest['files'] = [
    'full' => ['name' => basename($full), 'size' => filesize($full), 'sha256' => hash_file('sha256', $full)],
    'update' => ['name' => basename($update), 'size' => filesize($update), 'sha256' => hash_file('sha256', $update)],
];
file_put_contents("{$out}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents("{$out}/release-notes.md", releaseNotes($manifest, $root));

step(sprintf('fertig: %s (%.1f MB), %s (%.1f MB)', basename($full), filesize($full) / 1048576, basename($update), filesize($update) / 1048576));

// ---------- Hilfsfunktionen ----------

function step(string $message): void
{
    fwrite(STDOUT, "▸ {$message}\n");
}

function run(string $command): void
{
    passthru($command, $code);
    if (0 !== $code) {
        fwrite(STDERR, "Fehler bei: {$command}\n");
        exit($code);
    }
}

/** @return array<string, string> Plattform-Anforderungen aller Pakete */
function requirements(array $lock): array
{
    $req = [];
    foreach ($lock['packages'] as $package) {
        foreach ($package['require'] ?? [] as $name => $constraint) {
            if (str_starts_with($name, 'ext-')) {
                $req[$name] = $constraint;
            }
        }
    }
    ksort($req);

    return $req;
}

function copyDir(string $from, string $to): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
        $target = $to . '/' . substr($file->getPathname(), strlen($from) + 1);
        $file->isDir() ? @mkdir($target, 0o777, true) : copy($file->getPathname(), $target);
        if ($file->isFile() && is_executable($file->getPathname())) {
            chmod($target, 0o755);
        }
    }
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

function zipDir(string $dir, string $target, callable $include): void
{
    @unlink($target);
    $zip = new ZipArchive();
    if (true !== $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        throw new RuntimeException("ZIP nicht anlegbar: {$target}");
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        if (!$include($rel)) {
            continue;
        }
        if ($file->isDir()) {
            $zip->addEmptyDir($rel);
        } else {
            $zip->addFile($file->getPathname(), $rel);
            // Ausführbar bleiben (bin/console)
            if (is_executable($file->getPathname())) {
                $zip->setExternalAttributesName($rel, ZipArchive::OPSYS_UNIX, 0o100755 << 16);
            }
        }
    }
    $zip->close();
}

function releaseNotes(array $manifest, string $root): string
{
    $lines = ["## {$manifest['title']} · {$manifest['version']}", '', "Core: `{$manifest['core']}` · PHP {$manifest['requires']['php']}", ''];
    $lines[] = '| Paket | Version |';
    $lines[] = '| --- | --- |';
    foreach ($manifest['packages'] as $name => $version) {
        if (preg_match('~^(redaxo|yakamara|klxm|friendsofredaxo)/~', $name)) {
            $lines[] = "| `{$name}` | `{$version}` |";
        }
    }
    $lines[] = '';
    $lines[] = '**Neu installieren:** `' . $manifest['files']['full']['name'] . '` entpacken und hochladen, Document-Root auf `public/` (oder die `.htaccess` im Hauptordner nutzen), dann `install.php` aufrufen.';
    $lines[] = '';
    $lines[] = '**Aktualisieren:** `' . $manifest['files']['update']['name'] . '` enthält nur `vendor/` und die Composer-Dateien – `src/`, `.env`, `var/` und Medien bleiben unberührt. Den alten `vendor/`-Ordner austauschen (nicht darüber entpacken), danach `migrate` ausführen.';

    return implode("\n", $lines) . "\n";
}
