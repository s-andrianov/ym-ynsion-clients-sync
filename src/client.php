<?php
require 'vendor/autoload.php';

use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;

class YandexMusicWebSocketClient
{
    private $token;
    private $deviceId;
    private $redirectData;
    private $loop;
    private $isConnected = false;
    private $conn;
    private $role;
    private $onMessageCallback;
    private $name;
    private $host;
    private $info;

    public function __construct($token, $role = 'participant', $name = 'Client')
    {
        $this->token = $token;
        $this->deviceId = "27b61b24-cb43-46b9-bab8-5460f6cef678"; // хардкод чтобы не засорять юнисон устройствами, ибо не нашел способ удаления устройств
        // $this->deviceId = $this->uuid();
        $this->loop = Loop::get();
        $this->role = $role;
        $this->name = $name;
    }

    function getAccountInfo()
    {
        $response = file_get_contents(
            'https://api.music.yandex.net/account/status',
            false,
            stream_context_create(['http' => [
                'method' => 'GET',
                'header' => 'Authorization: OAuth ' . $this->token
            ]])
        );

        if ($response === false) {
            throw new Exception('> Ошибка запроса API Yandex.');
            return ['login' => $this->name];
        }

        $data = json_decode($response, true)['result'];

        $account = $data['account'];
        $subscription = $data['subscription'];

        return [
            'id' => $account['uid'],
            'login' => $account['login'],
            'name' => $account['displayName'] ?? null,
            'email' => $data['defaultEmail'] ?? null,
            'region' => $account['regionCode'] ?? null,
            'birthday' => $account['birthday'] ?? null,
            'has_plus' => $data['plus']['hasPlus'] ?? null,
            'subscription_active' => !empty($subscription['autoRenewable']) ? true : false,
            'subscription_until' => $subscription['autoRenewable'][0]['expires'] ?? null,
        ];
    }

    public function setOnMessageCallback(callable $callback)
    {
        $this->onMessageCallback = $callback;
    }

    public function getHost()
    {
        return $this->host;
    }

