<?php
// src/Dto/AccountFilter.php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\CurrencyEnum;

/**
 * Criteria collected by AccountFilterType (a GET form) and passed to
 * AccountRepository::findByUser(). All criteria are optional; the default
 * sort is the same alphabetical order the unfiltered list has always used.
 *
 * Deliberately not readonly — Symfony's form binder writes into it.
 */
final class AccountFilter
{
    public const SORT_NAME = 'name';
    public const SORT_BALANCE_DESC = 'balance_desc';
    public const SORT_BALANCE_ASC = 'balance_asc';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    private ?string $name = null;
    private ?CurrencyEnum $currency = null;
    private ?string $status = null;
    private string $sort = self::SORT_NAME;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        // Ignore values outside the form's choices: an unknown value should not
        // make the "Filtered view" banner claim a filter that was never applied.
        $this->status = \in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)
            ? $status
            : null;

        return $this;
    }

    public function getSort(): string
    {
        return $this->sort;
    }

    public function setSort(?string $sort): static
    {
        // A partially filled GET form submits null for the untouched selects.
        // Keep the default sort in that case, and ignore values outside the
        // form's choices rather than trusting request data in repository code.
        $this->sort = \in_array($sort, [
            self::SORT_NAME,
            self::SORT_BALANCE_DESC,
            self::SORT_BALANCE_ASC,
        ], true) ? $sort : self::SORT_NAME;

        return $this;
    }

    public function isActive(): bool
    {
        return (null !== $this->name && '' !== trim($this->name))
            || null !== $this->currency
            || null !== $this->status
            || self::SORT_NAME !== $this->sort;
    }
}
