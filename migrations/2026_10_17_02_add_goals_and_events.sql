-- ============================================================================
--  Migration: goals (conversions) and custom events
--
--  Adds three tables: `goals` (what counts as a conversion), `events` (raw
--  custom events sent by the tracker) and `goal_stats` (the daily rollup the
--  dashboard reads).
--
--  Existing installations keep their data: nothing is altered or dropped.
--  The {{PREFIX}} token is replaced with the configured table prefix.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `{{PREFIX}}goals` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id`     INT UNSIGNED NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `type`        ENUM('page','event') NOT NULL DEFAULT 'page',
  `match_value` VARCHAR(191) NOT NULL DEFAULT '',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_goals_site` (`site_id`, `is_active`),
  CONSTRAINT `fk_goals_site` FOREIGN KEY (`site_id`) REFERENCES `{{PREFIX}}sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
