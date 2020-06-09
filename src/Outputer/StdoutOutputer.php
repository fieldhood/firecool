<?php
declare(strict_types=1);

namespace Firecool\Outputer;

use Firecool\Contract\Clazz\Outputer;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class StdoutOutputer extends Outputer
{
    protected $recover = false;

    public function recover() {
        $this->recover = true;
    }

    public function handle(Channel $chan, Channel $recoverchan) {
        Coroutine::create(function() use ($chan, $recoverchan) {
            while(true) {
                Coroutine::sleep(0.01);
                $msg = $chan->pop(0.001);
                if ($msg === false) {
                    if ($this->recover == true) {
                        $recoverchan->push([
                            'outputer_exit' => true
                        ]);
                        break;
                    }
                    continue;
                }
                echo json_encode($msg), "\n";
            }
        });
    }
}