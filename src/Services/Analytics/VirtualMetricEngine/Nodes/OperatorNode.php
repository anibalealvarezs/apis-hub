<?php

namespace Services\Analytics\VirtualMetricEngine\Nodes;

use Services\Analytics\VirtualMetricEngine\AstNodeInterface;
use Services\Analytics\VirtualMetricEngine\EvaluationContext;
use InvalidArgumentException;

class OperatorNode implements AstNodeInterface
{
    public function __construct(
        protected string $operator,
        protected AstNodeInterface $left,
        protected AstNodeInterface $right
    ) {
        if (!in_array($operator, ['+', '-', '*', '/'])) {
            throw new InvalidArgumentException("Unsupported operator: {$operator}");
        }
    }

    public function getLeft(): AstNodeInterface
    {
        return $this->left;
    }

    public function getRight(): AstNodeInterface
    {
        return $this->right;
    }

    public function evaluate(EvaluationContext $context): float|int|array
    {
        $leftVal = $this->left->evaluate($context);
        $rightVal = $this->right->evaluate($context);

        if (is_array($leftVal) && is_array($rightVal)) {
            return $this->operateArrays($leftVal, $rightVal);
        }

        if (is_array($leftVal) && is_numeric($rightVal)) {
            return $this->operateArrayScalar($leftVal, $rightVal, false);
        }

        if (is_numeric($leftVal) && is_array($rightVal)) {
            return $this->operateArrayScalar($rightVal, $leftVal, true);
        }

        return $this->operateScalars($leftVal, $rightVal);
    }

    protected function operateArrays(array $left, array $right): array
    {
        $result = [];
        $dates = array_unique(array_merge(array_keys($left), array_keys($right)));

        foreach ($dates as $date) {
            $l = $left[$date] ?? 0;
            $r = $right[$date] ?? 0;
            $result[$date] = $this->operateScalars($l, $r);
        }

        ksort($result);
        return $result;
    }

    protected function operateArrayScalar(array $arr, float|int $scalar, bool $scalarIsLeft): array
    {
        $result = [];
        foreach ($arr as $date => $val) {
            $l = $scalarIsLeft ? $scalar : $val;
            $r = $scalarIsLeft ? $val : $scalar;
            $result[$date] = $this->operateScalars($l, $r);
        }
        return $result;
    }

    protected function operateScalars(float|int $left, float|int $right): float|int
    {
        return match ($this->operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $right == 0 ? 0 : $left / $right,
            default => 0,
        };
    }

    public function getMetrics(): array
    {
        return array_values(array_unique(array_merge(
            $this->left->getMetrics(),
            $this->right->getMetrics()
        )));
    }
}
