<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the hosted gitmeh commit-message request.
 *
 * The caller supplies only the diff. The system message is always the standard
 * instructions configured in config/packages/gitmeh.yaml, so this service
 * cannot be turned into a general-purpose completion proxy.
 */
final class GitMehCompletionService
{
    public const MAX_DIFF_BYTES = 2 * 1024 * 1024;
    public const MAX_INSTRUCTIONS_BYTES = 20000;

    private const TEMPERATURE = 0.3;
    private const MAX_TOKENS = 4096;

    /**
     * Conventional Commits 1.0.0 header: type(scope)!: description.
     */
    private const COMMIT_MESSAGE_PATTERN = '/\A(?:feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(?:\([a-z0-9._\/-]+\))?!?: .+\z/';

    public function __construct(
        private readonly ModelRunnerInterface $runner,
        #[Autowire('%gitmeh.instructions%')]
        private readonly string $instructions,
        #[Autowire('%gitmeh.default_model%')]
        private readonly string $defaultModel,
    ) {
    }

    /**
     * @return array{ok: true, message: string, model: string}|array{ok: false, error: string, status: int}
     */
    public function complete(string $diff): array
    {
        $messages = new MessageBag(
            Message::forSystem($this->instructions),
            Message::ofUser($diff),
        );

        $result = $this->runner->run(
            $this->defaultModel,
            $messages,
            ['temperature' => self::TEMPERATURE, 'max_tokens' => self::MAX_TOKENS],
        );

        if (!$result['ok']) {
            return $result;
        }

        $message = $this->firstCommitMessageLine($result['text']);

        if ($message === '' || preg_match(self::COMMIT_MESSAGE_PATTERN, $message) !== 1) {
            return [
                'ok' => false,
                'error' => 'The model did not return a valid Conventional Commits message.',
                'status' => 502,
            ];
        }

        return ['ok' => true, 'message' => $message, 'model' => $result['model']];
    }

    /**
     * Trim the reply, unwrap an optional code fence and surrounding quotes, and
     * keep the first non-empty line (a commit message is a single line).
     */
    private function firstCommitMessageLine(string $text): string
    {
        $text = trim($text);

        if (preg_match('/\A```[a-zA-Z0-9_-]*\R(.*?)\R?```\z/s', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line, " \t\n\r\0\x0B\"'`");

            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }
}
