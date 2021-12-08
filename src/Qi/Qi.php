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
    private $unknownMappings = [];

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

    public function getField($jsonObject, $fieldName, $field, $resourceData) {
        $res = null;
        if(array_key_exists('type', $field)) {
            if($field['type'] === 'list') {
                if(!array_key_exists('parent_path', $field) || !array_key_exists('key_path', $field) || !array_key_exists('value_path', $field)) {
                    echo 'Error: missing "parent_path", "key_path" or "value_path" for type "list" (field "' . $fieldName . '").' . PHP_EOL;
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
                        $keyResults = self::resultsToArray($object->get($field['key_path']));
                        if(!empty($keyResults)) {
                            $key = self::filterField($keyResults[0]);
                            if(array_key_exists('key_filter', $field)) {
                                if(!in_array($key, $field['key_filter'])) {
                                    $key = null;
                                }
                            }
                            if($key !== null) {
                                $valueResults = self::resultsToArray($object->get($field['value_path']));
                                if(!empty($valueResults)) {
                                    $res = $key . ': ' . self::filterField($valueResults[0]);
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
                    $fromDatesRes = self::resultsToArray($jsonObject->get($field['from_date_path']));
                    $toDatesRes = self::resultsToArray($jsonObject->get($field['to_date_path']));
                    $fromDates = [];
                    $toDates = [];
                    foreach($fromDatesRes as $date) {
                        if(strlen($date) > 0) {
                            $fromDates[] = $date;
                        }
                    }
                    foreach($toDatesRes as $date) {
                        if(strlen($date) > 0) {
                            $toDates[] = $date;
                        }
                    }
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
                        $date = str_replace('/__', '', $date);
                        if (!preg_match('/^[0-9]{1,4}-[0-9][0-9]-[0-9][0-9]$/', $date)) {
                            if (preg_match('/^[0-9]{1,4}\/[0-9][0-9]\/[0-9][0-9]$/', $date)) {
                                $date = str_replace('/', '-', $date);
                            } else if (preg_match('/^[0-9]{1,4}\/[0-9][0-9]$/', $date)) {
                                $month = substr($date, -2);
                                $year = substr(0, strpos($date, '/'));
                                $date = $date . '-01';
                            } else if (preg_match('/^[0-9]{1,4}___$/', $date)) {
                                $date = $date . '000-01-01';
                            } else if (preg_match('/^[0-9]{1,4}__$/', $date)) {
                                $date = $date . '00-01-01';
                            } else if (preg_match('/^[0-9]{1,4}_$/', $date)) {
                                $date = $date . '0-01-01';
                            } else if (preg_match('/^[0-9]{1,4}$/', $date)) {
                                $date = $date . '-01-01';
                            } else {
                                echo 'Unknown date: "' . $date . '"' . PHP_EOL;
                                $date = null;
                            }
                        }
                        if($date != null) {
                            while (strlen($date) < 10) {
                                $date = '0' . $date;
                            }
                            if (preg_match('/^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]$/', $date)) {
                                $fromDatesList[] = $date;
                            }
                        }
                    }
                    foreach ($toDates as $date) {
                        $date = str_replace('/__', '', $date);
                        if (!preg_match('/^[0-9]{1,4}-[0-9][0-9]-[0-9][0-9]$/', $date)) {
                            if (preg_match('/^[0-9]{1,4}\/[0-9][0-9]\/[0-9][0-9]$/', $date)) {
                                $date = str_replace('/', '-', $date);
                            } else if (preg_match('/^[0-9]{1,4}\/[0-9][0-9]$/', $date)) {
                                $month = substr($date, -2);
                                $year = substr(0, strpos($date, '/'));
                                $date = $date . '-' . $this->getMaxDaysInMonth($year, $month);
                            } else if (preg_match('/^[0-9]{1,4}___$/', $date)) {
                                $date = $date . '999-12-31';
                            } else if (preg_match('/^[0-9]{1,4}__$/', $date)) {
                                $date = $date . '99-12-31';
                            } else if (preg_match('/^[0-9]{1,4}_$/', $date)) {
                                $date = $date . '9-12-31';
                            } else if (preg_match('/^[0-9]{1,4}$/', $date)) {
                                $date = $date . '-12-31';
                            } else {
                                echo 'Unknown date: "' . $date . '"' . PHP_EOL;
                                $date = null;
                            }
                        }
                        if($date != null) {
                            while (strlen($date) < 10) {
                                $date = '0' . $date;
                            }
                            if (preg_match('/^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]$/', $date)) {
                                $toDatesList[] = $date;
                            }
                        }
                    }
                    sort($fromDatesList);
                    rsort($toDatesList);

                    if(!empty($fromDatesList) && !empty($toDatesList)) {
                        return $fromDatesList[0] . ',' . $toDatesList[0];
                    }
                }
            } else {
                echo 'Error: Unknown type "' . $field['type'] . '" for field "' . $fieldName . '"".' . PHP_EOL;
            }
        }
        if(array_key_exists('path', $field)) {
            $results = $this->resultsToArray($jsonObject->get($field['path']));
            if(count($results) > 0) {
                if (array_key_exists('mapping', $field)) {
                    if (array_key_exists($results[0], $field['mapping'])) {
                        $res = $field['mapping'][$results[0]];
                    } else {
                        if(!array_key_exists($fieldName, $this->unknownMappings)) {
                            $this->unknownMappings[$fieldName] = [];
                        }
                        if(!in_array($results[0], $this->unknownMappings[$fieldName])) {
                            $this->unknownMappings[$fieldName][] = $results[0];
                            echo 'INFO: Unknown mapping for ' . $fieldName . ': ' . $results[0] . PHP_EOL;
                        }
                    }
                } else {
                    $res = $this->filterField(implode(',', $results));
                }
            }
        }
        if($res !== null) {
            if(strlen($res) === 0) {
                $res = null;
            }
        }
        if($res !== null && array_key_exists('casing', $field)) {
            if($field['casing'] === 'lowercase') {
                $res = strtolower($res);
            }
        }
        if($res !== null && array_key_exists('overwrite', $field) && array_key_exists($fieldName, $resourceData)) {
            if($field['overwrite'] === 'no') {
                if(!empty($resourceData[$fieldName])) {
                    echo 'Not overwriting field ' . $fieldName . ' for res ' . $res . ' (already has ' . $resourceData[$fieldName] . ')' . PHP_EOL;
                    $res = null;
                }
            } else if($field['overwrite'] === 'merge') {
                if(!empty($resourceData[$fieldName])) {
                    if(strpos($resourceData[$fieldName], $res) === false) {
                        echo 'Merging field ' . $fieldName . ' for res ' . $res . ' (already has ' . $resourceData[$fieldName] . ')' . PHP_EOL;
                        $res = $resourceData[$fieldName] . '\n\n' . $res;
                    } else {
                        echo 'Not merging field ' . $fieldName . ' for res ' . $res . ' (already has ' . $resourceData[$fieldName] . ')' . PHP_EOL;
                        $res = null;
                    }
                }
            }
        }
        return $res;
    }

    private function resultsToArray($results) {
        if(is_string($results)) {
            return [ $results ];
        }
        if(is_array($results)) {
            return $results;
        }
        return [];
    }

    public function filterField($field) {
        $field = str_replace("<i>", '', $field);
        $field = str_replace("</i>", '', $field);
        $field = str_replace("\n", ' ', $field);
        return $field;
    }

    public function getMaxDaysInMonth($year, $month) {
        switch($month) {
            default:
            case '01':
            case '03':
            case '05':
            case '07':
            case '08':
            case '10':
            case '12':
                return '31';
            case '02':
                if ($year % 400 == 0) {
                    return '29';
                } elseif ($year % 100 == 0) {
                    return '28';
                } elseif ($year % 4 == 0) {
                    return '29';
                } else {
                    return '28';
                }
            case '04':
            case '06':
            case '09':
            case '11':
                return '30';
        }
    }

    public function getAllObjects()
    {
        $objects = array();

        $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields));
        $objs = json_decode($objsJson);
        $count = $objs->count;
        $records = $objs->records;
        foreach($records as $record) {
            if(!empty($record->object_number)) {
                $objects[$record->object_number] = $record;
            } else {
                echo 'Error: Qi record ' . $record->id . ' has no inventory number' . PHP_EOL;
            }
        }
        //TODO remove the ' && false', this is to only grab a few records without it being too slow
        for($i = 1; $i < ($count + 499) / 500 - 1/* && false*/; $i++) {
            $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields) . '/_offset/' . ($i * 500));
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
