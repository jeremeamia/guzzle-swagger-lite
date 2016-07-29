# Swagger Lite Guzzle Client

A "lite", Guzzle-based Swagger client that works with JSON services.

It works by reading the operations and parameters from your provided
JSON-formatted Swagger document and doing simple transformations on the provided
input parameters to form the actual request.

## Example Usage

First install the `jl6m/swagger-lite` package via Composer.

```php
require __DIR__ . '/vendor/autoload.php';

use JL6m\SwaggerLite\SwaggerClient;

$client = new SwaggerClient([
    'scheme' => 'https',
    'swagger' => 'http://example.com/service/swagger.json',
    'auth' => ['client_id', 'client_secret'],
]);

$response = $client->post('/users/{userId}/messages', [
    'userId' => '11830955',
    'subject' => 'Hello',
    'content' => 'Hello, it\'s me.',
]);
```

## License

The MIT License (MIT)
Copyright (c) 2016 Jeremy Lindblom

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to use,
copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the
Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
