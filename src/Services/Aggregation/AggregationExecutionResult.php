<?php

    declare(strict_types=1);

    namespace Services\Aggregation;

    final readonly class AggregationExecutionResult
    {
        /**
         * @param array<int, array<string, mixed>> $rows
         * @param array<string, mixed> $meta
         */
        public function __construct(
            private array $rows,
            private array $meta = [],
        )
        {
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public function getRows(): array
        {
            return $this->rows;
        }

        /**
         * @return array<string, mixed>
         */
        public function getMeta(): array
        {
            return $this->meta;
        }
    }

