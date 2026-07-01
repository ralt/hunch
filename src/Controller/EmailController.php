<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\MailIndex;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Read-only viewing of a found email. Not a client — just shows the message. */
final class EmailController extends AbstractController
{
    public function __construct(private readonly MailIndex $index)
    {
    }

    #[Route('/email/{id}', name: 'email_view', methods: ['GET'], requirements: ['id' => '[a-f0-9]{40}'])]
    public function view(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $doc = $this->index->getDocument($id);

        // Scope to the owner — never expose another user's mail.
        if (null === $doc || ($doc['userId'] ?? null) !== (string) $user->getId()) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        return new JsonResponse([
            'subject' => $doc['subject'] ?? '(no subject)',
            'from' => $doc['from'] ?? '',
            'to' => $doc['to'] ?? '',
            'date' => $doc['dateISO'] ?? '',
            'folder' => $doc['folder'] ?? '',
            'body' => $doc['body'] ?? '',
        ]);
    }
}
