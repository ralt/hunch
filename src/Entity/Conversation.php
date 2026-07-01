<?php

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A persisted AI search conversation — modeled on braindump's AiSession. */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
class Conversation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Candidate registry for this conversation: number => hit metadata. Kept
     * stable across turns so a reference like "#7" resolves even many messages
     * later (the model replays numbers from earlier assistant text).
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $candidates = [];

    /**
     * The emails last presented to the user (id + reason), so result cards can
     * be re-rendered when the conversation is reopened.
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $presentedResults = [];

    /** @var Collection<int, ConversationMessage> */
    #[ORM\OneToMany(targetEntity: ConversationMessage::class, mappedBy: 'conversation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->messages = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array<int, array<string, mixed>> */
    public function getCandidates(): array
    {
        return $this->candidates;
    }

    /** @param array<int, array<string, mixed>> $candidates */
    public function setCandidates(array $candidates): static
    {
        $this->candidates = $candidates;

        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function getPresentedResults(): array
    {
        return $this->presentedResults;
    }

    /** @param array<int, array<string, mixed>> $results */
    public function setPresentedResults(array $results): static
    {
        $this->presentedResults = $results;

        return $this;
    }

    /** @return Collection<int, ConversationMessage> */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(ConversationMessage $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
        }

        return $this;
    }
}
