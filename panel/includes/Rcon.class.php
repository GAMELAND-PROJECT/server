<?php
/**
 * GameLand GoldSource RCON & Info Client (Counter-Strike 1.6 / HLDS / ReHLDS)
 * Clean, lightweight, zero-dependency UDP Socket Implementation
 */

class GoldSourceRcon {
    private $host;
    private $port;
    private $password;
    private $socket;
    private $timeout = 2.0;

    public function __construct($host, $port, $password, $timeout = 2.0) {
        $this->host = $host;
        $this->port = (int)$port;
        $this->password = $password;
        $this->timeout = $timeout;
    }

    private function connect() {
        $this->socket = @fsockopen("udp://" . $this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            throw new Exception("Unable to connect to server UDP port: $errstr ($errno)");
        }
        stream_set_timeout($this->socket, (int)$this->timeout, (int)(($this->timeout - (int)$this->timeout) * 1000000));
    }

    private function disconnect() {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function sendPacket($data) {
        if (!$this->socket) {
            $this->connect();
        }
        @fwrite($this->socket, "\xFF\xFF\xFF\xFF" . $data);
    }

    private function readPackets() {
        $response = '';
        while ($this->socket && !feof($this->socket)) {
            $packet = @fread($this->socket, 4096);
            if ($packet === false || strlen($packet) === 0) {
                break;
            }
            
            // Check for GoldSource header 0xFFFFFFFF
            if (substr($packet, 0, 4) === "\xFF\xFF\xFF\xFF") {
                $response .= substr($packet, 4);
            } else {
                $response .= $packet;
            }
            
            // Peek or short wait for remaining packets
            $info = stream_get_meta_data($this->socket);
            if ($info['unread_bytes'] == 0) {
                // Short wait to see if next UDP packet arrives
                usleep(30000); // 30ms
                $info = stream_get_meta_data($this->socket);
                if ($info['unread_bytes'] == 0) {
                    break;
                }
            }
        }
        return $response;
    }

    public function getChallenge() {
        $this->connect();
        $this->sendPacket("challenge rcon\n");
        $res = $this->readPackets();
        $this->disconnect();

        if (preg_match('/challenge rcon (\d+)/i', $res, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function execute($command) {
        $challenge = $this->getChallenge();
        if (!$challenge) {
            // Some ReHLDS configurations do not enforce challenge number
            $challenge = "0";
        }

        $this->connect();
        $payload = sprintf('rcon %s "%s" %s' . "\n", $challenge, $this->password, $command);
        $this->sendPacket($payload);
        $res = $this->readPackets();
        $this->disconnect();

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
        $this->connect();
        // A2S_INFO query: 0x54 followed by "Source Engine Query\0"
        $this->sendPacket("TSource Engine Query\x00");
        $res = $this->readPackets();
        $ping = round((microtime(true) - $startTime) * 1000);
        $this->disconnect();

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
