<?php namespace JL6m\SwaggerLite;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Uri;

use function GuzzleHttp\json_decode;
use function GuzzleHttp\uri_template;

/**
 * A "lite", Guzzle-based Swagger client that works with JSON services.
 *
 * It works by reading the operations and parameters from the provided
 * JSON-formatted Swagger document and doing simple transformations on the
 * provided input parameters to form the actual request.
 *
 * @method ResponseInterface get(string $method, string $path, array $input = [])
 * @method ResponseInterface put(string $method, string $path, array $input = [])
 * @method ResponseInterface post(string $method, string $path, array $input = [])
 * @method ResponseInterface head(string $method, string $path, array $input = [])
 * @method ResponseInterface patch(string $method, string $path, array $input = [])
 * @method ResponseInterface delete(string $method, string $path, array $input = [])
 * @method ResponseInterface options(string $method, string $path, array $input = [])
 * @method PromiseInterface getAsync(string $method, string $path, array $input = [])
 * @method PromiseInterface putAsync(string $method, string $path, array $input = [])
 * @method PromiseInterface postAsync(string $method, string $path, array $input = [])
 * @method PromiseInterface headAsync(string $method, string $path, array $input = [])
 * @method PromiseInterface patchAsync(string $method, string $path, array $input = [])
 * @method PromiseInterface deleteAsync(string $method, string $path, array $input = [])
 * @method PromiseInterface optionsAsync(string $method, string $path, array $input = [])
 */
class SwaggerClient
{
    private static $defaults = [
        'swagger' => null,
        'scheme' => null,
        'host' => null,
        'basePath' => null,
    ];

    /** @var array */
    private $swagger;

    /** @var array */
    private $operations = [];

    /** @var HttpClient */
    private $http;

    /**
     * Create a Swagger Guzzle client.
     *
     * Options in addition to regular Guzzle options:
     * - `swagger` (string|callback|array) - Path/URL to Swagger document, a
     *    callback that fetches it, or the decoded data itself.
     * - `scheme` (string) - The scheme to use for requests. Must be in the
     *   allowed list of schemes in the Swagger doc. If there is only one
     *   allowed scheme, then this can be left blank.
     * - `host` (string) - Overwrites the "host" field in the Swagger doc.
     * - `basePath` (string) - Overwrites the "basePath" field in the Swagger doc.
     *
     * @param array $config An associative array of Guzzle options, including
     *     some additional options for the Swagger client as described above.
     * @link http://docs.guzzlephp.org/en/latest/quickstart.html#creating-a-client Guzzle client options
     */
    public function __construct(array $config = [])
    {
        $config += self::$defaults;
        $this->loadSwaggerDocument($config);
        $this->configureBaseUri($config);
        $this->http = new HttpClient($config);
    }

    /**
     * Supports calling the request or operation as a magic method.
     *
     * @param string $name Name of the magic method called.
     * @param array $args Arguments passed.
     * @return ResponseInterface|PromiseInterface
     */
    public function __call($name, array $args)
    {
        static $methods = ['get', 'put', 'post', 'head', 'patch', 'delete', 'options'];

        $asyncSuffix = '';
        if (substr($name, -5) === 'Async') {
            $name = substr($name, 0, -5);
            $asyncSuffix = 'Async';
        }

        if (in_array($name, $methods)) {
            $path = isset($args[0]) ? $args[0] : '/';
            $input = isset($args[1]) ? $args[1] : [];
            return $this->{"request{$asyncSuffix}"}($name, $path, $input);
        } else {
            $input = isset($args[0]) ? $args[0] : [];
            return $this->{"execute{$asyncSuffix}"}($name, $input);
        }
    }

    /**
     * Execute an operation identified by an operationId.
     *
     * @param string $operation Operation's "operationId" in the Swagger doc.
     * @param array $input Input parameters to the operation.
     * @return ResponseInterface
     */
    public function execute($operation, array $input = [])
    {
        return $this->executeAsync($operation, $input)->wait();
    }

    /**
     * Asynchronously execute an operation identified by an operationId.
     *
     * @param string $operation Operation's "operationId" in the Swagger doc.
     * @param array $input Input parameters to the operation.
     * @return PromiseInterface
     */
    public function executeAsync($operation, array $input = [])
    {
        list($path, $method) = $this->findPathForOperation($operation);

        return $this->requestAsync($method, $path, $input);
    }

    /**
     * Issue a request to the specified path and perform the specified action.
     *
     * @param string $method HTTP method for the action.
     * @param string $path Path of the resource.
     * @param array $input Input parameters to the request.
     * @return ResponseInterface
     */
    public function request($method, $path, array $input = [])
    {
        return $this->requestAsync($method, $path, $input)->wait();
    }

