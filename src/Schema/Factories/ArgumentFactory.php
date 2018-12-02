<?php

namespace Nuwave\Lighthouse\Schema\Factories;

use GraphQL\Utils\AST;
use Nuwave\Lighthouse\Support\Pipeline;
use Nuwave\Lighthouse\Schema\DirectiveRegistry;
use Nuwave\Lighthouse\Schema\Values\ArgumentValue;

class ArgumentFactory
{
    /**
     * @var DirectiveRegistry
     */
    protected $directiveRegistry;

    /**
     * @var Pipeline
     */
    protected $pipeline;

    /**
     * ArgumentFactory constructor.
     *
     * @param DirectiveRegistry $directiveRegistry
     * @param Pipeline          $pipeline
     */
    public function __construct(DirectiveRegistry $directiveRegistry, Pipeline $pipeline)
    {
        $this->directiveRegistry = $directiveRegistry;
        $this->pipeline = $pipeline;
    }

    /**
     * Convert argument definition to type.
     *
     * @param ArgumentValue $argumentValue
     *
     * @throws \Exception
     *
     * @return array
     */
    public function handle(ArgumentValue $argumentValue): array
    {
        $definition = $argumentValue->getAstNode();

        $fieldArgument = [
            'name' => $argumentValue->getName(),
            'description' => data_get($definition->description, 'value'),
            'type' => $argumentValue->getType(),
            'astNode' => $definition,
        ];

        if ($defaultValue = $definition->defaultValue) {
            $fieldArgument += [
                'defaultValue' => AST::valueFromASTUntyped($defaultValue),
            ];
        }

        // Add any dynamically declared public properties of the FieldArgument
        $fieldArgument += get_object_vars($argumentValue);

        // Used to construct a FieldArgument class
        return $fieldArgument;
    }
}
