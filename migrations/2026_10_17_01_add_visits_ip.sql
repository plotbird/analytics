-- ============================================================================
--  Migration: keep visitor IP addresses (optional feature)
--
--  Adds visits.ip, which is filled only when the administrator turns on
--  "Store visitor IP addresses" in the settings. The column exists either way
--  so that the schema does not have to change when the switch is flipped.
--
--  The {{PREFIX}} token is replaced with the configured table prefix.
-- ============================================================================

ALTER TABLE `{{PREFIX}}visits`
    ADD COLUMN `ip` VARCHAR(45) NOT NULL DEFAULT '' AFTER `language`;
