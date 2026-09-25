# Pilot Briefing model weather — 21 September 2026

All code stays in `yulcaribe/main`: PHP proxy/cache → small NOAA GRIB subset → same-origin browser decoder → route samples → Leaflet. No new service, commercial feed, or credential was added.

## Verified source availability

- NOAA Service Change Notice 23-111 withdrew `gfs.tCCz.awf_0p25.f0FF.grib2` from NOMADS/FTPPRD effective 17 January 2024. It directs users to WIFS. The previous assumption that changing `wafs_0p25` to `awf_0p25` would restore a current public feed was incorrect.
- Current NOMADS directory `gfs.20260921/00/atmos/` returned HTTP 200 with no `awf` or `wafs` entries. AWS ListObjects for `gfs.20260921/00/atmos/gfs.t00z.awf` returned HTTP 200, `KeyCount=0`.
- AWS `gfs.t00z.awf_0p25.f012.grib2` and its `.idx` returned HTTP 404. There is no current AWF inventory or EDPARM record to range-download/decode from these paths. Alternate sidecar suffixes cannot restore a withdrawn product.
- AWS `wafsgfs40.t00z.gribf12.grib2.idx` and `wafsgfs44.t00z.gribf12.grib2.idx` returned HTTP 404. No efficient current public legacy source was verified. `REFERENCE_UNAVAILABLE` describes this integration state; it does not claim all WAFS 1.25 products everywhere have ceased to exist.
- GFS NOMADS filter returned HTTP 200: a 67,823-byte subset with HGT, TMP, RH, UGRD, VGRD at 250 hPa for the 00Z run, +12 h, 21 September 2026; bbox 10–35°E, 33–56°N. Each record has 101 × 93 = 9,393 grid values.

References:

