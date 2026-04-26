<?php

declare(strict_types=1);

namespace Armin\AiAgent\Tests;

use Armin\AiAgent\Auth\AgentAuth;
use Armin\AiAgent\Auth\AgentAuthTokens;
use Armin\AiAgent\AiAgentClient;
use Armin\AiAgent\AiAgentConfig;
use Armin\AiAgent\AiAgentResponse;
use Armin\AiAgent\AiAgentTokenUsage;
use Armin\AiAgent\Exception\InvalidToolInput;
use Armin\AiAgent\Exception\InvalidSession;
use Armin\AiAgent\Exception\ToolNotFound;
use Armin\AiAgent\Internal\AiAgentRuntimeInterface;
use Armin\AiAgent\Tool\Builtin\ViewImageTool;
use Armin\AiAgent\Tool\SchemaAwareToolInterface;
use Armin\AiAgent\Tool\ToolInterface;
use Armin\AiAgent\Tool\ToolRegistry;
use Armin\AiAgent\Tool\ToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiAgentClientTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR);
        putenv(AiAgentConfig::MODEL_ENV_VAR);
        $this->tempDirectory = sys_get_temp_dir() . '/ai-agent-tests-' . bin2hex(random_bytes(4));
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR);
        putenv(AiAgentConfig::MODEL_ENV_VAR);
        $this->removeDirectory($this->tempDirectory);
    }

    public function testBuiltinsAreRegisteredByDefault(): void
    {
        $client = new AiAgentClient();

        self::assertTrue($client->hasTool('apply_patch'));
        self::assertTrue($client->hasTool('read_file'));
        self::assertTrue($client->hasTool('search'));
        self::assertTrue($client->hasTool('shell'));
        self::assertFalse($client->hasTool('write_file'));
        self::assertFalse($client->hasTool('edit_file'));
        self::assertFalse($client->hasTool('write_files'));
        self::assertFalse($client->hasTool('run_command'));
        self::assertTrue($client->hasTool('view_image'));
        self::assertFalse($client->hasTool('find_files'));
        self::assertFalse($client->hasTool('generate_image'));
    }

    public function testApiKeyIsReadFromEnvironment(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR . '=test-key');

        $client = new AiAgentClient(new AiAgentConfig());

        self::assertSame('test-key', $client->apiKey());
    }

    public function testApiKeyCanBeResolvedFromAuthObject(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(auth: new AgentAuth(
            authMode: 'tokens',
            apiKey: null,
            tokens: new AgentAuthTokens('id', 'access', 'refresh', 'account'),
        )));

        self::assertNull($client->apiKey());
        self::assertNotNull($client->auth());
    }

    public function testMutableApiKeyOverrideTakesPrecedenceOverAuthObject(): void
    {
        $config = new AiAgentConfig(auth: new AgentAuth(
            authMode: AgentAuth::MODE_API_KEY,
            apiKey: 'auth-key',
        ));

        $config->setApiKey('override-key');

        self::assertSame('override-key', $config->apiKey());
    }

    public function testConfigExposesWorkingDirectoryAndSystemPromptSettings(): void
    {
        $config = new AiAgentConfig(
            sessionFile: $this->tempDirectory . '/session.json',
            workingDirectory: $this->tempDirectory,
            systemPrompt: 'Answer tersely.',
            systemPromptMode: 'replace',
        );

        self::assertSame($this->tempDirectory . '/session.json', $config->sessionFile());
        self::assertSame($this->tempDirectory, $config->workingDirectory());
        self::assertSame('Answer tersely.', $config->systemPrompt());
        self::assertSame('replace', $config->systemPromptMode());
    }

    public function testBuiltinHostedToolFlagsDefaultToEnabled(): void
    {
        $config = new AiAgentConfig();

        self::assertTrue($config->enableBuiltinWebSearch());
        self::assertTrue($config->enableBuiltinImageGeneration());
    }

    public function testBuiltinHostedToolFlagsSupportConstructorAndSetterOverrides(): void
    {
        $config = new AiAgentConfig(
            enableBuiltinWebSearch: false,
            enableBuiltinImageGeneration: false,
        );

        self::assertFalse($config->enableBuiltinWebSearch());
        self::assertFalse($config->enableBuiltinImageGeneration());

        $config->setEnableBuiltinWebSearch(true);
        $config->setEnableBuiltinImageGeneration(true);

        self::assertTrue($config->enableBuiltinWebSearch());
        self::assertTrue($config->enableBuiltinImageGeneration());
    }

    public function testInvalidSystemPromptModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AiAgentConfig(systemPromptMode: 'invalid');
    }

    public function testMissingApiKeyReturnsNull(): void
    {
        putenv(AiAgentConfig::API_KEY_ENV_VAR);

        $client = new AiAgentClient(new AiAgentConfig());

        self::assertNull($client->apiKey());
    }

    public function testReadFileAndApplyPatchToolsWork(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory));
        $path = $this->tempDirectory . '/example.txt';
        file_put_contents($path, "hello\n");

        $patch = $client->runTool('apply_patch', [
            'patch' => "--- example.txt\n+++ example.txt\n@@ -1 +1 @@\n-hello\n+updated\n",
        ]);

        self::assertTrue($patch->isSuccess());
        self::assertSame(1, $patch->payload()['file_count']);
        self::assertSame(1, $patch->payload()['hunk_count']);

        $read = $client->runTool('read_file', [
            'path' => $path,
        ]);

        self::assertTrue($read->isSuccess());
        self::assertSame('updated', $read->payload()['contents']);
        self::assertSame(1, $read->payload()['start_line']);
        self::assertSame(1, $read->payload()['end_line']);
        self::assertSame(1, $read->payload()['total_lines']);
        self::assertFalse($read->payload()['truncated']);
    }

    public function testApplyPatchResolvesRelativePathsAgainstConfiguredWorkingDirectory(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory));
        mkdir($this->tempDirectory . '/nested', 0777, true);
        file_put_contents($this->tempDirectory . '/nested/example.txt', "hello\n");

        $result = $client->runTool('apply_patch', [
            'patch' => "--- nested/example.txt\n+++ nested/example.txt\n@@ -1 +1 @@\n-hello\n+patched\n",
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(['nested/example.txt'], $result->payload()['files']);

        $read = $client->runTool('read_file', [
            'path' => 'nested/example.txt',
        ]);

        self::assertTrue($read->isSuccess());
        self::assertSame('patched', $read->payload()['contents']);
        self::assertSame($this->tempDirectory . '/nested/example.txt', $read->payload()['path']);
    }

    public function testReadFileSupportsLineRanges(): void
    {
        $client = new AiAgentClient();
        $path = $this->tempDirectory . '/lines.txt';
        file_put_contents($path, "one\ntwo\nthree\nfour\n");

        $result = $client->runTool('read_file', [
            'path' => $path,
            'start_line' => 2,
            'end_line' => 3,
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame("two\nthree", $result->payload()['contents']);
        self::assertSame(2, $result->payload()['start_line']);
        self::assertSame(3, $result->payload()['end_line']);
        self::assertSame(4, $result->payload()['total_lines']);
        self::assertFalse($result->payload()['truncated']);
    }

    public function testReadFileSupportsTailLines(): void
    {
        $client = new AiAgentClient();
        $path = $this->tempDirectory . '/tail.txt';
        file_put_contents($path, "one\ntwo\nthree\nfour");

        $result = $client->runTool('read_file', [
            'path' => $path,
            'tail_lines' => 2,
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame("three\nfour", $result->payload()['contents']);
        self::assertSame(3, $result->payload()['start_line']);
        self::assertSame(4, $result->payload()['end_line']);
        self::assertSame(4, $result->payload()['total_lines']);
        self::assertFalse($result->payload()['truncated']);
    }

    public function testReadFileSupportsMaxLinesAndMarksTruncation(): void
    {
        $client = new AiAgentClient();
        $path = $this->tempDirectory . '/truncate.txt';
        file_put_contents($path, "one\ntwo\nthree\nfour");

        $result = $client->runTool('read_file', [
            'path' => $path,
            'max_lines' => 2,
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame("one\ntwo", $result->payload()['contents']);
        self::assertSame(1, $result->payload()['start_line']);
        self::assertSame(2, $result->payload()['end_line']);
        self::assertSame(4, $result->payload()['total_lines']);
        self::assertTrue($result->payload()['truncated']);
    }

    public function testReadFileReturnsFailureForMissingFile(): void
    {
        $client = new AiAgentClient();

        $result = $client->runTool('read_file', [
            'path' => $this->tempDirectory . '/missing.txt',
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('File not found.', $result->payload()['error']);
    }

    public function testReadFileIgnoresTailLinesWhenLineRangeIsProvided(): void
    {
        $client = new AiAgentClient();
        $path = $this->tempDirectory . '/invalid-lines.txt';
        file_put_contents($path, "one\ntwo");

        $result = $client->runTool('read_file', [
            'path' => $path,
            'start_line' => 1,
            'tail_lines' => 1,
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame("one\ntwo", $result->payload()['contents']);
        self::assertSame(1, $result->payload()['start_line']);
        self::assertSame(2, $result->payload()['end_line']);
    }

    public function testReadFileTreatsZeroTailLinesAsNotProvided(): void
    {
        $client = new AiAgentClient();
        $path = $this->tempDirectory . '/zero-tail.txt';
        file_put_contents($path, "one\ntwo");

        $result = $client->runTool('read_file', [
            'path' => $path,
            'tail_lines' => 0,
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame("one\ntwo", $result->payload()['contents']);
        self::assertSame(1, $result->payload()['start_line']);
        self::assertSame(2, $result->payload()['end_line']);
    }

    public function testReadFileReturnsFailureWhenStartLineIsOutsideFile(): void
    {
        $client = new AiAgentClient();
        $path = $this->tempDirectory . '/outside.txt';
        file_put_contents($path, "one\ntwo");

        $result = $client->runTool('read_file', [
            'path' => $path,
            'start_line' => 3,
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('Start line is outside the file.', $result->payload()['error']);
    }

    public function testApplyPatchSupportsMultipleFiles(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory));
        file_put_contents($this->tempDirectory . '/first.txt', "one\n");
        file_put_contents($this->tempDirectory . '/second.txt', "two\n");

        $result = $client->runTool('apply_patch', [
            'patch' => "--- first.txt\n+++ first.txt\n@@ -1 +1 @@\n-one\n+eins\n--- second.txt\n+++ second.txt\n@@ -1 +1 @@\n-two\n+zwei\n",
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(2, $result->payload()['file_count']);
        self::assertSame(2, $result->payload()['hunk_count']);
        self::assertSame("eins\n", file_get_contents($this->tempDirectory . '/first.txt'));
        self::assertSame("zwei\n", file_get_contents($this->tempDirectory . '/second.txt'));
    }

    public function testApplyPatchRollsBackWhenPatchIsInvalid(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory));
        file_put_contents($this->tempDirectory . '/broken.txt', "alpha\n");

        $result = $client->runTool('apply_patch', [
            'patch' => "--- broken.txt\n+++ broken.txt\n@@ -1 +1 @@\n-beta\n+gamma\n",
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('Patch validation failed.', $result->payload()['error']);
        self::assertSame("alpha\n", file_get_contents($this->tempDirectory . '/broken.txt'));
    }

    public function testSearchListsFilesInDirectory(): void
    {
        $client = new AiAgentClient();
        $directory = $this->tempDirectory . '/files';
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/a.php', '<?php');
        file_put_contents($directory . '/b.txt', 'text');

        $result = $client->runTool('search', [
            'path' => $directory,
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame($directory, $result->payload()['path']);
        self::assertNull($result->payload()['name_pattern']);
        self::assertNull($result->payload()['query']);
        self::assertSame(2, $result->payload()['count']);
        self::assertSame([
            ['type' => 'file', 'path' => $directory . '/a.php'],
            ['type' => 'file', 'path' => $directory . '/b.txt'],
        ], $result->payload()['results']);
    }

    public function testSearchLimitsFileSearchByDepth(): void
    {
        $client = new AiAgentClient();
        $directory = $this->tempDirectory . '/depth-files';
        mkdir($directory . '/nested/deeper', 0777, true);
        file_put_contents($directory . '/root.txt', 'root');
        file_put_contents($directory . '/nested/child.txt', 'child');
        file_put_contents($directory . '/nested/deeper/grandchild.txt', 'grandchild');

        $depthZero = $client->runTool('search', [
            'path' => $directory,
            'depth' => 0,
        ]);

        self::assertTrue($depthZero->isSuccess());
        self::assertSame(0, $depthZero->payload()['depth']);
        self::assertSame(1, $depthZero->payload()['count']);
        self::assertSame([
            ['type' => 'file', 'path' => $directory . '/root.txt'],
        ], $depthZero->payload()['results']);

        $depthOne = $client->runTool('search', [
            'path' => $directory,
            'depth' => 1,
        ]);

        self::assertTrue($depthOne->isSuccess());
        self::assertSame(1, $depthOne->payload()['depth']);
        self::assertSame(2, $depthOne->payload()['count']);
        self::assertSame([
            ['type' => 'file', 'path' => $directory . '/nested/child.txt'],
            ['type' => 'file', 'path' => $directory . '/root.txt'],
        ], $depthOne->payload()['results']);
    }

    public function testSearchFindsContentMatchesWithFiltersAndLineNumbers(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory));
        mkdir($this->tempDirectory . '/nested', 0777, true);
        file_put_contents($this->tempDirectory . '/nested/a.php', "<?php\nneedle here\n");
        file_put_contents($this->tempDirectory . '/nested/b.txt', "needle elsewhere\n");

        $result = $client->runTool('search', [
            'path' => 'nested',
            'name_pattern' => '*.php',
            'query' => 'needle',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame($this->tempDirectory . '/nested', $result->payload()['path']);
        self::assertSame('*.php', $result->payload()['name_pattern']);
        self::assertSame('needle', $result->payload()['query']);
        self::assertSame(1, $result->payload()['count']);
        self::assertSame('content', $result->payload()['results'][0]['type']);
        self::assertSame($this->tempDirectory . '/nested/a.php', $result->payload()['results'][0]['path']);
        self::assertSame(2, $result->payload()['results'][0]['line']);
        self::assertStringContainsString('needle here', $result->payload()['results'][0]['snippet']);
    }

    public function testSearchLimitsContentMatchesByDepth(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory));
        mkdir($this->tempDirectory . '/depth-content/child/grandchild', 0777, true);
        file_put_contents($this->tempDirectory . '/depth-content/root.txt', "needle root\n");
        file_put_contents($this->tempDirectory . '/depth-content/child/match.txt', "needle child\n");
        file_put_contents($this->tempDirectory . '/depth-content/child/grandchild/match.txt', "needle grandchild\n");

        $result = $client->runTool('search', [
            'path' => 'depth-content',
            'query' => 'needle',
            'depth' => 1,
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $result->payload()['depth']);
        self::assertSame(2, $result->payload()['count']);
        self::assertSame([
            $this->tempDirectory . '/depth-content/child/match.txt',
            $this->tempDirectory . '/depth-content/root.txt',
        ], array_column($result->payload()['results'], 'path'));
    }

    public function testSearchCanIncludeFullContentsForFileMatches(): void
    {
        $client = new AiAgentClient();
        $directory = $this->tempDirectory . '/contents-full';
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/example.txt', "one\ntwo\n");

        $result = $client->runTool('search', [
            'path' => $directory,
            'contents' => 'full',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame('full', $result->payload()['contents']);
        self::assertSame("one\ntwo\n", $result->payload()['results'][0]['contents']);
    }

    public function testSearchCanIncludeHeadContentsForFileMatches(): void
    {
        $client = new AiAgentClient();
        $directory = $this->tempDirectory . '/contents-head';
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/example.txt', "one\ntwo\nthree\n");

        $result = $client->runTool('search', [
            'path' => $directory,
            'contents' => 'head:2',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame('head:2', $result->payload()['contents']);
        self::assertSame("one\ntwo", $result->payload()['results'][0]['contents']);
    }

    public function testSearchCanIncludeTailContentsForContentMatches(): void
    {
        $client = new AiAgentClient();
        $directory = $this->tempDirectory . '/contents-tail';
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/example.txt', "one\ntwo\nneedle\nfour\n");

        $result = $client->runTool('search', [
            'path' => $directory,
            'query' => 'needle',
            'contents' => 'tail:2',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame('tail:2', $result->payload()['contents']);
        self::assertSame(1, $result->payload()['count']);
        self::assertSame(3, $result->payload()['results'][0]['line']);
        self::assertSame("needle\nfour", $result->payload()['results'][0]['contents']);
    }

    public function testSearchTreatsEmptyQueryAsFileSearch(): void
    {
        $client = new AiAgentClient();
        $directory = $this->tempDirectory . '/empty-query-search';
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/composer.json', "{\"name\":\"demo\"}\n");

        $result = $client->runTool('search', [
            'path' => $directory,
            'name_pattern' => 'composer.json',
            'query' => '',
            'contents' => 'full',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertNull($result->payload()['query']);
        self::assertSame(1, $result->payload()['count']);
        self::assertSame('file', $result->payload()['results'][0]['type']);
        self::assertSame("{\"name\":\"demo\"}\n", $result->payload()['results'][0]['contents']);
    }

    public function testSearchRespectsGitIgnoreRules(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory));
        file_put_contents($this->tempDirectory . '/.gitignore', "ignored.txt\nignored-dir/\n");
        file_put_contents($this->tempDirectory . '/visible.txt', 'needle');
        file_put_contents($this->tempDirectory . '/ignored.txt', 'needle');
        mkdir($this->tempDirectory . '/ignored-dir', 0777, true);
        file_put_contents($this->tempDirectory . '/ignored-dir/hidden.txt', 'needle');

        $result = $client->runTool('search', [
            'path' => '.',
            'query' => 'needle',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $result->payload()['count']);
        self::assertSame($this->tempDirectory . '/visible.txt', $result->payload()['results'][0]['path']);
    }

    public function testSearchRespectsGitIgnoreRulesWhenDepthAndContentsAreUsed(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory));
        file_put_contents($this->tempDirectory . '/.gitignore', "ignored-dir/\n");
        mkdir($this->tempDirectory . '/visible', 0777, true);
        mkdir($this->tempDirectory . '/ignored-dir', 0777, true);
        file_put_contents($this->tempDirectory . '/visible/file.txt', "needle\nvisible\n");
        file_put_contents($this->tempDirectory . '/ignored-dir/file.txt', "needle\nignored\n");

        $result = $client->runTool('search', [
            'path' => '.',
            'query' => 'needle',
            'depth' => 1,
            'contents' => 'head:2',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $result->payload()['count']);
        self::assertSame($this->tempDirectory . '/visible/file.txt', $result->payload()['results'][0]['path']);
        self::assertSame("needle\nvisible", $result->payload()['results'][0]['contents']);
    }

    public function testSearchIgnoresDirectoryPatternsWithoutTrailingSlash(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory));
        file_put_contents($this->tempDirectory . '/.gitignore', "/vendor\n");
        mkdir($this->tempDirectory . '/src', 0777, true);
        mkdir($this->tempDirectory . '/vendor/bin', 0777, true);
        file_put_contents($this->tempDirectory . '/src/visible.txt', 'needle');
        file_put_contents($this->tempDirectory . '/vendor/bin/hidden.txt', 'needle');

        $result = $client->runTool('search', [
            'path' => '.',
            'query' => 'needle',
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $result->payload()['count']);
        self::assertSame($this->tempDirectory . '/src/visible.txt', $result->payload()['results'][0]['path']);
    }

    public function testSearchReturnsFailureForMissingDirectory(): void
    {
        $client = new AiAgentClient();

        $result = $client->runTool('search', [
            'path' => $this->tempDirectory . '/missing',
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('Directory not found.', $result->payload()['error']);
        self::assertSame([], $result->payload()['results']);
    }

    public function testSearchRejectsInvalidDepth(): void
    {
        $client = new AiAgentClient();

        $this->expectException(InvalidToolInput::class);
        $this->expectExceptionMessage('The "depth" input must be a non-negative integer when provided.');

        $client->runTool('search', [
            'path' => $this->tempDirectory,
            'depth' => -1,
        ]);
    }

    public function testSearchRejectsInvalidContentsMode(): void
    {
        $client = new AiAgentClient();

        $this->expectException(InvalidToolInput::class);
        $this->expectExceptionMessage('The "contents" input must be one of "full", "head:N", or "tail:N" when provided.');

        $client->runTool('search', [
            'path' => $this->tempDirectory,
            'contents' => 'head:0',
        ]);
    }

    public function testViewImageReturnsCompactImageMetadata(): void
    {
        $client = new AiAgentClient(registerBuiltins: false);
        $client->registerTool(new ViewImageTool());
        $path = $this->tempDirectory . '/pixel.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $result = $client->runTool('view_image', ['path' => $path]);

        self::assertTrue($result->isSuccess());
        self::assertSame($path, $result->payload()['path']);
        self::assertSame('image/png', $result->payload()['mime_type']);
        self::assertSame(1, $result->payload()['width']);
        self::assertSame(1, $result->payload()['height']);
        self::assertFalse($result->payload()['was_resized']);
        self::assertSame(1, $result->payload()['final_width']);
        self::assertSame(1, $result->payload()['final_height']);
        self::assertArrayNotHasKey('base64', $result->payload());
        self::assertArrayNotHasKey('data_url', $result->payload());
    }

    public function testViewImageResolvesRelativePathsAgainstConfiguredWorkingDirectory(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory), registerBuiltins: false);
        $client->registerTool(new ViewImageTool($this->tempDirectory));
        $path = $this->tempDirectory . '/images/pixel.png';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $result = $client->runTool('view_image', ['path' => 'images/pixel.png']);

        self::assertTrue($result->isSuccess());
        self::assertSame($path, $result->payload()['path']);
    }

    public function testViewImageResolvesRelativePathsAgainstCurrentWorkingDirectoryWhenNoConfigExists(): void
    {
        $client = new AiAgentClient(registerBuiltins: false);
        $client->registerTool(new ViewImageTool());
        $workingDirectory = $this->tempDirectory . '/cwd';
        mkdir($workingDirectory, 0777, true);
        $path = $workingDirectory . '/image.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK9sAAAAASUVORK5CYII=', true));

        $previousWorkingDirectory = getcwd();
        chdir($workingDirectory);

        try {
            $result = $client->runTool('view_image', ['path' => 'image.png']);
        } finally {
            if (is_string($previousWorkingDirectory) && $previousWorkingDirectory !== '') {
                chdir($previousWorkingDirectory);
            }
        }

        self::assertTrue($result->isSuccess());
        self::assertSame($path, $result->payload()['path']);
    }

    public function testViewImageFailsForMissingFile(): void
    {
        $client = new AiAgentClient(registerBuiltins: false);
        $client->registerTool(new ViewImageTool());

        $this->expectException(InvalidToolInput::class);
        $this->expectExceptionMessage('was not found');

        $client->runTool('view_image', ['path' => $this->tempDirectory . '/missing.png']);
    }

    public function testViewImageResizesLargeImages(): void
    {
        if (!class_exists(\Imagick::class) && !function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('No supported image extension available.');
        }

        $client = new AiAgentClient(registerBuiltins: false);
        $client->registerTool(new ViewImageTool());
        $path = $this->tempDirectory . '/large.png';
        $this->createLargePng($path, 4096, 1024);

        $result = $client->runTool('view_image', ['path' => $path]);

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->payload()['was_resized']);
        self::assertSame(2048, $result->payload()['final_width']);
        self::assertSame(512, $result->payload()['final_height']);
    }

    public function testShellReturnsOutput(): void
    {
        $client = new AiAgentClient();

        $result = $client->runTool('shell', [
            'command' => ['sh', '-c', 'printf "ok"'],
            'cwd' => $this->tempDirectory,
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(0, $result->payload()['exit_code']);
        self::assertSame('ok', $result->payload()['stdout']);
        self::assertSame('', $result->payload()['stderr']);
        self::assertFalse($result->payload()['timed_out']);
        self::assertNull($result->payload()['timeout']);
    }

    public function testShellUsesConfiguredWorkingDirectoryByDefault(): void
    {
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory));

        $result = $client->runTool('shell', [
            'command' => ['pwd'],
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame($this->tempDirectory, trim((string) $result->payload()['stdout']));
        self::assertSame($this->tempDirectory, $result->payload()['cwd']);
    }

    public function testShellSupportsConfigurableTimeout(): void
    {
        $client = new AiAgentClient();

        $result = $client->runTool('shell', [
            'command' => ['sh', '-c', 'sleep 1'],
            'cwd' => $this->tempDirectory,
            'timeout' => 0.1,
        ]);

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->payload()['timed_out']);
        self::assertSame(0.1, $result->payload()['timeout']);
        self::assertSame('Process timed out.', $result->payload()['error']);
    }

    public function testShellRejectsInvalidTimeout(): void
    {
        $client = new AiAgentClient();

        $this->expectException(InvalidToolInput::class);

        $client->runTool('shell', [
            'command' => ['pwd'],
            'timeout' => 0,
        ]);
    }

    public function testCustomToolCanBeRegistered(): void
    {
        $client = new AiAgentClient(registerBuiltins: false);
        $client->registerTool(new class implements ToolInterface {
            public function name(): string
            {
                return 'custom';
            }

            public function execute(array $input): ToolResult
            {
                return ToolResult::success([
                    'value' => strtoupper((string) ($input['value'] ?? '')),
                ]);
            }
        });

        $result = $client->runTool('custom', ['value' => 'test']);

        self::assertTrue($result->isSuccess());
        self::assertSame('TEST', $result->payload()['value']);
    }

    public function testBuiltinToolCanBeUnregistered(): void
    {
        $client = new AiAgentClient();
        $client->unregisterTool('read_file');

        self::assertFalse($client->hasTool('read_file'));
    }

    public function testBuiltinToolCanBeReplaced(): void
    {
        $client = new AiAgentClient();
        $client
            ->unregisterTool('read_file')
            ->registerTool(new class implements ToolInterface {
                public function name(): string
                {
                    return 'read_file';
                }

                public function execute(array $input): ToolResult
                {
                    return ToolResult::success([
                        'path' => $input['path'] ?? null,
                        'contents' => 'custom',
                    ]);
                }
            });

        $result = $client->runTool('read_file', ['path' => 'example.txt']);

        self::assertTrue($result->isSuccess());
        self::assertSame('custom', $result->payload()['contents']);
    }

    public function testProvidedRegistryGetsBuiltinDefaultsThroughRegistryApi(): void
    {
        $registry = new ToolRegistry();
        $client = new AiAgentClient(new AiAgentConfig(workingDirectory: $this->tempDirectory), $registry);

        self::assertTrue($client->hasTool('apply_patch'));
        self::assertTrue($client->hasTool('read_file'));
        self::assertTrue($client->hasTool('search'));
        self::assertTrue($client->hasTool('shell'));
    }

    public function testSearchToolSchemaPublishesDepthAndContentsParameters(): void
    {
        $client = new AiAgentClient();
        $tool = $client->tools()['search'];

        self::assertInstanceOf(SchemaAwareToolInterface::class, $tool);

        $parameters = $tool->parameters();

        self::assertSame('integer', $parameters['properties']['depth']['type']);
        self::assertSame(0, $parameters['properties']['depth']['minimum']);
        self::assertSame('string', $parameters['properties']['contents']['type']);
    }

    public function testRequestReturnsStructuredResponse(): void
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse(
                    content: 'hello world',
                    model: 'openai:gpt-5',
                    toolCalls: [
                        ['name' => 'read_file', 'arguments' => ['path' => '/tmp/example.txt']],
                    ],
                    metadata: ['provider' => 'openai'],
                );
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        $client = new AiAgentClient(runtime: $runtime);
        $response = $client->request('Say hello');

        self::assertSame('hello world', $response->content());
        self::assertSame('openai:gpt-5', $response->model());
        self::assertSame('read_file', $response->toolCalls()[0]['name']);
        self::assertSame('openai', $response->metadata()['provider']);
    }

    public function testRequestTextReturnsOnlyContent(): void
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse('plain answer', 'openai:gpt-5');
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        $client = new AiAgentClient(runtime: $runtime);

        self::assertSame('plain answer', $client->requestText('Prompt'));
    }

    public function testRequestForwardsOptionalResponseClassToRuntime(): void
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public ?string $capturedResponseClass = null;

            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                $this->capturedResponseClass = $responseClass;

                return new AiAgentResponse('{"ok":true}', 'openai:gpt-5');
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        $client = new AiAgentClient(runtime: $runtime);
        $client->request('Prompt', ClientStructuredResponse::class);

        self::assertSame(ClientStructuredResponse::class, $runtime->capturedResponseClass);
    }

    public function testRequestTextReturnsJsonStringWhenResponseClassIsProvided(): void
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse('{"message":"hello"}', 'openai:gpt-5');
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        $client = new AiAgentClient(runtime: $runtime);

        self::assertSame('{"message":"hello"}', $client->requestText('Prompt', ClientStructuredResponse::class));
    }

    public function testRequestStructuredReturnsDtoFromRuntime(): void
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                throw new \BadMethodCallException('not used');
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                return new ClientStructuredResponse('hello');
            }
        };

        $client = new AiAgentClient(runtime: $runtime);
        $response = $client->requestStructured('Prompt', ClientStructuredResponse::class);

        self::assertInstanceOf(ClientStructuredResponse::class, $response);
        self::assertSame('hello', $response->message);
    }

    public function testRequestStructuredRejectsInvalidResponseClass(): void
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                throw new \BadMethodCallException('not used');
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                if (!class_exists($responseClass)) {
                    throw new \InvalidArgumentException(sprintf('Structured response class "%s" does not exist.', $responseClass));
                }

                return new $responseClass();
            }
        };

        $client = new AiAgentClient(runtime: $runtime);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $client->requestStructured('Prompt', 'Missing\\Dto');
    }

    public function testGetRequestTokensReturnsZeroUsageBeforeAnyRequest(): void
    {
        $client = new AiAgentClient(runtime: new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse($prompt, 'openai:gpt-5');
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        });

        self::assertSame((new AiAgentTokenUsage())->toArray(), $client->getRequestTokens()->toArray());
    }

    public function testGetRequestTokensReturnsUsageFromLastRequest(): void
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse(
                    content: 'hello world',
                    model: 'openai:gpt-5',
                    toolCalls: [
                        ['name' => 'read_file', 'arguments' => ['path' => 'composer.json']],
                        ['name' => 'read_file', 'arguments' => ['path' => 'README.md']],
                    ],
                    metadata: [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 10,
                                'input_tokens_details' => ['cached_tokens' => 3],
                                'output_tokens' => 4,
                                'output_tokens_details' => ['reasoning_tokens' => 2],
                                'total_tokens' => 14,
                            ],
                        ],
                    ],
                );
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        $client = new AiAgentClient(runtime: $runtime);
        $client->request('Say hello');

        self::assertSame([
            'input' => 10,
            'cached_input' => 3,
            'output' => 4,
            'reasoning' => 2,
            'total' => 14,
            'image_generation_input' => 0,
            'image_generation_output' => 0,
            'image_generation_total' => 0,
            'tool_calls' => 2,
            'tool_call_details' => ['read_file' => 2],
        ], $client->getRequestTokens()->toArray());
    }

    public function testGetRequestTokensAggregatesToolLoopAndGeneratedImageUsage(): void
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                return new AiAgentResponse(
                    content: 'final',
                    model: 'openai:gpt-5',
                    toolCalls: [
                        ['name' => 'shell', 'arguments' => ['command' => ['pwd']]],
                    ],
                    metadata: [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 20,
                                'output_tokens' => 8,
                                'total_tokens' => 28,
                            ],
                        ],
                        'request_assistant_messages' => [
                            [
                                'tool_calls' => [
                                    ['name' => 'search', 'arguments' => ['path' => '.']],
                                ],
                                'metadata' => [
                                    'final_response' => [
                                        'usage' => [
                                            'input_tokens' => 10,
                                            'output_tokens' => 2,
                                            'total_tokens' => 12,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'generated_images' => [
                            [
                                'provider_response' => [
                                    'tool_usage' => [
                                        'image_gen' => [
                                            'input_tokens' => 100,
                                            'output_tokens' => 50,
                                            'total_tokens' => 150,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                );
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        $client = new AiAgentClient(runtime: $runtime);
        $client->request('Generate something');

        self::assertSame([
            'input' => 30,
            'cached_input' => 0,
            'output' => 10,
            'reasoning' => 0,
            'total' => 40,
            'image_generation_input' => 100,
            'image_generation_output' => 50,
            'image_generation_total' => 150,
            'tool_calls' => 2,
            'tool_call_details' => ['search' => 1, 'shell' => 1],
        ], $client->getRequestTokens()->toArray());
    }

    public function testGetRequestTokensTracksOnlyMostRecentRequest(): void
    {
        $runtime = new class implements AiAgentRuntimeInterface {
            private int $calls = 0;

            public function request(string $prompt, ?string $responseClass = null): AiAgentResponse
            {
                ++$this->calls;

                return new AiAgentResponse(
                    content: $prompt,
                    model: 'openai:gpt-5',
                    toolCalls: [
                        ['name' => 'search', 'arguments' => ['path' => '.']],
                    ],
                    metadata: [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => $this->calls,
                                'output_tokens' => $this->calls * 2,
                                'total_tokens' => $this->calls * 3,
                            ],
                        ],
                    ],
                );
            }

            public function requestStructured(string $prompt, string $responseClass): object
            {
                throw new \BadMethodCallException('not used');
            }
        };

        $client = new AiAgentClient(runtime: $runtime);
        $client->request('first');
        $client->request('second');

        self::assertSame([
            'input' => 2,
            'cached_input' => 0,
            'output' => 4,
            'reasoning' => 0,
            'total' => 6,
            'image_generation_input' => 0,
            'image_generation_output' => 0,
            'image_generation_total' => 0,
            'tool_calls' => 1,
            'tool_call_details' => ['search' => 1],
        ], $client->getRequestTokens()->toArray());
    }

    public function testGetSessionTokensReturnsZeroWhenSessionFileIsNotConfiguredOrMissing(): void
    {
        $withoutSession = new AiAgentClient();
        $missingSession = new AiAgentClient(new AiAgentConfig(sessionFile: $this->tempDirectory . '/missing-session.json'));

        self::assertSame((new AiAgentTokenUsage())->toArray(), $withoutSession->getSessionTokens()->toArray());
        self::assertSame((new AiAgentTokenUsage())->toArray(), $missingSession->getSessionTokens()->toArray());
    }

    public function testGetSessionTokensAggregatesAssistantMessagesFromSessionFile(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                ['role' => 'user', 'content' => 'Prompt'],
                [
                    'role' => 'assistant',
                    'content' => 'step 1',
                    'tool_calls' => [
                        ['id' => 'call_1', 'name' => 'find_files', 'arguments' => ['path' => '/tmp']],
                    ],
                    'metadata' => [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 5,
                                'output_tokens' => 1,
                                'total_tokens' => 6,
                            ],
                        ],
                    ],
                ],
                ['role' => 'tool', 'content' => '{}', 'tool_call_id' => 'call_1'],
                [
                    'role' => 'assistant',
                    'content' => 'step 2',
                    'metadata' => [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 7,
                                'input_tokens_details' => ['cached_tokens' => 2],
                                'output_tokens' => 3,
                                'output_tokens_details' => ['reasoning_tokens' => 1],
                                'total_tokens' => 10,
                            ],
                        ],
                        'generated_images' => [
                            [
                                'provider_response' => [
                                    'tool_usage' => [
                                        'image_gen' => [
                                            'input_tokens' => 20,
                                            'output_tokens' => 40,
                                            'total_tokens' => 60,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = new AiAgentClient(new AiAgentConfig(sessionFile: $sessionFile));

        self::assertSame([
            'input' => 12,
            'cached_input' => 2,
            'output' => 4,
            'reasoning' => 1,
            'total' => 16,
            'image_generation_input' => 20,
            'image_generation_output' => 40,
            'image_generation_total' => 60,
            'tool_calls' => 1,
            'tool_call_details' => ['find_files' => 1],
        ], $client->getSessionTokens()->toArray());
    }

    public function testGetSessionTokensAggregatesNestedAssistantUsageFromSlimSessionFormat(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => 'final',
                    'metadata' => [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 20,
                                'output_tokens' => 8,
                                'total_tokens' => 28,
                            ],
                        ],
                        'request_assistant_messages' => [
                            [
                                'tool_calls' => [
                                    ['name' => 'shell', 'arguments' => ['command' => ['pwd']]],
                                ],
                                'metadata' => [
                                    'final_response' => [
                                        'usage' => [
                                            'input_tokens' => 10,
                                            'output_tokens' => 2,
                                            'total_tokens' => 12,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'generated_images' => [
                            [
                                'provider_response' => [
                                    'tool_usage' => [
                                        'image_gen' => [
                                            'input_tokens' => 100,
                                            'output_tokens' => 50,
                                            'total_tokens' => 150,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = new AiAgentClient(new AiAgentConfig(sessionFile: $sessionFile));

        self::assertSame([
            'input' => 30,
            'cached_input' => 0,
            'output' => 10,
            'reasoning' => 0,
            'total' => 40,
            'image_generation_input' => 100,
            'image_generation_output' => 50,
            'image_generation_total' => 150,
            'tool_calls' => 1,
            'tool_call_details' => ['shell' => 1],
        ], $client->getSessionTokens()->toArray());
    }

    public function testGetSessionTokensDoesNotDoubleCountNestedAssistantUsageWhenLoopMessagesAreStored(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                ['role' => 'user', 'content' => 'Prompt'],
                [
                    'role' => 'assistant',
                    'content' => 'step 1',
                    'tool_calls' => [
                        ['id' => 'call_1', 'name' => 'custom_read', 'arguments' => ['path' => 'composer.json']],
                    ],
                    'metadata' => [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 5,
                                'output_tokens' => 1,
                                'total_tokens' => 6,
                            ],
                        ],
                    ],
                ],
                ['role' => 'tool', 'content' => '{}', 'tool_call_id' => 'call_1'],
                [
                    'role' => 'assistant',
                    'content' => 'final',
                    'metadata' => [
                        'final_response' => [
                            'usage' => [
                                'input_tokens' => 7,
                                'output_tokens' => 3,
                                'total_tokens' => 10,
                            ],
                        ],
                        'request_assistant_messages' => [
                            [
                                'metadata' => [
                                    'final_response' => [
                                        'usage' => [
                                            'input_tokens' => 5,
                                            'output_tokens' => 1,
                                            'total_tokens' => 6,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = new AiAgentClient(new AiAgentConfig(sessionFile: $sessionFile));

        self::assertSame([
            'input' => 12,
            'cached_input' => 0,
            'output' => 4,
            'reasoning' => 0,
            'total' => 16,
            'image_generation_input' => 0,
            'image_generation_output' => 0,
            'image_generation_total' => 0,
            'tool_calls' => 1,
            'tool_call_details' => ['custom_read' => 1],
        ], $client->getSessionTokens()->toArray());
    }

    public function testGetSessionTokensReturnsZeroForLegacySessionWithoutUsageMetadata(): void
    {
        $sessionFile = $this->tempDirectory . '/session.json';
        file_put_contents($sessionFile, json_encode([
            'version' => 1,
            'messages' => [
                ['role' => 'assistant', 'content' => 'legacy', 'metadata' => ['provider' => 'openai']],
            ],
        ], JSON_THROW_ON_ERROR));

        $client = new AiAgentClient(new AiAgentConfig(sessionFile: $sessionFile));

        self::assertSame((new AiAgentTokenUsage())->toArray(), $client->getSessionTokens()->toArray());
    }

    public function testGetSessionTokensThrowsForInvalidSessionFile(): void
    {
        $sessionFile = $this->tempDirectory . '/broken.json';
        file_put_contents($sessionFile, '{invalid');

        $client = new AiAgentClient(new AiAgentConfig(sessionFile: $sessionFile));

        $this->expectException(InvalidSession::class);

        $client->getSessionTokens();
    }

    public function testRequestUsesMutableConfigOverrides(): void
    {
        $config = new AiAgentConfig();
        $config
            ->setModel('openai:gpt-5.1')
            ->setApiKey('secret')
            ->setSessionFile($this->tempDirectory . '/session.json');

        self::assertSame('openai:gpt-5.1', $config->model());
        self::assertSame('secret', $config->apiKey());
        self::assertSame($this->tempDirectory . '/session.json', $config->sessionFile());
    }

    public function testOpenAiTokenModeExecutesToolCallsThroughAgentLoop(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => $options['body'] ?? '',
            ];

            if (\count($requests) === 1) {
                return new MockResponse(<<<TEXT
event: response.created
data: {"type":"response.created"}

event: response.output_item.done
data: {"type":"response.output_item.done","item":{"id":"fc_123","type":"function_call","status":"completed","arguments":"{\"path\":\"composer.json\"}","call_id":"call_123","name":"custom_read"}}

event: response.completed
data: {"type":"response.completed","response":{"id":"resp_1","output":[],"usage":{"input_tokens":10,"output_tokens":2,"total_tokens":12}}}

TEXT, ['http_code' => 200]);
            }

            return new MockResponse(<<<TEXT
event: response.created
data: {"type":"response.created"}

event: response.output_text.delta
data: {"type":"response.output_text.delta","delta":"composer.json enthaelt den Namen armin/ai-agent."}

event: response.completed
data: {"type":"response.completed","response":{"id":"resp_2","output":[],"usage":{"input_tokens":20,"output_tokens":8,"total_tokens":28}}}

TEXT, ['http_code' => 200]);
        });

        $client = new AiAgentClient(
            new AiAgentConfig(
                model: 'openai:gpt-5.4-mini',
                auth: new AgentAuth(
                    authMode: AgentAuth::MODE_TOKENS,
                    tokens: new AgentAuthTokens('id', 'access', 'refresh', 'account'),
                ),
            ),
            registerBuiltins: false,
            httpClient: $httpClient,
        );
        $client->registerTool(new class implements SchemaAwareToolInterface {
            public function name(): string
            {
                return 'custom_read';
            }

            public function parameters(): array
            {
                return [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'path' => ['type' => 'string'],
                    ],
                    'required' => ['path'],
                ];
            }

            public function execute(array $input): ToolResult
            {
                return ToolResult::success([
                    'path' => $input['path'] ?? null,
                    'contents' => '{"name":"armin/ai-agent"}',
                ]);
            }
        });

        $response = $client->request('Was steht in der Datei composer.json?');

        self::assertCount(2, $requests);
        self::assertStringContainsString('"type":"function_call_output"', $requests[1]['body']);
        self::assertStringContainsString('"call_id":"call_123"', $requests[1]['body']);
        self::assertSame('composer.json enthaelt den Namen armin/ai-agent.', $response->content());
    }

    public function testUnknownToolThrows(): void
    {
        $client = new AiAgentClient(registerBuiltins: false);

        $this->expectException(ToolNotFound::class);

        $client->runTool('missing');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }

    private function createLargePng(string $path, int $width, int $height): void
    {
        if (class_exists(\Imagick::class)) {
            $image = new \Imagick();
            $image->newImage($width, $height, new \ImagickPixel('red'));
            $image->setImageFormat('png');
            file_put_contents($path, $image->getImagesBlob());

            return;
        }

        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        $red = imagecolorallocate($image, 255, 0, 0);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $red);
        imagepng($image, $path);
        imagedestroy($image);
    }
}

final readonly class ClientStructuredResponse
{
    public function __construct(
        public string $message = '',
    ) {
    }
}
