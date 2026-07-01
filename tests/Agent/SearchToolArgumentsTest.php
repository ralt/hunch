<?php

namespace App\Tests\Agent;

use App\Agent\Tool\EmailSearchTool;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Regression tests for the tool boundary. The model used to send semantic_ratio
 * as a JSON integer, which the float-typed parameter's denormalizer rejected and
 * crashed the whole search; the knob was removed. These lock that in.
 */
final class SearchToolArgumentsTest extends TestCase
{
    private function searchTool(): Tool
    {
        foreach ((new ReflectionToolFactory())->getTool(EmailSearchTool::class) as $tool) {
            if ('search_emails' === $tool->getName()) {
                return $tool;
            }
        }
        self::fail('search_emails tool not found');
    }

    public function testSemanticRatioIsNotExposed(): void
    {
        $params = $this->searchTool()->getParameters();
        $this->assertArrayNotHasKey('semantic_ratio', $params['properties'] ?? []);
        $this->assertArrayHasKey('query', $params['properties'] ?? []);
    }

    public function testIntegerSemanticRatioArgIsIgnoredAndDoesNotThrow(): void
    {
        $resolver = new ToolCallArgumentResolver();
        $args = $resolver->resolveArguments(
            $this->searchTool(),
            new ToolCall('t1', 'search_emails', ['query' => 'stock options', 'semantic_ratio' => 1]),
        );
        $this->assertSame('stock options', $args['query']);
        $this->assertArrayNotHasKey('semantic_ratio', $args);
    }
}
