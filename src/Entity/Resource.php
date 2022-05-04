<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="resource")
 */

class Resource
{
    /**
     * @ORM\Column(type="datetime")
     */
    private $importTimestamp;

    /**
     * @ORM\Id()
     * @ORM\Column(type="integer")
     */
    private $resourceId;

    /**
     * @ORM\Column(type="integer")
     */
    private $objectId;

    /**
     * @ORM\Column(type="string", length="255")
     */
    private $inventoryNumber;

    /**
     * @ORM\Column(type="string", length="255")
     */
    private $originalFilename;

    public function getImportTimestamp()
    {
        return $this->importTimestamp;
    }

    public function setImportTimestamp($importTimestamp)
    {
        $this->importTimestamp = $importTimestamp;
    }

    public function getResourceId()
    {
        return $this->resourceId;
    }

    public function setResourceId($resourceId)
    {
        $this->resourceId = $resourceId;
    }

    public function getObjectId()
    {
        return $this->objectId;
    }

    public function setObjectId($objectId)
    {
        $this->objectId = $objectId;
    }

    public function getInventoryNumber()
    {
        return $this->inventoryNumber;
    }

    public function setInventoryNumber($inventoryNumber)
    {
        $this->inventoryNumber = $inventoryNumber;
    }

    public function getOriginalFilename()
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename($originalFilename)
    {
        $this->originalFilename = $originalFilename;
    }
}
