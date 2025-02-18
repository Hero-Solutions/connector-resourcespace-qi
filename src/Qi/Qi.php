<?php

namespace App\Qi;

use App\Entity\QiObject;
use App\Entity\Resource;
use App\ResourceSpace\ResourceSpace;
use DateTime;
use Doctrine\ORM\EntityManager;
use JsonPath\InvalidJsonException;
use JsonPath\JsonObject;

class Qi
{
    /** @var $entityManager EntityManager */
    private $entityManager;

    private $baseUrl;
    private $username;
    private $password;
    private $getFields;
    private $overrideCertificateAuthorityFile;
    private $sslCertificateAuthorityFile;
    private $creditConfig;
    private $test;
    private $debug;
    private $update;
    private $fullProcessing;
    private $onlyOnlineRecords;
    private $unknownMappings = [];
    private $httpUtil;
    private $maxFieldValueLength;

    private $objectsByObjectId;
    private $objectsByInventoryNumber;

    public function __construct($entityManager, $qi, $sslCertificateAuthority, $creditConfig, $test, $debug, $update, $fullProcessing, $onlyOnlineRecords, $httpUtil, $maxFieldValueLength)
    {
        $this->entityManager = $entityManager;

        $qiApi = $qi['api'];
        $this->baseUrl = $qiApi['url'];
        $this->username = $qiApi['username'];
        $this->password = $qiApi['password'];
        $this->getFields = $qi['get_fields'];

        $this->overrideCertificateAuthorityFile = $sslCertificateAuthority['override'];
        $this->sslCertificateAuthorityFile = $sslCertificateAuthority['authority_file'];
        $this->creditConfig = $creditConfig;

        $this->test = $test;
        $this->debug = $debug;
        $this->update = $update;
        $this->fullProcessing = $fullProcessing;
        $this->onlyOnlineRecords = $onlyOnlineRecords;

        $this->httpUtil = $httpUtil;
        $this->maxFieldValueLength = $maxFieldValueLength;
    }

    public function getObjectsByObjectId()
    {
        return $this->objectsByObjectId;
    }

    public function getObjectsByInventoryNumber()
    {
        return $this->objectsByInventoryNumber;
    }

