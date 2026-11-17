<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GitMehCompletionService;
use App\Tests\Double\RecordingModelRunner;
use PHPUnit\Framework\TestCase;

final class GitMehCompletionServiceTest extends TestCase
{
    private const INSTRUCTIONS = "STANDARD GITMEH INSTRUCTIONS\nOne line only.";
    private const DIFF = "Unified diff:\ndiff --git a/main.go b/main.go\n--- a/main.go\n+++ b/main.go\n@@ -1 +1 @@\n-old\n+new\n";

    private RecordingModelRunner $runner;

    private GitMehCompletionService $service;

    protected function setUp(): void
    {
        $this->runner = new RecordingModelRunner('feat(api): add git_meh endpoint');
        $this->service = new GitMehCompletionService($this->runner, self::INSTRUCTIONS, 'test/upstream-model');
    }

    public function testSendsStandardInstructionsAndDiffVerbatim(): void
    {
        $result = $this->service->complete(self::DIFF);

        self::assertTrue($result['ok']);
        self::assertSame('feat(api): add git_meh endpoint', $result['message']);
        self::assertSame('test/upstream-model', $result['model']);

        self::assertCount(1, $this->runner->calls);
        $call = $this->runner->calls[0];
        self::assertSame('test/upstream-model', $call['model']);
        self::assertSame(self::INSTRUCTIONS, $call['system']);
        self::assertSame(self::DIFF, $call['user'], 'the diff must be sent verbatim, prefix included');
        self::assertSame(0.3, $call['options']['temperature']);
        self::assertSame(4096, $call['options']['max_tokens']);
    }

    public function testStripsCodeFenceBeforeValidating(): void
    {
        $runner = new RecordingModelRunner("```text\nfix(cli): remove retry delay\n```");
        $service = new GitMehCompletionService($runner, self::INSTRUCTIONS, 'model');

        $result = $service->complete(self::DIFF);

        self::assertTrue($result['ok']);
        self::assertSame('fix(cli): remove retry delay', $result['message']);
    }

    public function testStripsSurroundingQuotes(): void
    {
        $runner = new RecordingModelRunner('"docs: explain the hosted endpoint"');
        $service = new GitMehCompletionService($runner, self::INSTRUCTIONS, 'model');

        $result = $service->complete(self::DIFF);

        self::assertTrue($result['ok']);
        self::assertSame('docs: explain the hosted endpoint', $result['message']);
    }

    public function testKeepsOnlyTheFirstLine(): void
    {
        $runner = new RecordingModelRunner("refactor(config): split instructions\n\nA body the model added anyway.");
        $service = new GitMehCompletionService($runner, self::INSTRUCTIONS, 'model');

        $result = $service->complete(self::DIFF);

        self::assertTrue($result['ok']);
        self::assertSame('refactor(config): split instructions', $result['message']);
    }

    public function testRejectsNonConventionalReply(): void
    {
        $runner = new RecordingModelRunner('Here is the commit message: feat: add x');
        $service = new GitMehCompletionService($runner, self::INSTRUCTIONS, 'model');

        $result = $service->complete(self::DIFF);

        self::assertFalse($result['ok']);
        self::assertSame(502, $result['status']);
        self::assertStringContainsString('Conventional Commits', $result['error']);
    }

    public function testPassesThroughRunnerFailure(): void
    {
        $runner = new RecordingModelRunner('unused', false);
        $service = new GitMehCompletionService($runner, self::INSTRUCTIONS, 'model');

        $result = $service->complete(self::DIFF);

        self::assertFalse($result['ok']);
        self::assertSame(502, $result['status']);
        self::assertSame('upstream exploded', $result['error']);
    }
}
