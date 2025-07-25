<?php

namespace App\Command;

use App\Entity\Resource;
use App\Entity\UnlinkedResource;
use App\Qi\Qi;
use App\ResourceSpace\ResourceSpace;
use App\Util\HttpUtil;
use App\Util\StringUtil;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use JsonPath\JsonObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ProcessCommand extends Command
{
    /* @var $params ParameterBagInterface */
    private $params;
    /* @var $entityManager EntityManagerInterface */
    private $entityManager;
    private $update;

    /* @var $resourceSpace ResourceSpace */
    private $resourceSpace;
    /* @var $qi Qi */
    private $qi;

    private $debug;
    private $verbose;
    private $fullProcessing;

    private $resourcesByResourceId;
    private $resourcesByInventoryNumber;
    private $resourcesByFilename;
    private $objectsByObjectId;
    private $objectsByInventoryNumber;
    private $qiImages;
    private $linkedResources;
    /* @var $importedResources Resource[] */
    private $importedResources;

    private $httpUtil;
    private $qiReindexUrl;

    protected function configure()
    {
        $this
            ->setName('app:process')
            ->addOption('full-processing', null, InputOption::VALUE_NONE, 'Perform full processing, loading all Qi objects instead of only the recent ones.')
            ->setDescription('Links ResourceSpace resources to Qi objects, offloads resources where needed and exchanges metadata between both systems.');
    }

    public function __construct(ParameterBagInterface $params, EntityManagerInterface $entityManager)
    {
        $this->params = $params;
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        file_put_contents('/tmp/connector_process.pid', getmypid());
        $this->verbose = $input->getOption('verbose');
        $this->fullProcessing = $input->getOption('full-processing');
        $this->process();
        unlink('/tmp/connector_process.pid');
        return 0;
    }

    private function process()
    {
        $test = $this->params->get('test');
        $this->debug = $this->params->get('debug');
        $this->update = $this->params->get('update');
        $ftpFolder = $this->params->get('ftp_folder');
        if(!StringUtil::endsWith($ftpFolder, '/')) {
            $ftpFolder .= '/';
        }
        $tmpFtpFolder = $this->params->get('tmp_ftp_folder');
        if(!StringUtil::endsWith($tmpFtpFolder, '/')) {
            $tmpFtpFolder .= '/';
        }
        $ftpUser = $this->params->get('ftp_user');
        $ftpGroup = $this->params->get('ftp_group');
        $onlyOnlineRecords = $this->params->get('only_online_records');
        $recordsUpdatedSince = $this->params->get('records_updated_since');

        $allowedExtensions = $this->params->get('allowed_extensions');
        $allowedFiletypes = $this->params->get('allowed_filetypes');
        $fileSizes = $this->params->get('file_sizes');
        $forbiddenInventoryNumberPrefixes = $this->params->get('forbidden_inventory_number_prefixes');
        $forbiddenFilenamePostfixes = $this->params->get('forbidden_filename_postfixes');
        $creditConfig = $this->params->get('credit');

        $rsConfig = $this->params->get('resourcespace');
        $rsFields = $rsConfig['fields'];
        $rsLinkWithCmsValues = [];
        foreach($rsConfig['linkwithcmsvalues'] as $value) {
            $rsLinkWithCmsValues[$value] = $value;
        }
        $rsImportMapping = $rsConfig['import_mapping'];
        $rsFullDataFields = $rsConfig['full_data_fields'];
        $maxFieldValueLength = $rsConfig['api']['max_field_value_length'];

        $qiConfig = $this->params->get('qi');
        $qiUrl = $qiConfig['url'];
        $this->qiReindexUrl = $qiConfig['reindex_url'];
        $qiLinkDamsPrefix = $qiConfig['link_dams_prefix'];
        $qiMediaFolderIds = $qiConfig['media_folder_ids'];
        $qiImportMapping = $qiConfig['import_mapping'];
        $qiMappingToSelf = $qiConfig['mapping_to_self'];

        $sslCertificateAuthority = $this->params->get('ssl_certificate_authority');

        $this->httpUtil = new HttpUtil($sslCertificateAuthority, $this->debug);

        //Do not reindex orphaned resources that never managed to get linked when they are older than 1 month
        //TODO rethink this approach, because when these older linked and orphaned resources are being manually removed from objects, the object gets bugged
//        $oneMonthAgo = new DateTime('-1 month');

        /* @var $importedResourcesObjects Resource[] */
        $importedResourcesObjects = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Resource::class, 'r')
//            ->where('r.importTimestamp > :oneMonthAgo')
//            ->setParameter('oneMonthAgo', $oneMonthAgo)
            ->getQuery()
            ->getResult();
        $this->importedResources = [];
        $indexedObjects = [];
        foreach($importedResourcesObjects as $importedResource) {
            $this->importedResources[$importedResource->getResourceId()] = $importedResource;
            if($importedResource->getLinked() === 0 && !array_key_exists($importedResource->getObjectId(), $indexedObjects)) {
                if($this->update) {
                    $this->httpUtil->get($this->qiReindexUrl . $importedResource->getObjectId());
                    $indexedObjects[$importedResource->getObjectId()] = $importedResource->getObjectId();
                }
            }
        }

        $this->resourceSpace = new ResourceSpace($rsConfig['api'], $this->httpUtil);
        $allResources = $this->resourceSpace->getAllResources(urlencode($rsConfig['search_query']));
        $this->storeResources($allResources, $rsFields, $rsLinkWithCmsValues, $allowedExtensions, $allowedFiletypes, $forbiddenInventoryNumberPrefixes, $forbiddenFilenamePostfixes);
        echo count($this->resourcesByResourceId) . ' resources total for ' . count($this->resourcesByInventoryNumber) . ' unique inventory numbers.' . PHP_EOL;

        $this->qi = new Qi($this->entityManager, $qiConfig, $sslCertificateAuthority, $creditConfig, $test, $this->debug, $this->update, $this->fullProcessing, $onlyOnlineRecords, $this->httpUtil, $maxFieldValueLength);
        $this->qi->retrieveAllObjects($recordsUpdatedSince);
        $this->objectsByObjectId = $this->qi->getObjectsByObjectId();
        $this->objectsByInventoryNumber = $this->qi->getObjectsByInventoryNumber();
        echo count($this->objectsByInventoryNumber) . ' Qi objects with inventory number (' . count($this->objectsByObjectId) . ' total).' . PHP_EOL;

        $this->qiImages = [];
        foreach($this->objectsByObjectId as $objectId => $object) {
            $this->qiImages[$objectId] = $this->qi->getMediaInfos($object, $qiImportMapping, $qiMappingToSelf);
        }

        $this->linkedResources = [];

        // Remove the links in the database that no longer exist (most likely images that were manually removed from Qi)
        $this->unlinkDeletedMedia($qiLinkDamsPrefix);

        // Add Link DAMS and metadata to images in Qi that were imported in a previous run
        $this->linkImportedResources($rsFields, $qiImportMapping, $qiMediaFolderIds, $qiLinkDamsPrefix);

        // Update Qi image metadata with metadata from Qi objects
        $this->updateQiSelfMetadata($qiMappingToSelf);

        foreach ($this->resourcesByFilename as $inventoryNumber => $resourcesByEnding) {
            foreach($resourcesByEnding as $ending => $resources) {
                foreach ($resources as $resourceId => $resource) {
                    // Skip resources that are already linked to an object in Qi
                    if (array_key_exists($resourceId, $this->linkedResources)) {
                        echo 'Skipping resource ' . $resourceId . ' as it is already linked in Qi.' . PHP_EOL;
                        continue;
                    }
                    $inventoryNumber = $resource[$rsFields['inventorynumber']];
                    if (empty($inventoryNumber)) {
                        continue;
                    }
                    if (!array_key_exists($inventoryNumber, $this->objectsByInventoryNumber)) {
                        echo 'Skipping resource ' . $resourceId . ' as there are no objects with this inventory number.' . PHP_EOL;
                        continue;
                    }

                    $object = $this->objectsByInventoryNumber[$inventoryNumber];
                    $rsFilename = $resource[$rsFields['originalfilename']];
                    $hasMatchingImage = false;

                    $resourceIsLinked = false;
                    if (array_key_exists($resourceId, $this->importedResources)) {
                        $resourceIsLinked = true;
                    }

                    foreach ($this->qiImages[$object->id] as $id => $image) {
                        if ($this->qi->hasLinkDams($image)) {
                            //Update metadata for images that were automatically imported from ResourceSpace to Qi
                            if ($image['link_dams'] === $qiLinkDamsPrefix . $resourceId) {
                                $hasMatchingImage = true;
                                $this->qi->updateMetadata($image, $resource, $rsFields, $qiImportMapping, $qiLinkDamsPrefix, false, $this->qiReindexUrl . $object->id);
                            }
                        } else if (!$resourceIsLinked && array_key_exists('filename', $image)) {
                            //Link older images in Qi that once were manually copied from ResourceSpace to Qi
                            $fromRS = true;
                            if (!array_key_exists('media_folder_id', $image)) {
                                $fromRS = false;
                            } else if (!in_array($image['media_folder_id'], $qiMediaFolderIds)) {
                                $fromRS = false;
                            }
                            if (!$fromRS) {
                                if ($this->filenamesMatch($resourceId, $rsFilename, $image['filename'])) {
                                    $hasMatchingImage = true;
                                    echo 'Found matching image ' . $resourceId . ' for object ' . $object->id . ' (inv ' . $inventoryNumber . ')' . PHP_EOL;
                                    if($this->update) {
                                        $this->qi->updateMetadata($image, $resource, $rsFields, $qiImportMapping, $qiLinkDamsPrefix, true, $this->qiReindexUrl . $object->id);

                                        $resourceObject = new Resource();
                                        $resourceObject->setImportTimestamp(new DateTime());
                                        $resourceObject->setResourceId($resourceId);
                                        $resourceObject->setObjectId($object->id);
                                        $resourceObject->setInventoryNumber($inventoryNumber);
                                        if (array_key_exists('original_filename', $image)) {
                                            if (!empty($image['original_filename'])) {
                                                $resourceObject->setOriginalFilename($image['original_filename']);
                                            }
                                        }
                                        if (array_key_exists('width', $image)) {
                                            if (!empty($image['width'])) {
                                                $resourceObject->setWidth(intval($image['width']));
                                            }
                                        }
                                        if (array_key_exists('height', $image)) {
                                            if (!empty($image['height'])) {
                                                $resourceObject->setHeight(intval($image['height']));
                                            }
                                        }
                                        if (array_key_exists('filesize', $image)) {
                                            if (!empty($image['filesize'])) {
                                                $resourceObject->setFilesize(intval($image['filesize']));
                                            }
                                        }
                                        $resourceObject->setLinked(2);
                                        $this->entityManager->persist($resourceObject);
                                        $this->entityManager->flush();
                                    }
                                    $this->importedResources[$resourceId] = $resourceObject;
                                    $resourceIsLinked = true;
                                }
                            }
                        }
                    }
                    if ($hasMatchingImage) {
                        $this->qi->updateResourceSpaceData($object, $resource, $resourceId, $rsFields, $rsImportMapping, $rsFullDataFields, $qiUrl, $this->resourceSpace);
                    } else if (!$resourceIsLinked) {
                        echo 'Checking if resource ' . $resourceId . ' is to be uploaded to object ' . $object->id . ' (inventory number ' . $inventoryNumber . ')' . PHP_EOL;
                        $allImages = $this->resourceSpace->getAllImages($resourceId);
                        foreach ($fileSizes as $fileSize) {
                            $found = false;
                            foreach ($allImages as $image) {
                                if ($image['size_code'] === $fileSize) {
                                    $found = true;
                                    $filename = $object->id . '-1.' . strtolower($image['extension']);
                                    echo 'Uploading resource ' . $resourceId . ' to ' . $filename . ' (inventory number ' . $inventoryNumber . ').' . PHP_EOL;
                                    if ($this->update) {
                                        if (!is_dir($ftpFolder)) {
                                            mkdir($ftpFolder, 0700, true);
                                            chown($ftpFolder, $ftpUser);
                                            chgrp($ftpFolder, $ftpGroup);
                                        }

                                        //Put additional images in a temporary directory so they do not overwrite each other.
                                        //These images are processed by PlaceImagesInFtpFolderCommand
                                        //TODO check if '-2' '-3' etc doesn't simply work as well
                                        if(file_exists($ftpFolder . $filename)) {
                                            if(!is_dir($tmpFtpFolder)) {
                                                mkdir($tmpFtpFolder, 0700, true);
                                                chown($tmpFtpFolder, $ftpUser);
                                                chgrp($tmpFtpFolder, $ftpGroup);
                                            }
                                            $fileDir = $tmpFtpFolder . $object->id . '/';
                                            if(is_dir($fileDir)) {
                                                $i = 0;
                                                do {
                                                    $path = $fileDir . $i . '.' . strtolower($image['extension']);
                                                    $i++;
                                                } while (file_exists($path));
                                            } else {
                                                mkdir($fileDir, 0700, true);
                                                chown($fileDir, $ftpUser);
                                                chgrp($fileDir, $ftpGroup);
                                                $path = $fileDir . '0.' . strtolower($image['extension']);
                                            }
                                        } else {
                                            $path = $ftpFolder . $filename;
                                        }
                                        if(copy($image['url'], $path)) {
                                            chown($path, $ftpUser);
                                            chgrp($path, $ftpGroup);
                                            chmod($path, 0600);

                                            //Clear the file status cache to ensure we're not getting wrong filesize value
                                            clearstatcache(true, $path);

                                            $resourceObject = new Resource();
                                            $resourceObject->setImportTimestamp(new DateTime());
                                            $resourceObject->setResourceId($resourceId);
                                            $resourceObject->setObjectId($object->id);
                                            $resourceObject->setInventoryNumber($inventoryNumber);
                                            $resourceObject->setOriginalFilename($filename);
                                            $size = getimagesize($path);
                                            $resourceObject->setWidth($size[0]);
                                            $resourceObject->setHeight($size[1]);
                                            $resourceObject->setFilesize(filesize($path));
                                            $resourceObject->setLinked(0);
                                            $this->entityManager->persist($resourceObject);
                                            $this->importedResources[$resourceId] = $resourceObject;
                                            $this->entityManager->flush();
                                        } else {
                                            echo 'Error downloading resource ' . $resourceId . ' to ' . $path . ' (inventory number ' . $inventoryNumber . ').' . PHP_EOL;
                                        }
                                    }
                                    break;
                                }
                            }
                            if ($found) {
                                break;
                            }
                        }
                    }
                }
            }
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

    private function storeResources($allResources, $rsFields, $rsLinkWithCmsValues,
                                    $allowedExtensions, $allowedFiletypes, $forbiddenInventoryNumberPrefixes, $forbiddenFilenamePostfixes)
    {
        $this->resourcesByResourceId = [];
        $this->resourcesByInventoryNumber = [];
        $this->resourcesByFilename = [];
        $tmpResourcesByFilename = [];
        foreach($allResources as $resource) {
            $linkWithCms = $resource[$rsFields['linkwithcms']];
            if(array_key_exists($linkWithCms, $rsLinkWithCmsValues)) {
                echo 'Resource ' . $resource['ref'] . ' for inventory number ' . $resource[$rsFields['inventorynumber']] . PHP_EOL;
                $rsFilename = $resource[$rsFields['originalfilename']];
                $extension = strtolower(pathinfo($rsFilename, PATHINFO_EXTENSION));

                $fileExtension = '';
                if(array_key_exists($rsFields['fileextension'], $resource)) {
                    $fileExtension = $resource[$rsFields['fileextension']];
                }

                $filetype = '';
                if(array_key_exists($rsFields['filetype'], $resource)) {
                    $filetype = $resource[$rsFields['filetype']];
                }

                if (in_array($extension, $allowedExtensions) || in_array($fileExtension, $allowedExtensions) || in_array($filetype, $allowedFiletypes)) {
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
                                $resourceId = intval($resource['ref']);
                                $this->resourcesByResourceId[$resourceId] = $resource;
                                if (!array_key_exists($inventoryNumber, $this->resourcesByInventoryNumber)) {
                                    $this->resourcesByInventoryNumber[$inventoryNumber] = [];
                                }
                                $this->resourcesByInventoryNumber[$inventoryNumber][] = $resource;

                                // Sort the resources based on the postfix in their filename (_01, _01_ret, _01_det1, _01_retouche, _02, ..., _M01, _M02, ...)
                                $ending = "9999999999999999999";
                                if(preg_match('/^.*_M?[0-9]+_retouche[0-9]*$/', $filenameWithoutExtension)) {
                                    $ending = preg_replace('/^.*_(M?[0-9]+_retouche[0-9]*)$/', '$1', $filenameWithoutExtension);
                                } else if(preg_match('/^.*_M?[0-9]+_[rd]et[0-9]*$/', $filenameWithoutExtension)) {
                                    $ending = preg_replace('/^.*_(M?[0-9]+_[rd]et[0-9]*)$/', '$1', $filenameWithoutExtension);
                                } else if(preg_match('/^.*_M?[0-9]+$/', $filenameWithoutExtension)) {
                                    $ending = preg_replace('/^.*_(M?[0-9]+)$/', '$1', $filenameWithoutExtension);
                                }
                                if(!array_key_exists($inventoryNumber, $tmpResourcesByFilename)) {
                                    $tmpResourcesByFilename[$inventoryNumber] = [];
                                }
                                if(!array_key_exists($ending, $tmpResourcesByFilename[$inventoryNumber])) {
                                    $tmpResourcesByFilename[$inventoryNumber][$ending] = [];
                                }
                                $tmpResourcesByFilename[$inventoryNumber][$ending][$resourceId] = $resource;
                            } else {
                                echo 'Resource ' . $resource['ref'] . ' has forbidden filename (' . $filenameWithoutExtension . ')' . PHP_EOL;
                            }
                        } else {
                            echo 'Resource ' . $resource['ref'] . ' has forbidden inventory number (' . $inventoryNumber . ')' . PHP_EOL;
                        }
                    } else {
                        echo 'Resource ' . $resource['ref'] . ' has no inventory number.' . PHP_EOL;
                    }
                } else if (empty($extension)) {
                    echo 'Resource ' . $resource['ref'] . ' has no extension (' . $rsFilename . ')' . PHP_EOL;
                } else {
                    echo 'Resource ' . $resource['ref'] . ' has forbidden extension (' . $rsFilename . ')' . PHP_EOL;
                }
            }
        }
        foreach($tmpResourcesByFilename as $inventoryNumber => $resourcesByEnding) {
            ksort($resourcesByEnding);
            $this->resourcesByFilename[$inventoryNumber] = $resourcesByEnding;
        }
    }

    private function unlinkDeletedMedia($qiLinkDamsPrefix)
    {
        foreach($this->importedResources as $resourceId => $ir) {
            if($ir->getLinked() > 0) {
                //TODO what if the object is gone from Qi?
                if(array_key_exists($ir->getObjectId(), $this->objectsByObjectId)) {
                    $linked = false;
                    $images = $this->qiImages[$ir->getObjectId()];
                    foreach($images as $id => $image) {
                        if (array_key_exists('link_dams', $image)) {
                            if($image['link_dams'] === $qiLinkDamsPrefix . $ir->getResourceId()) {
                                $linked = true;
                                break;
                            }
                        }
                    }
                    if(!$linked) {
                        $this->unlinkResource($ir);
                    }
                }
            }
        }
    }

    private function unlinkResource($ir)
    {
        echo 'Unlink resource ' . $ir->getResourceId() . ' from object ' . $ir->getObjectId() . ' (inv. ' . $ir->getInventoryNumber() . ')' . PHP_EOL;
        unset($this->importedResources[$ir->getResourceId()]);
        if($this->update) {
            $unlinkedResource = new UnlinkedResource();
            $unlinkedResource->setImportTimestamp($ir->getImportTimestamp());
            $unlinkedResource->setResourceId($ir->getResourceId());
            $unlinkedResource->setObjectId($ir->getObjectId());
            $unlinkedResource->setInventoryNumber($ir->getInventoryNumber());
            $unlinkedResource->setOriginalFilename($ir->getOriginalFilename());
            $unlinkedResource->setWidth($ir->getWidth());
            $unlinkedResource->setHeight($ir->getHeight());
            $unlinkedResource->setFilesize($ir->getFilesize());
            $unlinkedResource->setLinked($ir->getLinked());
            $this->entityManager->persist($unlinkedResource);
            $this->entityManager->flush();
            $this->entityManager->remove($ir);
            $this->entityManager->flush();
        }
    }

    private function linkImportedResources($rsFields, $qiImportMapping, $qiMediaFolderIds, $qiLinkDamsPrefix)
    {
        foreach($this->importedResources as $ir) {
            if($ir->getLinked() === 0) {
                if(array_key_exists($ir->getResourceId(), $this->resourcesByResourceId) && array_key_exists($ir->getObjectId(), $this->objectsByObjectId)) {
                    $images = $this->qiImages[$ir->getObjectId()];
                    $resource = $this->resourcesByResourceId[$ir->getResourceId()];
                    $qiImage = $this->qi->getMatchingImageToBeLinked($images, $ir->getOriginalFilename(), $ir->getWidth(), $ir->getHeight(), $ir->getFilesize(), $qiMediaFolderIds);
                    if ($qiImage !== null) {
                        if($this->update) {
                            $this->qi->updateMetadata($qiImage, $resource, $rsFields, $qiImportMapping, $qiLinkDamsPrefix, true, $this->qiReindexUrl . $ir->getObjectId());
                            $ir->setLinked(1);
                            $this->entityManager->persist($ir);
                            $this->entityManager->flush();
                        }
                        $this->linkedResources[$ir->getResourceId()] = $ir->getResourceId();
                    } else {
                        echo 'ERROR: No matching image found for resource ' . $ir->getResourceId() . ' with object ' . $ir->getObjectId() . ' (inventory number ' . $ir->getInventoryNumber() . ')' . PHP_EOL;
                        //If problem persists for 2 weeks, automatically mark the resource as unlinked
                        if($ir->getImportTimestamp() < new DateTime('-2 weeks')) {
                            $this->unlinkResource($ir);
                        }
                    }
                } else {
                    echo 'ERROR: No match found for resource ' . $ir->getResourceId() . ' with object ' . $ir->getObjectId() . ' (inventory number ' . $ir->getInventoryNumber() . ')' . PHP_EOL;
                    //If problem persists for 2 weeks, automatically mark the resource as unlinked
                    if($ir->getImportTimestamp() < new DateTime('-2 weeks')) {
                        $this->unlinkResource($ir);
                    }
                }
            }
        }
    }

    private function updateQiSelfMetadata($qiMappingToSelf)
    {
        foreach($this->objectsByObjectId as $objectId => $object) {
            if(!empty($this->qiImages[$objectId])) {
                $hasLinkDams = false;
                foreach ($this->qiImages[$objectId] as $id => $image) {
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
                            foreach ($this->qiImages[$objectId] as $id => $image) {
                                if($this->qi->hasLinkDams($image)) {
                                    $changed = false;
                                    if (!array_key_exists($key, $image)) {
                                        $changed = true;
                                    } else if ($image[$key] !== $result && !(empty($image[$key]) && empty($result))) {
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
