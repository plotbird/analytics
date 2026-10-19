-- ============================================================================
--  PlotBird Analytics - database schema
--  Target: MySQL 5.7+ / MariaDB 10.2+
--  The literal token {{PREFIX}} is replaced with the configured table prefix
--  (default: mb_) by the installer before this file is executed.
--
--  Design notes
--  ------------
--  * Everything is utf8mb4 / InnoDB so that emoji and CJK in titles and URLs
--    are stored losslessly.
--  * No MySQL 8.0-only syntax is used (no CTE, no window functions, no
--    utf8mb4_0900_* collations, no functional indexes) - the project must run
--    on the older MySQL/MariaDB builds that shared hostings still ship.
--  * Raw, per-hit data lives in `pageviews`; per-visit data in `visits`.
--    Both are pruned by the retention setting. Aggregated, permanent numbers
--    live in `stats_daily` and `stats_dim` and are produced by the aggregator.
--  * `visits.visitor_hash` is md5(ip + user agent + salt + date) truncated to
--    32 hex chars: no cookies, no cross-day profile of a person (GDPR).
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`          VARCHAR(190) NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `name`           VARCHAR(120) NOT NULL DEFAULT '',
  `role`           ENUM('super_admin','client') NOT NULL DEFAULT 'client',
  `locale`         VARCHAR(10)  NOT NULL DEFAULT 'en',
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     DATETIME     NOT NULL,
  `updated_at`     DATETIME     DEFAULT NULL,
  `last_login_at`  DATETIME     DEFAULT NULL,
  `last_login_ip`  VARCHAR(45)  NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_email` (`email`),
  KEY `idx_users_role` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Tracked websites. `public_id` is the UUID handed to tracker.js as
-- data-website-id and is safe to expose publicly.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}sites` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`        CHAR(36)     NOT NULL,
  `name`             VARCHAR(120) NOT NULL,
  `domain`           VARCHAR(190) NOT NULL DEFAULT '',
  `timezone`         VARCHAR(64)  NOT NULL DEFAULT 'UTC',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `tracker_settings` TEXT         DEFAULT NULL,
  `created_by`       INT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL,
  `updated_at`       DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sites_public_id` (`public_id`),
  KEY `idx_sites_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Which client may see which site. A super admin implicitly sees everything.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}site_users` (
  `site_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`site_id`, `user_id`),
  KEY `idx_site_users_user` (`user_id`),
  CONSTRAINT `fk_site_users_site` FOREIGN KEY (`site_id`) REFERENCES `{{PREFIX}}sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_site_users_user` FOREIGN KEY (`user_id`) REFERENCES `{{PREFIX}}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Visits (sessions). A visit is opened by the first pageview of a visitor and
-- is extended as long as new pageviews arrive within the session timeout
-- (30 minutes by default, see Settings::SESSION_TIMEOUT).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}visits` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id`         INT UNSIGNED    NOT NULL,
  `session_key`     CHAR(32)        NOT NULL,
  `visitor_hash`    CHAR(32)        NOT NULL,
  `started_at`      DATETIME        NOT NULL,
  `last_seen_at`    DATETIME        NOT NULL,
  `duration`        INT UNSIGNED    NOT NULL DEFAULT 0,
  `pageviews`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_bounce`       TINYINT(1)      NOT NULL DEFAULT 1,
  `entry_path`      VARCHAR(512)    NOT NULL DEFAULT '',
  `exit_path`       VARCHAR(512)    NOT NULL DEFAULT '',
  `referrer_domain` VARCHAR(190)    NOT NULL DEFAULT '',
  `referrer_url`    VARCHAR(512)    NOT NULL DEFAULT '',
  `country`         CHAR(2)         NOT NULL DEFAULT '',
  `city`            VARCHAR(120)    NOT NULL DEFAULT '',
  `device`          VARCHAR(16)     NOT NULL DEFAULT '',
  `os`              VARCHAR(40)     NOT NULL DEFAULT '',
  `browser`         VARCHAR(40)     NOT NULL DEFAULT '',
  `screen`          VARCHAR(16)     NOT NULL DEFAULT '',
  `language`        VARCHAR(10)     NOT NULL DEFAULT '',
  -- Only filled when the administrator explicitly enables IP storage
  -- (Settings -> "Store visitor IP addresses"). Empty by default: the project
  -- is designed to work without ever persisting an IP address.
  `ip`              VARCHAR(45)     NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_visits_site_started` (`site_id`, `started_at`),
  KEY `idx_visits_session` (`site_id`, `session_key`),
  KEY `idx_visits_visitor` (`site_id`, `visitor_hash`, `started_at`),
  KEY `idx_visits_last_seen` (`site_id`, `last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Raw pageviews. One row per hit. Dimensions are denormalised on purpose:
