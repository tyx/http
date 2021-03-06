<?php
namespace Icicle\Http\Driver\Reader;

use Icicle\Http\Exception\{MessageException, ParseException};
use Icicle\Http\Message;
use Icicle\Http\Message\{BasicRequest, BasicResponse, BasicUri, Response};
use Icicle\Socket\Socket;
use Icicle\Stream;
use Icicle\Stream\Structures\Buffer;

class Http1Reader
{
    const DEFAULT_MAX_SIZE = 0x4000; // 16 kB
    const DEFAULT_START_LINE_LENGTH = 0x400; // 1 kB

    /**
     * @var int
     */
    private $maxStartLineLength = self::DEFAULT_START_LINE_LENGTH;

    /**
     * @var int
     */
    private $maxSize = self::DEFAULT_MAX_SIZE;

    /**
     * @param mixed[] $options
     */
    public function __construct(array $options = [])
    {
        $this->maxSize = isset($options['max_header_size'])
            ? (int) $options['max_header_size']
            : self::DEFAULT_MAX_SIZE;

        $this->maxStartLineLength = isset($options['max_start_line_length'])
            ? (int) $options['max_start_line_length']
            : self::DEFAULT_START_LINE_LENGTH;
    }

    /**
     * {@inheritdoc}
     */
    public function readResponse(Socket $socket, float $timeout = 0): \Generator
    {
        $buffer = new Buffer();

        try {
            do {
                $buffer->push(yield from $socket->read(0, null, $timeout));
            } while (false === ($position = $buffer->search("\r\n")) && $buffer->getLength() < $this->maxStartLineLength);

            if (false === $position) {
                throw new MessageException(
                    Response::REQUEST_HEADER_TOO_LARGE,
                    sprintf('Message start line exceeded maximum size of %d bytes.', $this->maxStartLineLength)
                );
            }

            $line = $buffer->shift($position + 2);

            if (!preg_match("/^HTTP\/(\d+(?:\.\d+)?) (\d{3})(?: (.+))?\r\n$/i", $line, $matches)) {
                throw new ParseException('Could not parse start line.');
            }

            $protocol = $matches[1];
            $code = (int) $matches[2];
            $reason = isset($matches[3]) ? $matches[3] : '';

            $headers = yield from $this->readHeaders($buffer, $socket, $timeout);
        } finally {
            $socket->unshift((string) $buffer);
        }

        return new BasicResponse($code, $headers, $socket, $reason, $protocol);
    }

    /**
     * {@inheritdoc}
     */
    public function readRequest(Socket $socket, float $timeout = 0): \Generator
    {
        $buffer = new Buffer();

        try {
            do {
                $buffer->push(yield from $socket->read(0, null, $timeout));
            } while (false === ($position = $buffer->search("\r\n")) && $buffer->getLength() < $this->maxStartLineLength);

            if (false === $position) {
                throw new MessageException(
                    Response::REQUEST_HEADER_TOO_LARGE,
                    sprintf('Message start line exceeded maximum size of %d bytes.', $this->maxStartLineLength)
                );
            }

            $line = $buffer->shift($position + 2);

            if (!preg_match("/^([A-Z]+) (\S+) HTTP\/(\d+(?:\.\d+)?)\r\n$/i", $line, $matches)) {
                throw new ParseException('Could not parse start line.');
            }

            $method = $matches[1];
            $target = $matches[2];
            $protocol = $matches[3];

            $headers = yield from $this->readHeaders($buffer, $socket, $timeout);
        } finally {
            $socket->unshift((string) $buffer);
        }

        if ('/' === $target[0]) { // origin-form
            $uri = new BasicUri($this->filterHost($this->findHost($headers)) . $target);
            $target = null; // Empty request target since it was a path.
        } elseif ('*' === $target) { // asterisk-form
            $uri = new BasicUri($this->filterHost($this->findHost($headers)));
        } elseif (preg_match('/^[A-Za-z0-9]+:\/\//', $target)) { // absolute-form
            $uri = new BasicUri($target);
        } else { // authority-form
            $uri = new BasicUri($this->filterHost($target));
        }

        return new BasicRequest($method, $uri, $headers, $socket, $target, $protocol);
    }

    /**
     * @param \Icicle\Stream\Structures\Buffer $buffer
     * @param \Icicle\Socket\Socket $socket
     * @param float|int $timeout
     *
     * @return \Generator
     *
     * @throws \Icicle\Http\Exception\MessageException
     * @throws \Icicle\Http\Exception\ParseException
     */
    protected function readHeaders(Buffer $buffer, Socket $socket, float $timeout = 0): \Generator
    {
        $size = 0;
        $headers = [];

        do {
            while (false === ($position = $buffer->search("\r\n"))) {
                if ($buffer->getLength() >= $this->maxSize) {
                    throw new MessageException(
                        Response::REQUEST_HEADER_TOO_LARGE,
                        sprintf('Message header exceeded maximum size of %d bytes.', $this->maxSize)
                    );
                }

                $buffer->push(yield from $socket->read(0, null, $timeout));
            }

            $length = $position + 2;
            $line = $buffer->shift($length);

            if (2 === $length) {
                return $headers;
            }

            $size += $length;

            $parts = explode(':', $line, 2);

            if (2 !== count($parts)) {
                throw new ParseException('Found header without colon.');
            }

            list($name, $value) = $parts;
            $name = Message\decode($name);
            $value = Message\decode(trim($value));

            // No check for case as Message class will automatically combine similarly named headers.
            if (!isset($headers[$name])) {
                $headers[$name] = [$value];
            } else {
                $headers[$name][] = $value;
            }
        } while ($size < $this->maxSize);

        throw new MessageException(
            Response::REQUEST_HEADER_TOO_LARGE,
            sprintf('Message header exceeded maximum size of %d bytes.', $this->maxSize)
        );
    }

    /**
     * @param string $host
     *
     * @return string
     */
    protected function filterHost(string $host): string
    {
        if (strrpos($host, ':', -1)) {
            return $host;
        }

        return '//' . $host;
    }

    /**
     * @param string[][] $headers
     *
     * @return string
     *
     * @throws \Icicle\Http\Exception\MessageException If no host header is find.
     */
    protected function findHost(array $headers): string
    {
        foreach ($headers as $name => $values) {
            if (strtolower($name) === 'host') {
                return $values[0];
            }
        }

        throw new MessageException(Response::BAD_REQUEST, 'No host header in message.');
    }
}
