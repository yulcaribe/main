# YulCaribe API v1

This directory is the canonical public read API for YulCaribe web clients and
mobile applications. New clients should not depend on legacy files directly
under `/main/api/`.

## Rules

- Web and Android use the same versioned API contracts.
- FAA NMS credentials, DB credentials, sync jobs and admin actions are never
  exposed through this directory.
- Data ingestion stays separate from data delivery. NMS sync writes to the
  local database; `notam.php` reads that normalized store.
- Breaking response changes require a new API version. Keep v1 compatible with
  already released mobile applications.
- Large resources are bounded by pagination, viewport and server-side limits.

## Resources

### NOTAM

`GET /main/api/v1/notam.php?action=list`

Default: NOTAMs valid at the selected UTC time.

Supported list parameters:

- `at`: ISO UTC date/time. Defaults to now.
- `state`: `valid`, `future`, `expired`, `cancelled`, `all`.
- `icao`: one or more comma-separated ICAO locations.
- `fir`: one or more comma-separated FIR identifiers.
- `type`, `classification`, `scope`, `traffic`.
- `purpose`, `selection_code`.
- `min_fl`, `max_fl`.
- `q`: free-text search across NOTAM text, selection code, location, FIR and
  NOTAM identifier.
- `page`, `limit` (maximum 200).
- `sort`: `updated_desc`, `start_desc`, `start_asc`, `ident`.
- `include_text=0`: omit full NOTAM text for lighter list payloads.
- `include_geometry=1`: include stored FAA geometry in list results.

Other NOTAM actions:

- `action=detail&id=<nms_id>`
- `action=filters`
- `action=map&west=...&south=...&east=...&north=...&z=...&at=...`

Historical playback uses effective times plus stored NOTAMC references. Expired
and cancelled history is retained for three days.

### Charts / NavMap

`GET /main/api/v1/chart.php?action=viewport&west=...&south=...&east=...&north=...&z=...&layers=airport,navaid,airway,airspace`

`GET /main/api/v1/chart.php?action=search&q=LTAI`

The chart API never returns NOTAM layers. NOTAMs are owned by `notam.php`.

### Airports

- `GET /main/api/v1/airports.php?action=search&q=AYT`
- `GET /main/api/v1/airports.php?action=detail&ident=LTAI`
- `GET /main/api/v1/airports.php?action=near&lat=36.90&lon=30.80&delta=1`

Search covers ICAO, IATA, airport name and city.

### METAR / TAF

- `GET /main/api/v1/metar.php?icao=LTAI`
- `GET /main/api/v1/taf.php?icao=LTAI`
- `GET /main/api/v1/weather.php?icao=LTAI` returns both products for clients that prefer one request.

METAR and TAF share one internal AWC transport/cache implementation; client-specific
endpoints do not duplicate upstream logic.

### Other stable v1 entry points

- `/main/api/v1/navdata.php`
- `/main/api/v1/weather.php`
- `/main/api/v1/wafs.php`
- `/main/api/v1/flights.php`
- `/main/api/v1/briefing.php`
- `/main/api/v1/enroute.php`
- `/main/api/v1/modelwx.php`

Some of these still delegate to the existing proven backend during migration.
That is intentional: clients move to the stable v1 contract first, then backend
internals can be reorganized without breaking the web site or released apps.
