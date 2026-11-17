<?php

namespace Project;

use Override;
use Redaxo\Core\AbstractProject;
use Redaxo\Core\Core;
use Redaxo\Core\Filesystem\Path;

final class Project extends AbstractProject
{
    #[Override]
    public function configure(): void
    {
        // Configuration for the project. Runs on every request,
        // while the core boots and before any addon is loaded.
    }

    #[Override]
    public function boot(): void
    {
        // Runtime initialization for the project. Runs on every request,
        // after the core and all addons have booted.

        $this->removeInstaller();
    }

    #[Override]
    public function install(): void
    {
        // Describe the tables the project owns here, e.g. via `Table::get(...)->ensureColumn(...)->ensure()`.
        // Runs on every `console migrate`, after the core and all addons, so it has to be idempotent.
        // For steps that should run exactly once there are migrations: `console migrate:make <description>`.
    }

    /**
     * Distro: Sicherheitsnetz, falls nach der Installation niemand „Installation abschließen“ geklickt hat.
     * Sobald jemand im Backend angemeldet ist, verschwinden public/install.php und der Migrations-Token.
     * Kann nach der Installation gefahrlos gelöscht werden.
     */
    private function removeInstaller(): void
    {
        $installer = Path::frontend('install.php');
        if (!Core::isBackend() || !is_file($installer) || !Core::getUser()) {
            return;
        }
        @unlink($installer);
        $env = Path::base('.env.local');
        if (is_file($env) && is_writable($env)) {
            $content = (string) file_get_contents($env);
            $cleaned = (string) preg_replace('/^(# Nur für die Installation.*\n)?REX_MIGRATE_TOKEN=.*\n?/m', '', $content);
            if ($cleaned !== $content) {
                file_put_contents($env, $cleaned, LOCK_EX);
            }
        }
    }
}
