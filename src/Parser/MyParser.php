<?php
declare(strict_types=1);
namespace Firecool\Parser;

use Firecool\Contract\Clazz\Parser;

class MyParser extends Parser
{
    /**
     * @param string $message
     * @return string
     */
    public function handle(string $message) {
        $newMessage = "";
        $tmp = explode(" ", $message);
        $newMessage = sprintf("%s, %s %s, %s", $tmp[3], $tmp[1], $tmp[2], $tmp[0]);
        return $newMessage;
    }
}