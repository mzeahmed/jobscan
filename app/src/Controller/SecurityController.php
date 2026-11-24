<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\UiUserProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route(
        '/login',
        name: 'app_login',
        methods: ['GET', 'POST']
    )]
    public function login(AuthenticationUtils $authentication, UiUserProvider $users): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_applications');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authentication->getLastUsername(),
            'error' => $authentication->getLastAuthenticationError(),
            'configured' => $users->isConfigured(),
        ]);
    }

    /** Interceptée par le pare-feu (avec jeton CSRF) : ce code n'est jamais exécuté. */
    #[Route(
        '/logout',
        name: 'app_logout',
        methods: ['GET']
    )]
    public function logout(): never
    {
        throw new \LogicException('Déconnexion gérée par le pare-feu.');
    }
}