    private function uuid()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);
        return vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($data), 4));
    }

    /**
     * Получает редирект от сервера Ynison (синхронно)
     */
    public function getRedirect()
    {
        $wsProto = [
            "Ynison-Device-Id" => $this->deviceId,
            "Ynison-Device-Info" => '{"app_name":"Yandex Music API","type":1}'
        ];

        $wsProtocolHeader = 'Bearer, v2, ' . json_encode($wsProto);

        echo "🔄 [{$this->name}] Получение редиректа...\n";
        echo "📱 [{$this->name}] ID устройства: {$this->deviceId}\n";

        try {
            $client = new \WebSocket\Client(
                "wss://ynison.music.yandex.ru/redirector.YnisonRedirectService/GetRedirectToYnison",
                [
                    'headers' => [
                        'Sec-WebSocket-Protocol' => $wsProtocolHeader,
                        'Origin' => 'http://music.yandex.ru',
                        'Authorization' => 'OAuth ' . $this->token,
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                    ],
                    'timeout' => 30
                ]
            );

            $response = $client->receive();
            $data = json_decode($response, true);

            if (isset($data['host'])) {
                $this->info = $this->getAccountInfo();

                echo "✅ [{$this->info['login']}] Редирект получен!\n";
                echo "🌐 [{$this->info['login']}] Хост: {$data['host']}\n";

                $this->redirectData = $data;
                $this->host = $data['host']; // Сохраняем хост
                return $data;
            } else {
                throw new Exception("Неожиданный ответ: " . $response);
            }
        } catch (Exception $e) {
            throw new Exception("Ошибка редиректа: " . $e->getMessage());
        }
    }

    /**
     * Подключается к конечному WebSocket серверу (асинхронно)
     */
    public function connectToYnison()
    {
        if (!$this->redirectData) {
            $this->getRedirect();
        }

        $wsProto = [
            "Ynison-Device-Id" => $this->deviceId,
            "Ynison-Device-Info" => '{"app_name":"Yandex Music API","type":1}',
            "Ynison-Redirect-Ticket" => $this->redirectData['redirect_ticket']
        ];

        $wsProtocolHeader = 'Bearer, v2, ' . json_encode($wsProto);
        $url = "wss://{$this->redirectData['host']}/ynison_state.YnisonStateService/PutYnisonState";

        echo "🔗 [{$this->name}] Подключение к Ynison: {$url}\n";

        $headers = [
            'Sec-WebSocket-Protocol' => [$wsProtocolHeader],
            'Origin' => ['http://music.yandex.ru'],
            'Authorization' => ['OAuth ' . $this->token],
            'User-Agent' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36']
        ];

        $connector = new \Ratchet\Client\Connector($this->loop);
        $connector($url, [], $headers)
            ->then(
                function (WebSocket $conn) {
                    echo "✅ [{$this->name}] Подключено к Ynison!\n";
                    $this->isConnected = true;
                    $this->conn = $conn;

                    $conn->on('message', function ($msg) use ($conn) {
                        $this->handleMessage($msg, $conn);
                    });

                    $conn->on('close', function ($code = null, $reason = null) {
                        echo "❌ [{$this->name}] Соединение закрыто ({$code} - {$reason})\n";
                        $this->isConnected = false;
                        $this->conn = null;
                    });

                    $this->sendInitialMessage($conn);
                },
                function (\Exception $e) {
                    echo "❌ [{$this->name}] Ошибка подключения: {$e->getMessage()}\n";
                    $this->isConnected = false;
                    $this->conn = null;
                }
            );
    }

    /**
     * Отправляет сообщение через WebSocket
     */
    public function sendMessage($data)
    {
        if ($this->isConnected && $this->conn) {
            $this->conn->send(json_encode($data));
            echo "📤 [{$this->name}] Сообщение отправлено участнику\n";
            return true;
        }
        echo "⚠️ [{$this->name}] Не могу отправить сообщение - нет подключения\n";
        return false;
    }

    /**
     * Запуск клиента
     */
    public function run()
    {
        echo "🚀 [{$this->name}] Запуск Yandex Music WebSocket клиента в роли {$this->role}...\n";
        $this->connectToYnison();
        $this->setupSignalHandlers();
    }

    /**
     * Обработка входящих сообщений
     */
    private function handleMessage($msg, WebSocket $conn)
    {
        $data = json_decode($msg, true);

        if ($this->onMessageCallback && is_callable($this->onMessageCallback)) {
            call_user_func($this->onMessageCallback, $data, $this);
        }

        if (isset($data['update_full_state'])) {
            echo "📥 [{$this->name}] Получено обновление состояния\n";
        }
    }

    /**
     * Отправка начального сообщения
     */
    private function sendInitialMessage(WebSocket $conn)
    {
        $current_time = round(microtime(true) * 1000);

        $capabilities = [
            'can_be_player' => false,
            'can_be_remote_controller' => $this->role === 'participant',
            'volume_granularity' => 16
        ];

        $initialMessage = [
            'update_full_state' => [
                'player_state' => [
                    'player_queue' => [
                        'current_playable_index' => -1,
                        'entity_id' => '',
                        'entity_type' => 'VARIOUS',
                        'playable_list' => [],
                        'options' => ['repeat_mode' => 'NONE'],
                        'entity_context' => 'BASED_ON_ENTITY_BY_DEFAULT',
                        'version' => [
                            'device_id' => $this->deviceId,
                            'version' => $current_time,
                            'timestamp_ms' => $current_time
                        ]
                    ],
                    'status' => [
                        'duration_ms' => 0,
                        'paused' => true,
                        'playback_speed' => 1.0,
                        'progress_ms' => 0,
                        'version' => [
                            'device_id' => $this->deviceId,
                            'version' => $current_time,
                            'timestamp_ms' => $current_time
                        ]
                    ]
                ],
                'device' => [
                    'capabilities' => $capabilities,
                    'info' => [
                        'device_id' => $this->deviceId,
                        'type' => 'WEB',
                        'title' => 'YUMI - ' . $this->name,
                        'app_name' => 'Sync'
                    ],
                    'volume_info' => ['volume' => 50],
                    'is_shadow' => false
                ],
                'is_currently_active' => false
            ],
            'rid' => $this->uuid(),
            'player_action_timestamp_ms' => $current_time,
            'activity_interception_type' => 'DO_NOT_INTERCEPT_BY_DEFAULT'
        ];

        $conn->send(json_encode($initialMessage));
        echo "📤 [{$this->name}] Начальное сообщение отправлено (роль: {$this->role})\n";
    }

    private function setupSignalHandlers()
    {
        if (extension_loaded('pcntl')) {
            $this->loop->addSignal(SIGINT, function () {
                $this->loop->stop();
                echo "\n\n🛑 [{$this->info['login']}] Соединение разорвано.";
            });

            $this->loop->addSignal(SIGTERM, function () {
                $this->loop->stop();
                echo "🛑 [{$this->info['login']}] Соединение разорвано.";
            });
        }
    }

    public function convertLeaderMessage($leaderData)
    {
        if (!isset($leaderData['player_state'])) {
            return null;
        }

        $playerState = $leaderData['player_state'];
        $devices = $leaderData['devices'] ?? [];

        $leaderDevice = null;
        foreach ($devices as $device) {
            if ($device['capabilities']['can_be_player'] && !$device['is_offline']) {
                $leaderDevice = $device;
                break;
            }
        }

        $current_time = round(microtime(true) * 1000);

        $participantData = [
            'update_full_state' => [
                'player_state' => [
                    'status' => [
                        'duration_ms' => (int)($playerState['status']['duration_ms'] ?? 0),
                        'progress_ms' => (int)($playerState['status']['progress_ms'] ?? 0),
                        'paused' => (bool)($playerState['status']['paused'] ?? true),
                        'playback_speed' => (float)($playerState['status']['playback_speed'] ?? 1.0),
                        'version' => [
                            'device_id' => $this->deviceId,
                            'version' => $current_time,
                            'timestamp_ms' => 0
                        ]
                    ],
                    'player_queue' => [
                        'entity_id' => $playerState['player_queue']['entity_id'] ?? '',
                        'entity_type' => $playerState['player_queue']['entity_type'] ?? 'VARIOUS',
                        'current_playable_index' => (int)($playerState['player_queue']['current_playable_index'] ?? -1),
                        'playable_list' => array_map(function ($track) {
                            return [
                                'album_id_optional' => $track['album_id_optional'] ?? null,
                                'from' => $track['from'] ?? '',
                                'playable_id' => $track['playable_id'] ?? '',
                                'playable_type' => $track['playable_type'] ?? 'TRACK',
                                'title' => $track['title'] ?? '',
                                'cover_url_optional' => $track['cover_url_optional'] ?? null,
                                'navigation_id_optional' => null,
                                'playback_action_id_optional' => null
                            ];
                        }, $playerState['player_queue']['playable_list'] ?? []),
                        'shuffle_optional' => null,
                        'options' => [
                            'repeat_mode' => $playerState['player_queue']['options']['repeat_mode'] ?? 'NONE'
                        ],
                        'entity_context' => $playerState['player_queue']['entity_context'] ?? 'BASED_ON_ENTITY_BY_DEFAULT',
                        'from_optional' => $playerState['player_queue']['from_optional'] ?? null,
                        'initial_entity_optional' => null,
                        'adding_options_optional' => null,
                        'queue' => null,
                        'version' => [
                            'device_id' => $this->deviceId,
                            'version' => $current_time,
                            'timestamp_ms' => 0
                        ]
                    ]
                ],
                'device' => [
                    'volume' => $leaderDevice ? (float)($leaderDevice['volume'] ?? 0.85) : 0.85,
                    'capabilities' => [
                        'can_be_player' => false,
                        'can_be_remote_controller' => true,
                        'volume_granularity' => 20
                    ],
                    'info' => [
                        'app_name' => 'YUMI',
                        'app_version' => '1.0',
                        'title' => 'Sync - ' . $this->name,
                        'device_id' => $this->deviceId,
                        'type' => 'WEB'
                    ],
                    'volume_info' => [
                        'volume' => $leaderDevice ? (float)($leaderDevice['volume'] ?? 0.85) : 0.85,
                        'version' => null
                    ],
                    'is_shadow' => false
                ],
                'is_currently_active' => false,
                'sync_state_from_eov_optional' => null
            ],
            'rid' => $this->uuid(),
            'player_action_timestamp_ms' => $current_time,
            'activity_interception_type' => 'DO_NOT_INTERCEPT_BY_DEFAULT'
        ];

        return $participantData;
    }

    public function isConnected()
    {
        return $this->isConnected;
    }

    public function getRole()
    {
        return $this->role;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getInfo()
    {
        return $this->info;
    }
}

