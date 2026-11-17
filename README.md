![REDAXO 6 Distro Builder](docs/hero.png)

# REDAXO 6 Distro Builder

Ein Template von [KLXM Crossmedia](https://klxm.de).

Vorlage, um eigene **REDAXO-6-Distributionen** zu bauen: REDAXO plus eine feste Auswahl an AddOns als fertiges ZIP.
Composer läuft dabei nur auf GitHub. Wer installiert, braucht weder Composer noch Shell.

Jeder Build erzeugt ein Release mit:

| Datei | Wofür |
| --- | --- |
| `<distro>-<version>.zip` | **Neu installieren**: hochladen, entpacken, `install.php` aufrufen |
| `<distro>-<version>-update.zip` | **Aktualisieren**: enthält nur `vendor/` und die Composer-Dateien. Projekt, `.env`, Medien und `var/` bleiben unberührt |
| `manifest.json` | Version, Paketliste, Anforderungen, Prüfsummen (für einen Updater) |

## Eigene Distro in 3 Schritten

1. **Use this template** → eigenes Repository anlegen (darf privat sein).
2. In `composer.json` die AddOns eintragen (aus Packagist oder als `repositories`-Eintrag), in `distro.json`
   Name und Kanal (`stable` oder `dev`) setzen.
3. Unter **Actions → Update** den ersten Lauf starten. Er legt die `composer.lock` an und stößt den **Build** an.
   Danach liegt das Release unter **Releases**.

Der Workflow **Update** läuft jede Woche. Gibt es neue Versionen von Core oder AddOns, entsteht automatisch ein
neuer Build.

## Installieren (für Anwender)

1. Das ZIP entpacken und alles hochladen.
2. Den Webserver auf `public/` zeigen lassen. Geht das beim Hoster nicht, leitet die `.htaccess` im Hauptordner
   dorthin weiter.
3. `https://deine-domain/install.php` aufrufen. Der Installer sieht aus wie das REDAXO-Backend, kennt Hell- und
   Dunkelmodus und spricht Deutsch und Englisch.

| 1 · System | 2 · Konfiguration |
| --- | --- |
| ![Systemprüfung](docs/installer-system.png) | ![Konfiguration](docs/installer-config-light-de.png) |

| Dunkel und Englisch | Fertig |
| --- | --- |
| ![Konfiguration, dunkel, englisch](docs/installer-config-dark-en.png) | ![Installation abgeschlossen](docs/installer-done.png) |

So läuft es ab:

1. **System:** prüft PHP-Version, Erweiterungen und Schreibrechte.
2. **Konfiguration:** Datenbank mit Verbindungstest (legt sie bei Bedarf an), Name, Adresse und Modus. Daraus
   schreibt der Installer die `.env.local`, mit zufälliger Instanz-ID und einmaligem Migrations-Token.
3. **Datenbank und Admin:** Die Migrationsseite des Cores (`/redaxo/?rex_migrate=…`) legt die Tabellen und den
   ersten Admin an.
4. **Abschließen:** Der Installer entfernt den Migrations-Token und löscht sich selbst.

## Aktualisieren

`*-update.zip` ersetzt `vendor/`. Bitte nicht darüber entpacken, sondern austauschen: den alten Ordner `vendor/`
umbenennen und den neuen an seine Stelle legen. Danach die Migration ausführen, über die Konsole
(`php bin/console migrate`) oder ohne Shell über `REX_MIGRATE_TOKEN`.

Ein Updater-AddOn, das das im Backend erledigt (Prüfsumme, Sicherung, Tausch, Migration, Rückfall), ist der
nächste Schritt.

## Lokal bauen

```bash
composer update --no-install   # composer.lock aktualisieren
php tools/build.php            # Ergebnis in dist/
```

Voraussetzung: PHP 8.5 mit `zip`, Composer 2.

## Aufbau

```
composer.json / composer.lock   Core und AddOns der Distro
distro.json                     Kennung, Titel, Kanal
skeleton/                       Projektgerüst (redaxo/project) plus public/install.php
tools/build.php                 Build: Projekt zusammenstellen, composer install, ZIPs, Manifest
.github/workflows/              build.yml (Release), update.yml (wöchentlich neue Versionen)
```
