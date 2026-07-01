<?php

namespace App\MessageHandler;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Enum\MessageRole;
use App\Message\SearchMessage;
use App\Repository\ConversationRepository;
use App\Service\AgentFactory;
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
        private readonly ResultCollector $collector,
        private readonly SearchContext $searchContext,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Per-user topic namespace. The subscriber cookie grants this exact topic
     * (not a template — Mercure treats relative URI templates as match-all), so
     * a browser can only subscribe to the conversation it currently holds.
     */
    public static function topic(string $userId, string $conversationId): string
    {
        return 'hunch/user/'.$userId.'/conversation/'.$conversationId;
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
        $user = $conversation->getUser();
        $topic = self::topic((string) $user->getId(), $message->conversationId);

        $this->publish($topic, ['type' => 'status', 'text' => 'Searching…']);

        if (!$user->isAiConfigured()) {
            $this->finish($conversation, $topic, 'Add your AI provider key in Settings to search.');

            return;
        }

        // Seed the candidate registry from the conversation so numbers stay
        // stable across turns (the model refers to "#7" from earlier messages).
        $this->collector->seed($conversation->getCandidates());
        $this->searchContext->userId = (string) $user->getId();
        $this->searchContext->topic = $topic; // lets the search tool stream live

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
            $agent = $this->agentFactory->forUser($user);
            $assistant = (string) $agent->call($bag, ['max_tokens' => 2048])->getContent();
        } catch (\Throwable $e) {
            $this->logger->error('Search failed: '.$e->getMessage());
            $this->finish($conversation, $topic, 'Search failed: '.$e->getMessage());

            return;
        }

        // The presented set = everything the agent formally presented via
        // present_results, PLUS any email it referenced by number ("#12") in its
        // reply. The model doesn't always call present_results, so treating every
        // mentioned email as a pick keeps the results pane's "Picked by Hunch"
        // grouping meaningful. Keyed by id to de-dupe.
        $candidates = $this->collector->candidates();
        $presented = [];
        foreach ($this->collector->results() as $r) {
            $id = (string) ($r['hit']['id'] ?? '');
            if ('' !== $id) {
                $presented[$id] = self::presentedRow($r['hit'], (string) $r['reason']);
            }
        }
        if (preg_match_all('/#(\d+)/', $assistant, $m)) {
            foreach ($m[1] as $num) {
                $h = $candidates[(int) $num] ?? null;
                if (\is_array($h) && '' !== ($id = (string) ($h['id'] ?? '')) && !isset($presented[$id])) {
                    $presented[$id] = self::presentedRow($h, '');
                }
            }
        }
        $results = array_values($presented);

        // Persist the (now conversation-wide) candidate registry and the presented
        // emails, so numbers keep resolving and the picks survive a reload.
        $conversation->setCandidates($candidates);
        if ($results) {
            $conversation->setPresentedResults($results);
        }
        $this->saveAssistant($conversation, $assistant);

        // Final authoritative ranked candidate set travels with the reply, so
        // "#12" references linkify and the results pane matches even if a
        // mid-search live update was missed.
        $this->publish($topic, [
            'type' => 'assistant',
            'text' => $assistant,
            'candidates' => $this->collector->rankedList(),
        ]);
        if ($results) {
            // Mark which candidates are presented (so they group at the top).
            $this->publish($topic, ['type' => 'results', 'results' => $results]);
        }
        $this->publish($topic, ['type' => 'done']);
    }

    /**
     * @param array<string,mixed> $h
     *
     * @return array<string,mixed>
     */
    private static function presentedRow(array $h, string $reason): array
    {
        return [
            'id' => (string) ($h['id'] ?? ''),
            'subject' => (string) ($h['subject'] ?? '(no subject)'),
            'from' => (string) ($h['from'] ?? ''),
            'date' => substr((string) ($h['dateISO'] ?? ''), 0, 10),
            'reason' => $reason,
        ];
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
            // private: true — the hub delivers this only to subscribers whose JWT
            // authorizes the topic, so users can't receive each other's streams.
            $this->hub->publish(new Update($topic, json_encode($data, \JSON_THROW_ON_ERROR), true));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed: '.$e->getMessage());
        }
    }
}
