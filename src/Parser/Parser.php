<?php
namespace Icicle\Http\Parser;

use Icicle\Http\Exception\InvalidArgumentException;
use Icicle\Http\Exception\LogicException;
use Icicle\Http\Exception\MessageException;
use Icicle\Http\Exception\MessageHeaderSizeException;
use Icicle\Http\Exception\MissingHostException;
use Icicle\Http\Exception\ParseException;
use Icicle\Http\Message\Request;
use Icicle\Http\Message\Response;
use Icicle\Http\Message\Uri;
use Icicle\Socket\Client\ClientInterface;
use Icicle\Stream\ReadableStreamInterface;
use Icicle\Stream\Sink;

class Parser implements ParserInterface
{
    /**
     * @inheritdoc
     */
    public function readMessage(ClientInterface $client, $maxSize, $timeout = null)
    {
        $data = '';

        do {
            $data .= (yield $client->read(null, "\n", $timeout));

            if (strlen($data) > $maxSize) {
                throw new MessageHeaderSizeException(
                    sprintf('Message header exceeded maximum size of %d bytes.', $maxSize)
                );
            }
        } while (!preg_match("/\r?\n\r?\n$/", $data));

        yield $data;
    }

    /**
     * @inheritdoc
     */
    public function parseResponse($message, ReadableStreamInterface $stream = null)
    {
        list($startLine, $headers, $body) = $this->parseMessage($message);

        if (null === $stream) {
            $stream = new Sink();
            $stream->write($body);
        } elseif ('' !== $body) {
            throw new LogicException('Body portion in message when stream provided for body.');
        }

        if (!preg_match('/^HTTP\/(\d+(?:\.\d+)?) (\d{3})(?: (.+))?$/i', $startLine, $matches)) {
            throw new ParseException('Could not parse start line.');
        }

        $protocol = $matches[1];
        $code = (int) $matches[2];
        $reason = isset($matches[3]) ? $matches[3] : null;

        $headers = $this->parseHeaders($headers);

        return new Response($code, $headers, $stream, $reason, $protocol);
    }

    /**
     * @inheritdoc
     */
    public function parseRequest($message, ReadableStreamInterface $stream = null)
    {
        list($startLine, $headers, $body) = $this->parseMessage($message);

        if (null === $stream) {
            $stream = new Sink();
            $stream->write($body);
        } elseif ('' !== $body) {
            throw new LogicException('Body portion in message when stream provided for body.');
        }

        if (!preg_match('/^([A-Z]+) (\S+) HTTP\/(\d+(?:\.\d+)?)$/i', $startLine, $matches)) {
            throw new ParseException('Could not parse start line.');
        }

        $method = $matches[1];
        $target = $matches[2];
        $protocol = $matches[3];

        $headers = $this->parseHeaders($headers);

        if ('/' === $target[0]) { // origin-form
            $uri = new Uri($this->filterHost($this->findHost($headers)) . $target);
            $target = null; // null $target since it was a path.
        } elseif ('*' === $target) { // asterisk-form
            $uri = new Uri($this->filterHost($this->findHost($headers)));
        } elseif (preg_match('/^https?:\/\//i', $target)) { // absolute-form
            $uri = new Uri($target);
        } else { // authority-form
            $uri = new Uri($this->filterHost($target));
        }

        return new Request($method, $uri, $headers, $stream, $target, $protocol);
    }

    /**
     * @param   string $message
     *
     * @return  array
     */
    protected function parseMessage($message)
    {
        list($header, $body) = $this->splitMessage($message);

        if (empty($header)) {
            throw new ParseException('No header found in message.');
        }

        $headers = $this->splitHeader($header);
        $startLine = trim(array_shift($headers));

        return [$startLine, $headers, $body];
    }

    /**
     * @param   string[] $lines
     *
     * @return  string[][]
     */
    protected function parseHeaders(array $lines)
    {
        $headers = [];

        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);

            if (2 !== count($parts)) {
                throw new ParseException('Found header without value.');
            }

            list($name, $value) = $parts;
            $value = trim($value);

            // No check for case as Message class will automatically combine similarly named headers.
            if (!isset($headers[$name])) {
                $headers[$name] = [$value];
            } else {
                $headers[$name][] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param   string $header
     *
     * @return  string[]
     */
    protected function splitHeader($header)
    {
        return preg_split("/\r?\n/", $header, null, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * @param   string $message
     *
     * @return  string[]
     */
    protected function splitMessage($message)
    {
        $parts = preg_split("/\r?\n\r?\n/", $message, 2);

        if (!isset($parts[1])) {
            throw new ParseException('Header/body boundary not found.');
        }

        return $parts;
    }

    /**
     * @param   string $host
     *
     * @return  string
     */
    protected function filterHost($host)
    {
        if (strrpos($host, ':', -1)) {
            return $host;
        }

        return '//' . $host;
    }

    /**
     * @param   string[][] $headers
     *
     * @return  string
     *
     * @throws  \Icicle\Http\Exception\MissingHostException If no host header is find.
     */
    protected function findHost(array $headers)
    {
        foreach ($headers as $name => $values) {
            if (strtolower($name) === 'host') {
                return implode(',', $values);
            }
        }

        throw new MissingHostException('No host header in message.');
    }
}
