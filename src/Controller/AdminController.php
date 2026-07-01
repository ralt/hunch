<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Admin-only user provisioning (no open registration). */
#[Route('/admin')]
final class AdminController extends AbstractController
{
    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function users(UserRepository $users): Response
    {
        return $this->render('admin/users.html.twig', ['users' => $users->findBy([], ['email' => 'ASC'])]);
    }

    #[Route('/users/new', name: 'admin_user_new', methods: ['POST'])]
    public function newUser(
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('admin', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('admin_users');
        }
        $email = trim((string) $request->request->get('email'));
        if ('' === $email) {
            $this->addFlash('error', 'Email is required.');

            return $this->redirectToRoute('admin_users');
        }
        if ($users->findOneByEmail($email)) {
            $this->addFlash('error', 'That email already exists.');

            return $this->redirectToRoute('admin_users');
        }

        $user = (new User())->setEmail($email);
        if ($request->request->getBoolean('admin')) {
            $user->setRoles(['ROLE_ADMIN']);
        }
        $token = $user->startActivation();
        $em->persist($user);
        $em->flush();

        $link = $this->generateUrl('activate', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->addFlash('success', "Created {$email}. Send them this activation link: {$link}");

        return $this->redirectToRoute('admin_users');
    }
}
