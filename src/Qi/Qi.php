<?php

namespace App\Qi;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class Qi
{
    private $baseUrl;
    private $username;
    private $password;
    private $getFields;
    private $overrideCertificateAuthorityFile;
    private $sslCertificateAuthorityFile;

    public function __construct($qi, $sslCertificateAuthority)
    {
        $qiApi = $qi['api'];
        $this->baseUrl = $qiApi['url'];
        $this->username = $qiApi['username'];
        $this->password = $qiApi['password'];
        $this->getFields = $qi['get_fields'];

        $this->overrideCertificateAuthorityFile = $sslCertificateAuthority['override'];
        $this->sslCertificateAuthorityFile = $sslCertificateAuthority['authority_file'];
    }

    public static function filterResults($results, $fieldName, $field, $resourceData) {
        $res = null;
        if(is_string($results)) {
            $results = [ self::filterField($results) ];
        }
        if(is_array($results)) {
            if(count($results) > 0) {
                if (array_key_exists('type', $field)) {
                    switch ($field['type']) {
                        case 'date_range':
                            if (count($results) === 2) {
                                $results[0] = Qi::filterField($results[0]);
                                if (!preg_match('/^[0-9]{1,4}-[0-9][0-9]-[0-9][0-9]$/', $results[0])) {
                                    if (preg_match('/^[0-9]{1,4}$/', $results[0])) {
                                        $results[0] = $results[0] . '-01-01';
                                    }
                                }
                                $results[1] = Qi::filterField($results[1]);
                                if (!preg_match('/^[0-9]{1,4}-[0-9][0-9]-[0-9][0-9]$/', $results[1])) {
                                    if (preg_match('/^[0-9]{1,4}$/', $results[1])) {
                                        $results[1] = $results[1] . '-12-31';
                                    }
                                }
                                if (preg_match('/^[0-9]{1,4}-[0-9][0-9]-[0-9][0-9]$/', $results[0])
                                    && preg_match('/^[0-9]{1,4}-[0-9][0-9]-[0-9][0-9]$/', $results[1])) {
                                    $res = $results[0] . ',' . $results[1];
                                }
                            } else if (count($results) === 1) {
                                $results[0] = Qi::filterField($results[0]);
                                if (!preg_match('/^[0-9]{1,4}-[0-9][0-9]-[0-9][0-9]$/', $results[0])) {
                                    if (preg_match('/^[0-9]{1,4}$/', $results[0])) {
                                        $results[0] = $results[0] . '-01-01,' . $results[0] . '-12-31';
                                    }
                                } else {
                                    $res = $results[0] . ',' . $results[0];
                                }
                            } else {
                                //TODO sometimes, there are two 'from' and 'to' dates. What do we want to do with these?
                            }
                            break;
                    }
                } else if (array_key_exists('mapping', $field)) {
                    if (array_key_exists($results[0], $field['mapping'])) {
                        $res = $field['mapping'][$results[0]];
                    } else {
                        echo 'Unknown ' . $fieldName . ': ' . $results[0] . PHP_EOL;
                    }
                } else {
                    $res = self::filterField(implode(',', $results));
                }
            }
            if($res != null && array_key_exists('overwrite', $field)) {
                if($field['overwrite'] === 'no' && array_key_exists($fieldName, $resourceData)) {
                    if(!empty($resourceData[$fieldName])) {
                        echo 'NO OVERWRITE ' . $res . PHP_EOL;
                        $res = null;
                    }
                }
            }
        }
        return $res;
    }

    public static function filterField($field) {
        $field = str_replace("<i>", '', $field);
        $field = str_replace("</i>", '', $field);
        $field = str_replace("\n", ' ', $field);
        $field = str_replace("/__/__", '', $field);
        return $field;
    }

    public function getAllObjects()
    {
        $objects = array();

        $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields));
        $objs = json_decode($objsJson);
        $count = $objs->count;
        $records = $objs->records;
        foreach($records as $record) {
            $objects[$record->object_number] = $record;
        }
        //TODO remove the $i < 1, this is to only grab a few records without it being too slow
        for($i = 1; $i < ($count + 499) / 500 - 1 && false; $i++) {
            $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields) . '/' . ($i * 500));
            $objs = json_decode($objsJson);
            $records = $objs->records;
            foreach($records as $record) {
                if(!empty($record->object_number)) {
                    $objects[$record->object_number] = $record;
                } else {
                    echo 'Error: Qi record ' . $record->id . ' has no inventory number' . PHP_EOL;
                }
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
