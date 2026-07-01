<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\User;
use App\Enum\MessageRole;
use App\Message\SearchMessage;
use App\MessageHandler\SearchHandler;
use App\Repository\ConversationRepository;
use App\Service\ResultCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Conversational, per-user email search. A search no longer runs in the request:
 * the controller persists the user's message, dispatches a background job, and
 * returns immediately with the Mercure topic. The worker runs the agent and
 * pushes the answer + result cards to that topic; the browser renders them live.
 */
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')] private readonly string $mercurePublicUrl,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $conversation = $this->loadConversation($request->query->get('conversation'), $user);

        return $this->render('search/index.html.twig', [
            'conversation' => $conversation,
            'presentedResults' => $conversation ? $conversation->getPresentedResults() : [],
            'candidates' => $conversation ? ResultCollector::rank($conversation->getCandidates()) : [],
            'recent' => $this->conversations->recentForUser($user),
            'mercurePublicUrl' => $this->mercurePublicUrl,
            'topic' => $conversation ? SearchHandler::topic((string) $conversation->getId()) : '',
        ]);
    }

    #[Route('/search', name: 'search', methods: ['POST'])]
    public function search(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getMailboxes()->isEmpty()) {
            return new JsonResponse(['error' => 'Add a mailbox before searching.', 'redirect' => $this->generateUrl('mailboxes')], 400);
        }
        if (!$user->hasAnthropicKey()) {
            return new JsonResponse(['error' => 'Add your Anthropic API key in Settings.', 'redirect' => $this->generateUrl('settings')], 400);
        }

        $payload = json_decode($request->getContent(), true) ?: [];
        $userText = trim((string) ($payload['message'] ?? ''));
        if ('' === $userText) {
            return new JsonResponse(['error' => 'Empty message.'], 400);
        }

        $conversation = $this->loadConversation($payload['conversation'] ?? null, $user);
        if (null === $conversation) {
            $conversation = (new Conversation())->setUser($user)->setTitle(mb_substr($userText, 0, 80));
            $this->em->persist($conversation);
        }

        $msg = (new ConversationMessage())->setRole(MessageRole::User)->setContent($userText);
        $conversation->addMessage($msg);
        $this->em->persist($msg);
        $this->em->flush();

        $this->bus->dispatch(new SearchMessage((string) $conversation->getId()));

        return new JsonResponse([
            'conversationId' => (string) $conversation->getId(),
            'topic' => SearchHandler::topic((string) $conversation->getId()),
        ]);
    }

    private function loadConversation(mixed $id, User $user): ?Conversation
    {
        if (!\is_string($id) || '' === $id || !Uuid::isValid($id)) {
            return null;
        }
        $c = $this->conversations->find(Uuid::fromString($id));

        return ($c && $c->getUser() === $user) ? $c : null;
    }
}
