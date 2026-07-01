<?php

namespace App\Entity;

use App\Repository\MailboxRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** An IMAP account belonging to a user. The password is stored encrypted at rest. */
#[ORM\Entity(repositoryClass: MailboxRepository::class)]
class Mailbox
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'mailboxes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 120)]
    private string $label = '';

    #[ORM\Column(length: 255)]
    private string $imapHost = '';

    #[ORM\Column]
    private int $imapPort = 1143;

    #[ORM\Column(length: 255)]
    private string $imapUsername = '';

    /** Encrypted IMAP password (libsodium secretbox), never the plaintext. */
    #[ORM\Column(type: 'text')]
    private string $imapPasswordEnc = '';

    #[ORM\Column(length: 20)]
    private string $security = 'starttls'; // starttls | ssl | none

    #[ORM\Column]
    private bool $verifyCert = false;

    /** @var list<string> */
    #[ORM\Column]
    private array $folders = ['INBOX'];

    /** @var array<string,int> folder => last seen UID (incremental sync cursor) */
    #[ORM\Column]
    private array $syncState = [];

    /** never | syncing | ok | error */
    #[ORM\Column(length: 20, options: ['default' => 'never'])]
    private string $syncStatus = 'never';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getImapHost(): string
    {
        return $this->imapHost;
    }

    public function setImapHost(string $h): static
    {
        $this->imapHost = $h;

        return $this;
    }

    public function getImapPort(): int
    {
        return $this->imapPort;
    }

    public function setImapPort(int $p): static
    {
        $this->imapPort = $p;

        return $this;
    }

    public function getImapUsername(): string
    {
        return $this->imapUsername;
    }

    public function setImapUsername(string $u): static
    {
        $this->imapUsername = $u;

        return $this;
    }

    public function getImapPasswordEnc(): string
    {
        return $this->imapPasswordEnc;
    }

    public function setImapPasswordEnc(string $enc): static
    {
        $this->imapPasswordEnc = $enc;

        return $this;
    }

    public function getSecurity(): string
    {
        return $this->security;
    }

    public function setSecurity(string $s): static
    {
        $this->security = $s;

        return $this;
    }

    public function isVerifyCert(): bool
    {
        return $this->verifyCert;
    }

    public function setVerifyCert(bool $v): static
    {
        $this->verifyCert = $v;

        return $this;
    }

    /** @return list<string> */
    public function getFolders(): array
    {
        return $this->folders;
    }

    /** @param list<string> $f */
    public function setFolders(array $f): static
    {
        $this->folders = array_values($f);

        return $this;
    }

    /** @return array<string,int> */
    public function getSyncState(): array
    {
        return $this->syncState;
    }

    public function lastUid(string $folder): int
    {
        return $this->syncState[$folder] ?? 0;
    }

    public function setLastUid(string $folder, int $uid): static
    {
        $this->syncState[$folder] = $uid;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSyncStatus(): string
    {
        return $this->syncStatus;
    }

    public function setSyncStatus(string $status): static
    {
        $this->syncStatus = $status;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $at): static
    {
        $this->lastSyncedAt = $at;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $error): static
    {
        $this->lastError = $error;

        return $this;
    }
}
