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
use Marko\Config\Exceptions\ConfigException;
use Marko\Http\Contracts\HttpClientInterface;
use Marko\Http\Exceptions\ConnectionException;
use Marko\Http\Exceptions\HttpException;
use Marko\Http\Exceptions\InvalidRequestOptionException;
use Marko\Http\Guzzle\GuzzleHttpClient;
use Marko\Http\HttpResponse;
use Marko\Testing\Fake\FakeConfigRepository;

function guzzleConfig(
    float $timeout = 30.0,
    float $connectTimeout = 10.0,
): FakeConfigRepository {
    return new FakeConfigRepository([
        'http-guzzle.timeout' => $timeout,
        'http-guzzle.connect_timeout' => $connectTimeout,
    ]);
}

function createTestableClient(
    MockHandler $mock,
    array &$history = [],
    float $timeout = 30.0,
    float $connectTimeout = 10.0,
): GuzzleHttpClient {
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));
    $guzzle = new Client(['handler' => $handlerStack]);
    $config = guzzleConfig($timeout, $connectTimeout);

    return new class ($guzzle, $config) extends GuzzleHttpClient
    {
        public function __construct(
            private readonly GuzzleClientInterface $testClient,
            FakeConfigRepository $config,
        ) {
            parent::__construct($config);
        }

        protected function createClient(): GuzzleClientInterface
        {
            return $this->testClient;
        }
    };
}

describe('GuzzleHttpClient', function (): void {
    it('implements HttpClientInterface', function (): void {
        $client = new GuzzleHttpClient(guzzleConfig());

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

    it('passes repeated response header values through intact', function (): void {
        $cookies = [
            'session=abc; Expires=Wed, 21 Oct 2026 07:28:00 GMT; Path=/',
            'theme=dark; Expires=Thu, 22 Oct 2026 07:28:00 GMT; Path=/',
        ];
        $mock = new MockHandler([new Response(200, ['Set-Cookie' => $cookies], '')]);
        $client = createTestableClient($mock);

        $response = $client->get('https://example.com/api');

        expect($response->headerValues('set-cookie'))->toBe($cookies)
            ->and($response->header('Set-Cookie'))->toBe(implode(', ', $cookies));
    });

    it('passes repeated header values through on the response attached to an HttpException', function (): void {
        $mock = new MockHandler([
            new Response(401, ['Set-Cookie' => ['a=1; Expires=Wed, 21 Oct 2026 07:28:00 GMT', 'b=2']], ''),
        ]);
        $client = createTestableClient($mock);

        try {
            $client->get('https://example.com/login');
            test()->fail('Expected HttpException');
        } catch (HttpException $e) {
            expect($e->getResponse()?->headerValues('set-cookie'))
                ->toBe(['a=1; Expires=Wed, 21 Oct 2026 07:28:00 GMT', 'b=2']);
        }
    });
});

describe('GuzzleHttpClient safe defaults', function (): void {
    it('applies a default timeout and connect_timeout so a slow upstream cannot hang a worker', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://example.com/api');

        expect($history[0]['options']['timeout'])->toBe(30.0)
            ->and($history[0]['options']['connect_timeout'])->toBe(10.0);
    });

    it('uses the timeouts from config', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history, timeout: 5.0, connectTimeout: 1.5);

        $client->get('https://example.com/api');

        expect($history[0]['options']['timeout'])->toBe(5.0)
            ->and($history[0]['options']['connect_timeout'])->toBe(1.5);
    });

    it('lets per-request timeout options override the defaults', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://example.com/api', ['timeout' => 0, 'connect_timeout' => 3]);

        expect($history[0]['options']['timeout'])->toBe(0)
            ->and($history[0]['options']['connect_timeout'])->toBe(3);
    });

    it('rejects a negative configured timeout', function (): void {
        $client = createTestableClient(new MockHandler([new Response(200)]), timeout: -1.0);

        expect(fn () => $client->get('https://example.com/api'))
            ->toThrow(ConfigException::class, 'http-guzzle.timeout');
    });

    it('rejects a negative configured connect_timeout', function (): void {
        $client = createTestableClient(new MockHandler([new Response(200)]), connectTimeout: -1.0);

        expect(fn () => $client->get('https://example.com/api'))
            ->toThrow(ConfigException::class, 'http-guzzle.connect_timeout');
    });

    it('lets the guzzle escape hatch override the configured timeouts', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://example.com/api', ['guzzle' => ['timeout' => 120, 'connect_timeout' => 0]]);

        expect($history[0]['options']['timeout'])->toBe(120)
            ->and($history[0]['options']['connect_timeout'])->toBe(0);
    });

    it('redacts the query string from HttpException messages', function (): void {
        $mock = new MockHandler([new Response(401, [], 'denied')]);
        $client = createTestableClient($mock);

        try {
            $client->get('https://api.example.com/v1/data?api_key=sk_live_SECRET123&page=2');
            test()->fail('Expected HttpException');
        } catch (HttpException $e) {
            expect($e->getMessage())->not->toContain('sk_live_SECRET123')
                ->and($e->getMessage())->not->toContain('api_key')
                ->and($e->getMessage())->toContain('https://api.example.com/v1/data?…')
                ->and($e->getResponse()?->statusCode())->toBe(401);
        }
    });

    it('redacts the query string and userinfo from ConnectionException messages', function (): void {
        $url = 'https://admin:hunter2@api.example.com/v1/data?token=SECRET123';
        $mock = new MockHandler([
            new ConnectException(
                "cURL error 28: Operation timed out (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for $url",
                new Request('GET', $url),
            ),
        ]);
        $client = createTestableClient($mock);

        try {
            $client->get($url);
            test()->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            expect($e->getMessage())->not->toContain('SECRET123')
                ->and($e->getMessage())->not->toContain('hunter2')
                ->and($e->getMessage())->not->toContain('admin')
                ->and($e->getMessage())->toContain('cURL error 28: Operation timed out')
                ->and($e->getMessage())->toContain('https://***@api.example.com/v1/data?…')
                ->and($e->getMessage())->toContain('https://curl.haxx.se/libcurl/c/libcurl-errors.html');
        }
    });

    it('redacts urls in RequestException messages without a response', function (): void {
        $url = 'https://api.example.com/v1?key=SECRET123#frag';
        $mock = new MockHandler([
            new RequestException("Error sending request to $url", new Request('GET', $url)),
        ]);
        $client = createTestableClient($mock);

        try {
            $client->get($url);
            test()->fail('Expected HttpException');
        } catch (HttpException $e) {
            expect($e->getMessage())->toBe('Error sending request to https://api.example.com/v1?…');
        }
    });
});

