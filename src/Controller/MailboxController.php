<?php

namespace App\Controller;

use App\Entity\Mailbox;
use App\Entity\User;
use App\Message\SyncMailboxMessage;
use App\Repository\MailboxRepository;
use App\Service\Crypto;
use App\Service\ImapTester;
use App\Service\MailIndex;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/mailboxes')]
final class MailboxController extends AbstractController
{
    #[Route('', name: 'mailboxes', methods: ['GET'])]
    public function index(MailIndex $index): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $rows = [];
        foreach ($user->getMailboxes() as $mb) {
            $rows[] = ['mb' => $mb, 'count' => $index->countForMailbox((string) $mb->getId())];
        }

        return $this->render('mailbox/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/new', name: 'mailbox_new', methods: ['POST'])]
    public function new(Request $request, Crypto $crypto, EntityManagerInterface $em, MessageBusInterface $bus): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('mailbox', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('mailboxes');
        }

        $folders = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->request->get('folders', 'INBOX'))
        )));

        $mb = (new Mailbox())
            ->setOwner($user)
            ->setLabel((string) $request->request->get('label', 'Mailbox'))
            ->setImapHost((string) $request->request->get('host'))
            ->setImapPort((int) $request->request->get('port', 1143))
            ->setImapUsername((string) $request->request->get('username'))
            ->setImapPasswordEnc($crypto->encrypt((string) $request->request->get('password')))
            ->setSecurity((string) $request->request->get('security', 'starttls'))
            ->setVerifyCert($request->request->getBoolean('verify_cert'))
            ->setFolders($folders ?: ['INBOX']);

        $em->persist($mb);
        $em->flush();

        // Kick off the initial sync in the background immediately.
        $bus->dispatch(new SyncMailboxMessage((string) $mb->getId()));
        $this->addFlash('success', 'Mailbox added — initial sync started in the background.');

        return $this->redirectToRoute('mailboxes');
    }

    #[Route('/{id}/sync', name: 'mailbox_sync', methods: ['POST'])]
    public function sync(string $id, Request $request, MailboxRepository $repo, MessageBusInterface $bus, EntityManagerInterface $em): Response
    {
        $mb = $this->ownedOr404($id, $repo, $request);
        // Clear any previous failure and mark it in-flight, so the page stops
        // showing a stale error the moment the user retries.
        $mb->setLastError(null)->setSyncStatus('syncing');
        $em->flush();
        // Syncing is long-running (IMAP + on-device embedding) — hand it to a
        // background worker and return immediately.
        $bus->dispatch(new SyncMailboxMessage((string) $mb->getId()));
        $this->addFlash('success', \sprintf('Sync started for %s — running in the background.', $mb->getLabel()));

        return $this->redirectToRoute('mailboxes');
    }

    /** AJAX: test IMAP settings before saving, so we know they'll work. */
    #[Route('/check', name: 'mailbox_check', methods: ['POST'])]
    public function check(Request $request, ImapTester $tester): JsonResponse
    {
        if (!$this->isCsrfTokenValid('mailbox', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
        }

        $result = $tester->test(
            (string) $request->request->get('host'),
            (int) $request->request->get('port', 1143),
            (string) $request->request->get('username'),
            (string) $request->request->get('password'),
            (string) $request->request->get('security', 'starttls'),
            $request->request->getBoolean('verify_cert'),
        );

        return new JsonResponse($result);
    }

    #[Route('/{id}/delete', name: 'mailbox_delete', methods: ['POST'])]
    public function delete(string $id, Request $request, MailboxRepository $repo, EntityManagerInterface $em): Response
    {
        $mb = $this->ownedOr404($id, $repo, $request);
        $em->remove($mb);
        $em->flush();
        $this->addFlash('success', 'Mailbox removed.');

        return $this->redirectToRoute('mailboxes');
    }

    private function ownedOr404(string $id, MailboxRepository $repo, Request $request): Mailbox
    {
        if (!$this->isCsrfTokenValid('mailbox', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $mb = Uuid::isValid($id) ? $repo->find(Uuid::fromString($id)) : null;
        if (!$mb || $mb->getOwner() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $mb;
    }
}
