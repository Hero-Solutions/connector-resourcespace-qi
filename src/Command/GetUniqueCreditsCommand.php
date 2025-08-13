<?php

namespace App\Command;

use App\Entity\Resource;
use App\Qi\Qi;
use App\ResourceSpace\ResourceSpace;
use App\Util\HttpUtil;
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
    private ParameterBagInterface $params;

    private ResourceSpace $resourceSpace;
    private bool $verbose;

    protected function configure(): void
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->verbose = $input->getOption('verbose');
        $this->test();
        return 0;
    }

    private function test(): void
    {
        $rsConfig = $this->params->get('resourcespace');
        $rsFields = $rsConfig['fields'];

        $sslCertificateAuthority = $this->params->get('ssl_certificate_authority');
        $httpUtil = new HttpUtil($sslCertificateAuthority, true);
        $this->resourceSpace = new ResourceSpace($rsConfig['api'], $httpUtil);
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
