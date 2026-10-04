<?php
// src/Entity/User.php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

// Table is "app_user": "user" is a reserved keyword in PostgreSQL.
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email.')]
#[UniqueEntity(fields: ['username'], message: 'This username is already taken.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAccessAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $inactivatedAt = null;

    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    private ?string $username = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastAccessAt(): ?\DateTimeImmutable
    {
        return $this->lastAccessAt;
    }

    public function setLastAccessAt(?\DateTimeImmutable $lastAccessAt): static
    {
        $this->lastAccessAt = $lastAccessAt;

        return $this;
    }

    public function getInactivatedAt(): ?\DateTimeImmutable
    {
        return $this->inactivatedAt;
    }

    public function setInactivatedAt(?\DateTimeImmutable $inactivatedAt): static
    {
        $this->inactivatedAt = $inactivatedAt;

        return $this;
    }

    public function isActive(): bool
    {
        return null === $this->inactivatedAt;
    }

    public function eraseCredentials(): void
    {
        // No temporary sensitive data stored on the user.
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }
}
