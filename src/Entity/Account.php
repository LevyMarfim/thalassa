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
    /**
     * Stable chart palette. New accounts are assigned the first colour not yet
     * used by the user; the migration backfills existing rows in the same
     * order so chart lines stay distinguishable without any manual editing.
     *
     * @var list<string>
     */
    public const array CHART_COLORS = [
        '#10b981',
        '#3b82f6',
        '#f59e0b',
        '#8b5cf6',
        '#ef4444',
        '#06b6d4',
        '#ec4899',
        '#84cc16',
    ];

    public const string DEFAULT_COLOR = '#10b981';

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
     * The account's line colour on the dashboard chart. Kept as a plain
     * #RRGGBB string: CSS and Chart.js both consume it directly, and the form
     * uses a native colour picker so no palette UI is needed.
     */
    #[ORM\Column(length: 7, options: ['default' => self::DEFAULT_COLOR])]
    #[Assert\NotBlank(message: 'Please choose a chart color.')]
    #[Assert\Regex(
        pattern: '/^#[0-9a-fA-F]{6}$/',
        message: 'Choose a color as a #RRGGBB value.',
    )]
    private ?string $color = self::DEFAULT_COLOR;

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

    public function getColor(): ?string
    {
        return $this->color;
    }

    /**
     * Nullable so a blank picker survives the data mapper and is rejected by
     * NotBlank with a 422, instead of throwing a TypeError -> 500.
     */
    public function setColor(?string $color): static
    {
        $this->color = null === $color ? null : strtolower(trim($color));

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
