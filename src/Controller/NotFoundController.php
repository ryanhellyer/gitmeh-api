<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NotFoundController extends AbstractController
{
    #[Route(
        '/{path}',
        name: 'app_not_found',
        requirements: ['path' => '.*'],
        priority: -1000
    )]
    public function __invoke(Request $request): Response
    {
        if (!in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)) {
            return $this->render('error404.html.twig', [
                'status_code' => Response::HTTP_METHOD_NOT_ALLOWED,
                'status_text' => 'Method Not Allowed',
            ])->setStatusCode(Response::HTTP_METHOD_NOT_ALLOWED);
        }

        return $this->render('error404.html.twig', [
            'status_code' => Response::HTTP_NOT_FOUND,
            'status_text' => 'Not Found',
        ])->setStatusCode(Response::HTTP_NOT_FOUND);
    }
}