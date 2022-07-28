## Preparation

You need to provide a database to use with the connector.
Just one table must be present in this database, called 'resource', according to the following schema:
```
CREATE TABLE `resource` (
    `import_timestamp` TIMESTAMP NOT NULL,
    `resource_id` INT UNSIGNED NOT NULL,
    `object_id` INT UNSIGNED NOT NULL,
    `inventory_number` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `width` INT UNSIGNED NOT NULL,
    `height` INT UNSIGNED NOT NULL,
    `filesize` INT UNSIGNED NOT NULL,
    PRIMARY KEY(resource_id)
);
```
