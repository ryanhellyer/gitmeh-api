<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GitmehCommitMessageGenerator
{
    public function __construct(
        private readonly ModelRunnerInterface $runner,
        #[Autowire('%gitmeh.prompt%')]
        private readonly string $defaultPrompt,
        #[Autowire('%gitmeh.default_model%')]
        private readonly string $defaultModel,
    ) {
    }

    /**
     * @param string[] $fallbackModels
     *
     * @return array{ok: true, message: string, model: string}|array{ok: false, error: string, status: int}
     */
    public function generate(
        string $instruction,
        string $unifiedDiff,
        ?int $inferenceTimeoutSeconds = null,
        ?string $model = null,
        array $fallbackModels = [],
    ): array {
        // The Laravel app treats the literal "gitmeh-hosted" model alias as "use the
        // server default" — forwarding it to OpenRouter verbatim would 404.
        if ($model === 'gitmeh-hosted') {
            $model = null;
        }
        $model ??= $this->defaultModel;

        $messages = new MessageBag(
            Message::forSystem($instruction),
            Message::ofUser("Unified diff:\n" . $unifiedDiff),
        );

        $result = $this->runner->run(
            $model,
            $messages,
            ['temperature' => 0.3, 'max_output_tokens' => 4096],
            $fallbackModels,
        );

        if (!$result['ok']) {
            return $result;
        }

        $firstLine = explode("\n", trim($result['text']), 2)[0];

        return ['ok' => true, 'message' => trim($firstLine), 'model' => $result['model']];
    }

    public function defaultInstruction(): string
    {
        return $this->defaultPrompt;
    }
}
