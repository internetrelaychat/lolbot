<?php
// Another bot just used for playing ascii arts


require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

set_include_path(implode(PATH_SEPARATOR, array(__DIR__ . '/library', __DIR__ . '/plugins', get_include_path())));

spl_autoload_register(function ($class) {
    $path = str_replace('\\', '/', $class) . '.php';
    include $path;
    return class_exists($class, false);
});

use Amp\Loop;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use knivey\irctools;

$config = Yaml::parseFile(__DIR__ . '/artconfig.yaml');


$bot = null;
Loop::run(function () {
    global $bot, $config;

    $bot = new \Irc\Client($config['name'], $config['server'], $config['port'], $config['bindIp'], $config['ssl']);
    $bot->setThrottle($config['throttle'] ?? true);
    $bot->setServerPassword($config['pass'] ?? '');

    $bot->on('welcome', function ($e, \Irc\Client $bot) {
        global $config;
        $nick = $bot->getNick();
        $bot->send("MODE $nick +x");
        $bot->join(implode(',', $config['channels']));
    });

    $bot->on('kick', function ($args, \Irc\Client $bot) {
        $bot->join($args->channel);
    });

    //Stop abuse from an IRCOP called sylar
    $bot->on('mode', function($args, \Irc\Client $bot) {
        echo "====== mode ======\n";
        var_dump($args->args);
        if($args->on == $bot->getNick()) {
            $adding = true;
            foreach (str_split($args->args[0]) as $mode) {
                switch($mode) {
                    case '+':
                        $adding = true;
                        break;
                    case '-':
                        $adding = false;
                        break;
                    case 'd':
                    case 'D':
                        if($adding)
                            $bot->send("MODE {$bot->getNick()} -{$mode}");
                }
            }
        }
    });

    $bot->on('chat', function ($args, \Irc\Client $bot) {
        global $config, $router;

        if(isIgnored($args->fullhost))
            return;

        tryRec($bot, $args->from, $args->channel, $args->text);
        if (isset($config['trigger'])) {
            if (substr($args->text, 0, 1) != $config['trigger']) {
                return;
            }
            $text = substr($args->text, 1);
        } elseif (isset($config['trigger_re'])) {
            $trig = "/(^${config['trigger_re']}).+$/";
            if (!preg_match($trig, $args->text, $m)) {
                return;
            }
            $text = substr($args->text, strlen($m[1]));
        } else {
            echo "No trigger defined\n";
            return;
        }

        //TODO SOME ART NAMES HAVE SPACES
        $text = explode(' ', $text);
        $cmd = strtolower(array_shift($text));
        $text = implode(' ', $text);

        if($cmd == 'search' || $cmd == 'find') {
            searchart($bot, $args->channel, $text);
            return;
        }
        if($cmd == 'random') {
            randart($bot, $args->channel, $text);
            return;
        }
        if($cmd == 'stop') {
            stop($bot, $args->from, $args->channel, $text);
            return;
        }
        if($cmd == 'record') {
            record($bot, $args->from, $args->channel, $text);
            return;
        }
        if($cmd == 'end') {
            endart($bot, $args->from, $args->channel, $text);
            return;
        }
        if($cmd == 'cancel') {
            cancel($bot, $args->from, $args->channel, $text);
            return;
        }
        if(trim($cmd) == '')
            return;
        Amp\asyncCall('reqart', $bot, $args->channel, $cmd);
    });

    Loop::onSignal(SIGINT, function ($watcherId) use ($bot) {
        Amp\Loop::cancel($watcherId);
        if (!$bot->isConnected)
            die("Terminating, not connected\n");
        echo "Caught SIGINT! exiting ...\n";
        try {
            yield $bot->sendNow("quit :Caught SIGTERM GOODBYE!!!!\r\n");
        } catch (Exception $e) {
            echo "Exception when sending quit\n $e\n";
        }
        $bot->exit();
        echo "Stopping Amp\\Loop\n";
        Amp\Loop::stop();
    });
    Loop::onSignal(SIGTERM, function ($watcherId) use ($bot) {
        Amp\Loop::cancel($watcherId);
        if (!$bot->isConnected)
            die("Terminating, not connected\n");
        echo "Caught SIGTERM! exiting ...\n";
        try {
            yield $bot->sendNow("quit :Caught SIGTERM GOODBYE!!!!\r\n");
        } catch (Exception $e) {
            echo "Exception when sending quit\n $e\n";
        }
        $bot->exit();
        echo "Stopping Amp\\Loop\n";
        Amp\Loop::stop();
    });

    $bot->go();
});

$recordings = [];

