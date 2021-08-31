#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Amp\Loop;
use knivey\cmdr\Cmdr;

$router = new Cmdr();

/*
 * TODO:
 * Find places where using delayed makes more sense
 * move all bots to one instance, surround everything with try catch (links youtube cmds etc)
 * each bot own config section, apis global?
 *  databases will need to be uniq for each bot, will likely put scripts into objects - this could be good for autoload
 * loading scripts maybe have recurvise dir include and dirs like all, extra
 */
/*
 * TODO
 * art:
 * other todos in various files..
 * allow ircwatch arts in @random search - append ircwatch/name array
 * allow @ircwatch/file to only load from ircwatch
 * @fortune
 *
 * website api for uploading, sending to chans, get keys from chat
 *
 * others:
 *
 * github: links to things like issues prs etc show more appropriate info
 *  * later would be nice to have github webhooks?
 * translate
 * reddit urls
 *
 * rss feeds
 * main loop catching exceptions and ValueError dont die
 * user system
 *
 * codesand: add js, c++, cleanup the timeout problem, maxlines shown twice if error after
 */

$config = Yaml::parseFile(__DIR__.'/config.yaml');
if($config['codesand'] ?? false) {
    require_once 'scripts/codesand/common.php';
}

/**
 * helper to make replies in cmds easier
 */
function makeRepliers($args, \Irc\Client $bot, string $prefix) {
    return [
        function ($msg, $err = null) use ($args, $bot, $prefix) {
            if($err == null) {
                $bot->pm($args->chan, "\2$prefix:\2 $msg");
            } else {
                $bot->pm($args->chan, "\2$prefix $err:\2 $msg");
            }
        },
        function ($msg, $err = null) use ($args, $bot, $prefix) {
            if($err == null) {
                $bot->notice($args->nick, "\2$prefix:\2 $msg");
            } else {
                $bot->notice($args->nick, "\2$prefix $err:\2 $msg");
            }
        }
    ];
}

require_once 'scripts/notifier/notifier.php';

require_once 'scripts/weather/weather.php';
require_once 'scripts/bing/bing.php';
require_once 'scripts/stocks/stocks.php';
require_once 'scripts/wolfram/wolfram.php';
require_once 'scripts/lastfm/lastfm.php';
require_once 'scripts/help/help.php';
require_once 'scripts/cumfacts/cumfacts.php';
require_once 'scripts/artfart/artfart.php';
require_once 'scripts/tools/tools.php';
require_once 'scripts/tell/tell.php';
require_once 'scripts/remindme/remindme.php';
require_once 'scripts/owncast/owncast.php';
require_once 'scripts/urbandict/urbandict.php';
require_once 'scripts/seen/seen.php';
require_once 'scripts/zyzz/zyzz.php';
require_once 'scripts/wiki/wiki.php';
require_once 'scripts/alias/alias.php';

require_once "scripts/JRH/jrh.php";

require_once 'scripts/linktitles/linktitles.php';
require_once 'scripts/youtube/youtube.php';

$router->loadFuncs();

//copied from Cmdr should give it its own function in there later
function parseOpts(string &$msg, array $validOpts = []): array {
    $opts = [];
    $msg = explode(' ', $msg);
    $msgb = [];
    foreach ($msg as $w) {
        if(str_contains($w, "=")) {
            list($lhs, $rhs) = explode("=", $w, 2);
        } else {
            $lhs = $w;
            $rhs = null;
        }
        if(in_array($lhs, $validOpts))
            $opts[$lhs] = $rhs;
        else
            $msgb[] = $w;
    }
    $msg = implode(' ', $msgb);
    return $opts;
}

