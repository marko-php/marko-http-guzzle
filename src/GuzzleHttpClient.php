<?php

declare(strict_types=1);

namespace Marko\Http\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Marko\Config\ConfigRepositoryInterface;
use Marko\Config\Exceptions\ConfigException;
use Marko\Http\Contracts\HttpClientInterface;
use Marko\Http\Exceptions\ConnectionException;
use Marko\Http\Exceptions\HttpException;
use Marko\Http\Exceptions\InvalidRequestOptionException;
use Marko\Http\HttpResponse;
use Marko\Http\RequestOptions;

class GuzzleHttpClient implements HttpClientInterface
{
    /**
     * Driver-specific escape hatch: an array merged verbatim into the Guzzle
     * request options. Not portable across HttpClientInterface drivers.
     */
    public const string GUZZLE_OPTIONS = 'guzzle';

    /**
     * Matches absolute URLs in an exception message, capturing the scheme,
     * the optional userinfo, the host and path, and the optional query and
     * fragment, so the secrets in userinfo and the query can be redacted.
     */
    private const string URL_PATTERN = '~\b([a-z][a-z0-9+.\-]*://)([^\s/?#@`\'"<>]*@)?([^\s?#`\'"<>]*)([?#][^\s`\'"<>]*)?~i';

    private ?GuzzleClientInterface $client = null;

    public function __construct(
        private readonly ConfigRepositoryInterface $config,
    ) {}

    /**
     * @param array<string, mixed> $options
     *
     * @throws HttpException|ConnectionException|InvalidRequestOptionException
     */
    public function request(
        string $method,
        string $url,
        array $options = [],
    ): HttpResponse {
        $guzzleOptions = $this->buildOptions($options);

        try {
            $response = $this->client()->request($method, $url, $guzzleOptions);

            return $this->toHttpResponse(
                $response->getStatusCode(),
                (string) $response->getBody(),
                $response->getHeaders(),
            );
        } catch (GuzzleConnectException $e) {
            throw new ConnectionException($this->redactUrls($e->getMessage()), previous: $e);
        } catch (GuzzleRequestException $e) {
            $response = $e->getResponse();
            $httpResponse = $response !== null
                ? $this->toHttpResponse(
                    $response->getStatusCode(),
                    (string) $response->getBody(),
                    $response->getHeaders(),
                )
                : null;

            throw new HttpException($this->redactUrls($e->getMessage()), $httpResponse, previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws HttpException|ConnectionException|InvalidRequestOptionException
     */
    public function get(
        string $url,
        array $options = [],
    ): HttpResponse {
        return $this->request('GET', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws HttpException|ConnectionException|InvalidRequestOptionException
     */
    public function post(
        string $url,
        array $options = [],
    ): HttpResponse {
        return $this->request('POST', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws HttpException|ConnectionException|InvalidRequestOptionException
     */
    public function put(
        string $url,
        array $options = [],
    ): HttpResponse {
        return $this->request('PUT', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws HttpException|ConnectionException|InvalidRequestOptionException
     */
    public function patch(
        string $url,
        array $options = [],
    ): HttpResponse {
        return $this->request('PATCH', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws HttpException|ConnectionException|InvalidRequestOptionException
     */
    public function delete(
        string $url,
        array $options = [],
    ): HttpResponse {
        return $this->request('DELETE', $url, $options);
    }

    protected function createClient(): GuzzleClientInterface
    {
        return new Client();
    }

    private function client(): GuzzleClientInterface
    {
        return $this->client ??= $this->createClient();
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildOptions(
        array $options,
    ): array {
        RequestOptions::validate($options, [self::GUZZLE_OPTIONS]);

        $escapeHatch = $options[self::GUZZLE_OPTIONS] ?? [];

        if (!is_array($escapeHatch)) {
            throw InvalidRequestOptionException::invalidType(self::GUZZLE_OPTIONS, 'array', $escapeHatch);
        }

        unset($options[self::GUZZLE_OPTIONS]);

        $guzzleOptions = $options;
        $guzzleOptions[RequestOptions::HTTP_ERRORS] = RequestOptions::throwsOnHttpError($options);
        $guzzleOptions[RequestOptions::TIMEOUT] ??= $this->timeoutFromConfig('timeout');
        $guzzleOptions[RequestOptions::CONNECT_TIMEOUT] ??= $this->timeoutFromConfig('connect_timeout');

        $bearerToken = RequestOptions::bearerToken($options);

        if ($bearerToken !== null) {
            unset($guzzleOptions[RequestOptions::AUTH]);
            $headers = $options[RequestOptions::HEADERS] ?? [];
            $headers['Authorization'] = "Bearer $bearerToken";
            $guzzleOptions[RequestOptions::HEADERS] = $headers;
        }

        if (is_int($options[RequestOptions::ALLOW_REDIRECTS] ?? null)) {
            $guzzleOptions[RequestOptions::ALLOW_REDIRECTS] = ['max' => $options[RequestOptions::ALLOW_REDIRECTS]];
        }

        return array_replace($guzzleOptions, $escapeHatch);
    }

    /**
     * @throws ConfigException
     */
    private function timeoutFromConfig(
        string $name,
    ): float {
        $key = "http-guzzle.$name";
        $seconds = $this->config->getFloat(key: $key);

        if ($seconds < 0) {
            throw new ConfigException(
                message: sprintf('Configuration key "%s" must not be negative', $key),
                context: sprintf('Got %s', $seconds),
                suggestion: sprintf(
                    'Set %s to a number of seconds (0 waits forever) in config/http-guzzle.php, or pass "%s" per request.',
                    $key,
                    $name,
                ),
            );
        }

        return $seconds;
    }

    /**
     * Guzzle puts the full request URL in its exception messages, and those
     * messages end up in logs. Strip userinfo and replace the query string so
     * credentials and API keys passed in the URL are not leaked. The original
     * Guzzle exception stays available as the previous exception.
     */
    private function redactUrls(
        string $message,
    ): string {
        return (string) preg_replace_callback(
            self::URL_PATTERN,
            static fn (array $matches): string => $matches[1]
                . (($matches[2] ?? '') !== '' ? '***@' : '')
                . $matches[3]
                . (str_starts_with($matches[4] ?? '', '?') ? '?…' : ''),
            $message,
        );
    }

    /**
     * headers() gets one string per header (repeated values joined with ", ");
     * headerValues() keeps every value, so Set-Cookie survives intact.
     *
     * @param array<string, array<string>> $rawHeaders
     */
    private function toHttpResponse(
        int $statusCode,
        string $body,
        array $rawHeaders,
    ): HttpResponse {
        $headerValues = [];

        foreach ($rawHeaders as $name => $values) {
            $headerValues[$name] = array_values($values);
        }

        return new HttpResponse(
            statusCode: $statusCode,
            body: $body,
            headers: $this->flattenHeaders($headerValues),
            headerValues: $headerValues,
        );
    }

    /**
     * Repeated header values are joined with ", ". This is lossy for headers
     * such as Set-Cookie whose values may themselves contain commas; use
     * HttpResponse::headerValues() to read each value.
     *
     * @param array<string, array<string>> $headers
     *
     * @return array<string, string>
     */
    private function flattenHeaders(
        array $headers,
    ): array {
        $flat = [];

        foreach ($headers as $name => $values) {
            $flat[$name] = implode(', ', $values);
        }

        return $flat;
    }
}
