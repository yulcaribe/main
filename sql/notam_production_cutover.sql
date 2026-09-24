-- YulCaribe FAA NMS: staging -> production clean cutover
-- Run this once in phpMyAdmin BEFORE switching data.php to production.
-- This touches only FAA NMS rows. Navdata and other project tables are not affected.

START TRANSACTION;

DELETE FROM notams
WHERE source = 'FAA_NMS'
  AND environment = 'staging';

DELETE FROM notam_sync_state
WHERE source = 'FAA_NMS'
  AND environment = 'staging';

COMMIT;

-- Verification
SELECT environment, COUNT(*) AS notam_count
FROM notams
WHERE source = 'FAA_NMS'
GROUP BY environment
ORDER BY environment;

SELECT source, environment, last_successful_sync, last_full_load, last_error
FROM notam_sync_state
WHERE source = 'FAA_NMS'
ORDER BY environment;
