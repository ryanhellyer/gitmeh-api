<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Service\ModelRunnerInterface;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Role;

/**
 * Minimal ModelRunnerInterface double: records the prompt/options it was handed.
 *
 * @internal
 */
final class RecordingModelRunner implements ModelRunnerInterface
{
    /** @var list<array{model: string, options: array<string, mixed>, system: string, user: string}> */
    public array $calls = [];

    public function __construct(
        private readonly string $text = 'translated text',
        private readonly bool $ok = true,
    ) {
    }

    public function run(
        string $model,
        MessageBag $messages,
        array $options = [],
        array $fallbackModels = [],
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
    ): array {
        $system = '';
        $user = '';

        foreach ($messages as $message) {
            $content = $message->getContent();

            if ($message->getRole() === Role::System) {
                $system = (string) $content; // string or Template
            } elseif ($message->getRole() === Role::User) {
                foreach ((array) $content as $part) {
                    if ($part instanceof Text) {
                        $user .= $part->getText();
                    }
                }
            }
        }

        $this->calls[] = [
            'model' => $model,
            'options' => $options,
            'system' => $system,
            'user' => $user,
        ];

        if (!$this->ok) {
            return ['ok' => false, 'error' => 'upstream exploded', 'status' => 502];
        }

        return ['ok' => true, 'text' => $this->text, 'model' => $model];
    }
}
