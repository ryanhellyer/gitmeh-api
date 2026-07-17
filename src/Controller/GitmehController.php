<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GitmehController extends AbstractController
{
    #[Route('/gitmeh', name: 'gitmeh_get', methods: ['GET'])]
    public function get(): Response
    {
        return new Response(
            'Hello from GET',
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain']
        );
    }

    #[Route('/gitmeh', name: 'gitmeh_post', methods: ['POST'])]
    public function post(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'message' => 'Hello from POST',
        ]);
    }
}