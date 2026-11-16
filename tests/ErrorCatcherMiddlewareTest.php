<?php

declare(strict_types=1);

namespace G\ErrorCatcherMiddleware\Tests;

use ErrorException;
use G\ErrorCatcherMiddleware\ErrorCatcherMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

#[CoversClass(ErrorCatcherMiddleware::class)]
final class ErrorCatcherMiddlewareTest extends TestCase
{
    private ?Throwable $caught = null;
    private ResponseInterface $errorResponse;
    private ResponseInterface $okResponse;
    private int $errorReporting;

    protected function setUp(): void
    {
        // PHPUnit runs tests with a reduced error_reporting(): start from a known level.
        $this->errorReporting = error_reporting(E_ALL);
        $this->caught = null;
        $this->errorResponse = $this->createStub(ResponseInterface::class);
        $this->okResponse = $this->createStub(ResponseInterface::class);
    }

    protected function tearDown(): void
    {
        error_reporting($this->errorReporting);
    }

    #[Test]
    public function it_implements_psr15_middleware_interface(): void
    {
        $this->assertInstanceOf(MiddlewareInterface::class, $this->middleware());
    }

    #[Test]
    public function it_passes_request_through_when_no_error_occurs(): void
    {
        $this->assertSame($this->okResponse, $this->dispatch(static fn() => null));
        $this->assertNull($this->caught);
    }

    #[Test]
    public function it_catches_exception_thrown_by_handler_and_calls_error_handler(): void
    {
        $result = $this->dispatch(static fn() => throw new RuntimeException('Boom'));

        $this->assertSame($this->errorResponse, $result);
        $this->assertInstanceOf(RuntimeException::class, $this->caught);
        $this->assertSame('Boom', $this->caught->getMessage());
    }

    #[Test]
    public function it_catches_php_errors(): void
    {
        $result = $this->dispatch(static fn() => intdiv(1, 0));

        $this->assertSame($this->errorResponse, $result);
        $this->assertInstanceOf(\DivisionByZeroError::class, $this->caught);
    }

    #[Test]
    public function it_converts_native_php_warning_to_error_exception(): void
    {
        $line = __LINE__ + 1;
        $result = $this->dispatch(static fn() => trigger_error('Something smells bad', E_USER_WARNING));

        $this->assertSame($this->errorResponse, $result);
        $this->assertInstanceOf(ErrorException::class, $this->caught);
        $this->assertSame('Something smells bad', $this->caught->getMessage());
        $this->assertSame(E_USER_WARNING, $this->caught->getSeverity());
        $this->assertSame(__FILE__, $this->caught->getFile());
        $this->assertSame($line, $this->caught->getLine());
    }

    #[Test]
    public function it_ignores_errors_silenced_with_the_at_operator(): void
    {
        $result = $this->dispatch(static fn() => @trigger_error('Silenced', E_USER_WARNING));

        $this->assertSame($this->okResponse, $result);
        $this->assertNull($this->caught);
    }

    #[Test]
    public function it_ignores_errors_excluded_by_error_reporting(): void
    {
        error_reporting(E_ALL & ~E_USER_NOTICE);

        $result = $this->dispatch(static fn() => trigger_error('Not reported', E_USER_NOTICE));

        $this->assertSame($this->okResponse, $result);
        $this->assertNull($this->caught);
    }

    #[Test]
    public function it_never_turns_a_deprecation_into_a_failure(): void
    {
        $displayErrors = ini_set('display_errors', '0');
        $logErrors = ini_set('log_errors', '0');

        try {
            $result = $this->dispatch(static fn() => trigger_error('Old API', E_USER_DEPRECATED));
        } finally {
            ini_set('display_errors', (string) $displayErrors);
            ini_set('log_errors', (string) $logErrors);
        }

        $this->assertSame($this->okResponse, $result);
        $this->assertNull($this->caught);
    }

    #[Test]
    public function it_restores_previous_error_handler_after_processing(): void
    {
        $previousHandler = static fn() => true;
        set_error_handler($previousHandler);

        try {
            $this->dispatch(static fn() => null);
            $this->assertSame($previousHandler, $this->currentErrorHandler());

            $this->dispatch(static fn() => throw new RuntimeException('Boom'));
            $this->assertSame($previousHandler, $this->currentErrorHandler());
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function it_can_process_several_requests_with_the_same_instance(): void
    {
        $middleware = $this->middleware();

        $this->assertSame($this->errorResponse, $this->dispatch(static fn() => throw new RuntimeException('1'), $middleware));
        $this->assertSame($this->okResponse, $this->dispatch(static fn() => null, $middleware));
        $this->assertSame($this->errorResponse, $this->dispatch(static fn() => throw new RuntimeException('3'), $middleware));
        $this->assertSame('3', $this->caught?->getMessage());
    }

    #[Test]
    public function default_shutdown_handler_outputs_a_plain_500_message_on_fatal_error(): void
    {
        $this->assertSame('Internal Server Error', $this->runFatalScript('default'));
    }

    #[Test]
    public function custom_shutdown_handler_receives_the_fatal_error(): void
    {
        $output = $this->runFatalScript('custom');

        $this->assertStringStartsWith('custom:E_ERROR:', $output);
        $this->assertStringContainsString('undefined_function_for_fatal_error', $output);
    }

    #[Test]
    public function shutdown_handler_stays_silent_without_fatal_error(): void
    {
        $this->assertSame('done', $this->runFatalScript('none'));
    }

    private function middleware(): ErrorCatcherMiddleware
    {
        return new ErrorCatcherMiddleware(function (Throwable $e): ResponseInterface {
            $this->caught = $e;

            return $this->errorResponse;
        });
    }

    /**
     * @param callable(): mixed $action Runs inside the request handler
     */
    private function dispatch(callable $action, ?ErrorCatcherMiddleware $middleware = null): ResponseInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function () use ($action): ResponseInterface {
            $action();

            return $this->okResponse;
        });

        return ($middleware ?? $this->middleware())->process(
            $this->createStub(ServerRequestInterface::class),
            $handler,
        );
    }

    private function currentErrorHandler(): mixed
    {
        $current = set_error_handler(null);
        restore_error_handler();

        return $current;
    }

    private function runFatalScript(string $mode): string
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/Fixtures/fatal.php') . ' ' . $mode;

        return (string) shell_exec($command);
    }
}
