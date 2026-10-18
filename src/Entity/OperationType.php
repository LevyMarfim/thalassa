<?php

namespace App\Entity;

use App\Repository\OperationTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: OperationTypeRepository::class)]
#[UniqueEntity(
    fields: ['type'],
    // Case- and whitespace-insensitive, so "aporte", "Aporte" and " Aporte "
    // are all rejected as duplicates. A plain fields:['type'] check would let
    // those through; the repository method below matches the DB unique index.
    repositoryMethod: 'findOneByTypeIgnoreCase',
    message: 'This operation type already exists.',
)]
class OperationType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private ?string $type = null;

    /**
     * @var Collection<int, Transaction>
     */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'operationType')]
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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        // Nullable + normalized: the form's data mapper writes before validation
        // runs, and trimming means " Aporte " collides with "Aporte" instead of
        // quietly creating a near-duplicate. Case is left alone (these are
        // display labels, not identifiers) and handled by the unique index.
        $this->type = null === $type ? null : trim(preg_replace('/\s+/u', ' ', $type) ?? $type);

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
            $transaction->setOperationType($this);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): static
    {
        if ($this->transactions->removeElement($transaction)) {
            // set the owning side to null (unless already changed)
            if ($transaction->getOperationType() === $this) {
                $transaction->setOperationType(null);
            }
        }

        return $this;
    }
}
