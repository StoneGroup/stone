<?php
namespace Stone\FastCGI;

use Stone\FastCGI\Exception as FastCGIException;
use Stone\FastCGI\Connection as Connection;

class Protocol
{
    // Socket descriptor
    const FCGI_LISTENSOCK_FILENO = 0;

    // Versions
    const FCGI_VERSION_1         = 1;

    // Records
    const FCGI_BEGIN_REQUEST     = 1;
    const FCGI_ABORT_REQUEST     = 2;
    const FCGI_END_REQUEST       = 3;
    const FCGI_PARAMS            = 4;
    const FCGI_STDIN             = 5;
    const FCGI_STDOUT            = 6;
    const FCGI_STDERR            = 7;
    const FCGI_DATA              = 8;
    const FCGI_GET_VALUES        = 9;

    // Roles
    const FCGI_RESPONDER         = 1;
    const FCGI_AUTHORIZER        = 2;
    const FCGI_FILTER            = 3;

    // Flags
    const FCGI_KEEP_CONNECTION   = 1;

    // Statuses
    const FCGI_REQUEST_COMPLETE  = 0;
    const FCGI_CANT_MPX_CONN     = 1;
    const FCGI_OVERLOADED        = 2;
    const FCGI_UNKNOWN_ROLE      = 3;

    /**
     * @var array
     */
    private $requests;

    private $connection;

    /**
     * @var string
     */
    private $buffer;

    /**
     * @var int
     */
    private $bufferLength;

    public function __construct(Connection $connection)
    {
        $this->buffer = '';
        $this->bufferLength = 0;
        $this->connection = $connection;
    }

    public function readFromString($data)
    {
        $this->buffer .= $data;
        $this->bufferLength += strlen($data);

        while (null !== ($record = $this->readRecord())) {
            $this->processRecord($record);
        }

        return $this->requests;
    }

    public function readRecord()
    {
        // Not enough data to read header
        if ($this->bufferLength < 8) {
            return;
        }

        $headerData = substr($this->buffer, 0, 8);
        $headerFormat = 'Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/x';
        $record = unpack($headerFormat, $headerData);

        // Not enough data to read rest of record
        if ($this->bufferLength - 8 < $record['contentLength'] + $record['paddingLength']) {
            return;
        }

        $record['contentData'] = substr($this->buffer, 8, $record['contentLength']);

        // Remove the record from the buffer
        $recordSize = 8 + $record['contentLength'] + $record['paddingLength'];

        $this->buffer        = substr($this->buffer, $recordSize);
        $this->bufferLength -= $recordSize;

        return $record;
    }

    public function processRecord($record)
    {
        $requestId = $record['requestId'];
        $content = 0 === $record['contentLength'] ? null : $record['contentData'];

        if (self::FCGI_BEGIN_REQUEST === $record['type']) {
            $this->processBeginRequestRecord($requestId, $content);
        } elseif (!isset($this->requests[$requestId])) {
            throw new ProtocolException('Invalid request id for record of type: '.$record['type']);
        } elseif (self::FCGI_PARAMS === $record['type']) {
            while (strlen($content) > 0) {
                $this->readNameValuePair($requestId, $content);
            }
        } elseif (self::FCGI_STDIN === $record['type']) {
            if (null !== $content) {
                fwrite($this->requests[$requestId]['stdin'], $content);
                $this->requests[$requestId]['rawPost'] = $content;
            } else {
                // TODO $this->dispatchRequest($requestId);
                return 1; // One request was dispatched
            }
        } elseif (self::FCGI_ABORT_REQUEST === $record['type']) {
            $this->endRequest($requestId);
        } else {
            throw new ProtocolException('Unexpected packet of type: '.$record['type']);
        }

        return 0; // Zero requests were dispatched
    }

