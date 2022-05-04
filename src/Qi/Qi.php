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
    private $test;
    private $debug;
    private $update;
    private $unknownMappings = [];

    private $objectsByObjectId;
    private $objectsByInventoryNumber;

    public function __construct($qi, $sslCertificateAuthority, $test, $debug, $update)
    {
        $qiApi = $qi['api'];
        $this->baseUrl = $qiApi['url'];
        $this->username = $qiApi['username'];
        $this->password = $qiApi['password'];
        $this->getFields = $qi['get_fields'];

        $this->overrideCertificateAuthorityFile = $sslCertificateAuthority['override'];
        $this->sslCertificateAuthorityFile = $sslCertificateAuthority['authority_file'];

        $this->test = $test;
        $this->debug = $debug;
        $this->update = $update;
    }

    public function getObjectsByObjectId()
    {
        return $this->objectsByObjectId;
    }

    public function getObjectsByInventoryNumber()
    {
        return $this->objectsByInventoryNumber;
    }

    public function retrieveAllObjects()
    {
        $this->objectsByObjectId = [];
        $this->objectsByInventoryNumber = [];

        if($this->test) {
            $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields) . '/_offset/12929');
        } else {
            $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields));
        }

        $objs = json_decode($objsJson);
        $count = $objs->count;
        $records = $objs->records;
        foreach($records as $record) {
            $this->objectsByObjectId[intval($record->id)] = $record;
            if(!empty($record->object_number)) {
                $this->objectsByInventoryNumber[$record->object_number] = $record;
            } else {
                echo 'Error: Qi record ' . $record->id . ' has no inventory number' . PHP_EOL;
            }
        }
        for($i = 1; !$this->test && $i < ($count + 499) / 500 - 1; $i++) {
            $objsJson = $this->get($this->baseUrl . '/get/object/_fields/' . urlencode($this->getFields) . '/_offset/' . ($i * 500));
            $objs = json_decode($objsJson);
            $records = $objs->records;
            foreach($records as $record) {
                $this->objectsByObjectId[intval($record->id)] = $record;
                if(!empty($record->object_number)) {
                    $this->objectsByInventoryNumber[$record->object_number] = $record;
                } else {
                    echo 'Error: Qi record ' . $record->id . ' has no inventory number' . PHP_EOL;
                }
            }
        }
    }

    public function getMediaInfos($object, $qiMediaFolderId, $qiImportMapping, $qiMappingToSelf)
    {
        $mediaInfos = [];
        if(property_exists($object, 'media.image.id')) {
            $allMediaInfo = [];
            $i = 0;
            foreach ($object->{'media.image.id'} as $id) {
                $allMediaInfo[$i] = [
                    'id' => $id
                ];
                $i++;
            }

            $count = count($allMediaInfo);
            if (property_exists($object, 'media.image.link_dams') && count($object->{'media.image.link_dams'}) === $count) {
                $i = 0;
                foreach ($object->{'media.image.link_dams'} as $linkDams) {
                    $allMediaInfo[$i]['link_dams'] = $linkDams;
                    $i++;
                }
            }
            if (property_exists($object, 'media.image.media_folder_id') && count($object->{'media.image.media_folder_id'}) === $count) {
                $i = 0;
                foreach ($object->{'media.image.media_folder_id'} as $mediaFolderId) {
                    $allMediaInfo[$i]['media_folder_id'] = $mediaFolderId;
                    $i++;
                }
            }
            if (property_exists($object, 'media.image.original_filename') && count($object->{'media.image.original_filename'}) === $count) {
                $i = 0;
                foreach ($object->{'media.image.original_filename'} as $originalFilename) {
                    $allMediaInfo[$i]['original_filename'] = $originalFilename;
                    $i++;
                }
            }
            foreach ($qiImportMapping as $qiPropertyName => $rsFieldId) {
                if (property_exists($object, 'media.image.' . $qiPropertyName) && count($object->{'media.image.' . $qiPropertyName}) === $count) {
                    $i = 0;
                    foreach ($object->{'media.image.' . $qiPropertyName} as $value) {
                        $allMediaInfo[$i][$qiPropertyName] = $value;
                        $i++;
                    }
                }
            }
            foreach ($qiMappingToSelf as $qiPropertyName => $jsonPath) {
                if (property_exists($object, 'media.image.' . $qiPropertyName) && count($object->{'media.image.' . $qiPropertyName}) === $count) {
                    $i = 0;
                    foreach ($object->{'media.image.' . $qiPropertyName} as $value) {
                        $allMediaInfo[$i][$qiPropertyName] = $value;
                        $i++;
                    }
                }
            }
            for ($i = 0; $i < $count; $i++) {
                if($allMediaInfo[$i]['media_folder_id'] === $qiMediaFolderId) {
                    $mediaInfos[] = $allMediaInfo[$i];
                }
            }
        }
        return $mediaInfos;
    }

    public function getMatchingUnlinkedImage($mediaInfos, $originalFilename, $qiLinkDamsPrefix)
    {
        $result = null;
        foreach($mediaInfos as $mediaInfo) {
            if(array_key_exists('link_dams', $mediaInfo)) {
                if(strpos($mediaInfo['link_dams'], $qiLinkDamsPrefix) === 0) {
                    continue;
                }
            }
            if(array_key_exists('original_filename', $mediaInfo)) {
                if($mediaInfo['original_filename'] === $originalFilename) {
                    $result = $mediaInfo;
                    break;
                }
            }
        }
        return $result;
    }

    public function getMatchingLinkedImage($mediaInfos, $resourceId, $qiLinkDamsPrefix)
    {
        $result = null;
        foreach($mediaInfos as $mediaInfo) {
            if(array_key_exists('link_dams', $mediaInfo)) {
                if(strpos($mediaInfo['link_dams'], $qiLinkDamsPrefix) === $qiLinkDamsPrefix . $resourceId) {
                    $result = $mediaInfo;
                    break;
                }
            }
        }
        return $result;
    }

    public function updateMetadata($qiImage, $resource, $rsFields, $qiImportMapping, $qiLinkDamsPrefix)
    {
        $record = [];
        foreach($qiImportMapping as $qiPropertyName => $rsPropertyName) {
            if(array_key_exists($rsFields[$rsPropertyName], $resource)) {
                $changed = false;
                if(array_key_exists($qiPropertyName, $qiImage)) {
                    if($qiImage[$qiPropertyName] !== $resource[$rsFields[$rsPropertyName]]) {
                        $changed = true;
                    }
                } else {
                    $changed = true;
                }
                if($changed) {
                    $record[$qiPropertyName] = $resource[$rsFields[$rsPropertyName]];
                }
            } else if(array_key_exists($qiPropertyName, $qiImage)) {
                $record[$qiPropertyName] = '';
            }
        }
        $addLinkDams = false;
        if(array_key_exists('link_dams', $qiImage)) {
            if($qiImage['link_dams'] !== $qiLinkDamsPrefix . $resource['ref']) {
                $addLinkDams = true;
            }
        } else {
            $addLinkDams = true;
        }
        if($addLinkDams) {
            $record['link_dams'] = $qiLinkDamsPrefix . $resource['ref'];
        }
        if(!empty($record)) {
            $data = [
                'id' => $qiImage['id'],
                'record' => $record
            ];
            self::putMetadata($data);
        }
    }

    public function putMetadata($data) {
        $this->put($this->baseUrl . '/put/media', json_encode($data));
    }

    public function getFieldData($jsonObject, $fieldName, $field)
    {
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
                            $valueResults = self::resultsToArray($object->get($field['value_path']));
                            if(!empty($valueResults)) {
                                if(array_key_exists('format', $field)) {
                                    $res = $field['format'];
                                    if(strpos($field['format'], '$key') !== false) {
                                        $res = str_replace('$key', $key, $field['format']);
                                    }
                                    if(strpos($field['format'], '$value') !== false) {
                                        $res = str_replace('$value', self::filterField($valueResults[0]), $res);
                                    }
                                } else if($key !== null) {
                                    $res = $key . ': ' . self::filterField($valueResults[0]);
                                } else {
                                    $res = self::filterField($valueResults[0]);
                                }
                            } else if($key !== null) {
                                $res = $key;
                            }
                        }
                        if($res !== null) {
                            $results[] = $res;
                        }
                    }
                    $concat = '\n\n';
                    if(array_key_exists('concat', $field)) {
                        $concat = $field['concat'];
                    }
                    $res = null;
                    foreach($results as $result) {
                        $result = self::filterField($result);
                        if(array_key_exists('remove_commas', $field)) {
                            if($field['remove_commas'] === 'yes') {
                                $result = str_replace(', ', ' ', $result);
                                $result = str_replace(',', ' ', $result);
                            }
                        }
                        if($res === null) {
                            $res = $result;
                        } else {
                            $res = $res . $concat . $result;
                        }
                    }
                    return $res;
                }
            } else if($field['type'] === 'date_range') {
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
                        if($date !== null) {
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
                        if($date !== null) {
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
        $allowEmpty = false;
        if(array_key_exists('path', $field)) {
            $results = $this->resultsToArray($jsonObject->get($field['path']));
            if(count($results) > 0) {
                if (array_key_exists('mapping', $field)) {
                    if (array_key_exists($results[0], $field['mapping'])) {
                        $res = $field['mapping'][$results[0]];
                        $allowEmpty = true;
                    } else {
                        if(!array_key_exists($fieldName, $this->unknownMappings)) {
                            $this->unknownMappings[$fieldName] = [];
                        }
                        if(!in_array($results[0], $this->unknownMappings[$fieldName])) {
                            $this->unknownMappings[$fieldName][] = $results[0];
                            echo 'INFO: Unknown mapping for ' . $fieldName . ': "' . $results[0] . '"' . PHP_EOL;
                        }
                    }
                } else {
                    $res = $this->filterField(implode(',', $results));
                }
            }
        }
        if($res !== null && !$allowEmpty) {
            if(strlen($res) === 0) {
                $res = null;
            }
        }
        if($res !== null && array_key_exists('casing', $field)) {
            if($field['casing'] === 'lowercase') {
                $res = strtolower($res);
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
        $field = str_replace("<i>", '\'', $field);
        $field = str_replace("</i>", '\'', $field);
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
                if ($year % 400 === 0) {
                    return '29';
                } elseif ($year % 100 === 0) {
                    return '28';
                } elseif ($year % 4 === 0) {
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

    public function put($url, $json)
    {
        if($this->debug) {
            echo $url . PHP_EOL;
            echo $json . PHP_EOL;
        }
        if(!$this->update) {
            return;
        }

        $headers = array (
            "Content-Type: application/json; charset=utf-8",
            "Content-Length: " . strlen($json)
        );

        $ch = curl_init();
        if ($this->overrideCertificateAuthorityFile) {
            curl_setopt($ch,CURLOPT_CAINFO, $this->sslCertificateAuthorityFile);
            curl_setopt($ch,CURLOPT_CAPATH, $this->sslCertificateAuthorityFile);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);

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
