<?php

namespace App\Entity;

use App\Enum\MarketEnum;
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

    /**
     * Which account the money moved in. Owning side, unidirectional like $user.
     * Backfilled in the migration that introduced it: every pre-existing row was
     * assigned to a "Default" account for its owner, so this can be NOT NULL.
     */
    #[ORM\ManyToOne(inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotBlank]
    private ?Account $account = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $amount = null;

    #[ORM\Column(nullable: true)]
    private ?float $unitPrice = null;

    #[ORM\Column(nullable: true)]
    private ?int $invoice = null;

    #[ORM\Column(nullable: true, enumType: MarketEnum::class)]
    private ?MarketEnum $market = null;

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

        // Keep the stored sign in step with the direction, whichever setter
        // runs last (form binding order is not a guarantee, fixtures differ).
        $this->syncTotalPriceSign();

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

    /**
     * Total with the operation's direction applied: a credit is money in
     * (positive), a debit is money out (negative).
     *
     * Writes already store the signed value (see setTotalPrice()), so this
     * mostly restates what the column holds. ABS() keeps it correct for rows
     * written before that normalisation, or imported from elsewhere. This is
     * the PHP counterpart of the CASE expression in
     * AccountRepository::findBalancesByUser(), so a listing and a balance can
     * never disagree about a debit.
     */
    public function getSignedTotalPrice(): float
    {
        $total = abs($this->totalPrice ?? 0.0);

        return OperationEnum::DEBIT === $this->operation ? -$total : $total;
    }

    public function setTotalPrice(?float $totalPrice): static
    {
        $this->totalPrice = $totalPrice;
        $this->syncTotalPriceSign();

        return $this;
    }

    /**
     * Stores the total as a signed amount — credits positive, debits negative —
     * so the column itself can be summed. Called from both setters because the
     * operation and the total may arrive in either order.
     */
    private function syncTotalPriceSign(): void
    {
        if (null === $this->totalPrice) {
            return;
        }

        $this->totalPrice = OperationEnum::DEBIT === $this->operation
            ? -abs($this->totalPrice)
            : abs($this->totalPrice);
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

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(?int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getUnitPrice(): ?float
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(?float $unitPrice): static
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    public function getInvoice(): ?int
    {
        return $this->invoice;
    }

    public function setInvoice(?int $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getMarket(): ?MarketEnum
    {
        return $this->market;
    }

    public function setMarket(?MarketEnum $market): static
    {
        $this->market = $market;

        return $this;
    }
}
