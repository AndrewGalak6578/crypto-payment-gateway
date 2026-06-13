<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

class GenerateOpenApiDocs extends Command
{
    protected $signature = 'docs:generate-openapi {--path=docs/api/openapi.json}';

    protected $description = 'Generate a Swagger-compatible OpenAPI document from Laravel routes';

    public function handle(Filesystem $files): int
    {
        $document = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name', 'Application').' API',
                'version' => (string) env('API_VERSION', '0.0.1'),
            ],
            'servers' => [
                ['url' => rtrim((string) config('app.url'), '/')],
            ],
            'paths' => [],
            'components' => [
                'securitySchemes' => [
                    'MerchantApiKey' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                    'SessionAuth' => [
                        'type' => 'apiKey',
                        'in' => 'cookie',
                        'name' => config('session.cookie', 'laravel_session'),
                    ],
                ],
            ],
        ];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach ($this->httpMethods($route) as $method) {
                $path = '/'.$route->uri();
                $document['paths'][$path][strtolower($method)] = $this->operationFor($route, $method);
            }
        }

        ksort($document['paths']);

        $path = base_path((string) $this->option('path'));
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->info('Generated OpenAPI document at '.str_replace(base_path().'/', '', $path).'.');

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function httpMethods(Route $route): array
    {
        return array_values(array_filter(
            $route->methods(),
            fn (string $method): bool => ! in_array($method, ['HEAD', 'OPTIONS'], true)
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function operationFor(Route $route, string $method): array
    {
        $operation = [
            'tags' => [$this->tagFor($route)],
            'summary' => $this->summaryFor($route),
            'operationId' => $this->operationIdFor($route, $method),
            'parameters' => $this->pathParameters($route),
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $requestBody = $this->requestBodyFor($route);

            if ($requestBody !== null) {
                $operation['requestBody'] = $requestBody;
            }
        }

        $security = $this->securityFor($route);

        if ($security !== []) {
            $operation['security'] = $security;
        }

        return $operation;
    }

    private function tagFor(Route $route): string
    {
        $segments = explode('/', $route->uri());

        return ucfirst($segments[1] ?? 'api');
    }

    private function summaryFor(Route $route): string
    {
        $action = $route->getActionName();

        if ($action === 'Closure') {
            return strtoupper(implode('|', $this->httpMethods($route))).' /'.$route->uri();
        }

        return str_replace('@', '::', class_basename($action));
    }

    private function operationIdFor(Route $route, string $method): string
    {
        $name = $route->getName() ?: strtolower($method).'_'.$route->uri();

        return preg_replace('/[^A-Za-z0-9_]+/', '_', str_replace('/', '_', $name)) ?: uniqid('operation_', false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pathParameters(Route $route): array
    {
        return array_map(
            fn (string $name): array => [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => str_ends_with($name, 'id') || $name === 'id' ? 'integer' : 'string'],
            ],
            $route->parameterNames()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBodyFor(Route $route): ?array
    {
        $requestClass = $this->formRequestClassFor($route);

        if ($requestClass === null) {
            return null;
        }

        $rules = $this->rulesFor($requestClass);

        if ($rules === []) {
            return null;
        }

        return [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => $this->schemaFromRules($rules),
                    ...($this->requestExampleFor($requestClass) !== null ? [
                        'example' => $this->requestExampleFor($requestClass),
                    ] : []),
                ],
            ],
        ];
    }

    /**
     * @param  class-string<FormRequest>  $requestClass
     * @return array<string, mixed>|null
     */
    private function requestExampleFor(string $requestClass): ?array
    {
        if ($requestClass === \App\Http\Requests\CreateInvoiceRequest::class) {
            return [
                'external_id' => 'demo-invoice-001',
                'amount_usd' => 5,
                'coin' => 'ltc',
                'expires_minutes' => 20,
            ];
        }

        return null;
    }

    private function formRequestClassFor(Route $route): ?string
    {
        $action = $route->getAction('uses');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $requestClass = $type->getName();

            if (is_subclass_of($requestClass, FormRequest::class)) {
                return $requestClass;
            }
        }

        return null;
    }

    /**
     * @param  class-string<FormRequest>  $requestClass
     * @return array<string, mixed>
     */
    private function rulesFor(string $requestClass): array
    {
        /** @var FormRequest $request */
        $request = new $requestClass;
        $request->setContainer(app());

        return method_exists($request, 'rules') ? $request->rules() : [];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function schemaFromRules(array $rules): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
        ];
        $required = [];

        foreach ($rules as $field => $fieldRules) {
            $normalizedRules = $this->normalizeRules($fieldRules);
            Arr::set($schema['properties'], str_replace('.', '.properties.', $field), $this->schemaForField($normalizedRules));

            if (in_array('required', $normalizedRules, true)) {
                $required[] = $field;
            }
        }

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return string[]
     */
    private function normalizeRules(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        if (! is_array($rules)) {
            return [(new ReflectionClass($rules))->getShortName()];
        }

        return array_map(
            fn (mixed $rule): string => is_string($rule) ? $rule : (new ReflectionClass($rule))->getShortName(),
            $rules
        );
    }

    /**
     * @param  string[]  $rules
     * @return array<string, mixed>
     */
    private function schemaForField(array $rules): array
    {
        $ruleText = implode('|', $rules);

        $schema = match (true) {
            str_contains($ruleText, 'integer') => ['type' => 'integer'],
            str_contains($ruleText, 'numeric') => ['type' => 'number'],
            str_contains($ruleText, 'boolean') => ['type' => 'boolean'],
            str_contains($ruleText, 'array') => ['type' => 'array', 'items' => ['type' => 'string']],
            default => ['type' => 'string'],
        };

        if (str_contains($ruleText, 'email')) {
            $schema['format'] = 'email';
        }

        if (str_contains($ruleText, 'uuid')) {
            $schema['format'] = 'uuid';
        }

        if (str_contains($ruleText, 'date')) {
            $schema['format'] = 'date-time';
        }

        return $schema;
    }

    /**
     * @return array<int, array<string, array<int, string>>>
     */
    private function securityFor(Route $route): array
    {
        $middleware = $route->gatherMiddleware();

        if (in_array('auth.merchant', $middleware, true)) {
            return [['MerchantApiKey' => []]];
        }

        if (in_array('auth.admin', $middleware, true) || in_array('auth.merchant.portal', $middleware, true)) {
            return [['SessionAuth' => []]];
        }

        return [];
    }
}
