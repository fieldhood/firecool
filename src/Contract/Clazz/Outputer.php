<?php
namespace Firecool\Contract\Clazz;

use Swoole\Coroutine\Channel;

abstract class Outputer implements \Firecool\Contract\Outputer
{
    public $name;

    //回收
    abstract public function recover();
    abstract public function handle(Channel $chan, Channel $recoverchan);
}