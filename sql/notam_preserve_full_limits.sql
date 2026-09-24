-- FAA NMS NOTAM schema correction
-- Preserve complete FAA limit text. No truncation.
-- Safe to run with existing NOTAM rows.

ALTER TABLE notams
    MODIFY lower_limit TEXT NULL,
    MODIFY upper_limit TEXT NULL;

UPDATE notams
SET classification = CASE UPPER(classification)
    WHEN 'INTL' THEN 'INTERNATIONAL'
    WHEN 'DOM' THEN 'DOMESTIC'
    WHEN 'MIL' THEN 'MILITARY'
    WHEN 'LMIL' THEN 'LOCAL_MILITARY'
    WHEN 'LOCAL_MIL' THEN 'LOCAL_MILITARY'
    ELSE classification
END
WHERE source = 'FAA_NMS'
  AND UPPER(classification) IN ('INTL','DOM','MIL','LMIL','LOCAL_MIL');

SELECT
    COLUMN_NAME,
    COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'notams'
  AND COLUMN_NAME IN ('lower_limit','upper_limit');

SELECT classification, COUNT(*) AS total
FROM notams
WHERE source = 'FAA_NMS'
GROUP BY classification
ORDER BY total DESC;