-- it keeps every report a single grouped scan instead of a chain of joins.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}pageviews` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id`         INT UNSIGNED    NOT NULL,
  `visit_id`        BIGINT UNSIGNED DEFAULT NULL,
  `session_key`     CHAR(32)        NOT NULL,
  `visitor_hash`    CHAR(32)        NOT NULL,
  `path`            VARCHAR(512)    NOT NULL DEFAULT '/',
  `title`           VARCHAR(255)    NOT NULL DEFAULT '',
  `referrer_domain` VARCHAR(190)    NOT NULL DEFAULT '',
  `referrer_url`    VARCHAR(512)    NOT NULL DEFAULT '',
  `country`         CHAR(2)         NOT NULL DEFAULT '',
  `city`            VARCHAR(120)    NOT NULL DEFAULT '',
  `device`          VARCHAR(16)     NOT NULL DEFAULT '',
  `os`              VARCHAR(40)     NOT NULL DEFAULT '',
  `browser`         VARCHAR(40)     NOT NULL DEFAULT '',
  `screen`          VARCHAR(16)     NOT NULL DEFAULT '',
  `language`        VARCHAR(10)     NOT NULL DEFAULT '',
  `is_bot`          TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`      DATETIME        NOT NULL,
  `created_date`    DATE            NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pv_site_date` (`site_id`, `created_date`),
  KEY `idx_pv_site_created` (`site_id`, `created_at`),
  KEY `idx_pv_site_path` (`site_id`, `path`(150)),
  KEY `idx_pv_site_referrer` (`site_id`, `referrer_domain`),
  KEY `idx_pv_site_country` (`site_id`, `country`),
  KEY `idx_pv_visit` (`visit_id`),
  KEY `idx_pv_visitor` (`site_id`, `visitor_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Daily rollup: one row per site per day. This is what dashboards read for
-- ranges that are already aggregated, so long date ranges stay fast.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}stats_daily` (
  `site_id`        INT UNSIGNED NOT NULL,
  `date`           DATE         NOT NULL,
  `pageviews`      INT UNSIGNED NOT NULL DEFAULT 0,
  `visitors`       INT UNSIGNED NOT NULL DEFAULT 0,
  `sessions`       INT UNSIGNED NOT NULL DEFAULT 0,
  `bounces`        INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`site_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Daily rollup per dimension value (top pages, referrers, countries,
-- devices, OS, browsers, languages, screens, entry/exit pages, titles, cities).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}stats_dim` (
  `site_id`   INT UNSIGNED NOT NULL,
  `date`      DATE         NOT NULL,
  `dimension` VARCHAR(16)  NOT NULL,
  `value`     VARCHAR(191) NOT NULL,
  `pageviews` INT UNSIGNED NOT NULL DEFAULT 0,
  `visitors`  INT UNSIGNED NOT NULL DEFAULT 0,
  `sessions`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`site_id`, `date`, `dimension`, `value`),
  KEY `idx_dim_lookup` (`site_id`, `dimension`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Public share links: read-only dashboard access without an account.
-- Only the sha256 of the token is stored, so a database leak does not hand
-- out working links.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}share_links` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id`      INT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64)     NOT NULL,
  `token_hint`   CHAR(8)      NOT NULL DEFAULT '',
  `label`        VARCHAR(120) NOT NULL DEFAULT '',
  `created_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL,
  `expires_at`   DATETIME     DEFAULT NULL,
  `revoked_at`   DATETIME     DEFAULT NULL,
  `last_used_at` DATETIME     DEFAULT NULL,
  `hits`         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_share_token` (`token_hash`),
  KEY `idx_share_site` (`site_id`, `revoked_at`),
  CONSTRAINT `fk_share_site` FOREIGN KEY (`site_id`) REFERENCES `{{PREFIX}}sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- IP -> country ranges, imported from a DB-IP Lite style CSV.
-- Addresses are stored as their 16-byte big-endian form; IPv4 is stored as an
-- IPv4-mapped address, so a single binary comparison works for both families
-- (the `family` column keeps the two spaces apart).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}geoip_ranges` (
  `family`       TINYINT UNSIGNED NOT NULL,
  `start_bin`    VARBINARY(16)    NOT NULL,
  `end_bin`      VARBINARY(16)    NOT NULL,
  `country_code` CHAR(2)          NOT NULL DEFAULT '',
  PRIMARY KEY (`family`, `start_bin`),
  KEY `idx_geoip_end` (`family`, `end_bin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Goals (conversions). A goal measures how many visitors did the thing the
-- website exists for: reaching a specific page, or triggering a named event
-- from the tracker (plotbird.track('signup')).
--
-- "page" goals need no change to the website - they are counted from ordinary
-- page views. "event" goals require the one-line tracker call.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}goals` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id`     INT UNSIGNED NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `type`        ENUM('page','event') NOT NULL DEFAULT 'page',
  -- A path ("/thanks", "/checkout/*") for page goals, an event name for events.
  `match_value` VARCHAR(191) NOT NULL DEFAULT '',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_goals_site` (`site_id`, `is_active`),
  CONSTRAINT `fk_goals_site` FOREIGN KEY (`site_id`) REFERENCES `{{PREFIX}}sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Raw custom events. Kept next to pageviews rather than inside them: an event
-- is not a page view and must not affect page or session counters.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}events` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id`      INT UNSIGNED    NOT NULL,
  `visit_id`     BIGINT UNSIGNED DEFAULT NULL,
  `visitor_hash` CHAR(32)        NOT NULL,
  `name`         VARCHAR(120)    NOT NULL DEFAULT '',
  `path`         VARCHAR(512)    NOT NULL DEFAULT '',
  `country`      CHAR(2)         NOT NULL DEFAULT '',
  `device`       VARCHAR(16)     NOT NULL DEFAULT '',
  `created_at`   DATETIME        NOT NULL,
  `created_date` DATE            NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_events_site_date` (`site_id`, `created_date`),
  KEY `idx_events_site_name` (`site_id`, `name`, `created_date`),
  KEY `idx_events_visitor` (`site_id`, `visitor_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Daily goal rollup: one row per goal per day, produced by the aggregator the
-- same way stats_daily is, so the goals widget stays fast on long ranges.
--
-- `conversions` counts visits that converted (a visitor who reached the goal
-- page twice in one session converts once), `visitors` counts the distinct
-- daily pseudonyms behind them.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}goal_stats` (
  `site_id`     INT UNSIGNED NOT NULL,
  `date`        DATE         NOT NULL,
  `goal_id`     INT UNSIGNED NOT NULL,
  `conversions` INT UNSIGNED NOT NULL DEFAULT 0,
  `visitors`    INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`site_id`, `date`, `goal_id`),
  KEY `idx_goal_stats_goal` (`goal_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Key/value application settings (schema version, cron secret, defaults...).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}settings` (
  `name`       VARCHAR(120) NOT NULL,
  `value`      TEXT         DEFAULT NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Failed/successful login audit, used for brute-force lockout.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}login_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash`    CHAR(32)     NOT NULL,
  `email`      VARCHAR(190) NOT NULL DEFAULT '',
  `successful` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_ip` (`ip_hash`, `created_at`),
  KEY `idx_login_email` (`email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Human-readable trail of administrative actions.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `{{PREFIX}}audit_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(60)  NOT NULL,
  `entity`     VARCHAR(40)  NOT NULL DEFAULT '',
  `entity_id`  VARCHAR(64)  NOT NULL DEFAULT '',
  `meta`       TEXT         DEFAULT NULL,
  `ip_hash`    CHAR(32)     NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
