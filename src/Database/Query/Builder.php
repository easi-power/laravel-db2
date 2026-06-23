<?php

namespace Easi\DB2\Database\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;

/**
 * Class Builder
 *
 * @package Easi\DB2\Database\Query
 */
class Builder extends BaseBuilder
{
    /**
     * The common table expressions for the query, keyed by name.
     *
     * @var array<string, BaseBuilder>
     */
    public array $expressions = [];

    /**
     * Add a common table expression (CTE) to the query.
     *
     * @param string $name
     * @param BaseBuilder $subquery
     * @return $this
     */
    public function withExpression(string $name, BaseBuilder $subquery): static
    {
        if (!array_key_exists('expressions', $this->bindings)) {
            $this->bindings = array_merge(['expressions' => []], $this->bindings);
        }

        $this->expressions[$name] = $subquery;

        foreach ($subquery->getBindings() as $binding) {
            $this->bindings['expressions'][] = $binding;
        }

        return $this;
    }
}
