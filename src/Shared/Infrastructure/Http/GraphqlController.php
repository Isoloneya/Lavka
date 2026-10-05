<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Cart\Application\CartService;
use App\Catalog\Infrastructure\CatalogQuery;
use App\Identity\Infrastructure\Security\User;
use App\Order\Application\OrderService;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use GraphQL\Error\UserError;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class GraphqlController extends AbstractController
{
    public function __construct(#[Autowire('%kernel.project_dir%/config/graphql.graphql')] private readonly string $schemaPath)
    {
    }

    #[Route('/api/graphql', methods: ['POST'])]
    public function query(Request $request, CatalogQuery $catalog, CartService $carts, OrderService $orders): JsonResponse
    {
        $input = Input::fromJson($request->getContent());
        $input->only('query', 'variables', 'operationName');
        $query = $input->text('query', 20000);
        $variables = $input->object('variables');
        $name = null === ($input->data->operationName ?? null) ? null : $input->text('operationName', 100);
        $source = file_get_contents($this->schemaPath);
        if (false === $source) {
            throw new \LogicException('GraphQL schema is unavailable.');
        }
        $schema = BuildSchema::build($source);
        $user = $this->getUser();
        $id = $user instanceof User ? $user->id : null;
        $group = $user instanceof User ? $user->customerGroup : null;
        $staff = $this->isGranted('ROLE_MANAGER');
        $token = $request->headers->get('X-Cart-Token') ?? '';
        $resolver = static function (mixed $root, mixed $args, mixed $context, ResolveInfo $info) use ($catalog, $carts, $orders, $id, $group, $staff, $token): mixed {
            if ('Query' !== $info->parentType->name) {
                return is_object($root) ? ($root->{$info->fieldName} ?? null) : (is_array($root) ? ($root[$info->fieldName] ?? null) : null);
            }
            try {
                $page = $args['page'] ?? 1;
                $size = $args['page_size'] ?? 20;
                if ($page < 1 || $page > 100000 || $size < 1 || $size > 100) {
                    throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректна пагінація.');
                }
                $currency = (new Input((object) ['currency' => $args['currency'] ?? 'UAH']))->currency();

                return match ($info->fieldName) {
                    'categories' => $catalog->categories($page, $size),
                    'products' => $catalog->products($page, $size, $args['q'] ?? '', $args['category'] ?? null, 'created_at', $currency, $group, null, null),
                    'product' => $catalog->product($args['slug']),
                    'cart' => $carts->get($token, $id, $group),
                    'orders' => $orders->listing($id, false, $page, $size),
                    'order' => $orders->get($args['number'], $id, $staff, $token),
                    default => null,
                };
            } catch (ApiProblem $error) {
                throw new UserError($error->errorCode.': '.$error->getMessage());
            }
        };
        $rules = [...DocumentValidator::allRules(), new QueryDepth(8), new QueryComplexity(200)];
        $result = GraphQL::executeQuery($schema, $query, null, null, get_object_vars($variables), $name, $resolver, $rules);

        return $this->json($result->toArray());
    }
}
