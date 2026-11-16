<?php

declare(strict_types=1);

namespace G\ErrorCatcherMiddleware;

use Closure;
use ErrorException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final class ErrorCatcherMiddleware implements MiddlewareInterface
{
    private const array FATAL_ERRORS = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
        E_RECOVERABLE_ERROR, // intentionally kept
    ];

    private const int DEPRECATIONS = E_DEPRECATED | E_USER_DEPRECATED;

    /**
     * @param Closure(Throwable): ResponseInterface $errorHandler Builds the response of a failed request
     */
    public function __construct(
        private readonly Closure $errorHandler,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        set_error_handler($this->buildNativeErrorHandler());

        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            return ($this->errorHandler)($e);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Registers a handler for fatal errors (E_ERROR, E_PARSE, etc.), which no try/catch can intercept.
     *
     * Call it once, when the application boots (in worker mode: before the request loop). It keeps no state:
     * every call registers one more shutdown function.
     *
     * At this point the PSR-15 cycle is dead: the handler must output directly.
     *
     * @param Closure(ErrorException): void|null $shutdownHandler Defaults to a plain-text 500 response
     */
    public static function registerShutdownHandler(?Closure $shutdownHandler = null): void
    {
        $shutdownHandler ??= static function (): void {
            if (!headers_sent()) {
                http_response_code(500);
            }
            echo 'Internal Server Error';
        };

        register_shutdown_function(static function () use ($shutdownHandler): void {
            $error = error_get_last();

            if ($error !== null && in_array($error['type'], self::FATAL_ERRORS, true)) {
                $shutdownHandler(
                    new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line'])
                );
            }
        });
    }

    /**
     * Converts native PHP errors (Warning, Notice, etc.) into ErrorException,
     * so they are caught by the try/catch in process().
     *
     * Errors excluded by error_reporting() (including @-suppressed ones) and deprecations are left
     * to PHP's standard handling (log/display settings): they must not turn a working request into a failure.
     */
    private function buildNativeErrorHandler(): Closure
    {
        return static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0 || ($severity & self::DEPRECATIONS) !== 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        };
    }
}
