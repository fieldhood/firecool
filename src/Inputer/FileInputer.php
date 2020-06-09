<?php
declare(strict_types=1);

namespace Firecool\Inputer;

use Firecool\Contract\Clazz\Inputer;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class FileInputer extends Inputer
{
    public $type = 'file';

    public $file = '';

    public $lineBreak = "\n";

    public $pos = '';

    protected $inotify = null;

    protected $watch = null;

    protected $fd = null;

    protected $file_pos = 0;

    protected $recover = false;

    public function recover() {
        $this->recover = true;
    }

    public function handle(Channel $chan, Channel $recoverchan) {
        $this->inotify = inotify_init();
        $this->watch = inotify_add_watch($this->inotify, $this->file, IN_MODIFY);
        if ($this->watch === false) {
            fprintf(STDERR, "Failed to watch file '%s'", $this->file);
            return 1;
        }
        $this->fd = fopen($this->file, "r");
        $pos = @file_get_contents($this->pos);
        if (!empty($pos)) {
            $this->file_pos = intval(trim($pos));
        }

        if ($this->file_pos != 0) {
            fseek($this->fd, $this->file_pos - 1);
        }

        Coroutine::create(function() use ($chan, $recoverchan) {
            //位置通道
            $poschan = new Channel(1);
            //读文件,
            Coroutine::create(function() use ($chan, $recoverchan, $poschan) {
                Coroutine::defer(function() {
                    inotify_rm_watch($this->inotify, $this->watch);
                    fclose($this->fd);
                });

                while (($events = inotify_read($this->inotify)) !== false) {
                    if ($this->recover == true) {
                        break;
                    }

                    foreach ($events as $event) {
                        if (!($event['mask'] & IN_MODIFY)) continue;
                        $msg = stream_get_contents($this->fd);
                        $messages = explode($this->lineBreak, $msg);
                        foreach($messages as $message) {
                            if (trim($message) != "") {
                                if (!is_null($this->filter)) {
                                    if (!$this->filter->handle($message)) {
                                        $this->file_pos += strlen($message) + strlen($this->lineBreak);
                                        continue;
                                    }
                                }
                                if (!is_null($this->parser)) {
                                    $message = $this->parser->handle($message);
                                }
                                $chan->push([
                                    "filename" => $this->file,
                                    "message" => $message
                                ]);
                                $this->file_pos += strlen($message) + strlen($this->lineBreak);
                            }
                        }
                    }
                }
            });

            //写位置
            Coroutine::create(function () use ($chan, $recoverchan, $poschan) {
                Coroutine::defer(function() use ($recoverchan) {
                    $recoverchan->push(['inputer_exit' => true]);
                });
                while (1) {
                    if ($this->recover == true) {
                        file_put_contents($this->pos, $this->file_pos);
                        break;
                    }
                    Coroutine::sleep(0.01);
                }
            });
        });
    }
}