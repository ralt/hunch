<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Crypto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/settings')]
final class SettingsController extends AbstractController
{
    #[Route('', name: 'settings', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('settings/index.html.twig', ['hasKey' => $user->hasAnthropicKey()]);
    }

    #[Route('/anthropic-key', name: 'settings_anthropic_key', methods: ['POST'])]
    public function setKey(Request $request, Crypto $crypto, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('settings', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('settings');
        }

        $key = trim((string) $request->request->get('anthropic_key'));
        if ('' === $key) {
            $user->setAnthropicKeyEnc(null);
            $this->addFlash('success', 'Anthropic API key cleared.');
        } else {
            $user->setAnthropicKeyEnc($crypto->encrypt($key));
            $this->addFlash('success', 'Anthropic API key saved (encrypted).');
        }
        $em->flush();

        return $this->redirectToRoute('settings');
    }
}