- [NOAA SCN 23-111](https://www.weather.gov/media/notification/pdf_2023_24/scn23-111_wafs_products_change.pdf)
- [NOMADS current GFS directory](https://nomads.ncep.noaa.gov/pub/data/nccf/com/gfs/prod/)
- [NOAA GFS parameter inventory](https://www.nco.ncep.noaa.gov/pmb/products/gfs/gfs.t00z.pgrb2.0p25.f003.shtml)
- [WIFS access and products](https://aviationweather.gov/wifs/)
- [GRIB2 scanning-mode specification](https://www.nco.ncep.noaa.gov/pmb/docs/grib2/grib2_doc/grib2_table3-4.shtml)

## Changes and why they matter

1. Removed speculative AWF/legacy mirror probing. `aviation025` and compatibility `wafs025` return HTTP 410 / `PUBLIC_FEED_RETIRED`; `wafs125` returns HTTP 503 / `REFERENCE_UNAVAILABLE` immediately. GFS rendering no longer waits for either source. Icing, EDR/CAT/MWT and CB remain N/A, never zero or inferred from humidity.
2. Corrected vendor flag-table decoding: WMO bit 1 is the most significant bit. The old interpretation of scan=64 introduced an erroneous half-cell latitude shift. Grid values now normalize to north-west row-major order. Null/missing values remain missing; constant simple-packed fields and bitmap value counts are handled correctly. Unsupported/truncated messages fail explicitly.
3. Parameter identification uses GRIB discipline/category/number. RH (0/1/1) and HGT (0/3/5) do not depend on incomplete vendor name tables. Each field is sampled using its own grid and all selected pressure levels must agree.
4. Samples follow cumulative great-circle segment distance, not waypoint list index. Target spacing is 25 NM, capped at 241 points; the actual spacing is displayed. LTAI–EDDB yields 49 samples. Five summary rows remain in the table.
5. Added a GFS map layer with sparse wind arrows and clickable sample points. Arrow = flow direction; popup/table direction = meteorological FROM direction, true degrees. Head/tail and crosswind are vector projections on the local route track. Clicking a table row opens that point on the map.
6. Exposed actual model valid time, run/forecast hour, selected pressure, estimated passage times, sample spacing, and a compact diagnostic record inventory. HGT is geopotential height in gpm, not aircraft flight level or terrain clearance.
7. GFS forecast-hour selection uses the available hourly fields through +120 h rather than unnecessary 3-hour rounding. Backend total network budget is 13 seconds; browser request limit remains 15 seconds. Obsolete requests are aborted and stale markers cleared.
8. API now exposes independent METAR/TAF/SIGMET source states. SIGMET upstream failure/malformed response is distinct from a successful empty collection. UI no longer says `CLEAR`.
9. SIGMET warnings outside the full flight time window are excluded from the main summary and default map layer, retained as labeled cards and an optional layer. Unknown time remains explicit. Corrected the pre-existing `BTN FL… AND FL…` regex delimiter and compact `FL200/400` parsing. `TOP ABV FL…` is not treated as an exact ceiling; a known top with unknown base does not prove cruise is inside the layer.

## Validation

`node --test tests/modelwx.test.cjs`

`php tests/briefing.test.php`

The committed real NOAA fixture includes its exact source URL and independent ECMWF ecCodes results. Five parameters × five locations match both decoded values and nearest-point coordinates within floating-point tolerance. Additional tiny ecCodes-generated fixtures cover north/south and east/west scanning and a constant field. Regression checks cover malformed GRIB, missing values, route spacing, dateline sample interpolation, vector wind signs and SIGMET vertical/time cases.

Live upstream availability is a separate check from decoding correctness. Network timeouts during any environment's live PHP fetch must remain visible; they must not be masked with the committed fixture. Test fixtures are never used by production code.

## What this product can and cannot currently tell a user

Useful now: terminal reports/forecasts, route-related published warnings, and a sourced overview of modeled upper wind/temperature/humidity/height. Correct GRIB decoding is not proof that a weather forecast will occur.

Limitations:

- One model valid time (near the route midpoint) is sampled along the whole route. Passage times use the existing rough 450 kt EET assumption, not an OFP schedule or wind-corrected performance calculation.
- FL360 maps to the nearest primary GFS pressure level, 250 hPa; no vertical interpolation is performed. It is not an exact FL360 forecast. The same cruise approximation is shown at route endpoints; climb/descent are not modeled.
- Nearest grid-point sampling is used, not spatial interpolation. More samples improve route coverage but do not increase GFS resolution or resolve every local phenomenon.
- SIGMET time relevance uses the whole flight window, not a precise entry/exit time for each polygon. Missing warnings are not evidence of safe weather. Movement/extrapolation and global dateline geometry require further work.
- Partial/unresolved OFP fixes, current public AWC coverage and issue times must be inspected. An airport METAR describes that location, not conditions at cruise altitude.
- No operational EDR, icing severity or CB hazard grid is connected. RH, temperature, CAPE or wind shear must not be relabeled as official icing/turbulence guidance.
- A legacy WAFS–GFS comparison is not an independent accuracy score; products can share model ancestry. It is lower priority than time/level matching.

## Highest-value next steps

1. Preserve actual OFP waypoint coordinates and passage times; show unresolved portions. Match each route segment to its expected time, including SIGMET entry/exit intervals and terminal TAF periods at ETD/ETA.
2. Interpolate wind components and temperature between bracketing pressure levels and model times. Test against an independent decoder/reference; expose the source levels and interpolation method. Add a route cross-section before adding decorative weather animations.
3. Connect an authorized WIFS/SADIS or other documented aviation-hazard provider; validate its units, levels, missing flags, timestamps and license. Keep official WAFS distinct from GFS guidance.
4. Add properly sourced regional radar/satellite or convective context only with coverage and timestamp labels. These are not substitutes for cruise icing/turbulence forecasts.

No ROUTE SAFE, GO/NO-GO, or recommended flight level is produced.
