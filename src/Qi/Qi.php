<?php

namespace App\Qi;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class Qi
{
    private $baseUrl;
    private $username;
    private $password;
    private $overrideCertificateAuthorityFile;
    private $sslCertificateAuthorityFile;

    public function __construct($qiApi, $sslCertificateAuthority)
    {
        $this->baseUrl = $qiApi['url'];
        $this->username = $qiApi['username'];
        $this->password = $qiApi['password'];

        $this->overrideCertificateAuthorityFile = $sslCertificateAuthority['override'];
        $this->sslCertificateAuthorityFile = $sslCertificateAuthority['authority_file'];
    }

    public function getAllObjects()
    {
        $objects = array();

        $objsJson = $this->get($this->baseUrl . '/get/object/_fields/object_number');
        $objs = json_decode($objsJson);
        $count = $objs->count;
        $records = $objs->records;
        foreach($records as $record) {
            $objects[$record->object_number] = $record;
        }
        for($i = 1; $i < ($count + 499) / 500 - 1; $i++) {
            $objsJson = $this->get($this->baseUrl . '/get/object/_fields/object_number/_offset/' . ($i * 500));
            $objs = json_decode($objsJson);
            $records = $objs->records;
            foreach($records as $record) {
                $objects[$record->object_number] = $record;
            }
        }

        return $objects;
    }

    public function get($url)
    {
        echo 'GET '. $url . PHP_EOL;
        $ch = curl_init();
        if ($this->overrideCertificateAuthorityFile) {
            curl_setopt($ch,CURLOPT_CAINFO, $this->sslCertificateAuthorityFile);
            curl_setopt($ch,CURLOPT_CAPATH, $this->sslCertificateAuthorityFile);
        }
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);

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