class SyncManager
{
    private $loop;
    private $hostRegistry = []; // Регистр хостов для проверки уникальности
    private $leader;
    private $participants = [];
    
    private $lastBroadcastTime = 0; // Время последней рассылки
    private $minBroadcastInterval = 100; // Минимальный интервал между рассылками (мс)
    private $messageCounter = 0; // Счетчик сообщений для отслеживания

    public function __construct($leaderToken, $participantTokens = [])
    {
        $this->loop = Loop::get();

        // Создаем ведущего
        $this->leader = new YandexMusicWebSocketClient($leaderToken, 'leader', 'Ведущий');

        // Создаем участников
        $participantIndex = 1;
        foreach ($participantTokens as $token) {
            $this->participants[] = new YandexMusicWebSocketClient(
                $token,
                'participant',
                'Участник-' . $participantIndex++
            );;
        }

        // Настраиваем callback для ведущего
        $this->leader->setOnMessageCallback(function ($data, $client) {
            $this->onLeaderMessage($data, $client);
        });

        // Настраиваем callback для участников
        foreach ($this->participants as $participant) {
            $participant->setOnMessageCallback(function ($data, $client) {
                $this->onParticipantMessage($data, $client);
            });
        }
    }

    private function onLeaderMessage($data, $leaderClient)
    {
        // Проверяем уникальность хоста при первом сообщении
        if (empty($this->hostRegistry)) {
            try {
                $this->checkHostUniqueness($leaderClient);

                // Проверяем хосты всех участников
                foreach ($this->participants as $participant) {
                    $this->checkHostUniqueness($participant);
                }

                echo "✅ [БЕЗОПАСНОСТЬ] Все хосты уникальны!\n";
            } catch (Exception $e) {
                echo $e->getMessage() . "\n";
                $this->loop->stop();
                return;
            }
        }

        // Защита от спама
        if (!$this->checkSpamProtection()) {
            return;
        }

        if (isset($data['player_state'])) {
            echo "🎵 [Ведущий] Получено обновление состояния, рассылаем участникам\n";

            $participantCount = 0;
            $successCount = 0;

            $this->leader_ts = microtime(true);

            // Рассылаем обновление всем участникам
            foreach ($this->participants as $participant) {
                if ($participant->isConnected()) {
                    $participantCount++;

                    $convertedMessage = $participant->convertLeaderMessage($data);
                    if ($convertedMessage && $participant->sendMessage($convertedMessage)) {
                        $successCount++;
                    }
                }
            }

            echo "📤 [СТАТИСТИКА] Отправлено {$successCount}/{$participantCount} сообщений участникам\n";
        }
    }

