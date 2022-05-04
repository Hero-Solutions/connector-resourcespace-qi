<?php

namespace App\Command;

use App\Entity\Resource;
use App\Qi\Qi;
use App\ResourceSpace\ResourceSpace;
use App\Util\StringUtil;
use Doctrine\ORM\EntityManagerInterface;
use JsonPath\InvalidJsonException;
use JsonPath\JsonObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TestCommand extends Command
{
    private $params;
    /* @var $entityManager EntityManagerInterface */
    private $entityManager;

    /* @var $resourceSpace ResourceSpace */
    private $resourceSpace;
    /* @var $qi Qi */
    private $qi;

    private $resourcesByResourceId;
    private $resourcesByInventoryNumber;
    private $objectsByObjectId;
    private $objectsByInventoryNumber;
    private $qiImages;

    protected function configure()
    {
        $this
            ->setName('app:test')
            ->setDescription('Test');
    }

    public function __construct(ParameterBagInterface $params, EntityManagerInterface $entityManager)
    {
        $this->params = $params;
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->verbose = $input->getOption('verbose');
        $this->test();
        return 0;
    }

    private function test()
    {
        $test = $this->params->get('test');
        $debug = $this->params->get('debug');
        $update = $this->params->get('update');

        $allowedExtensions = $this->params->get('allowed_extensions');
        $forbiddenInventoryNumberPrefixes = $this->params->get('forbidden_inventory_number_prefixes');
        $forbiddenFilenamePostfixes = $this->params->get('forbidden_filename_postfixes');
        $creditConfig = $this->params->get('credit');

        $rsConfig = $this->params->get('resourcespace');
        $rsFields = $rsConfig['fields'];
        $rsImportMapping = $rsConfig['import_mapping'];
        $fullRSDataFields = $rsConfig['full_resource_data'];

        $qiConfig = $this->params->get('qi');
        $qiLinkDamsPrefix = $qiConfig['link_dams_prefix'];
        $qiMediaFolderId = $qiConfig['media_folder_id'];
        $qiImportMapping = $qiConfig['import_mapping'];
        $qiMappingToSelf = $qiConfig['mapping_to_self'];

        $sslCertificateAuthority = $this->params->get('ssl_certificate_authority');

        $this->resourceSpace = new ResourceSpace($rsConfig['api']);
        $allResources = $this->resourceSpace->getAllResources(urlencode($rsConfig['search_query']));
        $this->storeResources($allResources, $rsFields, $allowedExtensions, $forbiddenInventoryNumberPrefixes, $forbiddenFilenamePostfixes);

        $this->qi = new Qi($qiConfig, $sslCertificateAuthority, $test, $debug, $update);
        $this->qi->retrieveAllObjects();
        $this->objectsByObjectId = $this->qi->getObjectsByObjectId();
        $this->objectsByInventoryNumber = $this->qi->getObjectsByInventoryNumber();
        echo count($this->objectsByInventoryNumber) . ' objects with inventory number (' . count($this->objectsByObjectId) . ' total).' . PHP_EOL;

        $this->qiImages = [];
        foreach($this->objectsByObjectId as $objectId => $object) {
            $this->qiImages[$objectId] = $this->qi->getMediaInfos($object, $qiMediaFolderId, $qiImportMapping, $qiMappingToSelf);
        }

        $this->linkImportedResources($qiLinkDamsPrefix, $rsFields, $qiImportMapping);
        $this->updateQiSelfMetadata($qiMappingToSelf);

        if(true) {
            return;
        }

        $toUpload = 0;
        $sameCount = 0;
        $recordsMatched = array();

        $fp = fopen('record_matches.csv', 'w');
        $fp1 = fopen('record_matches_with_matching_images.csv', 'w');
        $fp2 = fopen('record_matches_no_matching_images.csv', 'w');
        $fp3 = fopen('record_matches_no_matching_images_unique.csv', 'w');
        $fp4 = fopen('record_matches_no_images.csv', 'w');

        fputcsv($fp, array('inventory_number', 'rs_id', 'qi_id', 'rs_name', 'qi_name', 'rs_filename', 'qi_filename', 'same_filename'));
        fputcsv($fp1, array('inventory_number', 'rs_id', 'qi_id', 'rs_name', 'qi_name', 'rs_filename', 'qi_filename'));
        fputcsv($fp3, array('inventory_number', 'rs_id', 'qi_id', 'rs_name', 'qi_name', 'rs_filename'));
        fputcsv($fp4, array('inventory_number', 'rs_id', 'qi_id', 'rs_name', 'qi_name', 'rs_filename'));

        $matchingRecordsNoMatchingImages = [];

        foreach ($resourcesByResourceId as $resourceId => $resourceInfo) {
            $inventoryNumber = $resourceInfo[$rsFields['inventorynumber']];
            if(!empty($inventoryNumber)) {
                if (array_key_exists($inventoryNumber, $objectsByInventoryNumber)) {
                    $object = $objectsByInventoryNumber[$inventoryNumber];
                    $objectName = $this->qi->filterField($object->name);
                    $recordsMatched[] = $inventoryNumber;
                    $rsFilename = $resourceInfo[$rsFields['originalfilename']];
                    $upload = true;
                    $write = true;
                    $hasMatch = false;

                    $qiImage = $this->qi->getMatchingLinkedImage($this->qiImages[$object['id']], $resourceId, $qiLinkDamsPrefix);
                    if($qiImage !== null && $this->update) {
                        $this->qi->updateMetadata($qiImage, $resource, $rsFields, $qiImportMapping, $qiLinkDamsPrefix);
                    }

                    if (property_exists($object, 'media.image.filename')) {
                        if (!empty($object->{'media.image.filename'})) {
                            $qiFilenames = $object->{'media.image.filename'};
                            foreach($qiFilenames as $qiFilename) {
                                $same = $this->filenamesMatch($resourceId, $rsFilename, $qiFilename);
                                fputcsv($fp, array($inventoryNumber, $resourceId, $object->id, $resourceInfo[$rsFields['title']], $objectName, $rsFilename, $qiFilename, $same ? 'X' : ''));
                                if($same) {
                                    fputcsv($fp1, array($inventoryNumber, $resourceId, $object->id, $resourceInfo[$rsFields['title']], $objectName, $rsFilename, $qiFilename));
                                    $hasMatch = true;
                                }
                                $write = false;
                                if ($same) {
//                                    echo 'SAME: Resource ' . $resourceId . ' has matching object ' . $objectName . ', image names: ' . $rsFilename . ', ' . $qiFilename . PHP_EOL;
                                    $upload = false;
                                } else {
//                                    echo 'CHECK: Resource ' . $resourceId . ' has matching object ' . $objectName . ', image names: ' . $rsFilename . ', ' . $qiFilename . PHP_EOL;
                                }
                            }
                        }
                    }
                    if ($write) {
                        fputcsv($fp, array($inventoryNumber, $resourceId, $object->id, $resourceInfo[$rsFields['title']], $objectName, $rsFilename, '', ''));
                    }
                    $hasImages = false;
                    if(!$hasMatch) {
                        if (property_exists($object, 'media.image.filename')) {
                            if (!empty($object->{'media.image.filename'})) {
                                $qiFilenames = $object->{'media.image.filename'};
                                $arr = array($inventoryNumber, $resourceId, $object->id, $resourceInfo[$rsFields['title']], $objectName, $rsFilename);
                                foreach($qiFilenames as $filename) {
                                    $arr[] = $filename;
                                }
                                $matchingRecordsNoMatchingImages[] = $arr;
                            }
                        }
                        if($hasImages) {
                            fputcsv($fp3, array($inventoryNumber, $resourceId, $object->id, $resourceInfo[$rsFields['title']], $objectName, $rsFilename));
                        } else {
                            fputcsv($fp4, array($inventoryNumber, $resourceId, $object->id, $resourceInfo[$rsFields['title']], $objectName, $rsFilename));
                        }
                    }
                    if ($upload) {
//                        echo 'UPLOAD: Resource ' . $resourceId . ' has matching object ' . $objectName . ' for RS filename ' . $rsFilename . PHP_EOL;
                        $toUpload++;
                    } else {
                        $sameCount++;
                    }

/*                    try {
                        $jsonObject = new JsonObject($object);
//                        echo $object->id . PHP_EOL;
                        foreach ($rsImportMapping as $fieldName => $field) {
                            $res = $this->qi->getFieldData($jsonObject, $fieldName, $field);
                            if ($res !== null) {
                                if(array_key_exists($fieldName, $fullRSDataFields)) {
                                    $fullRSData = $this->resourceSpace->getResourceData($resourceId);
                                    if(array_key_exists($fieldName, $fullRSData)) {
                                        $resourceInfo[$fullRSDataFields[$fieldName]] = $fullRSData[$fieldName];
                                    }
                                }
                                $fieldId = $rsFields[$fieldName];
                                if(array_key_exists('overwrite', $field) && array_key_exists($fieldId, $resourceInfo)) {
                                    if($field['overwrite'] === 'no') {
                                        if(!empty($resourceData[$fieldId])) {
                                            echo 'Not overwriting field ' . $fieldName . ' for res ' . $res . ' (already has ' . $resourceData[$fieldId] . ')' . PHP_EOL;
                                            $res = null;
                                        }
                                    } else if($field['overwrite'] === 'merge') {
                                        if(!empty($resourceData[$fieldId])) {
                                            if(strpos($resourceData[$fieldId], $res) === false) {
                                                echo 'Merging field ' . $fieldName . ' for res ' . $res . ' (already has ' . $resourceData[$fieldId] . ')' . PHP_EOL;
                                                $res = $resourceData[$fieldId] . '\n\n' . $res;
                                            } else {
                                                echo 'Not merging field ' . $fieldName . ' for res ' . $res . ' (already has ' . $resourceData[$fieldId] . ')' . PHP_EOL;
                                                $res = null;
                                            }
                                        }
                                    }
                                }
                                if($res !== null) {
                                    $nodeValue = false;
                                    if (array_key_exists('node_value', $field)) {
                                        if ($field['node_value'] === 'yes') {
                                            $nodeValue = true;
                                        }
                                    }
                                    if ($resourceId === '149565') {
                                        echo $fieldName . ' - ' . $res . PHP_EOL;
                                        $this->resourceSpace->updateField($resourceId, $fieldName, $res, $nodeValue);
                                    }
                                }
                            }
                        }

                        foreach ($qiImportMapping as $qiFieldName => $rsFieldName) {

                        }
//                        echo PHP_EOL . PHP_EOL;
                    } catch (InvalidJsonException $e) {
                        echo 'JSONPath error: ' . $e->getMessage() . PHP_EOL;
                    }*/
                }
            }
//            echo $filename . PHP_EOL;
        }

        $mostImages = 0;
        foreach($matchingRecordsNoMatchingImages as $record) {
            $count = count($record);
            if($count > $mostImages) {
                $mostImages = $count;
            }
        }
        $arr = array('inventory_number', 'rs_id', 'qi_id', 'rs_name', 'qi_name', 'rs_filename');
        for($i = 0; $i < $mostImages - 6; $i++) {
            $arr[] = 'qi_filename' . ($i + 1);
        }

        fputcsv($fp2, $arr);
        foreach($matchingRecordsNoMatchingImages as $record) {
            fputcsv($fp2, $record);
        }


        fclose($fp);
        fclose($fp1);
        fclose($fp2);
        fclose($fp3);
        fclose($fp4);

        //Non-matching resources & objects
        $fp = fopen('unmatching_resources.csv', 'w');
        fputcsv($fp, array('inventory_number', 'rs_id', 'rs_name', 'qi_name', 'rs_filename'));
        foreach ($resourcesByResourceId as $resourceId => $resourceInfo) {
            $inventoryNumber = $resourceInfo[$rsFields['inventorynumber']];
            if(!empty($inventoryNumber)) {
                if (!array_key_exists($inventoryNumber, $objectsByInventoryNumber)) {
                    fputcsv($fp, array($inventoryNumber, $resourceId, $resourceInfo[$rsFields['title']], $resourceInfo[$rsFields['originalfilename']]));
                }
            }
        }

        fclose($fp);
        $fp = fopen('unmatching_objects_all_images.csv', 'w');
        $fp1 = fopen('unmatching_objects_unique.csv', 'w');
        $fp2 = fopen('objects_no_media.csv', 'w');
        fputcsv($fp, array('inventory_number', 'qi_id', 'qi_name', 'qi_filename'));
        fputcsv($fp1, array('inventory_number', 'qi_id', 'qi_name'));
        fputcsv($fp2, array('inventory_number', 'qi_id', 'qi_name'));
        foreach($objectsByInventoryNumber as $inventoryNumber => $object) {
            if(!in_array($inventoryNumber, $recordsMatched)) {
                $objectName = $this->qi->filterField($object->name);
                $object = $objectsByInventoryNumber[$inventoryNumber];
                $write = true;
                if (property_exists($object, 'media.image.filename')) {
                    if (!empty($object->{'media.image.filename'})) {
                        $qiFilenames = $object->{'media.image.filename'};
                        foreach($qiFilenames as $qiFilename) {
                            $write = false;
                            fputcsv($fp, array($inventoryNumber, $object->id,  $objectName, $qiFilename));
                        }
                    }
                }
                fputcsv($fp1, array($inventoryNumber, $object->id, $objectName));
                if($write) {
                    fputcsv($fp2, array($inventoryNumber, $object->id, $objectName));
                }
            }
        }
        fclose($fp);
        fclose($fp1);
        fclose($fp2);
        echo 'To upload: ' . $toUpload . ', same: ' . $sameCount . PHP_EOL;
    }

    private function filenamesMatch($resourceId, $rsFilename, $qiFilename)
    {
        $rsFilenameLower = strtolower($rsFilename);
        $qiFilenameLower = strtolower($qiFilename);

        if(preg_match('/^rs[0-9]+_.*$/', $qiFilenameLower)) {
            $resId = intval(preg_replace('/^rs([0-9]+)_.*$/', '$1', $qiFilenameLower));
            if($resId === $resourceId) {
                return true;
            }
        } else if(preg_match('/^rs[0-9]+-.*$/', $qiFilenameLower)) {
            $resId = intval(preg_replace('/^rs([0-9]+)-.*$/', '$1', $qiFilenameLower));
            if($resId === $resourceId) {
                return true;
            }
        }

        $rsFilenameWithoutExtension = pathinfo($rsFilenameLower, PATHINFO_FILENAME);
        $qiFilenameWithoutExtension = pathinfo($qiFilenameLower, PATHINFO_FILENAME);
        if ($rsFilenameWithoutExtension === $qiFilenameWithoutExtension) {
            return true;
        }

        if(preg_match('/^cat [0-9]+_.*$/', $rsFilenameWithoutExtension)) {
            $rsFilenameWithoutExtension = preg_replace('/^cat [0-9]+_(.*)$/', '$1', $rsFilenameWithoutExtension);
        }

        $rsFilenameUnderscores = str_replace('-', '_', $rsFilenameWithoutExtension);
        $qiFilenameUnderscores = str_replace('-', '_', $qiFilenameWithoutExtension);
        if ($rsFilenameUnderscores === $qiFilenameUnderscores) {
            return true;
        }

        if(StringUtil::endsWith($rsFilenameUnderscores, ' (r)')) {
            $rsFilenameUnderscores = substr($rsFilenameUnderscores, 0, -4) . '_r';
            if ($rsFilenameUnderscores === $qiFilenameUnderscores) {
                return true;
            }
        } else if(StringUtil::endsWith($rsFilenameUnderscores, ' (v)')) {
            $rsFilenameUnderscores = substr($rsFilenameUnderscores, 0, -4) . '_v';
            if ($rsFilenameUnderscores === $qiFilenameUnderscores) {
                return true;
            }
        }

        $rsFilenameUnderscores = str_replace(' ', '_', $rsFilenameUnderscores);
        $qiFilenameUnderscores = str_replace(' ', '_', $qiFilenameUnderscores);
        if ($rsFilenameUnderscores === $qiFilenameUnderscores) {
            return true;
        }

        if (StringUtil::endsWith($qiFilenameUnderscores, '_1')) {
            $qiFilenameUnderscores = substr($qiFilenameUnderscores, 0, -2);
            if ($rsFilenameUnderscores === $qiFilenameUnderscores) {
                return true;
            }
        }

        if (StringUtil::endsWith($rsFilenameUnderscores, '_1')) {
            $rsFilenameUnderscores = substr($rsFilenameUnderscores, 0, -2);
            if ($rsFilenameUnderscores === $qiFilenameUnderscores) {
                return true;
            }
        }

        if(StringUtil::startsWith($qiFilenameUnderscores, 'as')) {
            $qiFilenameUnderscores = substr($qiFilenameUnderscores, 1);
            if ($rsFilenameUnderscores === $qiFilenameUnderscores) {
                return true;
            }
        }
        if(StringUtil::startsWith($rsFilenameUnderscores, 'as')) {
            $rsFilenameUnderscores = substr($rsFilenameUnderscores, 1);
            if ($rsFilenameUnderscores === $qiFilenameUnderscores) {
                return true;
            }
        }
        return false;
    }

    private function translateCredit($credit, $creditConfig)
    {
        $split = [
            $credit
        ];
        foreach($creditConfig['split_chars'] as $splitChar) {
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
        foreach($creditConfig['languages'] as $language) {
            $translatedCredit[$language] = '';
        }
        foreach($split as $item) {
            $trimmedItem = trim($item);
            $match = null;
            foreach($creditConfig['translations'] as $nlValue => $translations) {
                if($nlValue === $trimmedItem) {
                    $match = $translations;
                    break;
                }
            }
            if($match === null) {
                foreach($creditConfig['languages'] as $language) {
                    $translatedCredit[$language] .= $item;
                }
            } else {
                $before = strlen($item) - strlen(ltrim($item));
                $left = substr($item, 0, $before);
                $after = strlen($item) - strlen(rtrim($item));
                $right = substr($item, 0, -$after);
                foreach($match as $language => $translation) {
                    $translatedCredit[$language] .= $left . $translation . $right;
                }
            }
        }
        return $translatedCredit;
    }

    private function storeResources($allResources, $rsFields, $allowedExtensions, $forbiddenInventoryNumberPrefixes, $forbiddenFilenamePostfixes)
    {
        $this->resourcesByResourceId = [];
        $this->resourcesByInventoryNumber = [];
        foreach($allResources as $resource) {
            $rsFilename = $resource[$rsFields['originalfilename']];
            $extension = strtolower(pathinfo($rsFilename, PATHINFO_EXTENSION));
            if(in_array($extension, $allowedExtensions)) {
                $inventoryNumber = $resource[$rsFields['inventorynumber']];
                if (!empty($inventoryNumber)) {
                    $forbiddenInventoryNumber = false;
                    foreach($forbiddenInventoryNumberPrefixes as $prefix) {
                        if(StringUtil::startsWith($inventoryNumber, $prefix)) {
                            $forbiddenInventoryNumber = true;
                            break;
                        }
                    }
                    if(!$forbiddenInventoryNumber) {
                        $forbiddenFilename = false;
                        $filenameWithoutExtension = pathinfo($rsFilename, PATHINFO_FILENAME);
                        foreach($forbiddenFilenamePostfixes as $postfix) {
                            if(StringUtil::endsWith($filenameWithoutExtension, $postfix)) {
                                $forbiddenFilename = true;
                                break;
                            }
                        }
                        if(!$forbiddenFilename) {
                            $this->resourcesByResourceId[intval($resource['ref'])] = $resource;
                            if (!array_key_exists($inventoryNumber, $this->resourcesByInventoryNumber)) {
                                $this->resourcesByInventoryNumber[$inventoryNumber] = [];
                            }
                            $this->resourcesByInventoryNumber[$inventoryNumber][] = $resource;
                        }
                    }
                }
            } else if(empty($extension)) {
                echo 'Resource ' . $resource['ref'] . ' has no extension (' . $rsFilename . ')' . PHP_EOL;
            }
        }
        echo count($this->resourcesByResourceId) . ' resources total for ' . count($this->resourcesByInventoryNumber) . ' unique inventory numbers.' . PHP_EOL;
    }

    private function linkImportedResources($qiLinkDamsPrefix, $rsFields, $qiImportMapping)
    {
        // Add link DAMS and metadata to any Qi object for which an image was imported in a previous run
        /* @var $importedResources Resource[] */
        $importedResources = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Resource::class, 'r')
            ->getQuery()
            ->getResult();
        $i = 0;
        foreach($importedResources as $ir) {
            if(array_key_exists($ir->getResourceId(), $this->resourcesByResourceId) && array_key_exists($ir->getInventoryNumber(), $this->resourcesByInventoryNumber)
                && array_key_exists($ir->getObjectId(), $this->objectsByObjectId) && array_key_exists($ir->getInventoryNumber(), $this->objectsByInventoryNumber)) {
                if(in_array($this->resourcesByResourceId[$ir->getResourceId()], $this->resourcesByInventoryNumber[$ir->getInventoryNumber()])
                    && $this->objectsByObjectId[$ir->getObjectId()] === $this->objectsByInventoryNumber[$ir->getInventoryNumber()]) {
                    $mediaInfos = $this->qiImages[$ir->getObjectId()];
                    $resource = $this->resourcesByResourceId[$ir->getResourceId()];
                    $qiImage = $this->qi->getMatchingUnlinkedImage($mediaInfos, $ir->getOriginalFilename(), $qiLinkDamsPrefix);
                    if ($qiImage !== null) {
                        if($this->update) {
                            $this->qi->updateMetadata($qiImage, $resource, $rsFields, $qiImportMapping, $qiLinkDamsPrefix);
                        }
                    }

                    $this->entityManager->remove($ir);
                    $i++;
                    if($i % 100 === 0) {
                        $this->entityManager->flush();
                    }
                }
            } else {
                echo 'ERROR: No match found for resource ' . $ir->getResourceId() . PHP_EOL;
            }
        }
        if($i > 0) {
            $this->entityManager->flush();
        }
    }

    private function updateQiSelfMetadata($qiMappingToSelf)
    {
        foreach($this->objectsByObjectId as $objectId => $object) {
            $toUpdate = [];
            if(!empty($this->qiImages[$objectId])) {
                $jsonObject = new JsonObject($object);
                foreach($qiMappingToSelf as $key => $jsonPath) {
                    $result = $jsonObject->get($jsonPath);
                    if($result !== false) {
                        if(is_array($result)) {
                            if (count($result) >= 1) {
                                $result = $result[0];
                                foreach ($this->qiImages[$objectId] as $image) {
                                    $changed = false;
                                    if (!array_key_exists($key, $image)) {
                                        $changed = true;
                                    } else if ($image[$key] !== $result) {
                                        $changed = true;
                                    }
                                    if ($changed) {
                                        if (!array_key_exists($image['id'], $toUpdate)) {
                                            $toUpdate[$image['id']] = [];
                                        }
                                        $toUpdate[$image['id']][$key] = $result;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if(!empty($toUpdate)) {
                foreach($toUpdate as $id => $newData) {
                    $data = [
                        'id' => $id,
                        'record' => $newData
                    ];
                    $this->qi->putMetadata($data);
                }
            }
        }
    }
}
