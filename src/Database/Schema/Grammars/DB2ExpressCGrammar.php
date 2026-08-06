<?php

namespace Easi\DB2\Database\Schema\Grammars;

class DB2ExpressCGrammar extends DB2Grammar
{
    /**
     * Compile the query to determine if a given table exists.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileTableExists($schema, $table): string
    {
        return sprintf(
            'select count(*) as "exists" from syspublic.all_tables where table_schema = upper(%s) and table_name = upper(%s)',
            $this->quoteString($schema ?? $this->connection->getDefaultSchema()),
            $this->quoteString($table)
        );
    }

    /**
     * Compile the query to determine the list of columns.
     *
     * @return string
     */
    public function compileColumnExists(): string
    {
        return 'select column_name from syspublic.all_ind_columns where table_schema = upper(?) and table_name = upper(?)';
    }
}
