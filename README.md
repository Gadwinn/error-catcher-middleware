# gadwinn/error-catcher-middleware

A PSR-15 middleware that turns unhandled exceptions and PHP errors into an HTTP response, with an optional safety net for fatal errors.

- No dependency besides the PSR interfaces (PSR-7, PSR-15).
- No global or static state: safe with long-running workers (FrankenPHP worker mode, RoadRunner…).

## Requirements

- PHP >= 8.3

## Installation

```bash
composer require gadwinn/error-catcher-middleware
```

## Usage

The middleware takes one closure, which receives the `Throwable` and returns the response to send:

```php
use G\ErrorCatcherMiddleware\ErrorCatcherMiddleware;
use Psr\Http\Message\ResponseInterface;

$middleware = new ErrorCatcherMiddleware(
    function (Throwable $e) use ($responseFactory, $logger): ResponseInterface {
        $logger->error($e->getMessage(), ['exception' => $e]);

        $response = $responseFactory->createResponse(500);
        $response->getBody()->write('Internal Server Error');

        return $response;
    },
);
```

Place it **outermost** in the pipeline, so that it wraps every other middleware and the application handler.

### What is caught

| Scenario | Behaviour |
|---|---|
| Exception or `Error` thrown while handling the request | Passed to the closure |
| PHP warning / notice | Converted to an `ErrorException`, passed to the closure |
| Error excluded by `error_reporting()` or silenced with `@` | Ignored (PHP's standard handling) |
| Deprecation (`E_DEPRECATED`, `E_USER_DEPRECATED`) | Never converted: logged or displayed by PHP according to its settings |
| Fatal error (`E_ERROR`, `E_PARSE`, …) | Not catchable by a middleware, see below |

The PHP error handler is installed for the duration of `process()` only, and the previous one is restored afterwards.

### Fatal errors

A fatal error (out of memory, timeout, parse error…) stops the script: no response object can be returned any more. Register a shutdown handler **once, when the application boots**:

```php
// Default: status 500 and "Internal Server Error" as plain text
ErrorCatcherMiddleware::registerShutdownHandler();

// Or your own output
ErrorCatcherMiddleware::registerShutdownHandler(static function (ErrorException $e): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    echo json_encode(['error' => 'Internal Server Error']);
});
```

The handler must output directly. Nothing is registered unless you call this method, and each call registers one more handler: in worker mode, call it before the request loop, not inside it.

## Development

```bash
composer install
composer test-all
```

## License

MIT
