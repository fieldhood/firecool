<?php

namespace Firecool\Contract\Clazz;

abstract class Filter implements \Firecool\Contract\Filter
{
    abstract public function handle(string $message);
}