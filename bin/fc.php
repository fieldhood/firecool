<?php
require __DIR__ .'/../vendor/autoload.php';

$type = require __DIR__ . '/../etc/type.php';

$argc = $_SERVER['argc'];
$argv = $_SERVER['argv'];

if ($argc != 2) {
    exit('Not found config file');
}

$configfile = $argv[1];

if (!is_file(realpath($configfile))) {
    exit(' config file error');
}

Swoole\Runtime::enableCoroutine();
$config = Symfony\Component\Yaml\Yaml::parseFile(realpath($configfile));

/**
 * @var \Firecool\Contract\Inputer
 */
$inputer = null;
/**
 * @var \Firecool\Contract\Outputer
 */
$outputer = null;

$input = $config['input'];
$output = $config['output'];


$inputer = new $type['input'][$input['type']];
foreach ($input as $key => $value) {
    if ($key == 'filter') {
        if (isset($type['filter'][$value])) {
            $inputer->filter = new $type['filter'][$value];
        }
        if (!$inputer->filter instanceof Firecool\Contract\Filter) {
            $inputer->filter = null;
        }
        continue;
    }
    if ($key == 'parser') {
        if (isset($type['parser'][$value])) {
            $inputer->parser = new $type['parser'][$value];
        }
        if (!$inputer->parser instanceof Firecool\Contract\Parser) {
            $inputer->parser = null;
        }
        continue;
    } else {
        $inputer->{$key} = $value;
    }
}

$outputer = new $type['output'][$output['type']];
foreach ($output as $key => $value) {
    $outputer->{$key} = $value;
}

$msgchan = new Swoole\Coroutine\Channel(1);
$recoverchan = new Swoole\Coroutine\Channel(1);
$inputer->handle($msgchan, $recoverchan);
$outputer->handle($msgchan, $recoverchan);

function recover($inputer, $outputer, $recoverchan) {
    $inputer->recover();
    $outputer->recover();
    Swoole\Coroutine::create(function() use($recoverchan) {
        $inputer_exit = false;
        $outputer_exit = false;
        while(1) {
            //var_dump("input: ", $inputer_exit, "output: ", $outputer_exit);
            if ($inputer_exit && $outputer_exit) {
                break;
            }
            $msg = $recoverchan->pop(0.01);
            if ($msg && is_array($msg)) {
                if (isset($msg['inputer_exit']) && $msg['inputer_exit'] == true) {
                    $inputer_exit = true;
                } else if (isset($msg['outputer_exit']) && $msg['outputer_exit'] == true) {
                    $outputer_exit = true;
                }
            }
        }
        Swoole\Event::exit();
    });
}
Swoole\Process::signal(SIGTERM, function($signo) use ($inputer, $outputer, $recoverchan) {
    recover($inputer, $outputer, $recoverchan);
});
Swoole\Process::signal(SIGINT, function($signo) use ($inputer, $outputer, $recoverchan) {
    recover($inputer, $outputer, $recoverchan);
});