function record($bot, $nick, $chan, $text) {
    global $recordings, $config;
    if(isset($recordings[$nick])) {
        return;
    }
    if(!preg_match('/^[a-z0-9\-\_]+$/', $text)) {
        $bot->pm($chan, 'Pick a filename matching [a-z0-9\-\_]+');
        return;
    }
    $reserved = ['artfart', 'random', 'search', 'find', 'stop', 'record', 'end', 'cancel'];
    if(in_array(strtolower($text), $reserved)) {
        $bot->pm($chan, 'That name has been reserved');
        return;
    }

    $exists = false;
    $base = $config['artdir'];
    try {
        $tree = knivey\tools\dirtree($base);
    } catch (Exception $e) {
        echo "{$e}\n";
        return;
    }
    foreach($tree as $ent) {
        if($text == strtolower(basename($ent, '.txt'))) {
            $exists = substr($ent, strlen($config['artdir']));
            break;
        }
    }
    if($exists)
        $bot->pm($chan, "Warning: That file name has been used by $exists, to playback may require a full path or you can @cancel and use a new name");

    $recordings[$nick] = [
        'name' => $text,
        'nick' => $nick,
        'chan' => $chan,
        'art' => [],
        'timeOut' => Amp\Loop::delay(15000, 'timeOut', [$nick, $bot]),
    ];
    $bot->pm($chan, 'Recording started type @end when done or discard with @cancel');
}

function tryRec($bot, $nick, $chan, $text) {
    global $recordings;
    if(!isset($recordings[$nick]))
        return;
    Amp\Loop::cancel($recordings[$nick]['timeOut']);
    $recordings[$nick]['timeOut'] = Amp\Loop::delay(15000, 'timeOut', [$nick, $bot]);
    $recordings[$nick]['art'][] = $text;
}

function timeOut($watcher, $data) {
    global $recordings;
    list ($nick, $bot) = $data;
    if(!isset($recordings[$nick])) {
        echo "Timeout called but not recording?\n";
        return;
    }
    $bot->pm($recordings[$nick]['chan'], "Canceling art for $nick due to no messages for 15 seconds");
    Amp\Loop::cancel($recordings[$nick]['timeOut']);
    unset($recordings[$nick]);
}

