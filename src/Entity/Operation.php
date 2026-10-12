<?php
// src/Entity/Operation.php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OperationEnum;
use App\Repository\OperationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OperationRepository::class)]
class Operation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: OperationEnum::class)]
    private ?OperationEnum $operation = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOperation(): ?OperationEnum
    {
        return $this->operation;
    }

    public function setOperation(OperationEnum $operation): static
    {
        $this->operation = $operation;

        return $this;
    }
}
