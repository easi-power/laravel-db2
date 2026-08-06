<?php

namespace Easi\DB2\Exceptions;

use Illuminate\Database\Query\Grammars\Grammar;
use Throwable;

trait TranslatesQueryMessage
{
    protected array $config;
    protected Grammar $grammar;

    public function __construct($connectionName, $sql, array $bindings, Throwable $previous, Grammar $grammar, array $config = [])
    {
        $this->config = $config;
        $this->grammar = $grammar;
        parent::__construct($connectionName, $sql, $bindings, $previous);
    }

    protected function formatMessage($connectionName, $sql, $bindings, Throwable $previous): string
    {
        if (isset($this->config['from_encoding']) && $this->config['from_encoding'] && !is_null($previous->getMessage())) {
            $previousMessage = iconv($this->config['from_encoding'], 'utf-8', $previous->getMessage());
        } else {
            $previousMessage = $previous->getMessage();
        }

        $displaySql = $this->grammar->substituteBindingsIntoRawSql($sql, $bindings);

        return $previousMessage.' (Connection: '.$connectionName.', SQL: '.$displaySql.')';
    }
}
