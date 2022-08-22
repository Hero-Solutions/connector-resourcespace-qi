<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="unlinked_resource")
 */

class UnlinkedResource
{
    /**
     * @ORM\Column(type="datetime")
     */
    private $importTimestamp;

    /**
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

    /**
     * @ORM\Column(type="integer")
     */
    private $width;

    /**
     * @ORM\Column(type="integer")
     */
    private $height;

    /**
     * @ORM\Column(type="integer")
     */
    private $filesize;

    /**
     * @ORM\Column(type="integer")
     */
    private $linked;

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

    public function getWidth()
    {
        return $this->width;
    }

    public function setWidth($width)
    {
        $this->width = $width;
    }

    public function getHeight()
    {
        return $this->height;
    }

    public function setHeight($height)
    {
        $this->height = $height;
    }

    public function getFilesize()
    {
        return $this->filesize;
    }

    public function setFilesize($filesize)
    {
        $this->filesize = $filesize;
    }

    public function getLinked()
    {
        return $this->linked;
    }

    public function setLinked($linked)
    {
        $this->linked = $linked;
    }
}
