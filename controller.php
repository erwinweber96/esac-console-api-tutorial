<?php

require "vendor/autoload.php";

class Controller {
    private $api;
    private $client;
    private $whitelist = ["your_nickname"];
    private $isLive = false;
    private $token = "your_token";
    private $matchId;
    private $participants;
    private $logins = [];

    private $ip = "your_dedicated_server_ip";
    private $port = 2350; // your_dedicated_server_port

    const UPCOMING = 1;
    const LIVE = 2;
    const ENDED = 3;

    /**
     * Controller constructor.
     */
    public function __construct()
    {
        $this->api = new \GuzzleHttp\Client([
            "base_uri" => "https://api.esac.gg/v1"
        ]);
        $this->client = new \Maniaplanet\DedicatedServer\Xmlrpc\GbxRemote(
            $this->ip,
            $this->port
        );

        try {
            $this->client->query("Authenticate", ["SuperAdmin", "SuperAdmin"]); //TODO: you might need to change
            $this->client->query("EnableCallbacks", [true]);
            $this->client->query("SetCallVoteTimeOut", [0]);
            $this->client->query("SetApiVersion", ["2013-04-16"]);
            $this->client->query('TriggerModeScriptEventArray', ["XmlRpc.EnableCallbacks", ["true"]]);
        } catch (\Throwable $exception) {
            die($exception->getMessage());
        }
    }

    function loop()
    {
        while(true) {
            try {
                $calls = $this->client->getCallbacks();
            } catch (\Throwable $exception) {
                var_dump($exception->getMessage());
                $calls = [];
            }

            $this->handleCalls($calls);
        }
    }

    function handleCalls(array $calls)
    {
        foreach ($calls as $call) {
            $eventName = $call[0];
            $eventData = $call[1];
            echo $eventName . "\n";

            switch ($eventName) {
                case "ManiaPlanet.PlayerChat":
                    // /start/{matchId}
                    $text = $eventData[2];
                    $command = explode("/", $text);

                    if (!count($command)) {
                        break;
                    }

                    if ($command[1] != "start") {
                        break;
                    }

                    $this->matchId = (int)$command[2];

                    // get participants from esac
                    try {
                        $this->participants = $this->getParticipants();
                    } catch (\Throwable $exception) {
                        echo $exception->getMessage() . "\n";
                    }

                    // whitelist participants
                    foreach ($this->participants as $participant) {
                        $this->whitelist[] = $participant["user"]["tm_nickname"];
                    }

                    // update the match status on esac
                    try {
                        $this->updateStatus(self::LIVE);
                    } catch (\Throwable $exception) {
                        echo $exception->getMessage() . "\n";
                    }
                    $this->isLive = true;

                    // add this server to the match
                    try {
                        $this->addGameServer();
                    } catch (\Throwable $exception) {
                        echo $exception->getMessage() . "\n";
                    }
                    break;
                case "ManiaPlanet.PlayerInfoChanged":
                    $player = $eventData[0];
                    $nickname = $player['NickName'];

                    $whitelisted = false;
                    foreach ($this->whitelist as $whitelistedNickname) {
                        if ($whitelistedNickname == $nickname) {
                            $whitelisted = true;
                            break;
                        }
                    }

                    if (!$whitelisted) {
                        try {
                            $this->client->query("Kick", [$player['Login'], "Not Authorized"]);
                        } catch (\Maniaplanet\DedicatedServer\Xmlrpc\MessageException $e) {
                            echo $e->getMessage() . "\n";
                        }
                    }

                    $this->logins[$player['Login']] = $nickname;

                    break;
                case "ManiaPlanet.EndMatch":
                    if ($this->isLive) {
                        $this->updateStatus(self::ENDED);
                        $this->isLive = false;
                    }
                    break;
                case "ManiaPlanet.ModeScriptCallbackArray":
                    $callbackType = $eventData[0];
                    $callbackData = $eventData[1];

                    switch ($callbackType) {
                        case "Trackmania.Event.WayPoint":
                            $waypoint = json_decode($callbackData[0], true);

                            // check if the waypoint is a finish waypoint
                            if (!$waypoint['isendrace']) {
                                break;
                            }

                            // match the player who set the time to the participant
                            $login = $waypoint['login'];
                            $nickname = $this->logins[$login];

                            $participantId = null;
                            foreach ($this->participants as $participant) {
                                if ($participant['user']['tm_nickname'] == $nickname) {
                                    $participantId = $participant['id'];
                                    break;
                                }
                            }

                            if (!$participantId) {
                                break;
                            }

                            // get the time
                            $time = $waypoint['racetime'];

                            // send the result
                            $this->createResult($participantId, $time);
                        break;
                    }

                    break;
            }
        }
    }

    function getParticipants()
    {
        $response = $this->api->get("/v1/matches/participants?token={$this->token}&matchId={$this->matchId}");
        return json_decode($response->getBody(), true);
    }

    function updateStatus($statusId)
    {
        $this->api->put("/v1/matches/{$this->matchId}/status/{$statusId}?token={$this->token}");
    }

    function addGameServer()
    {
        $this->api->request(
            "POST",
            "/v1/matches/game_servers",
            [
                "json" => [
                    "token" => $this->token,
                    "matchId" => $this->matchId,
                    "name" => "esac.gg test",
                    "pending" => false,
                    "serverLink" => "Live > Arcade Rooms > Search for 'esacggtest'"
                ]
            ]
        );
    }

    function createResult($participantId, $time)
    {
        $this->api->request(
            "POST",
            "/v1/matches/results",
            [
                "json" => [
                    "token" => $this->token,
                    "matchId" => $this->matchId,
                    "isTotalResult" => true,
                    "participantId" => (int)$participantId,
                    "result" => (string)$time,
                    "pending" => false
                ]
            ]
        );
    }
}

$controller = new Controller();
$controller->loop();