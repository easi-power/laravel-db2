<?php

namespace Easi\DB2\Database\Gateway\Transport;

use RuntimeException;

/**
 * TCP to the gateway on VMS. One socket per PHP request, frames back to back: the gateway is
 * started per connection (TCP/IP Services auxiliary server), and VMS process creation is too
 * slow to pay on every query.
 */
class SocketTransport implements GatewayTransport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeout = 10.0,
    ) {
    }

    public function send(string $frame): string
    {
        $socket = $this->socket ??= $this->connect();

        if (fwrite($socket, $frame) !== strlen($frame)) {
            $this->socket = null;
            throw new RuntimeException("Lost the RMS gateway at {$this->host}:{$this->port} while sending.");
        }

        $length = $this->read($socket, 8);

        return $length.$this->read($socket, (int) $length);
    }

    /** @return resource */
    private function connect()
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $code, $message, $this->timeout);

        if ($socket === false) {
            throw new RuntimeException("Cannot reach the RMS gateway at {$this->host}:{$this->port}: {$message}");
        }

        stream_set_timeout($socket, (int) $this->timeout, (int) (fmod($this->timeout, 1) * 1_000_000));

        return $socket;
    }

    /** @param resource $socket */
    private function read($socket, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = fread($socket, $bytes - strlen($data));

            if ($chunk === false || $chunk === '') {
                $this->socket = null;
                throw new RuntimeException("Lost the RMS gateway at {$this->host}:{$this->port} while reading.");
            }
            $data .= $chunk;
        }

        return $data;
    }
}
