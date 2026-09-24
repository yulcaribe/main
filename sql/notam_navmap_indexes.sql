-- YulCaribe NavMap / FAA NMS read-path indexes
-- MariaDB 10.11.x
-- Safe to run repeatedly: IF NOT EXISTS prevents duplicate index creation.
-- This migration changes only indexes; FAA NOTAM payload columns are not truncated or rewritten.

CREATE INDEX IF NOT EXISTS idx_notams_map_time
    ON notams (source, environment, effective_start, effective_end);

CREATE INDEX IF NOT EXISTS idx_notams_map_icao
    ON notams (source, environment, icao_location);

CREATE INDEX IF NOT EXISTS idx_notams_map_location
    ON notams (source, environment, location);

CREATE INDEX IF NOT EXISTS idx_notams_map_last_updated
    ON notams (source, environment, last_updated);
