# Add Symfony AI Platform with OpenRouter

## Overview
Install `symfony/ai-bundle` + `symfony/ai-open-router-platform`, wire it up to OpenRouter, and add a smoke-test route.

## Steps

### 1. Install packages
```bash
composer require symfony/ai-bundle symfony/ai-open-router-platform
```

`symfony/ai-bundle` pulls in `symfony/ai-platform` automatically and provides:
- Framework DI integration (autowiring `PlatformInterface`)
- YAML config under `config/packages/ai.yaml`
- Profiler toolbar panel showing AI calls
- Auto-registration of event listeners (template rendering, structured output, etc.)

`symfony/ai-open-router-platform` is the dedicated OpenRouter bridge — no need to hack the OpenAI bridge with a custom base URL.

### 2. Configure in `config/packages/ai.yaml`
The bundle recipe should scaffold this file. Configure it:
```yaml
ai:
    platform:
        openrouter:
            api_key: '%env(OPENROUTER_API_KEY)%'
            # optionally specify model defaults
```

### 3. Add env var
In `.env`:
```
OPENROUTER_API_KEY=
```
Set the actual key in `.env.local`.

### 4. Smoke test route
Create `src/Controller/AiTestController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AiTestController extends AbstractController
{
    #[Route('/ai-test', name: 'ai_test')]
    public function test(PlatformInterface $platform): Response
    {
        $messages = new MessageBag(
            Message::ofUser('Say hello in exactly 5 words.')
        );

        $result = $platform->invoke('openai/gpt-4o-mini', $messages);

        return new Response($result->asText());
    }
}
```

### 5. Verify
```bash
curl -k https://ai-symfony.hellyer.test/ai-test
```
Should return an LLM response, and the Symfony profiler toolbar should show the AI call.

## Files affected
- `composer.json` — new deps added by Composer
- `config/packages/ai.yaml` — created by recipe, edited
- `.env` / `.env.local` — add `OPENROUTER_API_KEY`
- `src/Controller/AiTestController.php` — new (remove after confirming it works)

## Post-verification
Remove the smoke test controller. The `PlatformInterface` will be autowirable throughout the app.