describe('GuzzleHttpClient resolve_to', function (): void {
    it('pins the connection with CURLOPT_RESOLVE for the URL host and default port', function (
        string $url,
        string $address,
        string $entry,
    ): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->post($url, ['resolve_to' => $address, 'allow_redirects' => false]);

        expect($history[0]['options']['curl'][CURLOPT_RESOLVE])->toBe([$entry])
            ->and($history[0]['options']['curl'][CURLOPT_FRESH_CONNECT])->toBeTrue()
            ->and($history[0]['options'])->not->toHaveKey('resolve_to')
            ->and($history[0]['request']->getHeaderLine('Host'))->toBe(parse_url($url, PHP_URL_HOST)
                . (parse_url($url, PHP_URL_PORT) !== null ? ':' . parse_url($url, PHP_URL_PORT) : ''));
    })->with([
        'https default port' => ['https://hooks.example.com/in', '93.184.215.14', 'hooks.example.com:443:93.184.215.14'],
        'http default port' => ['http://hooks.example.com/in', '93.184.215.14', 'hooks.example.com:80:93.184.215.14'],
        'explicit port' => ['https://hooks.example.com:8443/in', '93.184.215.14', 'hooks.example.com:8443:93.184.215.14'],
        'ipv6 address' => ['https://hooks.example.com/in', '2606:2800:21f::1', 'hooks.example.com:443:[2606:2800:21f::1]'],
    ]);

    it('sets no curl options when resolve_to is absent', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://example.com/a');

        expect($history[0]['options'])->not->toHaveKey('curl');
    });

    it('keeps other escape-hatch curl options alongside the pin', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://example.com/a', [
            'resolve_to' => '93.184.215.14',
            'allow_redirects' => false,
            'guzzle' => ['curl' => [CURLOPT_TCP_NODELAY => true]],
        ]);

        expect($history[0]['options']['curl'][CURLOPT_TCP_NODELAY])->toBeTrue()
            ->and($history[0]['options']['curl'][CURLOPT_RESOLVE])->toBe(['example.com:443:93.184.215.14']);
    });

    it('accepts an IP-literal URL that names the pinned address', function (): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        $client->get('https://[2606:2800:21f::1]/a', [
            'resolve_to' => '2606:2800:21f:0::1',
            'allow_redirects' => false,
        ]);

        expect($history[0]['options']['curl'])->not->toHaveKey(CURLOPT_RESOLVE)
            ->and($history[0]['options']['curl'][CURLOPT_FRESH_CONNECT])->toBeTrue();
    });

    it('rejects pinning options that would bypass or override the pin, before sending', function (
        string $url,
        array $guzzle,
        string $message,
    ): void {
        $mock = new MockHandler([new Response(200)]);
        $history = [];
        $client = createTestableClient($mock, $history);

        expect(fn () => $client->get($url, [
            'resolve_to' => '93.184.215.14',
            'allow_redirects' => false,
            'guzzle' => $guzzle,
        ]))->toThrow(InvalidRequestOptionException::class, $message)
            ->and($history)->toBeEmpty();
    })->with([
        'streamed request' => ['https://example.com/a', ['stream' => true], "with the 'stream' Guzzle option"],
        'guzzle proxy' => ['https://example.com/a', ['proxy' => 'http://proxy:8080'], "'guzzle.proxy'"],
        'guzzle redirects' => ['https://example.com/a', ['allow_redirects' => true], "requires 'allow_redirects' => false"],
        'curl resolve' => ['https://example.com/a', ['curl' => [CURLOPT_RESOLVE => ['x:443:1.1.1.1']]], 'CURLOPT_RESOLVE'],
        'curl connect_to' => ['https://example.com/a', ['curl' => [CURLOPT_CONNECT_TO => ['::1.1.1.1:']]], 'CURLOPT_CONNECT_TO'],
        'curl not an array' => ['https://example.com/a', ['curl' => 'x'], "'guzzle.curl'"],
        'relative url' => ['/a', [], 'needs an absolute http or https URL'],
        'ftp url' => ['ftp://example.com/a', [], 'needs an absolute http or https URL'],
        'different ip literal' => ['https://10.0.0.1/a', [], "'resolve_to' and 'the URL host' cannot be used together"],
    ]);

    it('connects to the pinned address while sending the original Host header', function (): void {
        $server = startPinningTestServer();

        try {
            $client = new GuzzleHttpClient(guzzleConfig());
            $response = $client->get("http://pinned.marko.invalid:{$server['port']}/hook", [
                'resolve_to' => '127.0.0.1',
                'allow_redirects' => false,
            ]);

            expect($response->statusCode())->toBe(200)
                ->and($response->body())->toBe("pinned.marko.invalid:{$server['port']}");
        } finally {
            proc_terminate($server['process']);
            proc_close($server['process']);
            unlink($server['router']);
        }
    });

    it('cannot reach a name that does not resolve without resolve_to', function (): void {
        $client = new GuzzleHttpClient(guzzleConfig(connectTimeout: 5.0));

        expect(fn () => $client->get('http://pinned.marko.invalid/hook'))
            ->toThrow(ConnectionException::class);
    });
});

/**
 * Starts PHP's built-in server on a free loopback port, answering every request with its Host header.
 *
 * @return array{process: resource, port: int, router: string}
 */
function startPinningTestServer(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $router = sys_get_temp_dir() . '/marko-pinning-router-' . getmypid() . '.php';
    file_put_contents($router, '<?php echo $_SERVER["HTTP_HOST"] ?? "";');

    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", $router],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    // Connection attempts are refused until the server is listening; those warnings are expected.
    set_error_handler(static fn (): bool => true);

    try {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return ['process' => $process, 'port' => $port, 'router' => $router];
            }

            usleep(20_000);
        }
    } finally {
        restore_error_handler();
    }

    proc_terminate($process);
    unlink($router);

    throw new RuntimeException("The pinning test server did not start on port $port.");
}
