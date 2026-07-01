<?php

namespace App\Entity;

use App\Enum\MessageRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One turn in a Conversation — modeled on braindump's AiMessage. */
#[ORM\Entity]
#[ORM\Index(columns: ['conversation_id', 'created_at'])]
class ConversationMessage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Conversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column(type: 'string', length: 20, enumType: MessageRole::class)]
    private MessageRole $role;

    #[ORM\Column(type: 'text')]
    private string $content;

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

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function setConversation(Conversation $conversation): static
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getRole(): MessageRole
    {
        return $this->role;
    }

    public function setRole(MessageRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
