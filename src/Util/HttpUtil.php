<?php

namespace App\Util;

class HttpUtil
{
    private bool $debug;
    private bool $overrideCertificateAuthorityFile;
    private string $sslCertificateAuthorityFile;

    public function __construct($sslCertificateAuthority, $debug)
    {
        $this->overrideCertificateAuthorityFile = $sslCertificateAuthority['override'];
        $this->sslCertificateAuthorityFile = $sslCertificateAuthority['authority_file'];
        $this->debug = $debug;
    }

    public function get($url, $username = null, $password = null): string|bool
    {
        return $this->executeCurl('GET', $url, null, null, $username, $password);
    }

    public function put($url, $headers, $json, $username = null, $password = null): string|bool
    {
        return $this->executeCurl('PUT', $url, $headers, $json, $username, $password);
    }

    public function executeCurl($method, $url, $headers, $json, $username = null, $password = null): string|bool
    {
        if ($this->debug) {
            echo $url . PHP_EOL;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 300);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        if ($this->overrideCertificateAuthorityFile) {
            curl_setopt($ch,CURLOPT_CAINFO, $this->sslCertificateAuthorityFile);
            curl_setopt($ch,CURLOPT_CAPATH, $this->sslCertificateAuthorityFile);
        }
        if($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if($headers !== null) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        if($username !== null && $password !== null) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        }

        $resultRaw = curl_exec($ch);
        if($resultRaw === false) {
            echo 'HTTP error: ' . curl_error($ch) . PHP_EOL;
        } else if (!curl_errno($ch)) {
            switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
                case 200:  # OK
                    break;
                default:
                    echo 'HTTP error ' .  $http_code . ': ' . $resultRaw . PHP_EOL;
                    $resultRaw = false;
                    break;
            }
        }
        curl_close($ch);
        return $resultRaw;
    }
}