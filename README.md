## Preparation

You need to provide a database to use with the connector, this is used to store MD5 file checksums to prevent duplicate offloads, export jobs with their status and download URL's with their expiry date.
Just one table must be present in this database, called 'resource', according to the following schema:
```
CREATE TABLE `resource` (
    `import_timestamp` TIMESTAMP NOT NULL,
    `resource_id` INT UNSIGNED NOT NULL,
    `object_id` INT UNSIGNED NOT NULL,
    `inventory_number` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    PRIMARY KEY(resource_id)
);
```
