<?php

namespace Firecool\Contract;

interface Filter
{
    public function handle(string $message);
}