$bot = null;
try {
    Loop::run(function () {
        global $bot, $config;

        $bot = new \Irc\Client($config['name'], $config['server'], $config['port'], $config['bindIp'], $config['ssl']);
        $bot->setThrottle($config['throttle'] ?? true);
        $bot->setServerPassword($config['pass'] ?? '');
        \scripts\tell\initTell($bot);
        \scripts\seen\initSeen($bot);
        \scripts\remindme\initRemindme($bot);

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
            try {
                global $config, $router;

                if(isIgnored($args->fullhost))
                    return;

                if ($config['youtube'] ?? false) {
                    \Amp\asyncCall('scripts\youtube\youtube', $bot, $args->from, $args->channel, $args->text);
                }
                if ($config['linktitles'] ?? false) {
                    \Amp\asyncCall('scripts\linktitles\linktitles', $bot, $args->channel, $args->text);
                }

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


                $ar = explode(' ', $text);
                if (array_shift($ar) == 'ping') {
                    $bot->msg($args->channel, "Pong");
                }


                $text = explode(' ', $text);
                $cmd = array_shift($text);
                $text = implode(' ', $text);
                if(trim($cmd) == '')
                    return;

                if(isset($router->cmds[$cmd])) {
                    try {
                        $router->call($cmd, $text, $args, $bot);
                    } catch (Exception $e) {
                        $bot->notice($args->from, $e->getMessage());
                    }
                } else {
                    //call other cmd handlers
                    $tmpText = $text;
                    $opts = parseOpts($tmpText, []);
                    $cmdArgs = \knivey\tools\makeArgs($tmpText);
                    if(!is_array($cmdArgs))
                        $cmdArgs = [];
                    if(count($cmdArgs) == 1 && $cmdArgs[0] == "")
                        $cmdArgs = [];
                    \scripts\alias\handleCmd($args, $bot, $cmd, $cmdArgs, $opts);
                }
            } catch (Exception $e) {
                echo "UNCAUGHT EXCEPTION $e\n";
            }
        });
        $server = yield from \scripts\notifier\notifier($bot);

        Loop::onSignal(SIGINT, function ($watcherId) use ($bot, $server) {
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
            if ($server != null) {
                $server->stop();
            }
            echo "Stopping Amp\\Loop\n";
            Amp\Loop::stop();
        });

        Loop::onSignal(SIGTERM, function ($watcherId) use ($bot, $server) {
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
            if ($server != null) {
                $server->stop();
            }
            echo "Stopping Amp\\Loop\n";
            Amp\Loop::stop();
        });

        $bot->go();
    });
} catch (Exception $e) {
    echo "=================================================\n";
    echo "Exception throw from Loop::run exiting (I HOPE)..\n";
    echo "=================================================\n";
    echo $e . "\n";
    echo "=================================================\n";
    exit(1);
}

/*
 * will probably move this later to some kinda user auth thing
 */


function getUserAuthServ($nick, $bot): \Amp\Promise {
    return \Amp\call(function () use ($nick, $bot) {
        $idx = null;
        $auth = null;
        $success = false;
        $def = new \Amp\Deferred();
        $cb = function ($args, \Irc\Client $bot) use (&$idx, $nick, &$success, &$def) {
            if ($args->nick != 'AuthServ')
                return;
            if (preg_match("/Account information for \x02([^\x02]+)\x02:/", $args->text, $m)) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve($m[1]);
            }
            $rnick = preg_quote($nick, '/');
            if (preg_match("/User with nick \x02{$rnick}\x02 does not exist\./", $args->text) ||
                preg_match("/{$rnick} must first authenticate with \x02AuthServ\x02\./", $args->text)
            ) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve(null);
            }
        };
        $bot->on('notice', $cb, $idx);
        $bot->send("as info $nick");
        $auth = yield \Amp\Promise\timeout($def->promise(), 2000);
        if (!$success)
            $bot->off('notice', null, $idx);
        return $auth;
    });
}

function getUserChanAccess($nick, $chan, $bot): \Amp\Promise {
    return \Amp\call(function () use ($nick, $chan, $bot) {
        $idx = null;
        $auth = null;
        $success = false;
        $def = new \Amp\Deferred();
        $cb = function ($args, \Irc\Client $bot) use (&$idx, $nick, $chan, &$success, &$def) {
            if ($args->nick != 'ChanServ')
                return;
            $rnick = preg_quote($nick, '/');
            $rchan = preg_quote($chan, '/');
            if (preg_match("/{$rnick} [^ ]+ has access \x02([^\x02]+)\x02 in {$rchan}/", $args->text, $m)) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve($m[1]);
            }
            /*
             * Won't recognize suspended users due to response being the following:
             * [ChanServ] knivey (kyte) has access 1 in #california.
             * [ChanServ] knivey's access to #california has been suspended.
             */
            if (preg_match("/User with nick \x02{$rnick}\x02 does not exist\./", $args->text) ||
                preg_match("/{$rnick} must first authenticate with \x02AuthServ\x02\./", $args->text) ||
                preg_match("/{$rnick} [^ ]+ lacks access to {$rchan}\./", $args->text) ||
                preg_match("/{$rchan} has not been registered with ChanServ./", $args->text)
            ) {
                $bot->off('notice', null, $idx);
                $success = true;
                $def->resolve(0);
            }
        };
        $bot->on('notice', $cb, $idx);
        $bot->send("cs $chan access $nick");
        $auth = yield \Amp\Promise\timeout($def->promise(), 2000);
        if (!$success)
            $bot->off('notice', null, $idx);
        return $auth;
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

//TODO replace this with something that doesnt check mtime, probably use rpc to manage admin things off irc
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