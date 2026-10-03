<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;

#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class ApiDocumentation implements OpenApiFactoryInterface
{
    public function __construct(
        private OpenApiFactoryInterface $inner,
        private RouterInterface $router,
        #[Autowire('%kernel.project_dir%/docs/api-examples.json')] private string $examplesPath,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $api = ($this->inner)($context);
        $schemes = $api->getComponents()->getSecuritySchemes() ?? new \ArrayObject();
        $schemes['bearerAuth'] = ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'];
        $api = $api->withComponents($api->getComponents()->withSecuritySchemes($schemes));
        $json = file_get_contents($this->examplesPath);
        $examples = false === $json ? new \stdClass() : json_decode($json, false, 64, JSON_THROW_ON_ERROR);

        foreach ($this->router->getRouteCollection() as $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/v1/') && '/health' !== $path) {
                continue;
            }
            $item = $api->getPaths()->getPath($path) ?? new PathItem();
            $parameters = [];
            preg_match_all('/\\{([^}]+)\\}/', $path, $matches);
            foreach ($matches[1] as $name) {
                $parameters[] = new Parameter($name, 'path', required: true, schema: ['type' => 'string']);
            }
            foreach ($route->getMethods() as $method) {
                $protected = str_contains($path, '/admin/') || '/api/v1/me' === $path;
                $body = null;
                if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                    $example = $examples->{$method.' '.$path} ?? new \stdClass();
                    $body = new RequestBody(content: new \ArrayObject(['application/json' => ['schema' => ['type' => 'object'], 'example' => $example]]), required: true);
                }
                $status = 'DELETE' === $method ? '204' : ('POST' === $method && !str_ends_with($path, '/login') && !str_ends_with($path, '/calculate') && !str_ends_with($path, '/preview') ? '201' : '200');
                $operation = new Operation(
                    operationId: strtolower($method).'_'.preg_replace('/[^a-zA-Z0-9]+/', '_', $path),
                    tags: [str_contains($path, '/admin/') ? 'Administration' : 'Public'],
                    responses: [$status => new Response('Успішна відповідь'), 'default' => new Response('Помилка application/problem+json із полями code, detail, violations')],
                    summary: $method.' '.$path,
                    parameters: $parameters,
                    requestBody: $body,
                    security: $protected ? [['bearerAuth' => []]] : [],
                );
                $item = match ($method) {
                    'GET' => $item->withGet($operation),
                    'POST' => $item->withPost($operation),
                    'PATCH' => $item->withPatch($operation),
                    'PUT' => $item->withPut($operation),
                    'DELETE' => $item->withDelete($operation),
                    default => $item,
                };
            }
            $api->getPaths()->addPath($path, $item);
        }

        return $api;
    }
}