    private function onParticipantMessage($data, $participantClient)
    {
        if (isset($data['player_state'])) {
            $content = $data['player_state'];

            $duration_ms = $content['status']['duration_ms'];
            $progress_ms = $content['status']['progress_ms'];
            $playing_text = $content['status']['paused'] ? '(ПАУЗА)' : '(ИГРАЕТ)';

            $current_playable_index = $content['player_queue']['current_playable_index'];
            $playable_list = $content['player_queue']['playable_list'];
            $current_track_id = $playable_list[$current_playable_index]['playable_id'];

            $track = $this->getTrackInfo($current_track_id);

            echo "\n| [{$participantClient->getInfo()['login']}] {$playing_text}";
            echo "\n| {$track['title']} - {$track['artists']['text']}";
            echo "\n| {$this->formatTime($progress_ms)} / {$this->formatTime($duration_ms)}\n";
        }
    }

    public function run()
    {
        echo "Запуск...\n";
        echo "Участники: " . count($this->participants) . "\n";

        // Запускаем всех клиентов
        $this->leader->run();
        foreach ($this->participants as $participant) {
            $participant->run();
        }

        // Периодическая проверка состояния
        $this->loop->addPeriodicTimer(10, function () {
            $this->healthCheck();
        });

        $this->loop->run();
    }

