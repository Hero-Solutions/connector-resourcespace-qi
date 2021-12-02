<?php

namespace App\Command;

use App\Qi\Qi;
use App\ResourceSpace\ResourceSpace;
use JsonPath\InvalidJsonException;
use JsonPath\JsonObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TestCommand extends Command
{
    private $params;
    private $resourceSpace;
    private $qi;

    protected function configure()
    {
        $this
            ->setName('app:test')
            ->setDescription('Test');
    }

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
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
        $rsConfig = $this->params->get('resourcespace');
        $qiConfig = $this->params->get('qi');
        $sslCertificateAuthority = $this->params->get('ssl_certificate_authority');
        $rsFields = $rsConfig['fields'];
        $mapping = $this->params->get('mapping');
        $qiToRS = $mapping['qi_to_resourcespace'];
        $rsToQi = $mapping['resourcespace_to_qi'];

        $this->resourceSpace = new ResourceSpace($rsConfig['api']);
        $this->qi = new Qi($qiConfig, $sslCertificateAuthority);

        $allResources = $this->resourceSpace->getAllResources(urlencode($rsConfig['search_query']));
        echo count($allResources) . ' resources total' . PHP_EOL;
        $allResourceData = [];

        $allObjects = $this->qi->getAllObjects();
        echo count($allObjects) . ' objects total' . PHP_EOL;

        $toUpload = 0;
        $same = 0;
        $recordsMatched = array();

        $fp = fopen('records.csv', 'w');

        fputcsv($fp, array('rs_id', 'qi_id', 'rs_name', 'qi_name', 'rs_filename', 'qi_filename', 'same_filename'));

        foreach ($allResources as $resourceInfo) {
            $inventoryNumber = $resourceInfo[$rsFields['inventory_number']];
            if(array_key_exists($inventoryNumber, $allObjects)) {
                $resourceId = $resourceInfo['ref'];
                $resourceData = $this->resourceSpace->getResourceData($resourceId);
                $allResourceData[$resourceId] = $resourceData;
                $object = $allObjects[$inventoryNumber];
                $objectName = Qi::filterField($object->name);
                $recordsMatched[] = $inventoryNumber;
                $rsFilename = $resourceInfo[$rsFields['filename']];
                $upload = true;
                $write = true;
                if(property_exists($object, 'media')) {
                    if(!empty($object->media)) {
                        if (property_exists($object->media, 'image')) {
                            if(!empty($object->media->image)) {
                                if (property_exists($object->media->image, 'filename')) {
                                    $qiFilename = $object->media->image->filename;
                                    $rsFilenameWithoutExtension = strtolower(pathinfo($rsFilename, PATHINFO_FILENAME));
                                    $qiFilenameWithoutExtension = strtolower(pathinfo($qiFilename, PATHINFO_FILENAME));
                                    fputcsv($fp, array($resourceId, $object->id, $resourceInfo[$rsFields['title']], $objectName, $rsFilename, $qiFilename, $rsFilenameWithoutExtension === $qiFilenameWithoutExtension ? 'X' : ''));
                                    $write = false;
                                    if($rsFilenameWithoutExtension === $qiFilenameWithoutExtension) {
//                                        echo 'SAME: Resource ' . $resourceId . ' has matching object ' . $objectName . ', image names: ' . $rsFilename . ', ' . $qiFilename . PHP_EOL;
                                        $upload = false;
                                    } else {
//                                        echo 'CHECK: Resource ' . $resourceId . ' has matching object ' . $objectName . ', image names: ' . $rsFilename . ', ' . $qiFilename . PHP_EOL;
                                    }
                                }
                            }
                        }
                    }
                }
                if($write) {
                    fputcsv($fp, array($resourceId, $object->id, $resourceInfo[$rsFields['title']], $objectName, $rsFilename, '', ''));
                }
                if($upload) {
//                    echo 'UPLOAD: Resource ' . $resourceId . ' has matching object ' . $objectName . ' for RS filename ' . $rsFilename . PHP_EOL;
                    $toUpload++;
                } else {
                    $same++;
                }

                try {
                    $jsonObject = new JsonObject($object);
                    foreach ($qiToRS as $fieldName => $field) {
                        $res = Qi::getField($jsonObject, $fieldName, $field, $resourceData);
                        if($res != null) {
                            $nodeValue = false;
                            if(array_key_exists('node_value', $field)) {
                                if($field['node_value'] === 'yes') {
                                    $nodeValue = true;
                                }
                            }
//                                $this->resourceSpace->updateField($resourceId, $fieldName, $res, $nodeValue);
                                echo $fieldName . ' - ' . $res . PHP_EOL;
                        }
                    }
                } catch (InvalidJsonException $e) {
                     echo 'JSONPath error: ' . $e->getMessage() . PHP_EOL;
                }
            }
//            echo $filename . PHP_EOL;
        }
        foreach ($allResources as $resourceInfo) {
            $inventoryNumber = $resourceInfo[$rsFields['inventory_number']];
            if(!array_key_exists($inventoryNumber, $allObjects)) {
                fputcsv($fp, array($resourceId, '', $resourceInfo[$rsFields['title']], '', $rsFilename, '', ''));
            }
        }
        foreach($allObjects as $inventoryNumber => $object) {
            if(!in_array($inventoryNumber, $recordsMatched)) {
                $write = true;
                if(property_exists($allObjects[$inventoryNumber], 'media')) {
                    $object = $allObjects[$inventoryNumber];
                    $objectName = Qi::filterField($object->name);
                    if(!empty($object->media)) {
                        if (property_exists($object->media, 'image')) {
                            if(!empty($object->media->image)) {
                                if (property_exists($object->media->image, 'filename')) {
                                    $qiFilename = $object->media->image->filename;
                                    $write = false;
                                    fputcsv($fp, array('', '', '', $object->id,  $objectName, $qiFilename, ''));
                                }
                            }
                        }
                    }
                }
                if($write) {
                    fputcsv($fp, array('', $object->id, '', $objectName, '', ''));
                }
            }
        }
        fclose($fp);
        echo 'To upload: ' . $toUpload . ', same: ' . $same . PHP_EOL;
    }
}
