<?php

namespace App\Command;

use App\Qi\Qi;
use App\ResourceSpace\ResourceSpace;
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

        $this->resourceSpace = new ResourceSpace($rsConfig['api']);
        $this->qi = new Qi($qiConfig['api'], $sslCertificateAuthority);

        $allResources = $this->resourceSpace->getAllResources(urlencode($rsConfig['search_query']));
        echo count($allResources) . ' resources total' . PHP_EOL;

        $allObjects = $this->qi->getAllObjects();
        echo count($allObjects) . ' objects total' . PHP_EOL;

        $toUpload = 0;
        $same = 0;

        foreach ($allResources as $resourceInfo) {
            $inventoryNumber = $resourceInfo[$rsConfig['inventory_number_field']];
            if(array_key_exists($inventoryNumber, $allObjects)) {
                $resourceId = $resourceInfo['ref'];
                $rsFilename = $resourceInfo[$rsConfig['filename_field']];
                $upload = true;
                if(property_exists($allObjects[$inventoryNumber], 'media')) {
                    if(!empty($allObjects[$inventoryNumber]->media)) {
                        if (property_exists($allObjects[$inventoryNumber]->media, 'image')) {
                            if(!empty($allObjects[$inventoryNumber]->media->image)) {
                                if (property_exists($allObjects[$inventoryNumber]->media->image, 'filename')) {
                                    $qiFilename = $allObjects[$inventoryNumber]->media->image->filename;
                                    $rsFilenameWithoutExtension = strtolower(pathinfo($rsFilename, PATHINFO_FILENAME));
                                    $qiFilenameWithoutExtension = strtolower(pathinfo($qiFilename, PATHINFO_FILENAME));
                                    if($rsFilenameWithoutExtension === $qiFilenameWithoutExtension) {
                                        echo 'SAME: Resource ' . $resourceId . ' has matching object ' . $allObjects[$inventoryNumber]->name . ', image names: ' . $rsFilename . ', ' . $qiFilename . PHP_EOL;
                                        $upload = false;
                                    } else {
                                        echo 'CHECK: Resource ' . $resourceId . ' has matching object ' . $allObjects[$inventoryNumber]->name . ', image names: ' . $rsFilename . ', ' . $qiFilename . PHP_EOL;
                                    }
                                }
                            }
                        }
                    }
                }
                if($upload) {
                    echo 'UPLOAD: Resource ' . $resourceId . ' has matching object ' . $allObjects[$inventoryNumber]->name . ' for RS filename ' . $rsFilename . PHP_EOL;
                    $toUpload++;
                } else {
                    $same++;
                }
            }
//            echo $filename . PHP_EOL;
        }
        echo 'To upload: ' . $toUpload . ', same: ' . $same . PHP_EOL;
    }
}
