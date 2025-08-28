<?php

namespace App\ResourceSpace;

use App\Util\HttpUtil;

class ResourceSpace
{
    private string $apiUrl;
    private string $apiUsername;
    private string $apiKey;
    private int $maxFieldValueLength;
    private HttpUtil $httpUtil;

    public function __construct($resourceSpaceApi, $httpUtil)
    {
        $this->apiUrl = $resourceSpaceApi['url'];
        $this->apiUsername = $resourceSpaceApi['username'];
        $this->apiKey = $resourceSpaceApi['key'];
        $this->maxFieldValueLength = $resourceSpaceApi['max_field_value_length'];
        $this->httpUtil = $httpUtil;
    }

    public function getAllResources($search): mixed
    {
        $allResources = $this->doApiCall('do_search&param1=' . $search);

        if ($allResources == 'Invalid signature') {
            echo 'Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.' . PHP_EOL;
//            $this->logger->error('Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.');
            return NULL;
        }

        return json_decode($allResources, true);
    }

    public function getResourceData($id): array
    {
        return $this->getResourceFieldDataAsAssocArray($this->getRawResourceFieldData($id));
    }

    public function getResourceFieldDataAsAssocArray($data): array
    {
        $result = array();
        foreach ($data as $field) {
            $result[$field['name']] = $field['value'];
        }
        return $result;
    }

    public function getRawResourceFieldData($id): mixed
    {
        $data = $this->doApiCall('get_resource_field_data&param1=' . $id);
        return json_decode($data, true);
    }

    public function getResourceUrl($id, $extension): mixed
    {
        $data = $this->doApiCall('get_resource_path&param1=' . $id . '&param2=0&param5=' . $extension);
        return json_decode($data, true);
    }

    public function updateField($id, $field, $value, $nodeValue = false): mixed
    {
        if(!empty($value) && strlen($value) > $this->maxFieldValueLength) {
            $value = substr($value, 0, $this->maxFieldValueLength);
        }
        $data = $this->doApiCall('update_field&param1=' . $id . '&param2=' . $field . "&param3=" . urlencode($value) . '&param4=' . $nodeValue);
        return json_decode($data, true);
    }

    public function getAllImages($id): mixed
    {
        $data = $this->doApiCall('get_resource_all_image_sizes&param1=' . $id);
        return json_decode($data, true);
    }

    private function doApiCall($query): string|bool
    {
        $query = 'user=' . str_replace(' ', '+', $this->apiUsername) . '&function=' . $query;
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        return $this->httpUtil->get($url);
    }

    private function getSign($query): string
    {
        return hash('sha256', $this->apiKey . $query);
    }
}