    private function processBeginRequestRecord($requestId, $contentData)
    {
        if (isset($this->requests[$requestId])) {
            throw new ProtocolException('Unexpected FCGI_BEGIN_REQUEST record');
        }
        $contentFormat = 'nrole/Cflags/x5';
        $content = unpack($contentFormat, $contentData);
        $keepAlive = self::FCGI_KEEP_CONNECTION & $content['flags'];
        $this->requests[$requestId] = [
            'keepAlive' => $keepAlive,
            'stdin'     => fopen('php://temp', 'r+'),
            'params'    => [],
        ];
        if (self::FCGI_RESPONDER !== $content['role']) {
            $this->endRequest($requestId, 0, self::FCGI_UNKNOWN_ROLE);
            return;
        }
    }

    private function readNameValuePair($requestId, &$buffer)
    {
        $nameLength  = $this->readFieldLength($buffer);
        $valueLength = $this->readFieldLength($buffer);
        $contentFormat = (
                'a'.$nameLength.'name/'.
                'a'.$valueLength.'value/'
                );
        $content = unpack($contentFormat, $buffer);
        $this->requests[$requestId]['params'][$content['name']] = $content['value'];
        $buffer = substr($buffer, $nameLength + $valueLength);
    }

    private function readFieldLength(&$buffer)
    {
        $block  = unpack('C4', $buffer);
        $length = $block[1];
        $skip   = 1;
        if ($length & 0x80) {
            $fullBlock = unpack('N', $buffer);
            $length    = $fullBlock[1] & 0x7FFFFFFF;
            $skip      = 4;
        }
        $buffer = substr($buffer, $skip);
        return $length;
    }

    private function beginRequest($requestId, $appStatus = 0, $protocolStatus = self::FCGI_BEGIN_REQUEST)
    {
        $c = pack('NC', $appStatus, $protocolStatus) // app status, protocol status
            . "\x00\x00\x00";
        return $this->connection->write(
                "\x01" // protocol version
                . "\x01" // record type (END_REQUEST)
                . pack('nn', $req->id, strlen($c)) // id, content length
                . "\x00" // padding length
                . "\x00" // reserved
                . $c // content
                );

        $content = pack('NCx3', $appStatus, $protocolStatus);
        $this->writeRecord($requestId, self::FCGI_END_REQUEST, $content);
        $keepAlive = $this->requests[$requestId]['keepAlive'];
        //fclose($this->requests[$requestId]['stdin']);
        unset($this->requests[$requestId]);
        if (!$keepAlive) {
            //$this->close();
        }
    }

    private function endRequest($requestId, $appStatus = 0, $protocolStatus = self::FCGI_REQUEST_COMPLETE)
    {
        $content = pack('NCx3', $appStatus, $protocolStatus);
        $this->writeRecord($requestId, self::FCGI_END_REQUEST, $content);
        $keepAlive = $this->requests[$requestId]['keepAlive'];
        //fclose($this->requests[$requestId]['stdin']);
        unset($this->requests[$requestId]);
        if (!$keepAlive) {
            //$this->close();
        }
    }

    private function writeRecord($requestId, $type, $content = null)
    {
        $contentLength = null === $content ? 0 : strlen($content);
        $headerData = pack('CCnnxx', self::FCGI_VERSION_1, $type, $requestId, $contentLength);
        $this->connection->write($headerData);
        if (null !== $content) {
            $this->connection->write($content);
        }
    }

    public function sendDataToClient($requestId, $data, $header = [])
    {
        $dataLength = strlen($data);

        if ($dataLength <= 65535) {
            $this->writeRecord($requestId, self::FCGI_STDOUT, $data);
        } else {
            $start = 0;
            $chunkSize = 8092;
            do {
                $this->writeRecord($requestId, self::FCGI_STDOUT, substr($data, $start, $chunkSize));
                $start += $chunkSize;
            } while($start < $dataLength);
            $this->writeRecord($requestId, self::FCGI_STDOUT);
        }

        $this->endRequest($requestId);
    }
}

