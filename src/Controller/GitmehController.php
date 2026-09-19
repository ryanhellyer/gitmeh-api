<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GitmehCommitMessageGenerator;
use App\Service\GitmehHourlyLimiter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GitmehController extends AbstractController
{
    public function __construct(
        private readonly GitmehCommitMessageGenerator $generator,
        private readonly GitmehHourlyLimiter $limiter,
    ) {
    }

    #[Route('/gitmeh', name: 'gitmeh_get', methods: ['GET'])]
    public function get(Request $request): Response
    {
        // Read-only quota status page — does NOT consume quota (the rate limiter
        // subscriber only counts POST /gitmeh). The start time is captured at the
        // very top of public/index.php (before autoload/Runtime/kernel/container),
        // so this measurement covers the full request lifecycle — same scope as
        // Laravel's LARAVEL_START.
        $start = $GLOBALS['_gitmeh_request_start'] ?? microtime(true);
        $ip = $request->getClientIp() ?? '127.0.0.1';

        return $this->render('gitmeh/status.html.twig', [
            'ip' => $ip,
            ...$this->limiter->statusForIp($ip),
            'render_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);
    }

    #[Route('/gitmeh', name: 'gitmeh_post', methods: ['POST'])]
    public function post(Request $request): Response
    {
        // Legacy endpoint: raw diff in the request body, plain-text commit message out.
        $diff = $request->getContent();
        $instruction = $this->generator->defaultInstruction();

        $result = $this->generator->generate($instruction, $diff);

        if (!$result['ok']) {
            return new Response(
                $result['error'],
                $result['status'],
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return new Response(
            $result['message'],
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }
}
