-- People who were deliberately removed from stat pay are not offered again.
-- Safe to run more than once.
-- decided_by is left NULL: the removal was confirmed by Abdul (superadmin)
-- and that user id is not fixed in this migration.

SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME='stat_holiday_exclusions')=0,
  'CREATE TABLE stat_holiday_exclusions (
      id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      org_id INT NOT NULL,
      user_id INT NOT NULL,
      site_id INT NOT NULL,
      holiday_date DATE NOT NULL,
      reason VARCHAR(255) NOT NULL,
      note VARCHAR(255) NULL,
      decided_by INT NULL,
      decided_at DATETIME NULL,
      UNIQUE KEY uq_stat_excl (org_id, user_id, site_id, holiday_date)
   )', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO stat_holiday_exclusions (org_id, user_id, site_id, holiday_date, reason, note, decided_by, decided_at)
SELECT 4, 10766, 121, '2026-10-12',
       'Removed: no shift after Sep 18 (AA confirmed)',
       'Confirmed by Abdul (superadmin). User id was not stored.',
       NULL, NOW()
 WHERE NOT EXISTS (
   SELECT 1 FROM stat_holiday_exclusions
    WHERE org_id=4 AND user_id=10766 AND site_id=121 AND holiday_date='2026-10-12'
 );
