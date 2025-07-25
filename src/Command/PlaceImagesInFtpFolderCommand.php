<?php

namespace App\Command;

use App\Util\StringUtil;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PlaceImagesInFtpFolderCommand extends Command
{
    /* @var $params ParameterBagInterface */
    private $params;
    private $debug;
    private $update;

    protected function configure()
    {
        $this
            ->setName('app:place-images-in-ftp-folder')
            ->setDescription('Moves media from the temporary folder to the FTP folder when no file exists with the same name.');
    }

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
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

        echo date("Y/m/d H:i:s") . PHP_EOL;

        if(is_dir($tmpFtpFolder)) {
            foreach (scandir($tmpFtpFolder) as $objectId) {
                $path = $tmpFtpFolder . $objectId;
                if (is_dir($path) && $objectId !== '.' && $objectId !== '..' && preg_match('/^[0-9]+$/', $objectId)) {
                    foreach (scandir($path) as $file) {
                        $filePath = $path . '/' . $file;
                        if (is_file($filePath)) {
                            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                            $targetFile = $ftpFolder . $objectId . '-1.' . $extension;
                            if (!file_exists($targetFile)) {
                                if($this->update) {
                                    if (!is_dir($ftpFolder)) {
                                        mkdir($ftpFolder, 0700, true);
                                        chown($ftpFolder, $ftpUser);
                                        chgrp($ftpFolder, $ftpGroup);
                                    }
                                    if(!rename($filePath, $targetFile)) {
                                        echo 'Error moving ' . $filePath . ' to ' . $targetFile . PHP_EOL;
                                    }
                                }
                                if($this->debug) {
                                   echo 'Moved file ' . $filePath . ' to ' . $targetFile . PHP_EOL;
                                }
                            }
                            break;
                        }
                    }
                    if(count(glob($path . '/*')) == 0) {
                        if($this->update) {
                            rmdir($path);
                        }
                        if($this->debug) {
                            echo 'Deleted empty directory ' . $path . PHP_EOL;
                        }
                    }
                }
            }
        }

        return 0;
    }
}