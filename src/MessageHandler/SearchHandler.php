<?php

namespace App\MessageHandler;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Enum\MessageRole;
use App\Message\SearchMessage;
use App\Repository\ConversationRepository;
use App\Service\AgentFactory;
use App\Service\Crypto;
use App\Service\ResultCollector;
use App\Service\SearchContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the conversational search in the background and pushes the result to the
 * browser over Mercure. The HTTP request that triggered it returned immediately;
 * the user watches "Searching…" then the answer + result cards stream in live.
 */
#[AsMessageHandler]
final class SearchHandler
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly EntityManagerInterface $em,
        private readonly AgentFactory $agentFactory,
        private readonly Crypto $crypto,
        private readonly ResultCollector $collector,
        private readonly SearchContext $searchContext,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function topic(string $conversationId): string
    {
        return 'hunch/conversation/'.$conversationId;
    }

    public function __invoke(SearchMessage $message): void
    {
        if (!Uuid::isValid($message->conversationId)) {
            return;
        }
        $conversation = $this->conversations->find(Uuid::fromString($message->conversationId));
        if (null === $conversation) {
            return;
        }
        $topic = self::topic($message->conversationId);
        $user = $conversation->getUser();

        $this->publish($topic, ['type' => 'status', 'text' => 'Searching…']);

        if (!$user->hasAnthropicKey()) {
            $this->finish($conversation, $topic, 'Add your Anthropic API key in Settings to search.');

            return;
        }

        $this->collector->reset();
        $this->searchContext->userId = (string) $user->getId();

        // System prompt + full prior conversation (the new user message is the last one).
        $bag = new MessageBag(Message::forSystem(
            AgentFactory::SYSTEM_PROMPT."\n\nToday is ".date('Y-m-d').'.'
        ));
        foreach ($conversation->getMessages() as $m) {
            $bag->add(MessageRole::Assistant === $m->getRole()
                ? Message::ofAssistant($m->getContent())
                : Message::ofUser($m->getContent()));
        }

        try {
            $agent = $this->agentFactory->forApiKey($this->crypto->decrypt($user->getAnthropicKeyEnc() ?? ''));
            $assistant = (string) $agent->call($bag, ['max_tokens' => 2048])->getContent();
        } catch (\Throwable $e) {
            $this->logger->error('Search failed: '.$e->getMessage());
            $this->finish($conversation, $topic, 'Search failed: '.$e->getMessage());

            return;
        }

        $this->saveAssistant($conversation, $assistant);
        $this->publish($topic, ['type' => 'assistant', 'text' => $assistant]);

        $results = [];
        foreach ($this->collector->results() as $r) {
            $h = $r['hit'];
            $results[] = [
                'id' => (string) ($h['id'] ?? ''),
                'subject' => (string) ($h['subject'] ?? '(no subject)'),
                'from' => (string) ($h['from'] ?? ''),
                'date' => substr((string) ($h['dateISO'] ?? ''), 0, 10),
                'reason' => (string) $r['reason'],
            ];
        }
        if ($results) {
            $this->publish($topic, ['type' => 'results', 'results' => $results]);
        }
        $this->publish($topic, ['type' => 'done']);
    }

    private function finish(Conversation $c, string $topic, string $assistant): void
    {
        $this->saveAssistant($c, $assistant);
        $this->publish($topic, ['type' => 'assistant', 'text' => $assistant]);
        $this->publish($topic, ['type' => 'done']);
    }

    private function saveAssistant(Conversation $c, string $text): void
    {
        $msg = (new ConversationMessage())->setRole(MessageRole::Assistant)->setContent($text);
        $c->addMessage($msg);
        $this->em->persist($msg);
        $this->em->flush();
    }

    /** @param array<string,mixed> $data */
    private function publish(string $topic, array $data): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode($data, \JSON_THROW_ON_ERROR)));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed: '.$e->getMessage());
        }
    }
}
