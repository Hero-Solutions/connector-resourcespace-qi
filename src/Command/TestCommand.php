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

        foreach ($allResources as $resourceInfo) {
            $resourceId = $resourceInfo['ref'];
            $filename = $resourceInfo[$rsConfig['filename_field']];
            $inventoryNumber = $resourceInfo[$rsConfig['inventory_number_field']];
            if(array_key_exists($inventoryNumber, $allObjects)) {
                echo 'Resource ' . $resourceId . ' has matching object ' . $allObjects[$inventoryNumber]->name . PHP_EOL;
            }
            echo $filename . PHP_EOL;
        }
    }
}