    public function retrieveAllObjects($recordsUpdatedSince)
    {
        $this->objectsByObjectId = [];
        $this->objectsByInventoryNumber = [];

        if($this->fullProcessing) {
            //Clear all cached Qi object data from MySQL
            $this->entityManager->createQueryBuilder()
                ->delete(QiObject::class, 'q')
                ->getQuery()
                ->execute();
        } else {
            //Retrieve alle cached Qi object data from MySQL
            /* @var $qiObjects QiObject[] */
            $qiObjects = $this->entityManager->createQueryBuilder()
                ->select('q')
                ->from(QiObject::class, 'q')
                ->getQuery()
                ->getResult();
            foreach($qiObjects as $qiObject) {
                $this->extractRecord(json_decode($qiObject->getMetadata()));
            }
        }

        //Get all records of up to 1 week ago
        $time = strtotime('-' . $recordsUpdatedSince, time());
        $date = date("Y-m-d", $time);

        if($this->test) {
            $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields) . '/_offset/12929');
        } else {
            if($this->fullProcessing) {
                $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields));
            } else {
                $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields) . '/_since/' . $date);
            }
        }

        $count = $this->storeObjects($objsJson);

        for($i = 1; !$this->test && $i <= intval(($count + 499) / 500) - 1; $i++) {
            echo 'Sleeping' . PHP_EOL;
            sleep(300);
            $tries = 0;
            while($tries < 10) {
                if($this->fullProcessing) {
                    $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields) . '/_offset/' . ($i * 500));
                } else {
                    $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields) . '/_since/' . $date . '/_offset/' . ($i * 500));
                }
                if($objsJson === false) {
                    $tries++;
                    echo 'Sleeping' . PHP_EOL;
                    sleep(300);
                } else {
                    $tries = 10;
                }
            }

            $this->storeObjects($objsJson);
        }

        if(!$this->fullProcessing) {

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
            foreach($importedResourcesObjects as $importedResource) {
                $objsJson = $this->get($this->baseUrl . '/get/object/id/' . $importedResource->getObjectId() . '/_fields/' . urlencode($this->getFields));
                $this->storeObjects($objsJson);
            }
        }
    }

    private function storeObjects($objsJson)
    {
        $objs = json_decode($objsJson);
        $records = $objs->records;
        $count = $objs->count;
        foreach($records as $record) {
            $this->extractRecord($record);
        }
        return $count;
    }

    private function extractRecord($record)
    {
        if(!$this->onlyOnlineRecords || $record->online === '1') {
            $this->objectsByObjectId[intval($record->id)] = $record;
            if(!empty($record->object_number)) {
                $this->objectsByInventoryNumber[$record->object_number] = $record;
            } else {
                echo 'Error: Qi record ' . $record->id . ' has no inventory number' . PHP_EOL;
            }
        }
        if($this->fullProcessing) {
            $this->ping();

            //Store in MySQL for faster processing in the next cycles
            //First, find if an object with this ID already exists
            $qiObject = $this->entityManager->getRepository(QiObject::class)->find(intval($record->id));
            if (!$qiObject) {
                $qiObject = new QiObject();
                $qiObject->setObjectId(intval($record->id));
            }
            $qiObject->setMetadata(json_encode($record));
            $this->entityManager->persist($qiObject);
            $this->entityManager->flush();
        }
    }

    private function ping()
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isConnected()) {
            $connection->connect();
        }
    }

    public function getMediaInfos($object, $qiImportMapping, $qiMappingToSelf)
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

    public function getMatchingImageToBeLinked($images, $originalFilename, $width, $height, $filesize, $qiMediaFolderIds)
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

    public function updateMetadata($qiImage, $resource, $rsFields, $qiImportMapping, $qiLinkDamsPrefix, $addLinkDams, $reindexUrl)
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

    public function updateResourceSpaceData($object, $resource, $resourceId, $rsFields, $rsImportMapping, $rsFullDataFields, $qiUrl, ResourceSpace $resourceSpace)
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
                $res = $this->getFieldData($jsonObject, $fieldName, $field);
                if ($res !== null) {
                    $fieldId = $rsFields[$fieldName];
                    $fetchFullData = false;
                    if(array_key_exists($fieldId, $resource)) {
                        if(strlen($resource[$fieldId]) >= 180) {
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
                                    echo 'Not overwriting field ' . $fieldName . ' for res ' . $res . ' (already has ' . $resource[$fieldId] . ')' . PHP_EOL;
                                }
                                $res = null;
                            }
                        } else if($field['overwrite'] === 'merge') {
                            if (!empty($resource[$fieldId])) {
                                if (strpos($resource[$fieldId], $res) === false) {
                                    if ($this->debug) {
                                        echo 'Merging field ' . $fieldName . ' for res ' . $res . ' (already has ' . $resource[$fieldId] . ')' . PHP_EOL;
                                    }
                                    $res = $resource[$fieldId] . '\n\n' . $res;
                                } else {
                                    if ($this->debug) {
                                        echo 'Not merging field ' . $fieldName . ' for res ' . $res . ' (already has ' . $resource[$fieldId] . ')' . PHP_EOL;
                                    }
                                    $res = null;
                                }
                            }
                        }
                    }
                    if($res !== null) {
                        if(strlen($res) > $this->maxFieldValueLength) {
                            $res = substr($res, 0, $this->maxFieldValueLength);
                        }
                        $update = true;
                        if(array_key_exists($fieldId, $resource)) {
                            if($resource[$fieldId] === $res || empty($resource[$fieldId]) && empty($res)) {
                                $update = false;
                            } else {
                                // Mostly for keywords, check if both fields contain the same comma-separated values but in a different order
                                $expl1 = explode(',', $resource[$fieldId]);
                                $expl2 = explode(',', $res);
                                if(count($expl1) === count($expl2)) {
                                    $vals1 = [];
                                    $vals2 = [];
                                    foreach($expl1 as $val) {
                                        $vals1[] = trim($val);
                                    }
                                    foreach($expl2 as $val) {
                                        $vals2[] = trim($val);
                                    }
                                    $update = false;
                                    foreach($vals1 as $val) {
                                        if(!in_array($val, $vals2)) {
                                            $update = true;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        if($update) {
                            $nodeValue = false;
                            if (array_key_exists('node_value', $field)) {
                                if ($field['node_value'] === 'yes') {
                                    $nodeValue = true;
                                }
                            }
                            if (!$this->test) {
                                $resourceSpace->updateField($resourceId, $fieldName, $res, $nodeValue);
                            }
                        }
                    }
                }
            }
        } catch (InvalidJsonException $e) {
            echo 'JSONPath error: ' . $e->getMessage() . PHP_EOL;
        }
    }

    private function translateCredit($credit)
    {
        $split = [
            $credit
        ];
        foreach($this->creditConfig['split_chars'] as $splitChar) {
            $newSplit = [];
            foreach($split as $item) {
                $splitItem = explode($splitChar, $item);
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

    public function putMetadata($data) {
        $this->put($this->baseUrl . '/put/media', json_encode($data));
    }

    public function getFieldData($jsonObject, $fieldName, $field)
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
                                if(array_key_exists('format', $field)) {
                                    $res = $field['format'];
                                    if(strpos($field['format'], '$key') !== false) {
                                        $res = str_replace('$key', $key, $field['format']);
                                    }
                                    if(strpos($field['format'], '$value') !== false) {
                                        $res = str_replace('$value', self::filterField($valueResults[0]), $res);
                                    }
                                } else if($key !== null) {
                                    $res = $key . ': ' . self::filterField($valueResults[0]);
                                } else {
                                    $res = self::filterField($valueResults[0]);
                                }
                            } else if($key !== null) {
                                $res = $key;
                            }
                        }
                        if($res !== null) {
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
                        if(strlen($date) > 0) {
                            $fromDates[] = $date;
                        }
                    }
                    foreach($toDatesRes as $date) {
                        if(strlen($date) > 0) {
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
                        $date = str_replace('/__', '', $date);
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
                        return $fromDatesList[0] . ',' . $toDatesList[0];
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

    private function resultsToArray($results) {
        if(is_string($results)) {
            return [ $results ];
        }
        if(is_array($results)) {
            return $results;
        }
        return [];
    }

    public function filterField($field) {
        $field = str_replace("<i>", '\'', $field);
        $field = str_replace("</i>", '\'', $field);
        $field = str_replace("\n", ' ', $field);
        return $field;
    }

    public function getMaxDaysInMonth($year, $month) {
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
                if ($year % 400 === 0) {
                    return '29';
                } elseif ($year % 100 === 0) {
                    return '28';
                } elseif ($year % 4 === 0) {
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

    public function hasLinkDams($image)
    {
        if (array_key_exists('link_dams', $image)) {
            if (!empty($image['link_dams'])) {
                return true;
            }
        }
        return false;
    }

    public function get($url)
    {
        if($this->debug) {
            echo $url . PHP_EOL;
        }

        $ch = curl_init();
        if ($this->overrideCertificateAuthorityFile) {
            curl_setopt($ch,CURLOPT_CAINFO, $this->sslCertificateAuthorityFile);
            curl_setopt($ch,CURLOPT_CAPATH, $this->sslCertificateAuthorityFile);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 300);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $resultJson = curl_exec($ch);
        if($resultJson === false) {
            echo 'HTTP error: ' . curl_error($ch) . PHP_EOL;
        } else if (!curl_errno($ch)) {
            switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
                case 200:  # OK
                    break;
                default:
                    echo 'HTTP error ' .  $http_code . ': ' . $resultJson . PHP_EOL;
                    $resultJson = false;
                    break;
            }
        }
        curl_close($ch);
        return $resultJson;
    }

    public function put($url, $json)
    {
        if($this->debug) {
            echo $url . PHP_EOL;
            echo $json . PHP_EOL;
        }
        if(!$this->update) {
            return;
        }

        $headers = array (
            "Content-Type: application/json; charset=utf-8",
            "Content-Length: " . strlen($json)
        );

        $ch = curl_init();
        if ($this->overrideCertificateAuthorityFile) {
            curl_setopt($ch,CURLOPT_CAINFO, $this->sslCertificateAuthorityFile);
            curl_setopt($ch,CURLOPT_CAPATH, $this->sslCertificateAuthorityFile);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);

        $resultJson = curl_exec($ch);
        if($resultJson === false) {
            echo 'HTTP error: ' . curl_error($ch) . PHP_EOL;
        } else if (!curl_errno($ch)) {
            switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
                case 200:  # OK
                    break;
                default:
                    echo 'HTTP error ' .  $http_code . ': ' . $resultJson . PHP_EOL;
                    break;
            }
        }
        curl_close($ch);
        return $resultJson;
    }
}
