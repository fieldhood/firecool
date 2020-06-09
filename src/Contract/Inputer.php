<?php
namespace Firecool\Contract;

use Swoole\Coroutine\Channel;

interface Inputer
{
    public function recover();
    public function handle(Channel $chan, Channel $recoverchan);
}