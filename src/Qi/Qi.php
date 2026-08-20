<?php

namespace App\Qi;

use App\Entity\Resource;
use App\ResourceSpace\ResourceSpace;
use App\Util\HttpUtil;
use DateTime;
use Doctrine\ORM\EntityManager;
use JsonException;
use JsonPath\InvalidJsonException;
use JsonPath\JsonObject;
use RuntimeException;
use Throwable;

class Qi
{
    private const CACHE_TABLE = 'qi_object';
    private const STAGING_TABLE = 'qi_object_staging';
    private const PREVIOUS_CACHE_TABLE = 'qi_object_previous';
    private const PAGE_SIZE = 500;
    private const MAX_PAGE_RETRIEVAL_ATTEMPTS = 3;
    private const MAX_SINGLE_OBJECT_RETRIEVAL_ATTEMPTS = 1;
    private const PAGE_DELAY_SECONDS = 300;
    private const RETRY_DELAY_SECONDS = 60;

    private Entitymanager $entityManager;

    private string $baseUrl;
    private string $username;
    private string $password;
    private string $getFields;
    private array $creditConfig;
    private bool $test;
    private bool $debug;
    private bool $update;
    private bool $fullProcessing;
    private bool $onlyOnlineRecords;
    private array $unknownMappings = [];
    private HttpUtil $httpUtil;
    private int $maxFieldValueLength;

    private array $objectsByObjectId;
    private array $objectsByInventoryNumber;

    public function __construct($entityManager, $qi, $creditConfig, $test, $debug, $update, $fullProcessing, $onlyOnlineRecords, $httpUtil, $maxFieldValueLength)
    {
        $this->entityManager = $entityManager;

        $qiApi = $qi['api'];
        $this->baseUrl = $qiApi['url'];
        $this->username = $qiApi['username'];
        $this->password = $qiApi['password'];
        $this->getFields = $qi['get_fields'];

        $this->creditConfig = $creditConfig;

        $this->test = $test;
        $this->debug = $debug;
        $this->update = $update;
        $this->fullProcessing = $fullProcessing;
        $this->onlyOnlineRecords = $onlyOnlineRecords;

        $this->httpUtil = $httpUtil;
        $this->maxFieldValueLength = $maxFieldValueLength;
    }

    public function getObjectsByObjectId(): array
    {
        return $this->objectsByObjectId;
    }

    public function getObjectsByInventoryNumber(): array
    {
        return $this->objectsByInventoryNumber;
    }

    public function retrieveAllObjects($recordsUpdatedSince): void
    {
        $this->objectsByObjectId = [];
        $this->objectsByInventoryNumber = [];

        try {
            if($this->fullProcessing) {
                $this->prepareStagingTable();
            } else {
                $this->loadCachedObjects();
            }

            //Get all records of up to 1 week ago
            $time = strtotime('-' . $recordsUpdatedSince, time());
            $date = date("Y-m-d", $time);

            if($this->test) {
                $firstPageUrl = $this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields) . '/_offset/12929';
            } else {
                $firstPageUrl = $this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields);
                if(!$this->fullProcessing) {
                    $firstPageUrl .= '/_since/' . $date;
                }
            }

            $page = $this->retrieveObjectsPage($firstPageUrl);
            $seenObjectIds = [];
            $this->registerPageObjectIds($page, $seenObjectIds);
            $this->storeObjects($page, !$this->fullProcessing);
            $receivedPageSize = count($page->records);
            $offset = 0;

