<?php

namespace App\Util;

class HttpUtil
{
    private $debug;
    private $overrideCertificateAuthorityFile;
    private $sslCertificateAuthorityFile;

    public function __construct($sslCertificateAuthority, $debug)
    {
        $this->overrideCertificateAuthorityFile = $sslCertificateAuthority['override'];
        $this->sslCertificateAuthorityFile = $sslCertificateAuthority['authority_file'];
        $this->debug = $debug;
    }

    public function get($url)
    {
        if($this->debug) {
            echo $url . PHP_EOL;
        }

        $ch = curl_init();
        if ($this->overrideCertificateAuthorityFile) {
            curl_setopt($ch,CURLOPT_CAINFO, $this->sslCertificateAuthorityFile);
            curl_setopt($ch,CURLOPT_CAPATH, $this->sslCertificateAuthorityFile);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $resultRaw = curl_exec($ch);
        if($resultRaw === false) {
            echo 'HTTP error: ' . curl_error($ch) . PHP_EOL;
        } else if (!curl_errno($ch)) {
            switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
                case 200:  # OK
                    break;
                default:
                    echo 'HTTP error ' .  $http_code . ': ' . $resultRaw . PHP_EOL;
                    break;
            }
        }
        curl_close($ch);
        return $resultRaw;
    }
}