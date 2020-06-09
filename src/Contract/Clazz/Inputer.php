<?php
namespace Firecool\Contract\Clazz;

use Swoole\Coroutine\Channel;

abstract class Inputer implements \Firecool\Contract\Inputer
{
    /**
     * 名字
     * @var string
     */
    public $name;

    /**
     * 过滤器
     * @var Firecool\Contract\Filter
     */
    public $filter = null;

    /**
     * 解析器
     * @var Firecool\Contract\Parser
     */
    public $parser = null;

    abstract public function recover();
    abstract public function handle(Channel $chan, Channel $recoverchan);
}