    /**
     * Asynchronously issue a request to the specified path and perform the specified action.
     *
     * @param string $method HTTP method for the action.
     * @param string $path Path of the resource.
     * @param array $input Input parameters to the request.
     * @return PromiseInterface
     */
    public function requestAsync($method, $path, array $input = [])
    {
        // Normalize the path and method.
        $method = strtolower($method);
        $path = '/' . ltrim($path, '/');

        // Collect any explicit HTTP options.
        $requestOptions = isset($input['@http']) ? $input['@http'] : [];
        unset($input['@http']);

        // Get the operation information.
        $operation = $this->searchDoc(['paths', $path, $method]);
        if (!$operation) {
            throw new \RuntimeException("No {$method} operation for path {$path}");
        }

        // Build full list of parameters.
        $parameters = [];
        if ($pathParams = $this->searchDoc(['paths', $path, 'parameters'])) {
            foreach ($pathParams as $param) {
                if (isset($param['$ref'])) {
                    $param += $this->resolveRef($param['$ref']);
                }
                $parameters[$param['name']] = $param;
            }
        }
        if (isset($operation['parameters'])) {
            foreach ($operation['parameters'] as $param) {
                $parameters[$param['name']] = $param;
            }
        }

        // Apply parameters to the request.
        $this->applyParams($requestOptions, $parameters, $input);
        if (isset($requestOptions['path'])) {
            $path = uri_template($path, $requestOptions['path']);
            unset($requestOptions['path']);
        }

        // Make the request.
        return $this->http->requestAsync($method, ltrim($path, '/'), $requestOptions);
    }

    private function loadSwaggerDocument(array &$config)
    {
        // Load the Swagger document.
        if (is_string($config['swagger'])) {
            $this->swagger = json_decode(file_get_contents($config['swagger']), true);
        } elseif (is_callable($config['swagger'])) {
            $this->swagger = $config['swagger']();
        } elseif (is_array($config['swagger'])) {
            $this->swagger = $config['swagger'];
        } else {
            throw new \RuntimeException('Cannot load Swagger document');
        }

        // Remove the Swagger setting from config. No need to pass to client.
        unset($config['swagger']);

        // Make sure the Swagger document has the required sections.
        if (!isset($this->swagger['swagger'], $this->swagger['info'], $this->swagger['paths'])) {
            throw new \InvalidArgumentException('Swagger document is missing required sections.');
        }

        // Set missing data in the Swagger document using config.
        if (!isset($this->swagger['schemes'])) {
            $this->swagger['schemes'] = ['https'];
        }
        if (!isset($this->swagger['host']) || $config['host']) {
            $this->swagger['host'] = $config['host'];
        }
        if (!isset($this->swagger['basePath']) || $config['basePath']) {
            $this->swagger['basePath'] = $config['basePath'];
        }
    }

    private function configureBaseUri(array &$config)
    {
        // Get the scheme from the config, and then remove it.
        $scheme = $config['scheme'];
        unset($config['scheme']);

        // If the base URI is explicitly provided, don't override it.
        if (isset($config['base_uri'])) {
            return;
        }

        // Make sure the scheme is valid.
        if (!$scheme) {
            if (count($this->swagger['schemes']) === 1) {
                $scheme = $this->swagger['schemes'][0];
            } else {
                throw new \InvalidArgumentException(
                    'Must specify a scheme. Choose one of: ' . implode(', ', $this->swagger['schemes'])
                );
            }
        } elseif (!in_array($scheme, $this->swagger['schemes'])) {
            throw new \InvalidArgumentException("Provided scheme {$scheme} is not allowed");
        }

        // Determine the host.
        $host = $this->swagger['host'];
        if (!$host) {
            throw new \RuntimeException(
                "The host was not set in the Swagger document. Please specify a host"
            );
        }

        // Determine the base path.
        $path = $this->swagger['basePath'];
        if ($path) {
            $path = rtrim($path, '/') . '/';
        }

        // Set the base_uri config option for Guzzle.
        $config['base_uri'] = (string) Uri::fromParts(compact('scheme', 'host', 'path'));
    }

    private function findPathForOperation($name)
    {
        if (isset($this->operations[$name])) {
            return $this->operations[$name];
        }

        foreach ($this->swagger['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (isset($operation['operationId']) && $operation['operationId'] === $name) {
                    return $this->operations[$name] = [$path, $method];
                }
            }
        }

        throw new \RuntimeException("Cannot find the {$name} operation");
    }

    private function applyParams(array &$options, array &$parameters, array &$input)
    {
        foreach ($parameters as $param) {
            if (isset($param['$ref'])) {
                $param = $this->resolveRef($param['$ref']) + $param;
            }
            $name = $param['name'];
            if (isset($input[$name])) {
                $value = $input[$name];
                switch ($param['in']) {
                    case 'body': $options['json'] = $value; break;
                    case 'query': $options['query'][$name] = $value; break;
                    case 'header': $options['headers'][$name] = $value; break;
                    case 'path': $options['path'][$name] = $value; break;
                    case 'formData': $options['form_params'][$name] = $value; break;
                    default: throw new \RuntimeException(
                        "Unrecognized \"in\" value \"{$param['in']}\" for the \"{$name}\" parameter"
                    );
                }
            } elseif (isset($param['required']) && $param['required']) {
                throw new \InvalidArgumentException("Input not provided for required parameter {$param['name']}");
            }
        }
    }

    private function resolveRef($ref)
    {
        list($uri, $path) = explode('#', $ref, 2);
        if ($uri) {
            throw new \RuntimeException('This Swagger client does not resolve refs in other Swagger documents.');
        }

        $result = $this->searchDoc(explode('/', ltrim($path, '/')));
        if ($result === null) {
            throw new \RuntimeException("Could not resolve ref {$ref} in this Swagger document.");
        }

        return $result;
    }

    private function searchDoc(array $segments)
    {
        $data =& $this->swagger;
        foreach ($segments as $segment) {
            if (array_key_exists($segment, $data)) {
                $data =& $data[$segment];
            } else {
                return null;
            }
        }
        return $data;
    }
}