    /**
     * Проверка уникальности хостов при подключении клиентов
     */
    private function checkHostUniqueness($client)
    {
        $host = $client->getHost();
        $name = $client->getInfo()['login'];

        if (in_array($host, $this->hostRegistry)) {
            $existingClient = array_search($host, $this->hostRegistry);
            throw new Exception("❌ [БЕЗОПАСНОСТЬ] Обнаружена коллизия хостов! Клиент '{$name}' имеет тот же хост, что и '{$existingClient}': {$host}");
        }

        $this->hostRegistry[$name] = $host;
        echo "✅ [БЕЗОПАСНОСТЬ] Хост зарегистрирован для {$name}: {$host}\n";
    }

    /**
     * Защита от спама - проверка частоты сообщений от ведущего
     */
    private function checkSpamProtection()
    {
        $currentTime = round(microtime(true) * 1000);
        $timeSinceLastBroadcast = $currentTime - $this->lastBroadcastTime;

        if ($timeSinceLastBroadcast < $this->minBroadcastInterval) {
            echo "⚠️ [БЕЗОПАСНОСТЬ] Защита от спама: Слишком частые сообщения от ведущего. Время с последнего: {$timeSinceLastBroadcast}мс\n";
            return false;
        }

        $this->lastBroadcastTime = $currentTime;
        $this->messageCounter++;

        echo "📊 [СТАТИСТИКА] Сообщение #{$this->messageCounter}, интервал: {$timeSinceLastBroadcast}мс\n";
        return true;
    }

    /**
     * Периодическая проверка здоровья соединений
     */
    private function healthCheck()
    {
        $connectedParticipants = 0;
        foreach ($this->participants as $participant) {
            if ($participant->isConnected()) {
                $connectedParticipants++;
            }
        }

        // не трогать
        // echo "\n🏥 [ЗДОРОВЬЕ] Ведущий: " . ($this->leader->isConnected() ? 'подключен' : 'отключен') . "\n";
        // echo "🏥 [ЗДОРОВЬЕ] Участники: {$connectedParticipants}/" . count($this->participants) . " подключено\n";
        // echo "🏥 [ЗДОРОВЬЕ] Всего сообщений: {$this->messageCounter}\n";

        // Переподключаем отключившихся участников
        foreach ($this->participants as $participant) {
            if (!$participant->isConnected()) {
                echo "🔄 [ЗДОРОВЬЕ] Переподключение {$participant->getInfo()['login']}...\n";
                $participant->run();
            }
        }
    }

    function getTrackInfo($track_id)
    {
        $response = file_get_contents(
            "https://api.music.yandex.net/tracks/{$track_id}/full-info",
        );

        if ($response === false) throw new Exception('> Ошибка запроса API Yandex.');

        $data = json_decode($response, true);

        $track = $data['result']['track'];
        $artists = array_map(function ($artist) {
            return [
                'id' => $artist['id'],
                'name' => $artist['name'],
                'cover' => $artist['cover']['uri']
            ];
        }, $track['artists']);

        return [
            'id' => $track['id'],
            'title' => $track['title'],
            'duration' => $track['durationMs'],
            'artists' => [
                'text' => implode(', ', array_column($artists, 'name')),
                'list' => $artists
            ],
            'album' => [
                'id' => $track['albums'][0]['id'],
                'title' => $track['albums'][0]['title']
            ],
            'year' => $track['albums'][0]['year'],
            'cover' => $track['ogImage'] ?? $track['coverUri'] ?? null
        ];
    }

    function formatTime($milliseconds)
    {
        $totalSeconds = floor($milliseconds / 1000);
        $minutes = floor($totalSeconds / 60);
        $seconds = $totalSeconds % 60;
        return sprintf("%d:%02d", $minutes, $seconds);
    }
}
