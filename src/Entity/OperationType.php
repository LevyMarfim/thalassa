<?php

namespace App\Entity;

use App\Enum\OperationEnum;
use App\Repository\OperationTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OperationTypeRepository::class)]
class OperationType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private ?string $type = null;

    /**
     * Nullable in PHP only so the form's data mapper can survive an empty
     * submit long enough for validation to reject it with a 422 (a non-nullable
     * property makes the mapper throw a TypeError -> 500). The database column
     * is NOT NULL, which is the real guarantee that every type has an operation.
     */
    #[ORM\Column(enumType: OperationEnum::class)]
    #[Assert\NotBlank(message: 'Please choose an operation.')]
    private ?OperationEnum $operation = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getOperation(): ?OperationEnum
    {
        return $this->operation;
    }

    public function setOperation(?OperationEnum $operation): static
    {
        $this->operation = $operation;

        return $this;
    }
}
