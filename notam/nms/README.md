# FAA NMS internals

This directory owns FAA NOTAM ingestion and maintenance.

- `cron.php`: production worker, CLI only.
- `sync.php`: manual CLI sync utility.
- `admin.php`: protected web maintenance endpoint used by NOTAM Health.
- `internal/`: implementation files; direct HTTP access is denied.

cPanel production cron:

```
*/5 * * * * php -q /home/yulcari1/public_html/main/notam/nms/cron.php >/dev/null 2>&1
```

Public clients must use `/main/api/v1/notam.php`; they must never call NMS
ingestion files directly.
