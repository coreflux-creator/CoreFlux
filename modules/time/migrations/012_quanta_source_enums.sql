-- Core migrations can run before the time module creates time_entries.
-- Append Quanta to legacy enums after the module schema exists. Preserve all
-- existing enum values, nullability, defaults, collation and column comments.
SET @sql := COALESCE((
    SELECT CONCAT('ALTER TABLE time_entries MODIFY COLUMN source ',
        LEFT(COLUMN_TYPE, CHAR_LENGTH(COLUMN_TYPE)-1), ',''quanta'')',
        ' CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME,
        IF(IS_NULLABLE='YES',' NULL',' NOT NULL'),
        CASE WHEN COLUMN_DEFAULT IS NULL THEN ''
             WHEN IS_NULLABLE='YES' AND COLUMN_DEFAULT='NULL' THEN ' DEFAULT NULL'
             WHEN LEFT(COLUMN_DEFAULT,1)=CHAR(39) AND RIGHT(COLUMN_DEFAULT,1)=CHAR(39)
                  THEN CONCAT(' DEFAULT ',COLUMN_DEFAULT)
             ELSE CONCAT(' DEFAULT ',QUOTE(COLUMN_DEFAULT)) END,
        ' COMMENT ',QUOTE(COLUMN_COMMENT))
    FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='time_entries'
      AND column_name='source' AND DATA_TYPE='enum'
      AND LOCATE('''quanta''',COLUMN_TYPE)=0
), 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := COALESCE((
    SELECT CONCAT('ALTER TABLE time_entries MODIFY COLUMN source_system ',
        LEFT(COLUMN_TYPE, CHAR_LENGTH(COLUMN_TYPE)-1), ',''quanta'')',
        ' CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME,
        IF(IS_NULLABLE='YES',' NULL',' NOT NULL'),
        CASE WHEN COLUMN_DEFAULT IS NULL THEN ''
             WHEN IS_NULLABLE='YES' AND COLUMN_DEFAULT='NULL' THEN ' DEFAULT NULL'
             WHEN LEFT(COLUMN_DEFAULT,1)=CHAR(39) AND RIGHT(COLUMN_DEFAULT,1)=CHAR(39)
                  THEN CONCAT(' DEFAULT ',COLUMN_DEFAULT)
             ELSE CONCAT(' DEFAULT ',QUOTE(COLUMN_DEFAULT)) END,
        ' COMMENT ',QUOTE(COLUMN_COMMENT))
    FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='time_entries'
      AND column_name='source_system' AND DATA_TYPE='enum'
      AND LOCATE('''quanta''',COLUMN_TYPE)=0
), 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

