<?php

namespace App\Entity;

use App\Enum\OperationEnum;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'app_transaction')]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Owning side, deliberately unidirectional (no inversedBy) so User does not
     * need a mirrored collection. Scoping reads by this column is what keeps one
     * user's transactions invisible to every other user.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank]
    private ?\DateTime $liquidation = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank]
    private ?\DateTime $movement = null;

    #[ORM\Column(enumType: OperationEnum::class)]
    #[Assert\NotBlank]
    private ?OperationEnum $operation = null;

    #[ORM\ManyToOne(inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotBlank]
    private ?OperationType $operationType = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $description = null;

    // Not a money-safe type (floats round); see the summary note.
    #[ORM\Column(type: Types::FLOAT)]
    #[Assert\NotBlank]
    #[Assert\Type(type: 'numeric')]
    private ?float $totalPrice = null;

    #[ORM\ManyToOne(inversedBy: 'transactions')]
    private ?Ticker $ticker = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getLiquidation(): ?\DateTime
    {
        return $this->liquidation;
    }

    /**
     * Date/number/enum setters accept null on purpose: the form's data mapper
     * writes before validation runs, so a blank field must survive long enough
     * to be rejected with a 422 instead of throwing a TypeError -> 500. Every
     * column here is NOT NULL in the database, which is the real guarantee.
     */
    public function setLiquidation(?\DateTime $liquidation): static
    {
        $this->liquidation = $liquidation;

        return $this;
    }

    public function getMovement(): ?\DateTime
    {
        return $this->movement;
    }

    public function setMovement(?\DateTime $movement): static
    {
        $this->movement = $movement;

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

    public function getOperationType(): ?OperationType
    {
        return $this->operationType;
    }

    public function setOperationType(?OperationType $operationType): static
    {
        $this->operationType = $operationType;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getTotalPrice(): ?float
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(?float $totalPrice): static
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    public function getTicker(): ?Ticker
    {
        return $this->ticker;
    }

    public function setTicker(?Ticker $ticker): static
    {
        $this->ticker = $ticker;

        return $this;
    }
}
