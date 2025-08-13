<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'qi_object')]
class QiObject
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $objectId;

    #[ORM\Column(type: 'text')]
    private string $metadata;

    public function getObjectId(): int
    {
        return $this->objectId;
    }

    public function setObjectId($objectId): void
    {
        $this->objectId = $objectId;
    }

    public function getMetadata(): string
    {
        return $this->metadata;
    }

    public function setMetadata($metadata): void
    {
        $this->metadata = $metadata;
    }
}
