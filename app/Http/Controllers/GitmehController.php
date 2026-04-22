<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GitmehCommitMessageGenerator;
use Illuminate\Http\Request;

class GitmehController extends Controller
{
    public function __construct(
        private GitmehCommitMessageGenerator $generator
    ) {}

    public function __invoke(Request $request)
    {
        $smartDiff = $request->getContent();
        $instruction = $this->generator->defaultInstruction();

        $result = $this->generator->generate($instruction, $smartDiff);

        if (! $result['ok']) {
            return $this->plain($result['error'], $result['status']);
        }

        return $this->plain($result['message'], 200);
    }

    private function plain(string $body, int $status)
    {
        return response($body, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
