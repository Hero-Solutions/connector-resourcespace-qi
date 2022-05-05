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

class GetUniqueCreditsCommand extends Command
{
    private $params;

    private $resourceSpace;

    protected function configure()
    {
        $this
            ->setName('app:get-unique-credits')
            ->setDescription('Fetches all unique Credits from ResourceSpace');
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
        $rsFields = $rsConfig['fields'];

        $this->resourceSpace = new ResourceSpace($rsConfig['api']);
        $allResources = $this->resourceSpace->getAllResources(urlencode($rsConfig['search_query']));

        $allCredits = [];
        foreach($allResources as $resource) {
            $credit = $resource[$rsFields['credit']];
            $credit = preg_replace( '/[^[:print:]]/', '', $credit);
            if(!array_key_exists($credit, $allCredits)) {
                $allCredits[$credit] = 1;
            } else {
                $allCredits[$credit]++;
            }
        }
        echo PHP_EOL;
        echo PHP_EOL;
        foreach($allCredits as $credit => $amount) {
            echo $amount . ';' . $credit . PHP_EOL;
        }
        echo PHP_EOL;
        echo PHP_EOL;
    }
}
