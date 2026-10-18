<?php

namespace App\Tests\Controller;

use App\Entity\OperationType;
use App\Entity\User;
use App\Enum\OperationEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SettingsControllerTest extends WebTestCase
{
    public function testAnonymousSettingsRedirectsToLogin(): void
    {
        static::createClient()->request('GET', '/settings');

        self::assertResponseRedirects('/login');
    }

    public function testAuthenticatedSettingsShowsBothForms(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[name="change_email_form"]'));
        self::assertCount(1, $crawler->filter('form[name="change_password_form"]'));
    }

    public function testChangeEmailSuccess(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);

        $newEmail = sprintf('new-%s@example.com', uniqid());
        $crawler = $client->request('GET', '/settings');
        $form = $crawler->filter('form[name="change_email_form"]')->form([
            'change_email_form[email]' => $newEmail,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--success', 'email');

        $fresh = $this->findUser($user->getUsername());
        self::assertSame($newEmail, $fresh->getEmail());
    }

    public function testChangeEmailRejectsDuplicate(): void
    {
        $client = static::createClient();
        $other = $this->createUser();
        $user = $this->createUser();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings');
        $form = $crawler->filter('form[name="change_email_form"]')->form([
            'change_email_form[email]' => $other->getEmail(),
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertNotSame($other->getEmail(), $this->findUser($user->getUsername())->getEmail());
    }

    public function testChangePasswordSuccess(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings');
        $form = $crawler->filter('form[name="change_password_form"]')->form([
            'change_password_form[currentPassword]' => 'password-123',
            'change_password_form[newPassword][first]' => 'brand-new-password-1',
            'change_password_form[newPassword][second]' => 'brand-new-password-1',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--success', 'password');

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $fresh = $this->findUser($user->getUsername());
        self::assertTrue($hasher->isPasswordValid($fresh, 'brand-new-password-1'));
        self::assertFalse($hasher->isPasswordValid($fresh, 'password-123'));
    }

    public function testChangePasswordRejectsWrongCurrentPassword(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings');
        $form = $crawler->filter('form[name="change_password_form"]')->form([
            'change_password_form[currentPassword]' => 'wrong-password-1',
            'change_password_form[newPassword][first]' => 'brand-new-password-2',
            'change_password_form[newPassword][second]' => 'brand-new-password-2',
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $fresh = $this->findUser($user->getUsername());
        self::assertTrue($hasher->isPasswordValid($fresh, 'password-123'));
    }

    public function testSettingsListsOperationTypes(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->createOperationType('PIX');
        $this->createOperationType('Boleto');

        $crawler = $client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.type-list', 'PIX');
        self::assertSelectorTextContains('.type-list', 'Boleto');
    }

    public function testCreateOperationTypeSuccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/settings');
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => 'TED',
            'operation_type_form[operation]' => 'DEBIT',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.type-list', 'TED');
        $created = $this->findOperationType('TED');
        self::assertNotNull($created);
        self::assertSame(OperationEnum::DEBIT, $created->getOperation());
    }

    public function testCreateOperationTypeRejectsMissingOperation(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $before = $this->countOperationTypes();

        // Raw POST: a crafted request can still send an empty operation
        // even though the select no longer renders a "none" option.
        $crawler = $client->request('GET', '/settings');
        $form = $crawler->filter('form[name="operation_type_form"]')->form();
        $values = $form->getPhpValues();
        $values['operation_type_form']['operation'] = '';
        $client->request('POST', $form->getUri(), $values);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countOperationTypes());
    }

    public function testListShowsOperationBadge(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->createOperationType('Salário', OperationEnum::CREDIT);
        $this->createOperationType('Aluguel', OperationEnum::DEBIT);

        $client->request('GET', '/settings');

        self::assertSelectorTextContains('.op-badge--credit', 'Crédito');
        self::assertSelectorTextContains('.op-badge--debit', 'Débito');
    }

    public function testCreateOperationTypeRejectsBlank(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $before = $this->countOperationTypes();

        $crawler = $client->request('GET', '/settings');
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => '',
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countOperationTypes());
    }

    public function testEditOperationTypePageRenders(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $type = $this->createOperationType('PIX');

        $crawler = $client->request('GET', sprintf('/settings/operation-types/%d/edit', $type->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame('PIX', $crawler->filter('input[name="operation_type_form[type]"]')->attr('value'));
    }

    public function testEditOperationTypeSuccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $type = $this->createOperationType('PIX', OperationEnum::CREDIT);

        $crawler = $client->request('GET', sprintf('/settings/operation-types/%d/edit', $type->getId()));
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => 'PIX Instant',
            'operation_type_form[operation]' => 'DEBIT',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $updated = $this->findOperationType('PIX Instant');
        self::assertNotNull($updated);
        self::assertSame(OperationEnum::DEBIT, $updated->getOperation());
    }

    public function testEditOperationTypeRejectsMissingOperation(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $type = $this->createOperationType('PIX', OperationEnum::CREDIT);

        $crawler = $client->request('GET', sprintf('/settings/operation-types/%d/edit', $type->getId()));
        $form = $crawler->filter('form[name="operation_type_form"]')->form();
        $values = $form->getPhpValues();
        $values['operation_type_form']['operation'] = '';
        $client->request('POST', $form->getUri(), $values);

        self::assertResponseIsUnprocessable();
        self::assertSame(OperationEnum::CREDIT, $this->findOperationType('PIX')?->getOperation());
    }

    public function testEditOperationTypeNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/settings/operation-types/999999/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousEditRedirectsToLogin(): void
    {
        static::createClient()->request('GET', '/settings/operation-types/1/edit');

        self::assertResponseRedirects('/login');
    }

    private function createOperationType(string $type, OperationEnum $operation = OperationEnum::CREDIT): OperationType
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $operationType = new OperationType();
        $operationType->setType($type);
        $operationType->setOperation($operation);
        $entityManager->persist($operationType);
        $entityManager->flush();
        $entityManager->clear();

        return $operationType;
    }

    private function findOperationType(string $type): ?OperationType
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(OperationType::class)
            ->findOneBy(['type' => $type]);
    }

    private function countOperationTypes(): int
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(OperationType::class)
            ->count([]);
    }

    private function createUser(?string $username = null): User
    {
        $username ??= sprintf('settings-%s', uniqid());
        $container = static::getContainer();
        $user = new User();
        $user->setEmail(sprintf('%s@example.com', $username));
        $user->setUsername($username);
        $user->setPassword(
            $container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password-123')
        );

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $entityManager->clear();

        return $user;
    }

    private function findUser(string $username): User
    {
        // Clear first: a rejected submit still mutates the managed entity
        // in memory, so re-fetching must hit the database, not the identity map.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $user = $entityManager
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]);

        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
