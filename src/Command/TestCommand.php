<?php

namespace App\Command;

use App\Entity\Resource;
use App\Qi\Qi;
use App\ResourceSpace\ResourceSpace;
use App\Util\StringUtil;
use DateTime;
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
    private $update;

    /* @var $resourceSpace ResourceSpace */
    private $resourceSpace;
    /* @var $qi Qi */
    private $qi;

    private $resourcesByResourceId;
    private $resourcesByInventoryNumber;
    private $objectsByObjectId;
    private $objectsByInventoryNumber;
    private $qiImages;
    private $objectIdsUploadedTo;
    private $linkedResources;
    /* @var $importedResources Resource[] */
    private $importedResources;

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
        $this->update = $this->params->get('update');
        $ftpFolder = $this->params->get('ftp_folder');
        if(!StringUtil::endsWith($ftpFolder, '/')) {
            $ftpFolder .= '/';
        }
        $ftpUser = $this->params->get('ftp_user');
        $ftpGroup = $this->params->get('ftp_group');
        $onlyOnlineRecords = $this->params->get('only_online_records');

        // Abort the run if the ftp folder exists and is not empty
        if(is_dir($ftpFolder)) {
            $files = array_diff(scandir($ftpFolder), array('.', '..'));
            if(!empty($files)) {
                echo 'Aborted - ftp folder is not empty!' . PHP_EOL;
                return;
            }
        }

        $allowedExtensions = $this->params->get('allowed_extensions');
        $fileSizes = $this->params->get('file_sizes');
        $forbiddenInventoryNumberPrefixes = $this->params->get('forbidden_inventory_number_prefixes');
        $forbiddenFilenamePostfixes = $this->params->get('forbidden_filename_postfixes');
        $creditConfig = $this->params->get('credit');

        $rsConfig = $this->params->get('resourcespace');
        $rsFields = $rsConfig['fields'];
        $rsLinkWithCmsValue = $rsConfig['linkwithcmsvalue'];
        $rsImportMapping = $rsConfig['import_mapping'];
        $rsFullDataFields = $rsConfig['full_data_fields'];

        $qiConfig = $this->params->get('qi');
        $qiUrl = $qiConfig['url'];
        $qiLinkDamsPrefix = $qiConfig['link_dams_prefix'];
        $qiMediaFolderId = $qiConfig['media_folder_id'];
        $qiImportMapping = $qiConfig['import_mapping'];
        $qiMappingToSelf = $qiConfig['mapping_to_self'];

        $sslCertificateAuthority = $this->params->get('ssl_certificate_authority');

        /* @var $importedResourcesObjects Resource[] */
        $importedResourcesObjects = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Resource::class, 'r')
            ->getQuery()
            ->getResult();
        $this->importedResources = [];
        foreach($importedResourcesObjects as $importedResource) {
            $this->importedResources[$importedResource->getResourceId()] = $importedResource;
        }

        $this->resourceSpace = new ResourceSpace($rsConfig['api']);
        $allResources = $this->resourceSpace->getAllResources(urlencode($rsConfig['search_query']));
        $this->storeResources($allResources, $rsFields, $rsLinkWithCmsValue, $allowedExtensions, $forbiddenInventoryNumberPrefixes, $forbiddenFilenamePostfixes);
        echo count($this->resourcesByResourceId) . ' resources total for ' . count($this->resourcesByInventoryNumber) . ' unique inventory numbers.' . PHP_EOL;

        $this->qi = new Qi($qiConfig, $sslCertificateAuthority, $creditConfig, $test, $debug, $this->update, $onlyOnlineRecords);
        $this->qi->retrieveAllObjects();
        $this->objectsByObjectId = $this->qi->getObjectsByObjectId();
        $this->objectsByInventoryNumber = $this->qi->getObjectsByInventoryNumber();
        echo count($this->objectsByInventoryNumber) . ' Qi objects with inventory number (' . count($this->objectsByObjectId) . ' total).' . PHP_EOL;

        $this->qiImages = [];
        foreach($this->objectsByObjectId as $objectId => $object) {
            $this->qiImages[$objectId] = $this->qi->getMediaInfos($object, $qiImportMapping, $qiMappingToSelf);
        }

        $this->objectIdsUploadedTo = [];
        $this->linkedResources = [];

        // Add Link DAMS and metadata to images in Qi that were imported in a previous run
        $this->linkImportedResources($rsFields, $qiImportMapping, $qiMediaFolderId, $qiLinkDamsPrefix);

        // Update Qi image metadata with metadata from Qi objects
        $this->updateQiSelfMetadata($qiMappingToSelf);

        $uploaded = 0;

        foreach ($this->resourcesByResourceId as $resourceId => $resource) {
            // Skip resources that are already linked to an object in Qi
            if(array_key_exists($resourceId, $this->linkedResources)) {
                continue;
            }
            $inventoryNumber = $resource[$rsFields['inventorynumber']];
            if(empty($inventoryNumber)) {
                continue;
            }
            if (!array_key_exists($inventoryNumber, $this->objectsByInventoryNumber)) {
                continue;
            }
            if(array_key_exists($resourceId, $this->importedResources) && $this->importedResources[$resourceId]->getInventoryNumber() === $inventoryNumber) {
                continue;
            }

            $object = $this->objectsByInventoryNumber[$inventoryNumber];
            $rsFilename = $resource[$rsFields['originalfilename']];
            $hasMatchingImage = false;

            foreach($this->qiImages[$object->id] as $image) {
                if($this->qi->hasLinkDams($image)) {
                    if ($image['link_dams'] === $qiLinkDamsPrefix . $resourceId) {
                        $hasMatchingImage = true;
                        $this->qi->updateMetadata($image, $resource, $rsFields, $qiImportMapping, $qiLinkDamsPrefix, false);
                    }
                } else if(array_key_exists('filename', $image)) {
                    $fromRS = true;
                    if(!array_key_exists('media_folder_id', $image)) {
                        $fromRS = false;
                    } else if($image['media_folder_id'] !== $qiMediaFolderId) {
                        $fromRS = false;
                    }
                    if(!$fromRS) {
                        if ($this->filenamesMatch($resourceId, $rsFilename, $image['filename'])) {
                            $hasMatchingImage = true;
                            $this->qi->updateMetadata($image, $resource, $rsFields, $qiImportMapping, $qiLinkDamsPrefix, true);

                            $resource = new Resource();
                            $resource->setImportTimestamp(new DateTime());
                            $resource->setResourceId($resourceId);
                            $resource->setObjectId($object->id);
                            $resource->setInventoryNumber($inventoryNumber);
                            if(array_key_exists('original_filename', $image)) {
                                if(!empty($image['original_filename'])) {
                                    $resource->setOriginalFilename($image['original_filename']);
                                }
                            }
                            if(array_key_exists('width', $image)) {
                                if (!empty($image['width'])) {
                                    $resource->setWidth(intval($image['width']));
                                }
                            }
                            if(array_key_exists('height', $image)) {
                                if (!empty($image['height'])) {
                                    $resource->setHeight(intval($image['height']));
                                }
                            }
                            if(array_key_exists('filesize', $image)) {
                                if (!empty($image['filesize'])) {
                                    $resource->setFilesize(intval($image['filesize']));
                                }
                            }
                            $resource->setLinked(2);
                            $this->entityManager->persist($resource);
                            $this->importedResources[$resourceId] = $resource;
                            $uploaded++;
                            if ($uploaded % 100 === 0) {
                                $this->entityManager->flush();
                            }
                        }
                    }
                }
            }
            if($hasMatchingImage) {
                $this->qi->updateResourceSpaceData($object, $resource, $resourceId, $rsFields, $rsImportMapping, $rsFullDataFields, $qiUrl, $this->resourceSpace);
            } else if(!array_key_exists($object->id, $this->objectIdsUploadedTo)) {
                $allImages = $this->resourceSpace->getAllImages($resourceId);
                foreach($fileSizes as $fileSize) {
                    $found = false;
                    foreach($allImages as $image) {
                        if($image['size_code'] === $fileSize) {
                            $found = true;
                            $filename = $object->id . '-1.' . $image['extension'];
                            $this->objectIdsUploadedTo[$object->id] = $object->id;
                            echo 'Uploading resource ' . $resourceId . ' to ' . $filename . ' (inventory number ' . $inventoryNumber . ').' . PHP_EOL;
                            if($this->update) {
                                if(!is_dir($ftpFolder)) {
                                    mkdir($ftpFolder, 0700, true);
                                    chown($ftpFolder, $ftpUser);
                                    chgrp($ftpFolder, $ftpGroup);
                                }
                                $path = $ftpFolder . $filename;
                                copy($image['url'], $path);
                                chown($path, $ftpUser);
                                chgrp($path, $ftpGroup);
                                chmod($path, 0600);

                                $resource = new Resource();
                                $resource->setImportTimestamp(new DateTime());
                                $resource->setResourceId($resourceId);
                                $resource->setObjectId($object->id);
                                $resource->setInventoryNumber($inventoryNumber);
                                $resource->setOriginalFilename($filename);
                                $size = getimagesize($path);
                                $resource->setWidth($size[0]);
                                $resource->setHeight($size[1]);
                                $resource->setFilesize(filesize($path));
                                $this->entityManager->persist($resource);
                                $this->importedResources[$resourceId] = $resource;
                                $uploaded++;
                                if ($uploaded % 100 === 0) {
                                    $this->entityManager->flush();
                                }
                            }
                            break;
                        }
                    }
                    if($found) {
                        break;
                    }
                }
            }
        }
        if($uploaded > 0) {
            $this->entityManager->flush();
        }
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

    private function storeResources($allResources, $rsFields, $rsLinkWithCmsValue,
                                    $allowedExtensions, $forbiddenInventoryNumberPrefixes, $forbiddenFilenamePostfixes)
    {
        $this->resourcesByResourceId = [];
        $this->resourcesByInventoryNumber = [];
        foreach($allResources as $resource) {
            $linkWithCms = $resource[$rsFields['linkwithcms']];
            if($linkWithCms === $rsLinkWithCmsValue) {
                $rsFilename = $resource[$rsFields['originalfilename']];
                $extension = strtolower(pathinfo($rsFilename, PATHINFO_EXTENSION));
                if (in_array($extension, $allowedExtensions)) {
                    $inventoryNumber = $resource[$rsFields['inventorynumber']];
                    if (!empty($inventoryNumber)) {
                        $forbiddenInventoryNumber = false;
                        foreach ($forbiddenInventoryNumberPrefixes as $prefix) {
                            if (StringUtil::startsWith($inventoryNumber, $prefix)) {
                                $forbiddenInventoryNumber = true;
                                break;
                            }
                        }
                        if (!$forbiddenInventoryNumber) {
                            $forbiddenFilename = false;
                            $filenameWithoutExtension = pathinfo($rsFilename, PATHINFO_FILENAME);
                            foreach ($forbiddenFilenamePostfixes as $postfix) {
                                if (StringUtil::endsWith($filenameWithoutExtension, $postfix)) {
                                    $forbiddenFilename = true;
                                    break;
                                }
                            }
                            if (!$forbiddenFilename) {
                                $this->resourcesByResourceId[intval($resource['ref'])] = $resource;
                                if (!array_key_exists($inventoryNumber, $this->resourcesByInventoryNumber)) {
                                    $this->resourcesByInventoryNumber[$inventoryNumber] = [];
                                }
                                $this->resourcesByInventoryNumber[$inventoryNumber][] = $resource;
                            }
                        }
                    }
                } else if (empty($extension)) {
                    echo 'Resource ' . $resource['ref'] . ' has no extension (' . $rsFilename . ')' . PHP_EOL;
                }
            }
        }
    }

    private function linkImportedResources($rsFields, $qiImportMapping, $qiMediaFolderId, $qiLinkDamsPrefix)
    {
        $i = 0;
        foreach($this->importedResources as $ir) {
            if($ir->getLinked() === 0) {
                if(array_key_exists($ir->getResourceId(), $this->resourcesByResourceId) && array_key_exists($ir->getInventoryNumber(), $this->resourcesByInventoryNumber)
                    && array_key_exists($ir->getObjectId(), $this->objectsByObjectId) && array_key_exists($ir->getInventoryNumber(), $this->objectsByInventoryNumber)) {
                    if(in_array($this->resourcesByResourceId[$ir->getResourceId()], $this->resourcesByInventoryNumber[$ir->getInventoryNumber()])
                        && $this->objectsByObjectId[$ir->getObjectId()] === $this->objectsByInventoryNumber[$ir->getInventoryNumber()]) {
                        $images = $this->qiImages[$ir->getObjectId()];
                        $resource = $this->resourcesByResourceId[$ir->getResourceId()];
                        $qiImage = $this->qi->getMatchingImageToBeLinked($images, $ir->getOriginalFilename(), $ir->getWidth(), $ir->getHeight(), $ir->getFilesize(), $qiMediaFolderId);
                        if ($qiImage !== null) {
                            $this->qi->updateMetadata($qiImage, $resource, $rsFields, $qiImportMapping, $qiLinkDamsPrefix, true);
                            $ir->setLinked(1);
                            $this->entityManager->persist($ir);
                            $this->linkedResources[$ir->getResourceId()] = $ir->getResourceId();
                            $i++;
                            if ($i % 100 === 0) {
                                $this->entityManager->flush();
                            }
                        } else {
                            $this->objectIdsUploadedTo[$ir->getObjectId()] = $ir->getObjectId();
                            echo 'ERROR: No matching image found for resource ' . $ir->getResourceId() . ' with object ' . $ir->getObjectId() . ' (inventory number ' . $ir->getInventoryNumber() . ')' . PHP_EOL;
                        }
                    } else {
                        $this->objectIdsUploadedTo[$ir->getObjectId()] = $ir->getObjectId();
                        echo 'ERROR: Mismatch for resource ' . $ir->getResourceId() . ' with object ' . $ir->getObjectId() . ' (inventory number ' . $ir->getInventoryNumber() . ')' . PHP_EOL;
                    }
                } else {
                    $this->objectIdsUploadedTo[$ir->getObjectId()] = $ir->getObjectId();
                    echo 'ERROR: No match found for resource ' . $ir->getResourceId() . ' with object ' . $ir->getObjectId() . ' (inventory number ' . $ir->getInventoryNumber() . ')' . PHP_EOL;
                }
            }
        }
        if($i > 0) {
            $this->entityManager->flush();
        }
    }

    private function updateQiSelfMetadata($qiMappingToSelf)
    {
        foreach($this->objectsByObjectId as $objectId => $object) {
            if(!empty($this->qiImages[$objectId])) {
                $hasLinkDams = false;
                foreach ($this->qiImages[$objectId] as $image) {
                    if($this->qi->hasLinkDams($image)) {
                        $hasLinkDams = true;
                        break;
                    }
                }

                if($hasLinkDams) {
                    $toUpdate = [];
                    $jsonObject = new JsonObject($object);
                    foreach($qiMappingToSelf as $key => $jsonPath) {
                        $result = $jsonObject->get($jsonPath);
                        if (!empty($result) && is_array($result)) {
                            $result = $result[0];
                            foreach ($this->qiImages[$objectId] as $image) {
                                if($this->qi->hasLinkDams($image)) {
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
    }
}
