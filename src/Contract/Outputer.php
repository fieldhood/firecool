<?php
namespace Firecool\Contract;

use Swoole\Coroutine\Channel;
interface Outputer
{
    public function recover();

    public function handle(Channel $chan, Channel $recoverchan);
}