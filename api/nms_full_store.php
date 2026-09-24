<?php
declare(strict_types=1);

function nmsFullUpsert(PDO $pdo, array $r): void {
    static $stmt = null;
    if (!$stmt instanceof PDOStatement) {
        $stmt = $pdo->prepare(
            'INSERT INTO notams (
                nms_id, series, number, year, notam_type, classification,
                affected_fir, location, icao_location, account_id,
                selection_code, traffic, purpose, scope,
                minimum_fl, maximum_fl,
                effective_start, effective_end, effective_end_raw,
                estimated, schedule, lower_limit, upper_limit,
                coordinates_raw, radius_nm, notam_text, last_updated,
                status, raw_json, source, environment
            ) VALUES (
                :nms_id, :series, :number, :year, :notam_type, :classification,
                :affected_fir, :location, :icao_location, :account_id,
                :selection_code, :traffic, :purpose, :scope,
                :minimum_fl, :maximum_fl,
                :effective_start, :effective_end, :effective_end_raw,
                :estimated, :schedule, :lower_limit, :upper_limit,
                :coordinates_raw, :radius_nm, :notam_text, :last_updated,
                :status, NULL, :source, :environment
            )
            ON DUPLICATE KEY UPDATE
                series = COALESCE(VALUES(series), series),
                number = COALESCE(VALUES(number), number),
                year = COALESCE(VALUES(year), year),
                notam_type = COALESCE(VALUES(notam_type), notam_type),
                classification = COALESCE(VALUES(classification), classification),
                affected_fir = COALESCE(VALUES(affected_fir), affected_fir),
                location = COALESCE(VALUES(location), location),
                icao_location = COALESCE(VALUES(icao_location), icao_location),
                account_id = COALESCE(VALUES(account_id), account_id),
                selection_code = COALESCE(VALUES(selection_code), selection_code),
                traffic = COALESCE(VALUES(traffic), traffic),
                purpose = COALESCE(VALUES(purpose), purpose),
                scope = COALESCE(VALUES(scope), scope),
                minimum_fl = COALESCE(VALUES(minimum_fl), minimum_fl),
                maximum_fl = COALESCE(VALUES(maximum_fl), maximum_fl),
                effective_start = COALESCE(VALUES(effective_start), effective_start),
                effective_end = COALESCE(VALUES(effective_end), effective_end),
                effective_end_raw = COALESCE(VALUES(effective_end_raw), effective_end_raw),
                estimated = COALESCE(VALUES(estimated), estimated),
                schedule = COALESCE(VALUES(schedule), schedule),
                lower_limit = COALESCE(VALUES(lower_limit), lower_limit),
                upper_limit = COALESCE(VALUES(upper_limit), upper_limit),
                coordinates_raw = COALESCE(VALUES(coordinates_raw), coordinates_raw),
                radius_nm = COALESCE(VALUES(radius_nm), radius_nm),
                notam_text = COALESCE(VALUES(notam_text), notam_text),
                last_updated = COALESCE(VALUES(last_updated), last_updated),
                status = VALUES(status),
                source = VALUES(source),
                environment = VALUES(environment)'
        );
    }
    $stmt->execute($r);
}