function endart($bot, $nick, $chan, $text) {
    global $recordings, $config;
    if(!isset($recordings[$nick])) {
        $bot->pm($chan, "You aren't doing a recording");
        return;
    }
    Amp\Loop::cancel($recordings[$nick]['timeOut']);
    //last line will be the command for end, so delete it
    array_pop($recordings[$nick]['art']);
    if(empty($recordings[$nick]['art'])) {
        $bot->pm($recordings[$nick]['chan'], "Nothing recorded, cancelling");
        unset($recordings[$nick]);
        return;
    }
    //TODO make h4x channel name?
    $dir = "${config['artdir']}h4x/$nick";
    if(file_exists($dir) && !is_dir($dir)) {
        $bot->pm($recordings[$nick]['chan'], "crazy error occurred panicing atm");
        unset($recordings[$nick]);
        return;
    }
    if(!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    $file = "$dir/". $recordings[$nick]['name'] . '.txt';
    file_put_contents($file, implode("\n", $recordings[$nick]['art']));
    $bot->pm($recordings[$nick]['chan'], "Recording finished ;) saved to " . substr($file, strlen($config['artdir'])));
    unset($recordings[$nick]);
}

function cancel($bot, $nick, $chan, $text) {
    global $recordings;
    if(!isset($recordings[$nick])) {
        $bot->pm($chan, "You aren't doing a recording");
        return;
    }
    $bot->pm($chan, "Recording canceled");
    Amp\Loop::cancel($recordings[$nick]['timeOut']);
    unset($recordings[$nick]);
}


$playing = [];

function reqart($bot, $chan, $file) {
    global $config, $playing;
    if(isset($playing[$chan])) {
        return;
    }
    $base = $config['artdir'];
    try {
        $tree = knivey\tools\dirtree($base);
    } catch (Exception $e) {
        echo "{$e}\n";
        return;
    }
    //try fullpath first
    foreach($tree as $ent) {
        if ($file . '.txt' == strtolower(substr($ent, strlen($config['artdir'])))) {
            playart($bot, $chan, $ent);
            return;
        }
    }
    foreach($tree as $ent) {
        if($file == strtolower(basename($ent, '.txt'))) {
            playart($bot, $chan, $ent);
            return;
        }
    }
    try {
        $client = HttpClientBuilder::buildDefault();
        $url = "https://irc.watch/ascii/$file/";
        $req = new Request("https://irc.watch/ascii/txt/$file.txt");
        /** @var Response $response */
        $response = yield $client->request($req);
        $body = yield $response->getBody()->buffer();
        if ($response->getStatus() == 200) {
            file_put_contents("ircwatch.txt", "$body\n$url");
            playart($bot, $chan, "ircwatch.txt");
            return;
        }
    } catch (Exception $error) {
        // If something goes wrong Amp will throw the exception where the promise was yielded.
        // The HttpClient::request() method itself will never throw directly, but returns a promise.
        echo "$error\n";
        //$bot->pm($chan, "LinkTitles Exception: " . $error);
    }
    //$bot->pm($chan, "that art not found");
}

function searchart($bot, $chan, $file) {
    global $config, $playing;
    if(isset($playing[$chan])) {
        return;
    }
    $file = strtolower($file);
    $base = $config['artdir'];
    try {
        $tree = knivey\tools\dirtree($base);
    } catch (Exception $e) {
        echo "{$e}\n";
        return;
    }
    $matches = $tree;
    if($file != '') {
        $matches = [];
        foreach ($tree as $ent) {
            $check = substr($ent, strlen($config['artdir']));
            $check = str_replace('.txt', '', $check);
            if (fnmatch("*$file*", strtolower($check))) {
                $matches[] = $ent;
            }
        }
    }
    if(!empty($matches)) {
        $cnt = 0;
        foreach ($matches as $match) {
            $match = str_ireplace($file, "\x0306$file\x0F", $match);
            $bot->pm($chan, substr($match, strlen($config['artdir'])));
            if ($cnt++ > 100) {
                $bot->pm($chan, count($matches) . " total matches only showing 100");
                break;
            }
        }
    }
    else
        $bot->pm($chan, "no matching art found");
}

function randart($bot, $chan, $file) {
    global $config, $playing;
    if(isset($playing[$chan])) {
        return;
    }
    $file = strtolower($file);
    $base = $config['artdir'];
    try {
        $tree = knivey\tools\dirtree($base);
    } catch (Exception $e) {
        echo "{$e}\n";
        return;
    }
    $matches = $tree;
    if($file != '') {
        $matches = [];
        foreach ($tree as $ent) {
            $check = substr($ent, strlen($config['artdir']));
            $check = str_replace('.txt', '', $check);
            if (fnmatch("*$file*", strtolower($check))) {
                $matches[] = $ent;
            }
        }
    }
    if(!empty($matches))
        playart($bot, $chan, $matches[array_rand($matches)], $file);
    else
        $bot->pm($chan, "no matching art found");
}

function stop($bot, $nick, $chan, $text) {
    global $recordings, $playing;
    if(isset($playing[$chan])) {
        $playing[$chan] = [];
        $bot->pm($chan, 'stopped');
    } else {
        if(isset($recordings[$nick])) {
            endart($bot, $nick, $chan, $text);
            return;
        }
        $bot->pm($chan, 'not playing');
    }
}



function playart($bot, $chan, $file, $searched = false) {
    global $playing, $config;
    \Amp\asyncCall(function() use($searched, $bot, $chan, $file, &$playing, $config) {
        if (!isset($playing[$chan])) {
            $playing[$chan] = irctools\loadartfile($file);
            $pmsg = "Playing " . substr($file, strlen($config['artdir']));;
            if($searched) {
                $pmsg = str_ireplace($searched, "\x0306$searched\x0F", $pmsg);
            }
            array_unshift($playing[$chan], $pmsg);
        }
        while (!empty($playing[$chan])) {
            $bot->pm($chan, irctools\fixColors(array_shift($playing[$chan])));
            yield \Amp\delay(25);
        }
        unset($playing[$chan]);
    });
}


//TODO move this to irctools package
function hostmaskToRegex($mask) {
    $out = '';
    $i = 0;
    while($i < strlen($mask)) {
        $nextc = strcspn($mask, '*?', $i);
        $out .= preg_quote(substr($mask, $i, $nextc), '@');
        if($nextc + $i == strlen($mask))
            break;
        if($mask[$nextc + $i] == '?')
            $out .= '.';
        if($mask[$nextc + $i] == '*')
            $out .= '.*';
        $i += $nextc + 1;
    }
    return "@{$out}@i";
}

function getIgnores($file = "ignores.txt") {
    static $ignores;
    static $mtime;
    if(!file_exists($file))
        return [];
    // Retarded that i had to figure out to do this otherwise php caches mtime..
    clearstatcache();
    $newmtime = filemtime($file);
    if($newmtime <= ($mtime ?? 0))
        return ($ignores ?? []);
    $mtime = $newmtime;
    return $ignores = file($file, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
}

function isIgnored($fullhost) {
    $ignores = getIgnores();
    foreach ($ignores as $i) {
        if (preg_match(hostmaskToRegex($i), $fullhost)) {
            return true;
        }
    }
    return false;
}