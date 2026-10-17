<?php
/**
 * GameLand GoldSource RCON & Info Client (Counter-Strike 1.6 / HLDS / ReHLDS)
 * Clean, lightweight, zero-dependency UDP Socket Implementation
 * Features resilient loopback fallback for local server management
 */

class GoldSourceRcon {
    private $host;
    private $port;
    private $password;
    private $timeout = 2.0;

    public function __construct($host, $port, $password, $timeout = 2.0) {
        $this->host = $this->normalizeHost($host);
        $this->port = (int)$port;
        $this->password = (string)$password;
        $this->timeout = (float)$timeout;
    }

    private function normalizeHost($host) {
        $h = trim((string)$host);
        if ($h === '' || $h === '0.0.0.0' || $h === 'localhost') {
            return '127.0.0.1';
        }
        return $h;
    }

    private function connectTo($targetHost) {
        $sock = @fsockopen("udp://" . $targetHost, $this->port, $errno, $errstr, $this->timeout);
        if (!$sock) {
            throw new Exception("Unable to connect to UDP ($targetHost:{$this->port}): $errstr ($errno)");
        }
        stream_set_timeout($sock, (int)$this->timeout, (int)(($this->timeout - (int)$this->timeout) * 1000000));
        return $sock;
    }

    private function readPacketsFrom($sock) {
        $response = '';
        while ($sock && !feof($sock)) {
            $packet = @fread($sock, 4096);
            if ($packet === false || strlen($packet) === 0) {
                break;
            }
            
            // Check for GoldSource header 0xFFFFFFFF
            if (substr($packet, 0, 4) === "\xFF\xFF\xFF\xFF") {
                $response .= substr($packet, 4);
            } else {
                $response .= $packet;
            }
            
            // Short wait for multi-packet GoldSource responses
            $info = stream_get_meta_data($sock);
            if ($info['unread_bytes'] == 0) {
                usleep(25000); // 25ms
                $info = stream_get_meta_data($sock);
                if ($info['unread_bytes'] == 0) {
                    break;
                }
            }
        }
        return $response;
    }

    private function sendAndReceive($targetHost, $payload) {
        try {
            $sock = $this->connectTo($targetHost);
            @fwrite($sock, "\xFF\xFF\xFF\xFF" . $payload);
            $res = $this->readPacketsFrom($sock);
            @fclose($sock);
            return $res;
        } catch (Exception $e) {
            return '';
        }
    }

    public function getChallenge() {
        // Query current host
        $res = $this->sendAndReceive($this->host, "challenge rcon\n");
        if (preg_match('/challenge rcon (\d+)/i', $res, $matches)) {
            return $matches[1];
        }

        // If target host is not loopback and didn't respond, try loopback fallback
        if ($this->host !== '127.0.0.1') {
            $resLoop = $this->sendAndReceive('127.0.0.1', "challenge rcon\n");
            if (preg_match('/challenge rcon (\d+)/i', $resLoop, $matches)) {
                $this->host = '127.0.0.1'; // switch host permanently
                return $matches[1];
            }
        }
        return null;
    }

    public function execute($command) {
        $challenge = $this->getChallenge();
        if (!$challenge) {
            $challenge = "0";
        }

        $payload = sprintf('rcon %s "%s" %s' . "\n", $challenge, $this->password, $command);
        $res = $this->sendAndReceive($this->host, $payload);

        // Fallback to 127.0.0.1 if host timed out or gave empty response
        if (empty($res) && $this->host !== '127.0.0.1') {
            $res = $this->sendAndReceive('127.0.0.1', $payload);
            if (!empty($res)) {
                $this->host = '127.0.0.1';
            }
        }

        // Strip leading command echo or 'l' letter GoldSource response marker
        if (substr($res, 0, 1) === 'l') {
            $res = substr($res, 1);
        }

        return trim($res);
    }

    /**
     * Fetch A2S_INFO (Server Name, Map, Players, Max Players, Ping)
     */
    public function getInfo() {
        $startTime = microtime(true);
        $payload = "TSource Engine Query\x00";
        $res = $this->sendAndReceive($this->host, $payload);
        if (empty($res) && $this->host !== '127.0.0.1') {
            $res = $this->sendAndReceive('127.0.0.1', $payload);
            if (!empty($res)) {
                $this->host = '127.0.0.1';
            }
        }

        $ping = round((microtime(true) - $startTime) * 1000);

        if (empty($res)) {
            return false;
        }

        // Parse response
        // Usually starts with 'I' (0x49) or 'm' (0x6D old GoldSource)
        $header = substr($res, 0, 1);
        $data = substr($res, 1);

        if ($header === 'I') {
            // New Protocol
            $pos = 1; // skip protocol byte
            $serverName = $this->readNullString($data, $pos);
            $map = $this->readNullString($data, $pos);
            $folder = $this->readNullString($data, $pos);
            $game = $this->readNullString($data, $pos);
            $appId = (ord($data[$pos]) | (ord($data[$pos+1]) << 8));
            $pos += 2;
            $numPlayers = ord($data[$pos++]);
            $maxPlayers = ord($data[$pos++]);
            $numBots = ord($data[$pos++]);

            return [
                'online' => true,
                'hostname' => $serverName,
                'map' => $map,
                'players' => $numPlayers,
                'maxplayers' => $maxPlayers,
                'bots' => $numBots,
                'ping' => $ping,
            ];
        } elseif ($header === 'm') {
            // Old GoldSource protocol
            $pos = 0;
            $netAddress = $this->readNullString($data, $pos);
            $serverName = $this->readNullString($data, $pos);
            $map = $this->readNullString($data, $pos);
            $gameDir = $this->readNullString($data, $pos);
            $gameDesc = $this->readNullString($data, $pos);
            $numPlayers = ord($data[$pos++]);
            $maxPlayers = ord($data[$pos++]);

            return [
                'online' => true,
                'hostname' => $serverName,
                'map' => $map,
                'players' => $numPlayers,
                'maxplayers' => $maxPlayers,
                'bots' => 0,
                'ping' => $ping,
            ];
        }

        return [
            'online' => true,
            'hostname' => 'GameLand Server',
            'map' => 'unknown',
            'players' => 0,
            'maxplayers' => 0,
            'bots' => 0,
            'ping' => $ping,
        ];
    }

    private function readNullString($data, &$pos) {
        $nullPos = strpos($data, "\x00", $pos);
        if ($nullPos === false) {
            $str = substr($data, $pos);
            $pos = strlen($data);
            return $str;
        }
        $str = substr($data, $pos, $nullPos - $pos);
        $pos = $nullPos + 1;
        return $str;
    }
}
