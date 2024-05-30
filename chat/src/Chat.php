<?php

namespace MyApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Chat implements MessageComponentInterface
{
    protected $clients;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn)
    {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);

        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $numRecv = count($this->clients) - 1;
        echo sprintf('Connection %d sending message "%s" to %d other connection%s' . "\n", $from->resourceId, $msg, $numRecv, $numRecv == 1 ? '' : 's');

        $msgArr = json_decode($msg, true);

        // Проверка типа сообщения и обработка координат
        if (isset($msgArr['type']) && $msgArr['type'] === 'coordinates') {
            $latitude = $msgArr['latitude'];
            $longitude = $msgArr['longitude'];
            $userId = $from->resourceId;

            // Пересылка координат всем остальным клиентам
            foreach ($this->clients as $client) {
                if ($from !== $client) {
                    $client->send(json_encode([
                        'type' => 'coordinates',
                        'userId' => $userId,
                        'latitude' => $latitude,
                        'longitude' => $longitude
                    ], JSON_UNESCAPED_UNICODE));
                }
            }
        } else {
            // Обработка других типов сообщений (например, текстовых)
            $msgText = $msgArr['message'];
            $userId = $msgArr['user_id'] != 0 ? $msgArr['user_id'] : $from->resourceId;

            $guzzleClient = new \GuzzleHttp\Client();
            $headers = [
                'Content-Type' => 'application/json'
            ];
            $body = json_encode([
                'msg' => $msgText,
                'user_id' => $userId,
                'anon' => $msgArr['user_id'] != 0 ? false : true
            ]);
            print_r($body);

            $guzzleRequest = new \GuzzleHttp\Psr7\Request('POST', 'http://diplom.ru/api/message', $headers, $body);
            $clients = $this->clients;
            $guzzleRes = $guzzleClient->sendAsync($guzzleRequest)->then(function ($response) use ($clients, $from, $userId, $msgText) {
                $body = $response->getBody();
                $bodyArr = json_decode($body, true);
                foreach ($this->clients as $client) {
                    if ($from !== $client) {
                        $client->send(json_encode([
                            'from_id' => $userId,
                            'message' => $msgText,
                            'name' => $bodyArr["name"]
                        ], JSON_UNESCAPED_UNICODE));
                    }
                }
            })->wait();
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);

        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occurred: {$e->getMessage()}\n";

        $conn->close();
    }
}
