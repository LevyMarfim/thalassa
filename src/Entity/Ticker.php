<?php

namespace App\Entity;

use App\Repository\TickerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TickerRepository::class)]
#[UniqueEntity(fields: ['ticker'], message: 'This ticker already exists.')]
class Ticker
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank(message: 'Please enter a ticker symbol.')]
    #[Assert\Length(
        max: 20,
        maxMessage: 'A ticker symbol must be at most {{ limit }} characters long.',
    )]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9.\-\/=^]+$/',
        message: 'Use only letters, numbers, dots, slashes, carets or dashes (e.g. PETR4, BRK.B, ^BVSP).',
    )]
    private ?string $ticker = null;

    /**
     * @var Collection<int, Transaction>
     */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'ticker')]
    private Collection $transactions;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
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

    public function getTicker(): ?string
    {
        return $this->ticker;
    }

    public function setTicker(string $ticker): static
    {
        // Ticker symbols are conventionally uppercase; normalizing here also
        // keeps the unique constraint from splitting petr4 / PETR4 into two rows.
        $this->ticker = strtoupper(trim($ticker));

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
            $transaction->setTicker($this);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): static
    {
        if ($this->transactions->removeElement($transaction)) {
            // set the owning side to null (unless already changed)
            if ($transaction->getTicker() === $this) {
                $transaction->setTicker(null);
            }
        }

        return $this;
    }
}
