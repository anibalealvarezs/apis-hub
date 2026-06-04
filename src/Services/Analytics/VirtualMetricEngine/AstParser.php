<?php

namespace Services\Analytics\VirtualMetricEngine;

use Services\Analytics\VirtualMetricEngine\Nodes\MetricNode;
use Services\Analytics\VirtualMetricEngine\Nodes\OperatorNode;
use Services\Analytics\VirtualMetricEngine\Nodes\ValueNode;
use InvalidArgumentException;

class AstParser
{
    /**
     * Parses a JSON string or array into an AST Node tree.
     *
     * Example array:
     * [
     *     'type' => 'operator',
     *     'operator' => '+',
     *     'left' => ['type' => 'metric', 'metric' => 'meta.spend'],
     *     'right' => ['type' => 'value', 'value' => 100]
     * ]
     *
     * @param string|array $ast
     * @return AstNodeInterface
     */
    public function parse(string|array $ast): AstNodeInterface
    {
        $data = is_string($ast) ? json_decode($ast, true) : $ast;

        if (!is_array($data)) {
            throw new InvalidArgumentException("Invalid AST format provided.");
        }

        return $this->buildNode($data);
    }

    protected function buildNode(array $data): AstNodeInterface
    {
        $type = $data['type'] ?? null;

        return match ($type) {
            'value' => new ValueNode($data['value']),
            'metric' => new MetricNode($data['metric'], $data['filters'] ?? []),
            'operator' => new OperatorNode(
                $data['operator'],
                $this->buildNode($data['left']),
                $this->buildNode($data['right'])
            ),
            default => throw new InvalidArgumentException("Unknown AST node type: {$type}")
        };
    }
}
