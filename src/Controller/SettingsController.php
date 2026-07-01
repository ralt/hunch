<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AgentFactory;
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

        return $this->render('settings/index.html.twig', [
            'provider' => $user->getAiProvider(),
            'model' => $user->getAiModel(),
            'baseUrl' => $user->getAiBaseUrl(),
            'hasKey' => $user->hasApiKey(),
            'providers' => AgentFactory::PROVIDERS,
            'defaultModels' => AgentFactory::DEFAULT_MODELS,
            'ollamaDefaultUrl' => AgentFactory::OLLAMA_DEFAULT_URL,
        ]);
    }

    #[Route('/ai', name: 'settings_ai', methods: ['POST'])]
    public function setAi(Request $request, Crypto $crypto, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('settings', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('settings');
        }

        $provider = (string) $request->request->get('provider');
        if (!\in_array($provider, AgentFactory::PROVIDERS, true)) {
            $this->addFlash('error', 'Unknown provider.');

            return $this->redirectToRoute('settings');
        }

        $user->setAiProvider($provider);
        $user->setAiModel(trim((string) $request->request->get('model')));
        $user->setAiBaseUrl(trim((string) $request->request->get('base_url')));

        // Blank key field keeps the stored one; a value replaces it; "__clear__" removes it.
        $key = trim((string) $request->request->get('api_key'));
        if ('__clear__' === $key) {
            $user->setApiKeyEnc(null);
        } elseif ('' !== $key) {
            $user->setApiKeyEnc($crypto->encrypt($key));
        }

        $em->flush();
        $this->addFlash('success', 'AI settings saved.');

        return $this->redirectToRoute('settings');
    }
}
