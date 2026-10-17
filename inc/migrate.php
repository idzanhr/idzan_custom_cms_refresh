<?php
declare(strict_types=1);

/**
 * Database upgrades for sites installed with an older version of the CMS.
 * Runs automatically when an admin page is opened. Fresh installs start at SCHEMA_VERSION.
 */
const SCHEMA_VERSION = 8;

function migrations(): array
{
    return [
        1 => [ // Affiliates & sponsorships
            "CREATE TABLE IF NOT EXISTS affiliates (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, slug VARCHAR(190) NOT NULL UNIQUE,
              company_name VARCHAR(255) NULL, company_url VARCHAR(500) NULL, link_url VARCHAR(500) NULL,
              kind VARCHAR(16) NOT NULL DEFAULT 'affiliate', has_code VARCHAR(8) NOT NULL DEFAULT 'no', promo_code VARCHAR(190) NULL,
              featured_image VARCHAR(500) NULL, featured_alt VARCHAR(255) NULL, content MEDIUMTEXT NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'published', published_at DATETIME NULL,
              created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        2 => [ // Two-factor login
            "ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL",
            "ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE users ADD COLUMN totp_last_step BIGINT NOT NULL DEFAULT 0",
            "ALTER TABLE users ADD COLUMN recovery_codes TEXT NULL",
        ],
        3 => [ // Remembered devices + user roles
            "CREATE TABLE IF NOT EXISTS remember_tokens (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, selector CHAR(24) NOT NULL UNIQUE,
              validator_hash CHAR(64) NOT NULL, user_agent VARCHAR(255) NULL, created_at DATETIME NOT NULL,
              last_used_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, KEY user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "ALTER TABLE users ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'admin'",
        ],
        4 => [ // Contact links (grid on the contact page)
            str_starts_with((string)db()->getAttribute(PDO::ATTR_DRIVER_NAME), 'sqlite')
                ? "CREATE TABLE IF NOT EXISTS contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR(190) NOT NULL, value VARCHAR(255) NULL,
                   link VARCHAR(500) NOT NULL, icon VARCHAR(32) NOT NULL DEFAULT '', sort_order INT NOT NULL DEFAULT 100,
                   status VARCHAR(16) NOT NULL DEFAULT 'published', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)"
                : "CREATE TABLE IF NOT EXISTS contacts (
                   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, label VARCHAR(190) NOT NULL, value VARCHAR(255) NULL,
                   link VARCHAR(500) NOT NULL, icon VARCHAR(32) NOT NULL DEFAULT '', sort_order INT NOT NULL DEFAULT 100,
                   status VARCHAR(16) NOT NULL DEFAULT 'published', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // Turn the existing contact page list into entries, then republish.
            function () { if (contacts_convert_page()) content_changed(); },
        ],
        5 => [ // Coloured info / notice / warning boxes on Contact and Affiliates, as on the old site
            function () { if (legacy_boxes_apply()) content_changed(); },
        ],
        6 => [ // Translations (Croatian, Bosnian, Serbian Cyrillic, Slovenian)
            str_starts_with((string)db()->getAttribute(PDO::ATTR_DRIVER_NAME), 'sqlite')
                ? "CREATE TABLE IF NOT EXISTS translations (id INTEGER PRIMARY KEY AUTOINCREMENT, entity VARCHAR(32) NOT NULL, entity_id INT NOT NULL,
                   lang VARCHAR(8) NOT NULL, field VARCHAR(64) NOT NULL, value MEDIUMTEXT NULL, src_hash CHAR(32) NOT NULL DEFAULT '',
                   status VARCHAR(16) NOT NULL DEFAULT 'published', engine VARCHAR(16) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL,
                   UNIQUE (entity, entity_id, lang, field))"
                : "CREATE TABLE IF NOT EXISTS translations (
                   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity VARCHAR(32) NOT NULL, entity_id INT UNSIGNED NOT NULL,
                   lang VARCHAR(8) NOT NULL, field VARCHAR(64) NOT NULL, value MEDIUMTEXT NULL, src_hash CHAR(32) NOT NULL DEFAULT '',
                   status VARCHAR(16) NOT NULL DEFAULT 'published', engine VARCHAR(16) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL,
                   UNIQUE KEY one_field (entity, entity_id, lang, field), KEY lang_entity (lang, entity)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        7 => [ // "Last edited" date for the sitemap, set only when content is saved in the editor
            "ALTER TABLE posts ADD COLUMN modified_at DATETIME NULL",
            "ALTER TABLE projects ADD COLUMN modified_at DATETIME NULL",
            "ALTER TABLE experience ADD COLUMN modified_at DATETIME NULL",
            "ALTER TABLE pages ADD COLUMN modified_at DATETIME NULL",
            "ALTER TABLE affiliates ADD COLUMN modified_at DATETIME NULL",
            // The import and the image-link repair touched updated_at on everything, so start posts from their publish date.
            "UPDATE posts SET modified_at = published_at WHERE modified_at IS NULL",
            "UPDATE affiliates SET modified_at = published_at WHERE modified_at IS NULL",
        ],
        8 => [ // Search title / description per item, alt text per image
            "ALTER TABLE posts ADD COLUMN seo_title VARCHAR(255) NULL",
            "ALTER TABLE posts ADD COLUMN seo_description VARCHAR(500) NULL",
            "ALTER TABLE pages ADD COLUMN seo_title VARCHAR(255) NULL",
            "ALTER TABLE pages ADD COLUMN seo_description VARCHAR(500) NULL",
            "ALTER TABLE projects ADD COLUMN seo_title VARCHAR(255) NULL",
            "ALTER TABLE projects ADD COLUMN seo_description VARCHAR(500) NULL",
            "ALTER TABLE affiliates ADD COLUMN seo_title VARCHAR(255) NULL",
            "ALTER TABLE affiliates ADD COLUMN seo_description VARCHAR(500) NULL",
            str_starts_with((string)db()->getAttribute(PDO::ATTR_DRIVER_NAME), 'sqlite')
                ? "CREATE TABLE IF NOT EXISTS media_meta (path VARCHAR(255) PRIMARY KEY, alt VARCHAR(255) NULL, updated_at DATETIME NOT NULL)"
                : "CREATE TABLE IF NOT EXISTS media_meta (path VARCHAR(255) NOT NULL PRIMARY KEY, alt VARCHAR(255) NULL, updated_at DATETIME NOT NULL
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
    ];
}

/** True once the CMS tables exist (the installer has run). */
function cms_installed(): bool
{
    try { one('SELECT id FROM users LIMIT 1'); return true; } catch (Throwable $e) { return false; }
}

function run_migrations(): void
{
    if (!cms_installed()) return;
    $current = (int)setting('schema_version', '0');
    if ($current >= SCHEMA_VERSION) return;
    foreach (migrations() as $v => $stmts) {
        if ($v <= $current) continue;
        foreach ($stmts as $sql) {
            try {
                if (is_callable($sql)) { $sql(); continue; }
                db()->exec($sql);
            } catch (PDOException $e) {
                // "Duplicate column" / "already exists" means a partial earlier run — safe to continue.
                if (!preg_match('~duplicate column|already exists~i', $e->getMessage())) throw $e;
            }
        }
        save_setting('schema_version', (string)$v);
    }
    setting('__reset');
}
