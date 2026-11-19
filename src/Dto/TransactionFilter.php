<?php
// src/Dto/TransactionFilter.php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Account;
use App\Entity\OperationType;
use App\Entity\Ticker;
use App\Enum\OperationEnum;

/**
 * Criteria collected by TransactionFilterType (a GET form) and handed to
 * TransactionRepository::findByUser. Every property is optional: an empty
 * filter means "no narrowing", which is the listing's default.
 *
 * Deliberately not readonly — Symfony's form binder writes into it.
 */
final class TransactionFilter
{
    public const SORT_MOVEMENT_DESC = 'movement_desc';
    public const SORT_MOVEMENT_ASC = 'movement_asc';
    public const SORT_TOTAL_DESC = 'total_desc';
    public const SORT_TOTAL_ASC = 'total_asc';

    private ?Account $account = null;
    private ?OperationEnum $operation = null;
    private ?OperationType $operationType = null;
    private ?Ticker $ticker = null;
    private ?\DateTimeImmutable $movementFrom = null;
    private ?\DateTimeImmutable $movementTo = null;
    private ?string $description = null;
    private string $sort = self::SORT_MOVEMENT_DESC;

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

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

    public function getTicker(): ?Ticker
    {
        return $this->ticker;
    }

    public function setTicker(?Ticker $ticker): static
    {
        $this->ticker = $ticker;

        return $this;
    }

    public function getMovementFrom(): ?\DateTimeImmutable
    {
        return $this->movementFrom;
    }

    public function setMovementFrom(?\DateTimeImmutable $movementFrom): static
    {
        $this->movementFrom = $movementFrom;

        return $this;
    }

    public function getMovementTo(): ?\DateTimeImmutable
    {
        return $this->movementTo;
    }

    public function setMovementTo(?\DateTimeImmutable $movementTo): static
    {
        $this->movementTo = $movementTo;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSort(): string
    {
        return $this->sort;
    }

    public function setSort(?string $sort): static
    {
        // A partially filled GET form submits null for the sort select. Keep the
        // newest-first default, and ignore values outside the form's choices.
        $this->sort = \in_array($sort, [
            self::SORT_MOVEMENT_DESC,
            self::SORT_MOVEMENT_ASC,
            self::SORT_TOTAL_DESC,
            self::SORT_TOTAL_ASC,
        ], true) ? $sort : self::SORT_MOVEMENT_DESC;

        return $this;
    }

    /**
     * True when at least one criterion would narrow the list, so the template
     * can tell "nothing here yet" apart from "nothing matches".
     */
    public function isActive(): bool
    {
        return null !== $this->account
            || null !== $this->operation
            || null !== $this->operationType
            || null !== $this->ticker
            || null !== $this->movementFrom
            || null !== $this->movementTo
            || (null !== $this->description && '' !== trim($this->description));
    }
}
