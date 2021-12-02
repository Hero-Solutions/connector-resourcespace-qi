<?php

namespace App\Qi;

use JsonPath\InvalidJsonException;
use JsonPath\JsonObject;

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

    public static function getField($jsonObject, $fieldName, $field, $resourceData) {
        if(array_key_exists('type', $field)) {
            if($field['type'] === 'list') {
                if(!array_key_exists('parent_path', $field) || !array_key_exists('paths', $field)) {
                    echo 'Error: missing "parent_path" or "paths" for type "date_range" (field "' . $fieldName . '").' . PHP_EOL;
                    return null;
                } else {
                    $parentObjects = self::resultsToArray($jsonObject->get($field['parent_path']));
                    $results = [];
                    foreach($parentObjects as $parentObject) {
                        try {
                            $object = new JsonObject($parentObject);
                        } catch (InvalidJsonException $e) {
                            echo 'JSONPath error: ' . $e->getMessage() . PHP_EOL;
                        }
                        $res = null;
                        foreach($field['paths'] as $path) {
                            $childResults = self::resultsToArray($object->get($path));
                            foreach($childResults as $childResult) {
                                if($res == null) {
                                    $res = $childResult;
                                } else {
                                    $res = $res . ': ' . $childResult;
                                }
                            }
                        }
                        if($res != null) {
                            $results[] = $res;
                        }
                    }
                    $res = null;
                    foreach($results as $result) {
                        $result = self::filterField($result);
                        if($res == null) {
                            $res = $result;
                        } else {
                            $res = $res . '\n\n' . $result;
                        }
                    }
                    return $res;
                }
            } else if($field['type'] == 'date_range') {
                if(!array_key_exists('from_date_path', $field) || !array_key_exists('to_date_path', $field)) {
                    echo 'Error: missing "from_date_path" or "to_date_path" for type "date_range" (field "' . $fieldName . '").' . PHP_EOL;
                    return null;
                } else {
                    $fromDates = self::resultsToArray($jsonObject->get($field['from_date_path']));
                    $toDates = self::resultsToArray($jsonObject->get($field['to_date_path']));
                    if (empty($fromDates)) {
                        if (empty($toDates)) {
                            return null;
                        } else {
                            $fromDates = $toDates;
                        }
                    } else if(empty($toDates)) {
                        $toDates = $fromDates;
                    }
                    $fromDatesList = [];
                    $toDatesList = [];
                    foreach ($fromDates as $date) {
                        $date = self::filterField($date);
                        if (!preg_match('/^[0-9]{1,4}-[0-9][0-9]-[0-9][0-9]$/', $date)) {
                            if (preg_match('/^[0-9]{1,4}$/', $date)) {
                                $date = $date . '-01-01';
                            }
                        }
                        while (strlen($date) < 10) {
                            $date = '0' . $date;
                        }
                        $fromDatesList[] = $date;
                    }
                    foreach ($toDates as $date) {
                        $date = self::filterField($date);
                        if (!preg_match('/^[0-9]{1,4}-[0-9][0-9]-[0-9][0-9]$/', $date)) {
                            if (preg_match('/^[0-9]{1,4}$/', $date)) {
                                $date = $date . '-12-31';
                            }
                        }
                        while (strlen($date) < 10) {
                            $date = '0' . $date;
                        }
                        $toDatesList[] = $date;
                    }
                    sort($fromDatesList);
                    rsort($toDatesList);

                    if(count($fromDatesList) > 1 || count($toDatesList) > 1) {
                        echo '' . PHP_EOL . PHP_EOL;
                        var_dump($fromDates);
                        var_dump($toDates);
                        var_dump($fromDatesList);
                        var_dump($toDatesList);
                    }
                    return $fromDatesList[0] . ',' . $toDatesList[0];
                }
            } else {
                echo 'Error: Unknown type "' . $field['type'] . '" for field "' . $fieldName . '"".' . PHP_EOL;
            }
        }
        if(array_key_exists('path', $field)) {
            $results = self::resultsToArray($jsonObject->get($field['path']));
            if(count($results) > 0) {
                if (array_key_exists('mapping', $field)) {
                    if (array_key_exists($results[0], $field['mapping'])) {
                        $res = $field['mapping'][$results[0]];
                    } else {
                        echo 'Unknown mapping for ' . $fieldName . ': ' . $results[0] . PHP_EOL;
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
            return $res;
        } else {
            return null;
        }
        return null;
    }

    private static function resultsToArray($results) {
        if(is_string($results)) {
            return [ $results ];
        }
        if(is_array($results)) {
            return $results;
        }
        return [];
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
