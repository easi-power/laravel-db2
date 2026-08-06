<?php

namespace Easi\DB2\Database\Schema;

/**
 * @method $this forColumn(string $column) Specify the underlying system column name for this column (IBM i)
 * @method $this before(string $column) Position this new column before an existing column (IBM i)
 * @method $this startWith(int $value) Set the starting value of an identity column (IBM i)
 * @method $this generated(bool|string $expression = true) Mark the column as a generated column, always or by expression (IBM i)
 * @method $this implicitlyHidden() Hide the column from "SELECT *" (IBM i)
 */
class ColumnDefinition extends \Illuminate\Database\Schema\ColumnDefinition
{
    //
}
