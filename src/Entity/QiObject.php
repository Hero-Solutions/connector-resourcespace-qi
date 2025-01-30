<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="qi_object")
 */
class QiObject
{
    /**
     * @ORM\Id()
     * @ORM\Column(type="integer")
     */
    private $objectId;

    /**
     * @ORM\Column(type="text")
     */
    private $metadata;

    public function getObjectId()
    {
        return $this->objectId;
    }

    public function setObjectId($objectId)
    {
        $this->objectId = $objectId;
    }

    public function getMetadata()
    {
        return $this->metadata;
    }

    public function setMetadata($metadata)
    {
        $this->metadata = $metadata;
    }
}
