-- idzan.hr CMS — MySQL 5.7+ / MariaDB 10.3+
-- The web installer (/install.php) runs this file for you.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(64)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret   VARCHAR(64)  NULL,
  totp_enabled  TINYINT(1)   NOT NULL DEFAULT 0,
  totp_last_step BIGINT      NOT NULL DEFAULT 0,
  recovery_codes TEXT        NULL,
  role          VARCHAR(16)  NOT NULL DEFAULT 'admin',
  created_at    DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  selector       CHAR(24)     NOT NULL UNIQUE,
  validator_hash CHAR(64)     NOT NULL,
  user_agent     VARCHAR(255) NULL,
  created_at     DATETIME     NOT NULL,
  last_used_at   DATETIME     NOT NULL,
  expires_at     DATETIME     NOT NULL,
  KEY user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_hash      CHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL,
  KEY ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v MEDIUMTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS terms (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  taxonomy VARCHAR(32)  NOT NULL,
  name     VARCHAR(190) NOT NULL,
  slug     VARCHAR(190) NOT NULL,
  UNIQUE KEY tax_slug (taxonomy, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entity_terms (
  entity    VARCHAR(32)  NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  term_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (entity, entity_id, term_id),
  KEY term (term_id),
  CONSTRAINT fk_et_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(255) NOT NULL,
  slug           VARCHAR(190) NOT NULL UNIQUE,
  category_id    INT UNSIGNED NULL,
  featured_image VARCHAR(500) NULL,
  featured_alt   VARCHAR(255) NULL,
  excerpt        TEXT NULL,
  content        MEDIUMTEXT NULL,
  status         VARCHAR(16) NOT NULL DEFAULT 'draft',
  published_at   DATETIME NULL,
  seo_title      VARCHAR(255) NULL,
  seo_description VARCHAR(500) NULL,
  modified_at    DATETIME NULL,
  created_at     DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL,
  KEY pub (status, published_at),
  CONSTRAINT fk_post_cat FOREIGN KEY (category_id) REFERENCES terms (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(255) NOT NULL,
  slug           VARCHAR(190) NOT NULL UNIQUE,
  client         VARCHAR(255) NULL,
  started_on     DATE NULL,
  url            VARCHAR(500) NULL,
  featured_image VARCHAR(500) NULL,
  featured_alt   VARCHAR(255) NULL,
  content        MEDIUMTEXT NULL,
  status         VARCHAR(16) NOT NULL DEFAULT 'published',
  seo_title      VARCHAR(255) NULL,
  seo_description VARCHAR(500) NULL,
  modified_at    DATETIME NULL,
  created_at     DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS experience (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company     VARCHAR(255) NOT NULL,
  role        VARCHAR(255) NOT NULL,
  slug        VARCHAR(190) NOT NULL UNIQUE,
  start_date  DATE NOT NULL,
  end_date    DATE NULL,
  ongoing     TINYINT(1) NOT NULL DEFAULT 0,
  work_type   VARCHAR(16) NOT NULL DEFAULT 'remote',
  description MEDIUMTEXT NULL,
  status      VARCHAR(16) NOT NULL DEFAULT 'published',
  modified_at DATETIME NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS education (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution VARCHAR(255) NOT NULL,
  program     VARCHAR(255) NULL,
  location    VARCHAR(255) NULL,
  start_date  DATE NULL,
  end_date    DATE NULL,
  ongoing     TINYINT(1) NOT NULL DEFAULT 0,
  description TEXT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  status      VARCHAR(16) NOT NULL DEFAULT 'published',
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certifications (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(255) NOT NULL,
  issuer         VARCHAR(255) NULL,
  issued_on      DATE NULL,
  credential_url VARCHAR(500) NULL,
  image          VARCHAR(500) NULL,
  image_alt      VARCHAR(255) NULL,
  status         VARCHAR(16) NOT NULL DEFAULT 'published',
  created_at     DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title        VARCHAR(255) NOT NULL,
  slug         VARCHAR(190) NOT NULL UNIQUE,
  content      MEDIUMTEXT NULL,
  status       VARCHAR(16) NOT NULL DEFAULT 'published',
  in_footer    TINYINT(1) NOT NULL DEFAULT 0,
  footer_order INT NOT NULL DEFAULT 0,
  seo_title    VARCHAR(255) NULL,
  seo_description VARCHAR(500) NULL,
  modified_at  DATETIME NULL,
  created_at   DATETIME NOT NULL,
  updated_at   DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS affiliates (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(255) NOT NULL,
  slug           VARCHAR(190) NOT NULL UNIQUE,
  company_name   VARCHAR(255) NULL,
  company_url    VARCHAR(500) NULL,
  link_url       VARCHAR(500) NULL,
  kind           VARCHAR(16)  NOT NULL DEFAULT 'affiliate',
  has_code       VARCHAR(8)   NOT NULL DEFAULT 'no',
  promo_code     VARCHAR(190) NULL,
  featured_image VARCHAR(500) NULL,
  featured_alt   VARCHAR(255) NULL,
  content        MEDIUMTEXT NULL,
  status         VARCHAR(16)  NOT NULL DEFAULT 'published',
  published_at   DATETIME NULL,
  seo_title      VARCHAR(255) NULL,
  seo_description VARCHAR(500) NULL,
  modified_at    DATETIME NULL,
  created_at     DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label      VARCHAR(190) NOT NULL,
  value      VARCHAR(255) NULL,
  link       VARCHAR(500) NOT NULL,
  icon       VARCHAR(32)  NOT NULL DEFAULT '',
  sort_order INT          NOT NULL DEFAULT 100,
  status     VARCHAR(16)  NOT NULL DEFAULT 'published',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translations (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity     VARCHAR(32)  NOT NULL,
  entity_id  INT UNSIGNED NOT NULL,
  lang       VARCHAR(8)   NOT NULL,
  field      VARCHAR(64)  NOT NULL,
  value      MEDIUMTEXT NULL,
  src_hash   CHAR(32)     NOT NULL DEFAULT '',
  status     VARCHAR(16)  NOT NULL DEFAULT 'published',
  engine     VARCHAR(16)  NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL,
  UNIQUE KEY one_field (entity, entity_id, lang, field),
  KEY lang_entity (lang, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_meta (
  path        VARCHAR(255) NOT NULL PRIMARY KEY,
  alt         VARCHAR(255) NULL,
  updated_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
