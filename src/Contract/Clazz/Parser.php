<?php

namespace Firecool\Contract\Clazz;

abstract class Parser implements \Firecool\Contract\Parser
{
    abstract public function handle(string $message);
}