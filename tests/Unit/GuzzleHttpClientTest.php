<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Marko\Http\Contracts\HttpClientInterface;
use Marko\Http\Exceptions\ConnectionException;
use Marko\Http\Exceptions\HttpException;
use Marko\Http\Exceptions\InvalidRequestOptionException;
use Marko\Http\Guzzle\GuzzleHttpClient;
use Marko\Http\HttpResponse;

function createTestableClient(
    MockHandler $mock,
    array &$history = [],
): GuzzleHttpClient {
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));
    $guzzle = new Client(['handler' => $handlerStack]);

    return new class ($guzzle) extends GuzzleHttpClient
    {
        public function __construct(
            private readonly GuzzleClientInterface $testClient,
        ) {}

        protected function createClient(): GuzzleClientInterface
        {
            return $this->testClient;
        }
    };
}

describe('GuzzleHttpClient', function (): void {
    it('implements HttpClientInterface', function (): void {
        $client = new GuzzleHttpClient();

        expect($client)->toBeInstanceOf(HttpClientInterface::class);
    });

    it('sends GET request and returns response', function (): void {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/plain'], 'Hello World'),
        ]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $response = $client->get('https://example.com/api');

        expect($response)->toBeInstanceOf(HttpResponse::class)
            ->and($response->statusCode())->toBe(200)
            ->and($response->body())->toBe('Hello World')
            ->and($response->headers())->toHaveKey('Content-Type')
            ->and($history)->toHaveCount(1)
            ->and($history[0]['request']->getMethod())->toBe('GET')
            ->and((string) $history[0]['request']->getUri())->toBe('https://example.com/api');
    });

    it('sends POST request with json body', function (): void {
        $mock = new MockHandler([
            new Response(201, [], '{"id":1}'),
        ]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $response = $client->post('https://example.com/api/users', [
            'json' => ['name' => 'Marko'],
        ]);

        expect($response->statusCode())->toBe(201)
            ->and($response->json())->toBe(['id' => 1])
            ->and($history[0]['request']->getMethod())->toBe('POST')
            ->and((string) $history[0]['request']->getBody())->toBe('{"name":"Marko"}');
    });

    it('sends PUT request with body', function (): void {
        $mock = new MockHandler([
            new Response(200, [], '{"updated":true}'),
        ]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $response = $client->put('https://example.com/api/users/1', [
            'body' => '{"name":"Updated"}',
        ]);

        expect($response->statusCode())->toBe(200)
            ->and($history[0]['request']->getMethod())->toBe('PUT');
    });

    it('sends DELETE request', function (): void {
        $mock = new MockHandler([
            new Response(204, [], ''),
        ]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $response = $client->delete('https://example.com/api/users/1');

        expect($response->statusCode())->toBe(204)
            ->and($response->body())->toBe('')
            ->and($history[0]['request']->getMethod())->toBe('DELETE');
    });

    it('includes custom headers in request', function (): void {
        $mock = new MockHandler([
            new Response(200, [], ''),
        ]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://example.com/api', [
            'headers' => ['Authorization' => 'Bearer token123'],
        ]);

        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer token123');
    });

    it('maps response status code', function (): void {
        $mock = new MockHandler([
            new Response(204, [], ''),
        ]);
        $client = createTestableClient($mock);

        $response = $client->get('https://example.com/api');

        expect($response->statusCode())->toBe(204)
            ->and($response->isSuccessful())->toBeTrue();
    });

    it('maps response headers', function (): void {
        $mock = new MockHandler([
            new Response(200, [
                'X-Custom' => 'value1',
                'X-Rate-Limit' => '100',
            ], ''),
        ]);
        $client = createTestableClient($mock);

        $response = $client->get('https://example.com/api');

        expect($response->headers())->toHaveKey('X-Custom')
            ->and($response->headers()['X-Custom'])->toBe('value1')
            ->and($response->headers()['X-Rate-Limit'])->toBe('100');
    });

    it('throws HttpException for error status codes', function (): void {
        $mock = new MockHandler([
            new RequestException(
                'Client error',
                new Request('GET', 'https://example.com/api'),
                new Response(404, [], 'Not Found'),
            ),
        ]);
        $client = createTestableClient($mock);

        try {
            $client->get('https://example.com/api');
            test()->fail('Expected HttpException to be thrown');
        } catch (HttpException $e) {
            expect($e->getMessage())->toBe('Client error')
                ->and($e->getResponse())->not->toBeNull()
                ->and($e->getResponse()->statusCode())->toBe(404)
                ->and($e->getResponse()->body())->toBe('Not Found');
        }
    });

    it('throws ConnectionException for network failures', function (): void {
        $mock = new MockHandler([
            new ConnectException(
                'Connection refused',
                new Request('GET', 'https://example.com/api'),
            ),
        ]);
        $client = createTestableClient($mock);

        expect(fn () => $client->get('https://example.com/api'))
            ->toThrow(ConnectionException::class, 'Connection refused');
    });
});

describe('GuzzleHttpClient request options', function (): void {
    it('throws InvalidRequestOptionException for an unknown option key before sending', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        try {
            $client->post('https://example.com/api', ['form_param' => ['a' => 1]]);
            test()->fail('Expected InvalidRequestOptionException');
        } catch (InvalidRequestOptionException $e) {
            expect($e->getMessage())->toContain('form_param')
                ->and($e->getSuggestion())->toContain('form_params')
                ->and($e->getSuggestion())->toContain('guzzle')
                ->and($history)->toBeEmpty();
        }
    });

    it('forwards form_params as a url-encoded body', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->post('https://example.com/api', ['form_params' => ['name' => 'Marko', 'v' => '1']]);

        expect((string) $history[0]['request']->getBody())->toBe('name=Marko&v=1')
            ->and($history[0]['request']->getHeaderLine('Content-Type'))
            ->toBe('application/x-www-form-urlencoded');
    });

    it('forwards multipart as a multipart body', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->post('https://example.com/upload', [
            'multipart' => [
                ['name' => 'file', 'contents' => 'hello', 'filename' => 'hello.txt'],
            ],
        ]);

        $request = $history[0]['request'];

        expect($request->getHeaderLine('Content-Type'))->toStartWith('multipart/form-data; boundary=')
            ->and((string) $request->getBody())->toContain('filename="hello.txt"')
            ->and((string) $request->getBody())->toContain('hello');
    });

    it('forwards basic auth credentials', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://example.com/api', ['auth' => ['user', 'secret']]);

        expect($history[0]['request']->getHeaderLine('Authorization'))
            ->toBe('Basic ' . base64_encode('user:secret'));
    });

    it('forwards a bearer token as an Authorization header', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://example.com/api', [
            'headers' => ['Accept' => 'application/json'],
            'auth' => ['bearer' => 'token-123'],
        ]);

        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer token-123')
            ->and($history[0]['request']->getHeaderLine('Accept'))->toBe('application/json')
            ->and($history[0]['options'])->not->toHaveKey('auth');
    });

    it('forwards connect_timeout, verify and proxy to guzzle', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://example.com/api', [
            'timeout' => 10,
            'connect_timeout' => 2.5,
            'verify' => '/etc/ssl/ca.pem',
            'proxy' => 'http://proxy.local:8080',
        ]);

        expect($history[0]['options']['timeout'])->toBe(10)
            ->and($history[0]['options']['connect_timeout'])->toBe(2.5)
            ->and($history[0]['options']['verify'])->toBe('/etc/ssl/ca.pem')
            ->and($history[0]['options']['proxy'])->toBe('http://proxy.local:8080');
    });

    it('forwards allow_redirects as a bool or a maximum redirect count', function (): void {
        $mock = new MockHandler([new Response(200), new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://example.com/a', ['allow_redirects' => false]);
        $client->get('https://example.com/b', ['allow_redirects' => 3]);

        expect($history[0]['options']['allow_redirects'])->toBeFalse()
            ->and($history[1]['options']['allow_redirects'])->toBeArray()
            ->and($history[1]['options']['allow_redirects']['max'])->toBe(3);
    });

    it('rejects an allow_redirects value that is neither bool nor int', function (): void {
        $client = createTestableClient(new MockHandler([new Response(200)]));

        expect(fn () => $client->get('https://example.com/a', ['allow_redirects' => 'yes']))
            ->toThrow(InvalidRequestOptionException::class, 'allow_redirects');
    });

    it('merges the guzzle escape-hatch options verbatim', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://example.com/api', [
            'timeout' => 10,
            'guzzle' => [
                'cert' => '/path/to/cert.pem',
                'decode_content' => false,
                'timeout' => 30,
            ],
        ]);

        expect($history[0]['options']['cert'])->toBe('/path/to/cert.pem')
            ->and($history[0]['options']['decode_content'])->toBeFalse()
            ->and($history[0]['options']['timeout'])->toBe(30);
    });

    it('rejects a guzzle escape-hatch value that is not an array', function (): void {
        $client = createTestableClient(new MockHandler([new Response(200)]));

        expect(fn () => $client->get('https://example.com/api', ['guzzle' => 'debug']))
            ->toThrow(InvalidRequestOptionException::class, 'guzzle');
    });

    it('throws when two body options are given', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        expect(fn () => $client->post('https://example.com/api', [
            'body' => 'raw',
            'json' => ['a' => 1],
        ]))->toThrow(InvalidRequestOptionException::class, 'Only one body option')
            ->and($history)->toBeEmpty();
    });

    it('returns 4xx and 5xx responses when http_errors is false', function (): void {
        $mock = new MockHandler([
            new Response(404, [], 'Not Found'),
            new Response(500, [], 'boom'),
        ]);
        $client = createTestableClient($mock);

        $notFound = $client->get('https://example.com/missing', ['http_errors' => false]);
        $serverError = $client->get('https://example.com/broken', ['http_errors' => false]);

        expect($notFound->statusCode())->toBe(404)
            ->and($notFound->isClientError())->toBeTrue()
            ->and($notFound->body())->toBe('Not Found')
            ->and($serverError->statusCode())->toBe(500)
            ->and($serverError->isServerError())->toBeTrue();
    });

    it('throws HttpException with the response attached by default for 4xx responses', function (): void {
        $mock = new MockHandler([new Response(404, [], 'Not Found')]);
        $client = createTestableClient($mock);

        try {
            $client->get('https://example.com/missing');
            test()->fail('Expected HttpException');
        } catch (HttpException $e) {
            expect($e)->not->toBeInstanceOf(ConnectionException::class)
                ->and($e->getResponse())->not->toBeNull()
                ->and($e->getResponse()->statusCode())->toBe(404)
                ->and($e->getResponse()->isClientError())->toBeTrue();
        }
    });

    it('joins repeated response header values with a comma', function (): void {
        $mock = new MockHandler([
            new Response(200, ['Set-Cookie' => ['a=1; Path=/', 'b=2; Path=/']], ''),
        ]);
        $client = createTestableClient($mock);

        $response = $client->get('https://example.com/api');

        expect($response->headers()['Set-Cookie'])->toBe('a=1; Path=/, b=2; Path=/');
    });
});
