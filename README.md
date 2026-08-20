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
