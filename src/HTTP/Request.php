<?php
/**
 * Copyright (c) 2015, VOOV LLC.
 * All rights reserved.
 * Written by Daniel Fekete
 */

namespace Billingo\API\Connector\HTTP;

use Billingo\API\Connector\Exceptions\JSONParseException;
use Billingo\API\Connector\Exceptions\RequestErrorException;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class Request implements \Billingo\API\Connector\Contracts\Request
{
	/**
	 * @var Client
	 */
	private $client;


	private $config;

	/**
	 * Request constructor.
	 * @param $options
	 */
	public function __construct($options)
	{
		$this->config = $this->resolveOptions($options);

        $this->client = new Client([
            'verify' => false,
            'base_uri' => $this->config['host'],
            'debug' => false,
            'handler' => !empty($this->config['log_dir']) ? $this->createLoggingHandlerStack($this->config['log_msg_format']) : false
        ]);
	}

	/**
	 * Get required options for the Billingo API to work
	 * @param $opts
	 * @return mixed
	 */
	protected function resolveOptions($opts)
	{
		$resolver = new OptionsResolver();
		$resolver->setDefault('version', '2');
		$resolver->setDefault('host', 'https://www.billingo.hu/api/'); // might be overridden in the future
		$resolver->setDefault('leeway', 60);
		$resolver->setDefault('log_syslog', false);
        $resolver->setDefault('log_logdna_key', false);
		$resolver->setDefault('log_dir', '');
		$resolver->setDefault('log_loggly_token', '');
		$resolver->setDefault('log_msg_format', ['{method} {uri} HTTP/{version} {req_body}','RESPONSE: {code} - {res_body}',]);
		$resolver->setRequired(['host', 'private_key', 'public_key', 'version', 'leeway']);
		return $resolver->resolve($opts);
	}

	/**
	 * Generate JWT authorization header
	 * @return string
	 */
	public function generateAuthHeader()
	{
		$time = time();
		$iss = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'cli';
		$signatureData = [
				'sub' => $this->config['public_key'],
				'iat' => $time - $this->config['leeway'],
				'exp' => $time + $this->config['leeway'],
				'iss' => $iss,
				'nbf' => $time - $this->config['leeway'],
				'jti' => md5($this->config['public_key'] . $time)
		];
		return JWT::encode($signatureData, $this->config['private_key']);
	}

	/**
	 * Make a request to the Billingo API
	 * @param $method
	 * @param $uri
	 * @param array $data
	 * @return mixed|array
	 * @throws JSONParseException
	 * @throws RequestErrorException
	 */
    public function request($method, $uri, $data=[])
    {
        // get the key to use for the query
        if ($method == strtoupper('GET') || $method == strtoupper('DELETE')) {
            $queryKey = 'query';
        } else {
            $queryKey = 'json';
        }

        // make signature
        $response = $this->client->request($method, $uri, [$queryKey => $data, 'headers' => [
            'Authorization' => 'Bearer ' . $this->generateAuthHeader()
        ]]);

        $jsonData = json_decode($response->getBody(), true);

        if ($jsonData == null) {
            throw new JSONParseException('Cannot decode: ' . $response->getBody());
        }

        if ($response->getStatusCode() != 200 || $jsonData['success'] == 0) {
            throw new RequestErrorException('Error: ' . $jsonData['error'], $response->getStatusCode());
        }

        if (array_key_exists('data', $jsonData)) {
            return $jsonData['data'];
        }

        return [];
    }

	/**
	 * GET
	 * @param $uri
	 * @param array $data
	 * @return mixed|ResponseInterface
	 */
	public function get($uri, $data=[])
	{
		return $this->request('GET', $uri, $data);
	}

	/**
	 * POST
	 * @param $uri
	 * @param array $data
	 * @return mixed|ResponseInterface
	 */
	public function post($uri, $data=[])
	{
		return $this->request('POST', $uri, $data);
	}

	/**
	 * PUT
	 * @param $uri
	 * @param array $data
	 * @return mixed|ResponseInterface
	 */
	public function put($uri, $data = [])
	{
		return $this->request('PUT', $uri, $data);
	}


	/**
	 * DELETE
	 * @param $uri
	 * @param array $data
	 * @return mixed|ResponseInterface
	 */
	public function delete($uri, $data = [])
	{
		return $this->request('DELETE', $uri, $data);
	}

    /**
     * Downloads the given invoice
     * @param $id
     * @param null|resource|string $file
     * @return \Psr\Http\Message\StreamInterface|string|null
     */
    public function downloadInvoice($id, $file=null)
    {
        $uri = "invoices/{$id}/download";
        $options = ['headers' => [
            'Authorization' => 'Bearer ' . $this->generateAuthHeader()
        ]];
        if(!is_null($file)) $options['sink'] = $file;
        $response = $this->client->request('GET', $uri, $options);
        return $response instanceof ResponseInterface ? $response->getBody() : null;
	}

    /**
     *	Logger functionality: Creates a log file for each day with all requests and responses
     */
    private function getLogger()
    {
        if (empty($this->logger)) {
            $this->logger = new \Monolog\Logger('api-billingo-consumer');
            $this->logger->pushHandler(
                new \Monolog\Handler\RotatingFileHandler( $this->config['log_dir'] . 'api-billingo-consumer.log')
            );
            if (!empty($this->config['log_loggly_token'])) {
                $this->logger->pushHandler(
                    new \Monolog\Handler\LogglyHandler($this->config['log_loggly_token'],\Monolog\Logger::INFO)
                );
            }
			if (!empty($this->config['log_syslog'])) {
				$this->logger->pushHandler(
					new \Monolog\Handler\SyslogHandler('api-billingo-consumer')
				);
			}
            if (!empty($this->config['log_logdna_key'])) {
                $this->logger->pushHandler(
                    new \Zwijn\Monolog\Handler\LogdnaHandler($this->config['log_logdna_key'], 'api-billingo-consumer')
                );
            }
        }

        return $this->logger;
    }

    private function createGuzzleLoggingMiddleware(string $messageFormat)
    {
        return \GuzzleHttp\Middleware::log(
            $this->getLogger(),
            new \GuzzleHttp\MessageFormatter($messageFormat)
        );
    }

    private function createLoggingHandlerStack(array $messageFormats)
    {
        $stack = \GuzzleHttp\HandlerStack::create();

        collect($messageFormats)->each(function ($messageFormat) use ($stack) {
            // We'll use unshift instead of push, to add the middleware to the bottom of the stack, not the top
            $stack->unshift(
                $this->createGuzzleLoggingMiddleware($messageFormat)
            );
        });

        return $stack;
    }
}
