<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    /**
     * Which AI provider drives this user's search: 'anthropic', 'openai', or
     * 'ollama' (local inference). See App\Service\AgentFactory.
     */
    #[ORM\Column(length: 20, options: ['default' => 'anthropic'])]
    private string $aiProvider = 'anthropic';

    /** Encrypted API key for the provider (per-user); null for Ollama / until set. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $apiKeyEnc = null;

    /** Optional model override; falls back to the provider's default when empty. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $aiModel = null;

    /** Ollama server URL (e.g. http://localhost:11434); only used for the Ollama provider. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $aiBaseUrl = null;

    /**
     * SHA-256 hash of the one-time activation token (the plaintext only ever
     * lives in the invite link). Single-use: cleared the moment the user sets a
     * password. Stored hashed so a DB leak can't be used to activate accounts.
     */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $activationToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activationExpiresAt = null;

    /** @var Collection<int, Mailbox> */
    #[ORM\OneToMany(targetEntity: Mailbox::class, mappedBy: 'owner', cascade: ['remove'], orphanRemoval: true)]
    private Collection $mailboxes;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->mailboxes = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
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
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function isAdmin(): bool
    {
        return \in_array('ROLE_ADMIN', $this->roles, true);
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Put the account into "must set password" state and return the *plaintext*
     * token for the invite link (only the hash is persisted). Valid for 72h.
     */
    public function startActivation(): string
    {
        $plain = bin2hex(random_bytes(32));
        $this->password = '';
        $this->activationToken = self::hashToken($plain);
        $this->activationExpiresAt = new \DateTimeImmutable('+72 hours');

        return $plain;
    }

    public function isPendingActivation(): bool
    {
        return null !== $this->activationToken;
    }

    public function isActivationExpired(): bool
    {
        return null === $this->activationExpiresAt || $this->activationExpiresAt < new \DateTimeImmutable();
    }

    /** Complete activation: store the hashed password and burn the token (single-use). */
    public function activate(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
        $this->activationToken = null;
        $this->activationExpiresAt = null;
    }

    public function getAiProvider(): string
    {
        return $this->aiProvider;
    }

    public function setAiProvider(string $provider): static
    {
        $this->aiProvider = $provider;

        return $this;
    }

    public function getApiKeyEnc(): ?string
    {
        return $this->apiKeyEnc;
    }

    public function setApiKeyEnc(?string $enc): static
    {
        $this->apiKeyEnc = $enc;

        return $this;
    }

    public function hasApiKey(): bool
    {
        return null !== $this->apiKeyEnc && '' !== $this->apiKeyEnc;
    }

    public function getAiModel(): ?string
    {
        return $this->aiModel;
    }

    public function setAiModel(?string $model): static
    {
        $this->aiModel = $model ?: null;

        return $this;
    }

    public function getAiBaseUrl(): ?string
    {
        return $this->aiBaseUrl;
    }

    public function setAiBaseUrl(?string $url): static
    {
        $this->aiBaseUrl = $url ?: null;

        return $this;
    }

    /**
     * Whether this user can run a search: key-based providers need a key;
     * Ollama runs locally and only needs its server reachable (URL defaulted).
     */
    public function isAiConfigured(): bool
    {
        return 'ollama' === $this->aiProvider || $this->hasApiKey();
    }

    /** @return Collection<int, Mailbox> */
    public function getMailboxes(): Collection
    {
        return $this->mailboxes;
    }

    public function eraseCredentials(): void
    {
    }
}
