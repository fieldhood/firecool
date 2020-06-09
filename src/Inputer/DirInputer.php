<?php
declare(strict_types=1);

namespace Firecool\Inputer;

use Firecool\Contract\Clazz\Inputer;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Symfony\Component\Finder\Finder;

class DirInputer extends Inputer
{
    public $type = 'dir';

    /**
     * 目录
     * @var string
     */
    public $dir = '';

    /**
     * 文件后缀
     * @var string
     */
    public $filepatten = '*.log';

    /**
     * 消息分隔符
     * @var string
     */
    public $lineBreak = "\n";

    public $pos = '';

    protected $files = [];

    protected $files_pos = [];

    protected $files_fd = [];

    protected $inotify = null;

    protected $watch = null;

    protected $recover = false;

    protected function refresh() {
        $finder = new Finder();
        $finder->files()->in([$this->dir])->depth(0)->name($this->filepatten);
        foreach($finder as $file) {
            if (!isset($this->files[$file->getPathname()])) {
                $this->files[$file->getPathname()] = 1;
                $fd = fopen($file->getPathName(), "r");
                $this->files_fd[$file->getPathname()] = $fd;
                $this->files_pos[$file->getPathname()] = 0;
            }
        }
    }

    protected function init() {
        $this->inotify = inotify_init();
        $this->watch = inotify_add_watch($this->inotify, $this->dir, IN_MODIFY);
        if ($this->watch === false) {
            fprintf(STDERR, "Failed to watch dir '%s'", $this->dir);
            return 1;
        }
        $finder = new Finder();
        $finder->files()->in([$this->dir])->depth(0)->name($this->filepatten);
        foreach($finder as $file) {
            $this->files[$file->getPathname()] = 1;
            $fd = fopen($file->getPathName(), "r");
            $this->files_fd[$file->getPathname()] = $fd;
            $this->files_pos[$file->getPathname()] = 0;
        }
        $pos_content = @file_get_contents($this->pos);
        if (!empty($pos_content)) {
            $json = @json_decode($pos_content, true);
            if (is_array($json)) {
                foreach($json as $key => $value) {
                    $this->files_pos[$key] = intval($value);
                }
            }
        }
        foreach($this->files_pos as $key => $value) {
            if ($this->files_pos[$key] != 0) {
                fseek($this->files_fd[$key], $this->files_pos[$key] - 1);
            }
        }
    }

    public function recover() {
        $this->recover = true;
    }

    public function handle(Channel $chan, Channel $recoverchan) {
        $this->init();
        Coroutine::create(function() use ($chan, $recoverchan) {
            $poschan = new Channel(1);
            //读文件,
            Coroutine::create(function() use ($chan, $recoverchan, $poschan) {
                Coroutine::defer(function() {
                    inotify_rm_watch($this->inotify, $this->watch);
                    foreach($this->files_fd as $fd) {
                        fclose($fd);
                    }
                });
                while (($events = inotify_read($this->inotify)) !== false) {
                    $ret = $recoverchan->pop(0.001);
                    if ($this->recover == true) {
                        $poschan->push([
                            'stop' => true
                        ]);
                        Coroutine::sleep(0.1);
                        break;
                    }
                    foreach ($events as $event) {
                        if (!($event['mask'] & IN_MODIFY)) continue;
                        $fpath = $this->dir .'/'. $event['name'];
                        if (is_file($fpath)) {
                            if (!isset($this->files[$fpath])) {
                                $this->refresh();
                            }
                        }
                        if (!isset($this->files_fd[$fpath])) continue;
                        $msg = stream_get_contents($this->files_fd[$fpath]);
                        $messages = explode($this->lineBreak, $msg);
                        foreach ($messages as $message) {
                            if (trim($message) != "") {
                                if (!is_null($this->filter)) {
                                    if (!$this->filter->handle($message)) {
                                        $this->files_pos[$fpath] += strlen($message) + strlen($this->lineBreak);
                                        continue;
                                    }
                                }
                                if (!is_null($this->parser)) {
                                    $message = $this->parser->handle($message);
                                }
                                $chan->push([
                                    "filename" => $fpath,
                                    "message" => $message
                                ]);
                                $this->files_pos[$fpath] += strlen($message) + strlen($this->lineBreak);
                            }
                        }
                    }
                }
            });

            //写位置
            Coroutine::create(function () use ($poschan, $recoverchan) {
                Coroutine::defer(function() use ($recoverchan) {
                    $recoverchan->push(['inputer_exit' => true]);
                });
                while (1) {
                    if ($this->recover == true) {
                        file_put_contents($this->pos, json_encode($this->files_pos));
                        break;
                    }
                    Coroutine::sleep(0.01);
                }
            });
        });
    }
}