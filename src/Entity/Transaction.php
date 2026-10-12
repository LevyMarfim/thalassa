<?php

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'app_transaction')]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $liquidation = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $movement = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getLiquidation(): ?\DateTime
    {
        return $this->liquidation;
    }

    public function setLiquidation(\DateTime $liquidation): static
    {
        $this->liquidation = $liquidation;

        return $this;
    }

    public function getMovement(): ?\DateTime
    {
        return $this->movement;
    }

    public function setMovement(\DateTime $movement): static
    {
        $this->movement = $movement;

        return $this;
    }
}
