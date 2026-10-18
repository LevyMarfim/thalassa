<?php

namespace App\Entity;

use App\Enum\CurrencyEnum;
use App\Repository\AccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A user-owned financial account (bank, brokerage, wallet).
 *
 * Balance is deliberately not stored: it is derived from the signed totals of
 * the transactions pointing here, so a stored figure can never drift away from
 * the ledger. See AccountRepository::findBalanceByAccount().
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'app_account')]
#[UniqueEntity(
    fields: ['user', 'name'],
    repositoryMethod: 'findOneByNameForUser',
    message: 'You already have an account with this name.',
)]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Owning side, deliberately unidirectional (no inversedBy) so User does not
     * need a mirrored collection. Scoping reads by this column is what keeps one
     * user's accounts invisible to every other user.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'Please enter an account name.')]
    #[Assert\Length(
        max: 64,
        maxMessage: 'An account name must be at most {{ limit }} characters long.',
    )]
    private ?string $name = null;

    #[ORM\Column(enumType: CurrencyEnum::class, length: 3)]
    #[Assert\NotBlank(message: 'Please choose a currency.')]
    private ?CurrencyEnum $currency = null;

    #[ORM\Column]
    private ?bool $active = null;

    /**
     * @var Collection<int, Transaction>
     */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'account')]
    private Collection $transactions;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->active = true;
    }

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

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Nullable on purpose: the form's data mapper writes before validation
     * runs, so a blank field must survive long enough to be rejected with a
     * 422 instead of throwing a TypeError -> 500. The column is NOT NULL.
     */
    public function setName(?string $name): static
    {
        $this->name = null === $name ? null : trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return $this;
    }

    public function getCurrency(): ?CurrencyEnum
    {
        return $this->currency;
    }

    public function setCurrency(?CurrencyEnum $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(Transaction $transaction): static
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
            $transaction->setAccount($this);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): static
    {
        if ($this->transactions->removeElement($transaction)) {
            if ($transaction->getAccount() === $this) {
                $transaction->setAccount(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
