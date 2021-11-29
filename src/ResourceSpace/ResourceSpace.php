<?php

namespace App\ResourceSpace;

class ResourceSpace
{
    private $apiUrl;
    private $apiUsername;
    private $apiKey;

    public function __construct($resourceSpaceApi)
    {
        $this->apiUrl = $resourceSpaceApi['url'];
        $this->apiUsername = $resourceSpaceApi['username'];
        $this->apiKey = $resourceSpaceApi['key'];
    }

    public function getAllResources($search)
    {
        $allResources = $this->doApiCall('do_search&param1=' . $search);

        if ($allResources == 'Invalid signature') {
            echo 'Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.' . PHP_EOL;
//            $this->logger->error('Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into config/connector.yml.');
            return NULL;
        }

        $resources = json_decode($allResources, true);
        return $resources;
    }

    public function getResourceData($id)
    {
        return $this->getResourceFieldDataAsAssocArray($this->getRawResourceFieldData($id));
    }

    public function getResourceFieldDataAsAssocArray($data)
    {
        $result = array();
        foreach ($data as $field) {
            $result[$field['name']] = $field['value'];
        }
        return $result;
    }

    public function getRawResourceFieldData($id)
    {
        $data = $this->doApiCall('get_resource_field_data&param1=' . $id);
        return json_decode($data, true);
    }

    public function getResourceUrl($id, $extension)
    {
        $data = $this->doApiCall('get_resource_path&param1=' . $id . '&param2=0&param5=' . $extension);
        return json_decode($data, true);
    }

    public function updateField($id, $field, $value, $nodeValue = false)
    {
        $data = $this->doApiCall('update_field&param1=' . $id . '&param2=' . $field . "&param3=" . urlencode($value) . '&param4=' . $nodeValue);
        return json_decode($data, true);
    }

    public function getResourcePath($id, $type, $filePath, $extension = '')
    {
        $data = $this->doApiCall('get_resource_path&param1=' . $id . '&param2=' . $filePath . '&param3=' . $type . '&param5=' . $extension);
        return json_decode($data, true);
    }

    private function doApiCall($query)
    {
        $query = 'user=' . str_replace(' ', '+', $this->apiUsername) . '&function=' . $query;
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $data = file_get_contents($url);
        return $data;
    }

    private function getSign($query)
    {
        return hash('sha256', $this->apiKey . $query);
    }
}
