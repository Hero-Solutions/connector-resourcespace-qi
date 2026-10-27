## Preparation

You need to provide a database to use with the connector, according to the following schema:
```
CREATE TABLE `qi_object` (
    `object_id` INT NOT NULL,
    `metadata` LONGTEXT NOT NULL,
    PRIMARY KEY (`object_id`)
);
CREATE TABLE `resource` (
    `import_timestamp` TIMESTAMP NOT NULL,
    `resource_id` INT UNSIGNED NOT NULL,
    `object_id` INT UNSIGNED NOT NULL,
    `inventory_number` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `width` INT UNSIGNED NOT NULL DEFAULT 0,
    `height` INT UNSIGNED NOT NULL DEFAULT 0,
    `filesize` INT UNSIGNED NOT NULL DEFAULT 0,
    `linked` TINYINT UNSIGNED NOT NULL DEFAULT 0
);
CREATE TABLE `unlinked_resource` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `import_timestamp` TIMESTAMP NOT NULL,
    `resource_id` INT UNSIGNED NOT NULL,
    `object_id` INT UNSIGNED NOT NULL,
    `inventory_number` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `width` INT UNSIGNED NOT NULL DEFAULT 0,
    `height` INT UNSIGNED NOT NULL DEFAULT 0,
    `filesize` INT UNSIGNED NOT NULL DEFAULT 0,
    `linked` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY(id)
);
```

## Full Qi processing

A full processing run retrieves all Qi objects into a `qi_object_staging` table. The existing `qi_object` cache remains active while the data is being retrieved. Pages are requested until Qi returns fewer than 500 records. The connector then swaps both tables with one atomic MySQL `RENAME TABLE` statement. An empty result, a repeated full page without new object IDs, an HTTP error, invalid response or database error discards the staging table and leaves the existing cache untouched.

The database user running the connector therefore needs permission to create, drop and rename tables in addition to the regular read and write permissions. Connector processes are serialized through `/tmp/connector_process.lock`, so overlapping runs cannot modify the staging table concurrently.

Partial runs also save every freshly retrieved Qi object to `qi_object`. This includes individual object refreshes for recent imports, so their images do not disappear from the local view when the two-week refresh window expires. Full runs continue writing to staging until the complete cache is ready.

## Weekly schedule (operator only)

Do not schedule the full run at the same time as a partial run. Both use the same non-blocking process lock: whichever starts first runs, and the other exits immediately. The supplied schedule started both jobs on Sundays at 18:10.

For the current partial window, use a separate Sunday morning slot. Apply this crontab change manually on the host that owns the jobs; the times below use that host's cron timezone:

```cron
10 18-23,0-2 * * * /opt/connector/run.sh
*/5 * * * * php /opt/connector/bin/console app:place-images-in-ftp-folder >> /var/log/ftp_connector_log/log.log
10 6 * * 0 /opt/connector/run.sh full-processing
```

Only the full run moves, from Sunday 18:10 to Sunday 06:10. Successful full runs in the supplied logs took 9:09:55 and 10:14:50. Starting at 06:10 therefore leaves about 1 hour 45 minutes before the first evening partial, based on the longer observed run. The last preceding partial starts at 02:10. This is an observed scheduling margin, not a maximum runtime guarantee; retain the process lock so an unusually long run still prevents concurrent syncs.

The five-minute FTP-folder job can retain its existing schedule. It moves queued files and is separate from `app:process`. Moving the full run avoids the deterministic start collision, but does not fix Qi HTTP failures: the full attempt on 18 October 2026 failed after three HTTP 500 responses. Check that each weekly full run logs `Replacing the Qi cache` and completes successfully. If it fails, an operator should arrange a separate retry outside the partial window rather than waiting another week or starting repeated overlapping retries.

## Import safety

A ResourceSpace resource that was successfully linked to a Qi object is never automatically imported or linked again to that same object. The identity is the pair of ResourceSpace resource ID and Qi object ID, not the individual Qi media ID.

- Both active `resource` registrations and historical `unlinked_resource` rows with `linked > 0` protect the pair. Archived attempts with `linked = 0` do not establish a successful link.
- Missing images or RS links in a Qi response produce a warning. The connector retains the successful registration instead of deleting it and making another import possible.
- Images that still have the correct RS link continue receiving metadata updates. Removing duplicate image associations while keeping one correctly linked image does not require changing the connector database.
- An existing correct RS link without an import registration is recorded when observed, so it is protected in future runs as well.
- Pending first imports remain reserved. For up to two weeks the connector can finish their initial link. After that, or when successful history already exists for the pair, it reports manual review instead of reindexing, relinking or automatically uploading another copy.
- A first import or first filename-based link remains possible for a pair without an active registration or successful history. History on another Qi object does not block a new pair.

This uses the existing tables and does not require a schema migration or additional Qi API requests. Preserve the active registrations and historical archive when cleaning up duplicates. An intentional reimport of a previously linked pair requires a separate, explicit operator action; the connector has no automatic reset.

### Applying the change and cleaning up (operator only)

Apply the updated `src/Command/ProcessCommand.php` and `src/Qi/Qi.php` before cleaning up Qi. Coordinate the change with running connector jobs. Files already queued in the FTP or temporary import folder by the previous code can still be ingested by Qi; an operator must inspect and resolve those queues as needed. This change does not remove queued files or existing Qi duplicates.

Then remove the extra image associations from each Qi object, leaving one image with the correct RS link per resource. Merely clearing the Link DAMS field is not the same as removing an extra image association. Either an older or newer image can be kept; compare its quality and metadata before choosing. Keep the corresponding rows in `resource` and `unlinked_resource` intact.

All production deployment, queue inspection and Qi cleanup are manual operator work. Do not run the connector or inspect live services as part of local validation.

### Offline regression tests

The standalone tests use in-memory database and HTTP fixtures. They load source classes directly and do not load Composer, Symfony, application configuration, environment files or real clients.

```sh
php -n -d allow_url_fopen=0 -d disable_functions=curl_init,curl_exec,fsockopen,pfsockopen,stream_socket_client,exec,shell_exec,system,passthru,proc_open,popen,mail tests/import_safety.php
```
