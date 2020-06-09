<?php
declare(strict_types=1);

namespace Firecool\Filter;

use Firecool\Contract\Clazz\Filter;

class MyFilter extends Filter
{
    /**
     * 返回true 保留， 返回false 抛弃
     * @param string $message
     */
    public function handle(string $message) {
        if (strpos($message, "INFO") !== false) {
            return false;
        }
        return true;
    }
}