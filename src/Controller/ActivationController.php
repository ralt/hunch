<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Set your password on first login": an admin-created account carries a
 * one-time activation token. The user opens /activate/<token>, chooses a
 * password, and the account becomes usable. Public route (user isn't logged in
 * yet).
 */
final class ActivationController extends AbstractController
{
    #[Route('/activate/{token}', name: 'activate', methods: ['GET', 'POST'])]
    public function activate(
        string $token,
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): Response {
        $user = $users->findByActivationToken($token);
        if (null === $user || $user->isActivationExpired()) {
            // Unknown, already-used, or expired token.
            return $this->render('activate/invalid.html.twig', [], new Response('', 404));
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('activate', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid CSRF token.');
            } else {
                $password = (string) $request->request->get('password');
                $confirm = (string) $request->request->get('confirm');
                if (mb_strlen($password) < 8) {
                    $this->addFlash('error', 'Password must be at least 8 characters.');
                } elseif ($password !== $confirm) {
                    $this->addFlash('error', 'Passwords do not match.');
                } else {
                    $user->activate($hasher->hashPassword($user, $password));
                    $em->flush();
                    $this->addFlash('success', 'Password set — you can now log in.');

                    return $this->redirectToRoute('login');
                }
            }
        }

        return $this->render('activate/set_password.html.twig', ['email' => $user->getEmail()]);
    }
}