            while(!$this->test && $receivedPageSize >= self::PAGE_SIZE) {
                $offset += $receivedPageSize;
                $this->waitBeforeNextRequest();
                if($this->fullProcessing) {
                    $pageUrl = $this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields) . '/_offset/' . $offset;
                } else {
                    $pageUrl = $this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields) . '/_since/' . $date . '/_offset/' . $offset;
                }

                $page = $this->retrieveObjectsPage($pageUrl);
                $receivedPageSize = count($page->records);
                $registeredIds = $this->registerPageObjectIds($page, $seenObjectIds);
                if($receivedPageSize >= self::PAGE_SIZE) {
                    if($registeredIds['valid'] === 0) {
                        throw new RuntimeException(
                            'Qi returned a full page at offset ' . $offset
                            . ' without any usable object IDs; aborting pagination.'
                        );
                    }
                    if($registeredIds['new'] === 0) {
                        throw new RuntimeException(
                            'Qi returned a full page at offset ' . $offset
                            . ' without any new object IDs; aborting pagination.'
                        );
                    }
                }
                $this->storeObjects($page, !$this->fullProcessing);
            }

            if($this->fullProcessing) {
                $this->activateStagedCache();
                $this->loadCachedObjects();
            } else {

                $this->ping();

                //Retrieve all objects from Qi where resources were recently added or unlinked
                $twoWeeksAgo = new DateTime('-2 weeks');
                /* @var $importedResourcesObjects Resource[] */
                $importedResourcesObjects = $this->entityManager->createQueryBuilder()
                    ->select('r')
                    ->from(Resource::class, 'r')
                    ->where('r.importTimestamp > :twoWeeksAgo')
                    ->setParameter('twoWeeksAgo', $twoWeeksAgo)
                    ->getQuery()
                    ->getResult();
                $loadedObjects = [];
                foreach($importedResourcesObjects as $importedResource) {
                    if(!array_key_exists($importedResource->getObjectId(), $loadedObjects)) {
                        $objectUrl = $this->baseUrl . '/get/object/id/' . $importedResource->getObjectId() . '/_fields/' . urlencode($this->getFields);
                        try {
                            $objectsPage = $this->retrieveObjectsPage(
                                $objectUrl,
                                self::MAX_SINGLE_OBJECT_RETRIEVAL_ATTEMPTS
                            );
                            $this->storeObjects($objectsPage);
                        } catch(Throwable $exception) {
                            echo 'Could not retrieve Qi object ' . $importedResource->getObjectId()
                                . ': ' . $exception->getMessage() . PHP_EOL;
                        }
                        $loadedObjects[$importedResource->getObjectId()] = $importedResource->getObjectId();
                    }
                }
            }
        } catch(Throwable $exception) {
            if($this->fullProcessing) {
                $this->discardStagingTable();
            }
            throw $exception;
        }
    }

    private function prepareStagingTable(): void
    {
        $this->ping();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS ' . self::STAGING_TABLE);
        $connection->executeStatement('CREATE TABLE ' . self::STAGING_TABLE . ' LIKE ' . self::CACHE_TABLE);
    }

    private function discardStagingTable(): void
    {
        try {
            $this->ping();
            $this->entityManager->getConnection()->executeStatement('DROP TABLE IF EXISTS ' . self::STAGING_TABLE);
        } catch(Throwable $cleanupException) {
            echo 'Could not remove the incomplete Qi staging table: ' . $cleanupException->getMessage() . PHP_EOL;
        }
    }

    private function activateStagedCache(): void
    {
        $this->ping();
        $connection = $this->entityManager->getConnection();
        $currentCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . self::CACHE_TABLE);
        $stagedCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . self::STAGING_TABLE);
        if($stagedCount === 0) {
            throw new RuntimeException('Qi returned zero usable objects; refusing to replace the active cache.');
        }
        echo 'Replacing the Qi cache containing ' . $currentCount . ' objects with '
            . $stagedCount . ' staged objects.' . PHP_EOL;

        $connection->executeStatement('DROP TABLE IF EXISTS ' . self::PREVIOUS_CACHE_TABLE);
        $connection->executeStatement(
            'RENAME TABLE ' . self::CACHE_TABLE . ' TO ' . self::PREVIOUS_CACHE_TABLE . ', '
            . self::STAGING_TABLE . ' TO ' . self::CACHE_TABLE
        );

        try {
            $connection->executeStatement('DROP TABLE ' . self::PREVIOUS_CACHE_TABLE);
        } catch(Throwable $cleanupException) {
            echo 'The new Qi cache is active, but the previous cache table could not be removed: '
                . $cleanupException->getMessage() . PHP_EOL;
        }
    }

    private function retrieveObjectsPage(
        string $url,
        int $maxAttempts = self::MAX_PAGE_RETRIEVAL_ATTEMPTS
    ): object
    {
        $lastError = 'unknown error';

        for($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $objectsJson = $this->get($url);
            if($objectsJson === false) {
                $lastError = 'HTTP request failed';
            } else {
                try {
                    $objectsPage = json_decode($objectsJson, false, 512, JSON_THROW_ON_ERROR);
                } catch(JsonException $exception) {
                    $lastError = $exception->getMessage();
                    echo 'Invalid Qi response: ' . $lastError . PHP_EOL;
                    $objectsPage = null;
                }

                if($objectsPage !== null) {
                    $this->validateObjectsPageStructure($objectsPage);
                    return $objectsPage;
                }
            }

            if($attempt < $maxAttempts) {
                $this->waitBeforeRetry($attempt);
            }
        }

        throw new RuntimeException(
            'Could not retrieve a valid Qi page after ' . $maxAttempts
            . ' attempts (' . $lastError . ').'
        );
    }

    private function validateObjectsPageStructure(mixed $objectsPage): void
    {
        if(!is_object($objectsPage)) {
            throw new RuntimeException('The response is not a JSON object.');
        }
        if(!property_exists($objectsPage, 'records') || !is_array($objectsPage->records)) {
            throw new RuntimeException('The response has no records array.');
        }
    }

    private function getValidObjectId(mixed $record): ?int
    {
        if(!is_object($record) || !isset($record->id) || !is_numeric($record->id) || (int) $record->id <= 0) {
            return null;
        }
        return (int) $record->id;
    }

    private function registerPageObjectIds(object $objectsPage, array &$seenObjectIds): array
    {
        $validObjectIdCount = 0;
        $newObjectIdCount = 0;
        foreach($objectsPage->records as $record) {
            $objectId = $this->getValidObjectId($record);
            if($objectId === null) {
                continue;
            }

            $validObjectIdCount++;
            if(!array_key_exists($objectId, $seenObjectIds)) {
                $seenObjectIds[$objectId] = true;
                $newObjectIdCount++;
            }
        }
        return [
            'valid' => $validObjectIdCount,
            'new' => $newObjectIdCount
        ];
    }

    private function waitBeforeNextRequest(): void
    {
        echo 'Sleeping before the next Qi page' . PHP_EOL;
        sleep(self::PAGE_DELAY_SECONDS);
    }

    private function waitBeforeRetry(int $attempt): void
    {
        $delay = min(self::RETRY_DELAY_SECONDS * (2 ** ($attempt - 1)), self::PAGE_DELAY_SECONDS);
        echo 'Retrying the Qi request in ' . $delay . ' seconds' . PHP_EOL;
        sleep($delay);
    }

    private function loadCachedObjects(): void
    {
        // Use DBAL instead of Doctrine ORM here to avoid keeping all QiObject entities managed in memory.
        $result = $this->entityManager->getConnection()->executeQuery('SELECT metadata FROM ' . self::CACHE_TABLE);
        $invalidJsonCount = 0;
        $invalidObjectIdCount = 0;
        $firstJsonError = null;
        foreach($result->iterateAssociative() as $qiObject) {
            try {
                $record = json_decode($qiObject['metadata'], false, 512, JSON_THROW_ON_ERROR);
            } catch(JsonException $exception) {
                if($firstJsonError === null) {
                    $firstJsonError = $exception->getMessage();
                }
                $invalidJsonCount++;
                continue;
            }

            $objectId = $this->getValidObjectId($record);
            if($objectId === null) {
                $invalidObjectIdCount++;
                continue;
            }
            $record->id = $objectId;
            $this->extractRecord($record);
        }
        $result->free();
        if($invalidJsonCount > 0) {
            echo 'Skipped ' . $invalidJsonCount . ' cached Qi objects with invalid JSON. First error: '
                . $firstJsonError . PHP_EOL;
        }
        if($invalidObjectIdCount > 0) {
            echo 'Skipped ' . $invalidObjectIdCount . ' cached Qi objects without a valid object ID.' . PHP_EOL;
        }
    }

    private function storeObjects(object $objectsPage, bool $indexObjects = true): void
    {
        $skippedRecordCount = 0;
        foreach($objectsPage->records as $record) {
            $objectId = $this->getValidObjectId($record);
            if($objectId === null) {
                $skippedRecordCount++;
                continue;
            }
            $record->id = $objectId;

            if($indexObjects) {
                $this->extractRecord($record);
            }
            if($this->fullProcessing) {
                $this->storeStagedObject($record);
            }
        }
        if($skippedRecordCount > 0) {
            echo 'Skipped ' . $skippedRecordCount . ' Qi records without a valid object ID.' . PHP_EOL;
        }
    }

    private function extractRecord($record): void
    {
        if(!$this->onlyOnlineRecords || (string) ($record->online ?? '') === '1') {
            $this->objectsByObjectId[intval($record->id)] = $record;
            if(!empty($record->object_number)) {
                $this->objectsByInventoryNumber[$record->object_number] = $record;
            } else {
                echo 'Error: Qi record ' . $record->id . ' has no inventory number' . PHP_EOL;
            }
        }
    }

    private function storeStagedObject($record): void
    {
        $sql = 'INSERT INTO ' . self::STAGING_TABLE . ' (object_id, metadata) '
            . 'VALUES (:object_id, :metadata) '
            . 'ON DUPLICATE KEY UPDATE metadata = VALUES(metadata)';
        $parameters = [
            'object_id' => intval($record->id),
            'metadata' => json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)
        ];
        $connection = $this->entityManager->getConnection();

        try {
            $connection->executeStatement($sql, $parameters);
        } catch(Throwable $exception) {
            // Retrying is safe because the upsert is idempotent. This also recovers from an idle MySQL connection.
            echo 'Database write failed; reconnecting and retrying: ' . $exception->getMessage() . PHP_EOL;
            $connection->close();
            $connection->executeStatement($sql, $parameters);
        }
    }

    private function ping(): void
    {
        $connection = $this->entityManager->getConnection();
        try {
            $result = $connection->executeQuery('SELECT 1');
            $result->free();
        } catch(Throwable $exception) {
            echo 'Database ping failed; reconnecting: ' . $exception->getMessage() . PHP_EOL;
            $connection->close();
            $result = $connection->executeQuery('SELECT 1');
            $result->free();
        }
    }

    public function getMediaInfos($object, $qiImportMapping, $qiMappingToSelf): array
    {
        $mediaInfos = [];
        if(property_exists($object, 'media.image.id')) {
            $allMediaInfo = [];
            $i = 0;
            foreach ($object->{'media.image.id'} as $id) {
                $allMediaInfo[$i] = [
                    'id' => $id
                ];
                $i++;
            }

            $count = count($allMediaInfo);
            $fieldsToGet = [
                'link_dams' => '',
                'media_folder_id' => '',
                'filename' => '',
                'original_filename' => '',
                'width' => '',
                'height' => '',
                'filesize' => ''
            ];
            $qiCreditFieldPrefix = $this->creditConfig['qi_field_prefix'];
            $fieldsToGet[$qiCreditFieldPrefix] = '';
            foreach($this->creditConfig['languages'] as $language) {
                $fieldsToGet[$qiCreditFieldPrefix . '_' . $language] = '';
            }
            $fieldsToGet = array_merge($fieldsToGet, $qiImportMapping, $qiMappingToSelf);
            foreach ($fieldsToGet as $fieldName => $dummy) {
                if (property_exists($object, 'media.image.' . $fieldName) && count($object->{'media.image.' . $fieldName}) === $count) {
                    $i = 0;
                    foreach ($object->{'media.image.' . $fieldName} as $value) {
                        $allMediaInfo[$i][$fieldName] = $value;
                        $i++;
                    }
                }
            }
            for ($i = 0; $i < $count; $i++) {
                $mediaInfos[$allMediaInfo[$i]['id']] = $allMediaInfo[$i];
            }
        }

        // Update metadata using the "image" property (more reliable, but some images will be missing
        // due to the API not returning all images in the "image" property as compared to "media.image.*")
        if(property_exists($object, 'media')) {
            $media = $object->media;
            if(is_object($media)) {
                if (!property_exists($media, 'image')) {
                    return $mediaInfos;
                }
                $images = $media->image;
            } elseif(is_array($media)) {
                if(!array_key_exists('image', $media)) {
                    return $mediaInfos;
                } elseif(empty($media['image'])) {
                    return $mediaInfos;
                }
                $images = $media['image'];
            } else {
                return $mediaInfos;
            }

            foreach ($images as $mediaItem) {
                $mediaInfo = [
                    'id' => $mediaItem->{'id'} ?? null,
                    'link_dams' => $mediaItem->{'link_dams'} ?? null,
                    'media_folder_id' => $mediaItem->{'media_folder_id'} ?? null,
                    'filename' => $mediaItem->{'filename'} ?? null,
                    'original_filename' => $mediaItem->{'original_filename'} ?? null,
                    'width' => $mediaItem->{'width'} ?? null,
                    'height' => $mediaItem->{'height'} ?? null,
                    'filesize' => $mediaItem->{'filesize'} ?? null,
                ];

                $qiCreditFieldPrefix = $this->creditConfig['qi_field_prefix'];
                $mediaInfo[$qiCreditFieldPrefix] = $mediaItem->{$qiCreditFieldPrefix} ?? null;

                foreach ($this->creditConfig['languages'] as $language) {
                    $fieldKey = $qiCreditFieldPrefix . '_' . $language;
                    $mediaInfo[$fieldKey] = $mediaItem->{$fieldKey} ?? null;
                }

                foreach (array_merge($qiImportMapping, $qiMappingToSelf) as $fieldName => $dummy) {
                    $mediaInfo[$fieldName] = $mediaItem->{$fieldName} ?? null;
                }

                $id = $mediaInfo['id'];
                if($id !== null) {
                    //Add to array if it does not yet exist (unlikely)
                    if (!array_key_exists($id, $mediaInfos)) {
                        $mediaInfos[$id] = $mediaInfo;
                    } else {
                        //Update existing metadata as it is more reliable
                        foreach($mediaInfo as $key => $value) {
                            $mediaInfos[$id][$key] = $value;
                        }
                    }
                }
            }
        }

        return $mediaInfos;
    }

    public function getMatchingImageToBeLinked($images, $originalFilename, $width, $height, $filesize, $qiMediaFolderIds): array|null
    {
        $result = null;
        foreach($images as $id => $image) {
            if(array_key_exists('media_folder_id', $image)) {
                if(in_array($image['media_folder_id'], $qiMediaFolderIds)) {
                    if (array_key_exists('link_dams', $image)) {
                        if(empty($image['link_dams']) && array_key_exists('original_filename', $image)
                            && array_key_exists('width', $image) && array_key_exists('height', $image)
                            && array_key_exists('filesize', $image)) {
                            if ($image['original_filename'] === $originalFilename && intval($image['width']) === $width
                                && intval($image['height']) === $height && intval($image['filesize']) === $filesize) {
                                $result = $image;
                                break;
                            }
                        }
                    }
                }
            }
        }
        return $result;
    }

    public function updateMetadata($qiImage, $resource, $rsFields, $qiImportMapping, $qiLinkDamsPrefix, $addLinkDams, $reindexUrl): void
    {
        $resourceId = $resource['ref'];
        $record = [];

        // Translate ResourceSpace credit field and check if this needs updating in this Qi image
        if(array_key_exists($rsFields['credit'], $resource)) {
            $translatedCredits = $this->translateCredit($resource[$rsFields['credit']]);
            $translatedCredits[$this->creditConfig['qi_field_prefix']] = $resource[$rsFields['credit']];
            foreach($translatedCredits as $qiField => $credit) {
                $changed = false;
                if(array_key_exists($qiField, $qiImage)) {
                    if($qiImage[$qiField] !== $credit && !(empty($qiImage[$qiField]) && empty($credit))) {
                        $changed = true;
                    }
                } else {
                    $changed = true;
                }
                if($changed) {
                    $record[$qiField] = $credit;
                }
            }
        } else {
            foreach($this->creditConfig['languages'] as $language) {
                $key = $this->creditConfig['qi_field_prefix'] . '_' . $language;
                $changed = false;
                if(array_key_exists($key, $qiImage)) {
                    if(!empty($qiImage[$key])) {
                        $changed = true;
                    }
                }
                if($changed) {
                    $record[$key] = '';
                }
            }
        }

        // Loop through all other ResourceSpace fields and check if they need updating in this Qi image
        foreach($qiImportMapping as $qiPropertyName => $rsPropertyName) {
            if(array_key_exists($rsFields[$rsPropertyName], $resource)) {
                $changed = false;
                if(array_key_exists($qiPropertyName, $qiImage)) {
                    if($qiImage[$qiPropertyName] !== $resource[$rsFields[$rsPropertyName]]
                    && !(empty($qiImage[$qiPropertyName]) && empty($resource[$rsFields[$rsPropertyName]]))) {
                        $changed = true;
                    }
                } else {
                    $changed = true;
                }
                if($changed) {
                    $record[$qiPropertyName] = $resource[$rsFields[$rsPropertyName]];
                }
            } else if(array_key_exists($qiPropertyName, $qiImage)) {
                $record[$qiPropertyName] = '';
            }
        }
        if($addLinkDams) {
            $record['link_dams'] = $qiLinkDamsPrefix . $resourceId;
        }
        if(!empty($record)) {
            $data = [
                'id' => $qiImage['id'],
                'record' => $record
            ];
            self::putMetadata($data);
            $this->httpUtil->get($reindexUrl);
        }
    }

    public function updateResourceSpaceData($object, $resource, $resourceId, $rsFields, $rsImportMapping, $rsFullDataFields, $qiUrl, ResourceSpace $resourceSpace): void
    {
        try {
            $linkCms = $qiUrl . $object->id;
            $updateLinkCms = false;
            if(!array_key_exists($rsFields['linkcms'], $resource)) {
                $updateLinkCms = true;
            } else if($resource[$rsFields['linkcms']] !== $linkCms) {
                $updateLinkCms = true;
            }
            if($updateLinkCms) {
                $resourceSpace->updateField($resourceId, 'linkcms', $linkCms);
            }

            $jsonObject = new JsonObject($object);
            foreach ($rsImportMapping as $fieldName => $field) {
                $qiFieldData = $this->getFieldData($jsonObject, $fieldName, $field);
                if ($qiFieldData !== null) {
                    $fieldId = $rsFields[$fieldName];
                    $fetchFullData = false;
                    if(array_key_exists($fieldId, $resource)) {
                        if(!empty($resource[$fieldId]) && strlen($resource[$fieldId]) >= 180) {
                            $fetchFullData = true;
                        }
                    }
                    if($fetchFullData || array_key_exists($fieldName, $rsFullDataFields)) {
                        $fullRSData = $resourceSpace->getResourceData($resourceId);
                        if(array_key_exists($fieldName, $fullRSData)) {
                            $resource[$fieldId] = $fullRSData[$fieldName];
                        }
                    }
                    if(array_key_exists('overwrite', $field) && array_key_exists($fieldId, $resource)) {
                        if($field['overwrite'] === 'no') {
                            if (!empty($resource[$fieldId])) {
                                if ($this->debug) {
                                    echo 'Not overwriting field ' . $fieldName . ' for res ' . $qiFieldData . ' (already has ' . $resource[$fieldId] . ')' . PHP_EOL;
                                }
                                $qiFieldData = null;
                            }
                        } else if($field['overwrite'] === 'merge') {
                            if (!empty($resource[$fieldId])) {
                                if (strpos($resource[$fieldId], $qiFieldData) === false) {
                                    if ($this->debug) {
                                        echo 'Merging field ' . $fieldName . ' for res ' . $qiFieldData . ' (already has ' . $resource[$fieldId] . ')' . PHP_EOL;
                                    }
                                    $qiFieldData = $resource[$fieldId] . '\n\n' . $qiFieldData;
                                } else {
                                    if ($this->debug) {
                                        echo 'Not merging field ' . $fieldName . ' for res ' . $qiFieldData . ' (already has ' . $resource[$fieldId] . ')' . PHP_EOL;
                                    }
                                    $qiFieldData = null;
                                }
                            }
                        }
                    }
                    if($qiFieldData !== null) {
                        if(strlen($qiFieldData) > $this->maxFieldValueLength) {
                            $qiFieldData = substr($qiFieldData, 0, $this->maxFieldValueLength);
                        }
                        $update = true;
                        if(array_key_exists($fieldId, $resource)) {
                            if($resource[$fieldId] === $qiFieldData || empty($resource[$fieldId]) && empty($qiFieldData)) {
                                $update = false;
                            } else {
                                // Mostly for keywords and date ranges, check if both fields contain the same comma-separated values (but in a different order)
                                $oldRSDataSplit = empty($resource[$fieldId]) ? [] : explode(',', $resource[$fieldId]);
                                $newQiDataSplit = explode(',', $qiFieldData);
                                if(array_key_exists('type', $field)) {
                                    //Date ranges need to be YYYY-MM-DD/YYYY-MM-DD when passed to the ResourceSpace API,
                                    //but are returned as YYYY-MM-DD, YYYY-MM-DD when fetched from the ResourceSpace API.
                                    //Furthermore, the dates returned from the ResourceSpace API may be in reversed order
                                    if($field['type'] === 'date_range') {
                                        $newQiDataSplit = explode('/', $qiFieldData);
                                    }
                                }
                                if(count($oldRSDataSplit) === count($newQiDataSplit)) {
                                    $update = false;
                                    $oldRSDataSplitTrimmed = [];
                                    $newQiDataSpitTrimmed = [];
                                    foreach($oldRSDataSplit as $val) {
                                        $oldRSDataSplitTrimmed[] = trim($val);
                                    }
                                    foreach($newQiDataSplit as $val) {
                                        $newQiDataSpitTrimmed[] = trim($val);
                                    }
                                    foreach($oldRSDataSplitTrimmed as $val) {
                                        if(!in_array($val, $newQiDataSpitTrimmed)) {
                                            $update = true;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        if($update) {
                            $isNodeValue = false;
                            if (array_key_exists('node_value', $field)) {
                                if ($field['node_value'] === 'yes') {
                                    $isNodeValue = true;
                                }
                            }
                            if (!$this->test) {
                                $resourceSpace->updateField($resourceId, $fieldName, $qiFieldData, $isNodeValue);
                            }
                        }
                    }
                }
            }
        } catch (InvalidJsonException $e) {
            echo 'JSONPath error: ' . $e->getMessage() . PHP_EOL;
        }
    }

    private function translateCredit($credit): array
    {
        $split = [
            $credit
        ];
        foreach($this->creditConfig['split_chars'] as $splitChar) {
            $newSplit = [];
            foreach($split as $item) {
                $splitItem = empty($item) ? [] : explode($splitChar, $item);
                $count = count($splitItem);
                for($i = 0; $i < $count; $i++) {
                    $newSplit[] = $splitItem[$i];
                    if($i < $count - 1) {
                        $newSplit[] = $splitChar;
                    }
                }
            }
            $split = $newSplit;
        }
        $translatedCredit = [];
        $qiFieldPrefix = $this->creditConfig['qi_field_prefix'] . '_';
        foreach($this->creditConfig['languages'] as $language) {
            $translatedCredit[$qiFieldPrefix . $language] = '';
        }
        foreach($split as $item) {
            $trimmedItem = trim($item);
            $match = null;
            foreach($this->creditConfig['translations'] as $nlValue => $translations) {
                if($nlValue === $trimmedItem) {
                    $match = $translations;
                    break;
                }
            }
            if($match === null) {
                foreach($this->creditConfig['languages'] as $language) {
                    $translatedCredit[$qiFieldPrefix . $language] .= $item;
                }
            } else {
                $item = $item ?? '';
                $before = strlen($item) - strlen(ltrim($item));
                $left = substr($item, 0, $before);
                $after = strlen($item) - strlen(rtrim($item));
                $right = substr($item, 0, -$after);
                foreach($match as $language => $translation) {
                    $translatedCredit[$qiFieldPrefix . $language] .= $left . $translation . $right;
                }
            }
        }
        return $translatedCredit;
    }

    public function putMetadata($data): string|bool
    {
        return $this->put($this->baseUrl . '/put/media', json_encode($data));
    }

    public function getFieldData($jsonObject, $fieldName, $field): string|null
    {
        $res = null;
        if(array_key_exists('type', $field)) {
            if($field['type'] === 'list') {
                if(!array_key_exists('parent_path', $field) || !array_key_exists('key_path', $field) || !array_key_exists('value_path', $field)) {
                    echo 'Error: missing "parent_path", "key_path" or "value_path" for type "list" (field "' . $fieldName . '").' . PHP_EOL;
                    return null;
                } else {
                    $parentObjects = self::resultsToArray($jsonObject->get($field['parent_path']));
                    $results = [];
                    foreach($parentObjects as $parentObject) {
                        try {
                            $object = new JsonObject($parentObject);
                        } catch (InvalidJsonException $e) {
                            echo 'JSONPath error: ' . $e->getMessage() . PHP_EOL;
                        }
                        $res = null;
                        $keyResults = self::resultsToArray($object->get($field['key_path']));
                        if(!empty($keyResults)) {
                            $key = self::filterField($keyResults[0]);
                            if(array_key_exists('key_filter', $field)) {
                                if(!in_array($key, $field['key_filter'])) {
                                    $key = null;
                                }
                            }
                            $valueResults = self::resultsToArray($object->get($field['value_path']));
                            if(!empty($valueResults)) {
                                $firstValueResult = self::filterField($valueResults[0]);
                                if(array_key_exists('format', $field)) {
                                    $res = $field['format'];
                                    if(str_contains($field['format'], '$key')) {
                                        $res = str_replace('$key', empty($key) ? '' : $key, $field['format']);
                                    }
                                    if(str_contains($field['format'], '$value')) {
                                        $res = str_replace('$value', $firstValueResult, $res);
                                    }
                                } else if($key !== null) {
                                    $res = $key . ': ' . $firstValueResult;
                                } else {
                                    $res = $firstValueResult;
                                }
                            } else if($key !== null) {
                                $res = $key;
                            }
                        }
                        if(!empty($res)) {
                            $results[] = $res;
                        }
                    }
                    $concat = PHP_EOL . PHP_EOL;
                    if(array_key_exists('concat', $field)) {
                        $concat = $field['concat'];
                    }
                    $res = null;
                    foreach($results as $result) {
                        $result = self::filterField($result);
                        if(array_key_exists('remove_commas', $field)) {
                            if($field['remove_commas'] === 'yes') {
                                $result = str_replace(', ', ' ', $result);
                                $result = str_replace(',', ' ', $result);
                            }
                        }
                        if($res === null) {
                            $res = $result;
                        } else {
                            $res = $res . $concat . $result;
                        }
                    }
                    return $res;
                }
            } else if($field['type'] === 'date_range') {
                if(!array_key_exists('from_date_path', $field) || !array_key_exists('to_date_path', $field)) {
                    echo 'Error: missing "from_date_path" or "to_date_path" for type "date_range" (field "' . $fieldName . '").' . PHP_EOL;
                    return null;
                } else {
                    $fromDatesRes = self::resultsToArray($jsonObject->get($field['from_date_path']));
                    $toDatesRes = self::resultsToArray($jsonObject->get($field['to_date_path']));
                    $fromDates = [];
                    $toDates = [];
                    foreach($fromDatesRes as $date) {
                        if(!empty($date)) {
                            $fromDates[] = $date;
                        }
                    }
                    foreach($toDatesRes as $date) {
                        if(!empty($date)) {
                            $toDates[] = $date;
                        }
                    }
                    if (empty($fromDates)) {
                        if (empty($toDates)) {
                            return null;
                        } else {
                            $fromDates = $toDates;
                        }
                    } else if(empty($toDates)) {
                        $toDates = $fromDates;
                    }
                    $fromDatesList = [];
                    $toDatesList = [];
                    foreach ($fromDates as $date) {
                        $date = str_replace('/__', '', $date);
                        if (!preg_match('/^[0-9]{1,4}-[0-9][0-9]-[0-9][0-9]$/', $date)) {
                            if (preg_match('/^[0-9]{1,4}\/[0-9][0-9]\/[0-9][0-9]$/', $date)) {
                                $date = str_replace('/', '-', $date);
                            } else if (preg_match('/^[0-9]{1,4}\/[0-9][0-9]$/', $date)) {
                                $date = $date . '-01';
                            } else if (preg_match('/^[0-9]{1,4}___$/', $date)) {
                                $date = $date . '000-01-01';
                            } else if (preg_match('/^[0-9]{1,4}__$/', $date)) {
                                $date = $date . '00-01-01';
                            } else if (preg_match('/^[0-9]{1,4}_$/', $date)) {
                                $date = $date . '0-01-01';
                            } else if (preg_match('/^[0-9]{1,4}$/', $date)) {
                                $date = $date . '-01-01';
                            } else {
                                echo 'Unknown date: "' . $date . '"' . PHP_EOL;
                                $date = null;
                            }
                        }
                        if($date !== null) {
                            while (strlen($date) < 10) {
                                $date = '0' . $date;
                            }
                            if (preg_match('/^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]$/', $date)) {
                                $fromDatesList[] = $date;
                            }
                        }
                    }
                    foreach ($toDates as $date) {
                        if(!empty($date)) {
                            $date = str_replace('/__', '', $date);
                        }
                        if (!preg_match('/^[0-9]{1,4}-[0-9][0-9]-[0-9][0-9]$/', $date)) {
                            if (preg_match('/^[0-9]{1,4}\/[0-9][0-9]\/[0-9][0-9]$/', $date)) {
                                $date = str_replace('/', '-', $date);
                            } else if (preg_match('/^[0-9]{1,4}\/[0-9][0-9]$/', $date)) {
                                $month = substr($date, -2);
                                $year = substr(0, strpos($date, '/'));
                                $date = $date . '-' . $this->getMaxDaysInMonth($year, $month);
                            } else if (preg_match('/^[0-9]{1,4}___$/', $date)) {
                                $date = $date . '999-12-31';
                            } else if (preg_match('/^[0-9]{1,4}__$/', $date)) {
                                $date = $date . '99-12-31';
                            } else if (preg_match('/^[0-9]{1,4}_$/', $date)) {
                                $date = $date . '9-12-31';
                            } else if (preg_match('/^[0-9]{1,4}$/', $date)) {
                                $date = $date . '-12-31';
                            } else {
                                echo 'Unknown date: "' . $date . '"' . PHP_EOL;
                                $date = null;
                            }
                        }
                        if($date !== null) {
                            while (strlen($date) < 10) {
                                $date = '0' . $date;
                            }
                            if (preg_match('/^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]$/', $date)) {
                                $toDatesList[] = $date;
                            }
                        }
                    }
                    sort($fromDatesList);
                    rsort($toDatesList);

                    if(!empty($fromDatesList) && !empty($toDatesList)) {
                        if($fromDatesList[0] === $toDatesList[0]) {
                            return $fromDatesList[0];
                        } else {
                            return $fromDatesList[0] . '/' . $toDatesList[0];
                        }
                    }
                }
            } else {
                echo 'Error: Unknown type "' . $field['type'] . '" for field "' . $fieldName . '"".' . PHP_EOL;
            }
        }
        $allowEmpty = false;
        if(array_key_exists('path', $field)) {
            $results = $this->resultsToArray($jsonObject->get($field['path']));
            if(count($results) > 0) {
                if (array_key_exists('mapping', $field)) {
                    if (array_key_exists($results[0], $field['mapping'])) {
                        $res = $field['mapping'][$results[0]];
                        $allowEmpty = true;
                    } else {
                        if(!array_key_exists($fieldName, $this->unknownMappings)) {
                            $this->unknownMappings[$fieldName] = [];
                        }
                        if(!in_array($results[0], $this->unknownMappings[$fieldName])) {
                            $this->unknownMappings[$fieldName][] = $results[0];
                            echo 'INFO: Unknown mapping for ' . $fieldName . ': "' . $results[0] . '"' . PHP_EOL;
                        }
                    }
                } else {
                    $res = $this->filterField(implode(',', $results));
                }
            }
        }
        if($res !== null && !$allowEmpty) {
            if(strlen($res) === 0) {
                $res = null;
            }
        }
        if($res !== null && array_key_exists('casing', $field)) {
            if($field['casing'] === 'lowercase') {
                $res = strtolower($res);
            }
        }
        return $res;
    }

    private function resultsToArray($results): array
    {
        if(is_string($results)) {
            return [ $results ];
        }
        if(is_array($results)) {
            return $results;
        }
        return [];
    }

    public function filterField($field): string
    {
        if(empty($field)) {
            return '';
        }
        $field = str_replace("<i>", '\'', $field);
        $field = str_replace("</i>", '\'', $field);
        return str_replace("\n", ' ', $field);
    }

    public function getMaxDaysInMonth($year, $month) : string
    {
        switch($month) {
            default:
            case '01':
            case '03':
            case '05':
            case '07':
            case '08':
            case '10':
            case '12':
                return '31';
            case '02':
                $yearI = intval($year);
                if ($yearI % 400 === 0) {
                    return '29';
                } elseif ($yearI % 100 === 0) {
                    return '28';
                } elseif ($yearI % 4 === 0) {
                    return '29';
                } else {
                    return '28';
                }
            case '04':
            case '06':
            case '09':
            case '11':
                return '30';
        }
    }

    public function hasLinkDams($image): bool
    {
        if (array_key_exists('link_dams', $image)) {
            if (!empty($image['link_dams'])) {
                return true;
            }
        }
        return false;
    }

    public function get($url): string|bool
    {
        return $this->httpUtil->get($url, $this->username, $this->password);
    }

    public function put($url, $json): string|bool
    {
        if($this->debug) {
            echo $url . PHP_EOL;
            echo $json . PHP_EOL;
        }
        if(!$this->update) {
            return false;
        }
        $headers = array (
            "Content-Type: application/json; charset=utf-8",
            "Content-Length: " . strlen($json)
        );
        return $this->httpUtil->put($url, $headers, $json, $this->username, $this->password);
    }
}
