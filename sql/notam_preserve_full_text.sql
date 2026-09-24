-- FAA NMS NOTAM full-text preservation
-- Preserve the complete FAA NOTAM text without truncation.
-- Safe to run with existing rows; no NOTAM rows are deleted.

ALTER TABLE notams
    MODIFY notam_text LONGTEXT NULL;

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'notams'
  AND COLUMN_NAME IN ('notam_text','schedule','coordinates_raw','raw_json','lower_limit','upper_limit')
ORDER BY ORDINAL_POSITION;
