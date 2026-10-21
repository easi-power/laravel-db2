<?php

namespace Easi\DB2\Database\Gateway\Transport;

interface GatewayTransport
{
    /** Send one request frame and return the gateway's response frame. */
    public function send(string $frame): string;
}
