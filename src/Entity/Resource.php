<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'resource')]
class Resource
{
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $importTimestamp;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $resourceId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $objectId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $inventoryNumber;

    #[ORM\Column(type: 'string', length: 255)]
    private string $originalFilename;

    #[ORM\Column(type: 'integer')]
    private int $width;

    #[ORM\Column(type: 'integer')]
    private int $height;

    #[ORM\Column(type: 'integer')]
    private int $filesize;

    /**
     * 0 = uploaded but not yet linked
     * 1 = uploaded and linked
     * 2 = existing image linked
     */
    #[ORM\Column(type: 'integer')]
    private int $linked;

    public function getImportTimestamp(): \DateTimeInterface
    {
        return $this->importTimestamp;
    }

    public function setImportTimestamp(\DateTimeInterface $importTimestamp): void
    {
        $this->importTimestamp = $importTimestamp;
    }

    public function getResourceId(): int
    {
        return $this->resourceId;
    }

    public function setResourceId(int $resourceId): void
    {
        $this->resourceId = $resourceId;
    }

    public function getObjectId(): int
    {
        return $this->objectId;
    }

    public function setObjectId(int $objectId): void
    {
        $this->objectId = $objectId;
    }

    public function getInventoryNumber(): string
    {
        return $this->inventoryNumber;
    }

    public function setInventoryNumber(string $inventoryNumber): void
    {
        $this->inventoryNumber = $inventoryNumber;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): void
    {
        $this->originalFilename = $originalFilename;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function setWidth(int $width): void
    {
        $this->width = $width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function setHeight(int $height): void
    {
        $this->height = $height;
    }

    public function getFilesize(): int
    {
        return $this->filesize;
    }

    public function setFilesize(int $filesize): void
    {
        $this->filesize = $filesize;
    }

    public function getLinked(): int
    {
        return $this->linked;
    }

    public function setLinked(int $linked): void
    {
        $this->linked = $linked;
    }
}
