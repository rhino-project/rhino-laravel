<?php

namespace Rhino\Tests\Feature;

use ReflectionMethod;
use ReflectionProperty;
use Rhino\Commands\GenerateCommand;
use Rhino\Tests\TestCase;

/**
 * The generator's fourth entry: `rhino:generate` → request.
 *
 * Follows GenerateCommandTest's harness — the interactive prompts are not
 * driven here; the stub and its placeholder substitution are, because a stub
 * that does not render to valid PHP is the failure mode that matters.
 */
class GenerateRequestCommandTest extends TestCase
{
    protected GenerateCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new GenerateCommand();
        $this->setProperty('stubPath', realpath(__DIR__ . '/../../stubs/generate'));
    }

    protected function invokeMethod(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(GenerateCommand::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->command, ...$args);
    }

    protected function setProperty(string $property, mixed $value): void
    {
        $ref = new ReflectionProperty(GenerateCommand::class, $property);
        $ref->setAccessible(true);
        $ref->setValue($this->command, $value);
    }

    protected function renderStub(string $action): string
    {
        return $this->invokeMethod('replacePlaceholders', [
            $this->invokeMethod('getStub', ['request']),
            [
                'className' => 'TaskStoreRequest',
                'modelName' => 'Task',
                'modelLower' => 'task',
                'action' => $action,
                'actionSuffix' => $action === 'store' ? 'Store' : 'Update',
                'actionVerb' => $action === 'store' ? 'creating' : 'updating',
                'httpCall' => 'POST /{resource}',
                'policySuffix' => $action === 'store' ? 'Create' : 'Update',
                'presence' => $action === 'store' ? 'required' : 'sometimes',
                'recordDoc' => 'always null on store',
                'recordRuleDoc' => 'x',
                'recordRuleExample' => $this->invokeMethod('requestRecordRuleExample', [$action]),
            ],
        ]);
    }

    public function test_the_request_stub_exists_and_extends_resource_request(): void
    {
        $stub = $this->invokeMethod('getStub', ['request']);

        $this->assertStringContainsString('namespace App\\Http\\Requests', $stub);
        $this->assertStringContainsString('use Rhino\\Http\\Requests\\ResourceRequest;', $stub);
        $this->assertStringContainsString('class {{ className }} extends ResourceRequest', $stub);
        // H-13: the fail-closed field dropping must be called out in the stub.
        $this->assertStringContainsString('what you validate is what gets written', $stub);
    }

    public function test_a_rendered_store_request_is_valid_php_with_no_placeholders_left(): void
    {
        $rendered = $this->renderStub('store');

        $this->assertStringNotContainsString('{{', $rendered);
        $this->assertStringContainsString('class TaskStoreRequest extends ResourceRequest', $rendered);
        $this->assertStringContainsString("'required|string|max:255'", $rendered);

        $this->assertNoSyntaxErrors($rendered);
    }

    public function test_a_rendered_update_request_uses_sometimes_and_the_record_example(): void
    {
        $rendered = $this->renderStub('update');

        $this->assertStringNotContainsString('{{', $rendered);
        $this->assertStringContainsString("'sometimes|string|max:255'", $rendered);
        // The record-dependent example only makes sense on update.
        $this->assertStringContainsString('$this->record()?->status', $rendered);

        $this->assertNoSyntaxErrors($rendered);
    }

    public function test_the_store_variant_has_no_record_dependent_example(): void
    {
        $this->assertStringContainsString(
            'nothing to show here',
            $this->invokeMethod('requestRecordRuleExample', ['store'])
        );
    }

    public function test_generate_is_advertised_for_requests(): void
    {
        $this->assertStringContainsString(
            'Request',
            (new GenerateCommand())->getDescription()
        );
    }

    /**
     * Lint the rendered stub with the PHP binary running the suite.
     */
    protected function assertNoSyntaxErrors(string $code): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rhino_request_stub_') . '.php';
        file_put_contents($file, $code);

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        @unlink($file);

        $this->assertSame(0, $status, implode("\n", $output));
    }
}
