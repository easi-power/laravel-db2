<?php

namespace Easi\DB2\Database\Gateway;

use LogicException;

/** A query shape the RMS gateway cannot execute: joins, grouping, raw SQL, locks, ... */
class UnsupportedGatewayQuery extends LogicException
{
}
