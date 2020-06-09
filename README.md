# firecool
一个简单的日志收集器

##### 目录结构
```shell script
├── bin
│   ├── composer.phar  安装用的
│   ├── fc.php        入口文件
│   ├── php           php解析器(内核3.0以上linux可用, 已静态编译)
│   └── php.ini       php配置
├── composer.json
├── etc               配置文件
│   ├── dir.yaml
│   ├── file.yaml
│   └── type.php      类映射
├── README.md
└── src
    ├── Contract      约定接口和抽象类
    │   ├── Clazz
    │   │   ├── Filter.php
    │   │   ├── Inputer.php
    │   │   ├── Outputer.php
    │   │   └── Parser.php
    │   ├── Filter.php
    │   ├── Inputer.php
    │   ├── Outputer.php
    │   └── Parser.php
    ├── Filter       过滤器
    │   └── MyFilter.php
    ├── Inputer      输入器
    │   ├── DirInputer.php
    │   └── FileInputer.php
    ├── Outputer     输出器
    │   └── StdoutOutputer.php
    └── Parser       解析器
        └── MyParser.php
```

##### 类映射配置 etc/type.php
```php
<?php
return [
    'input' => [
        'file' => 'Firecool\Inputer\FileInputer',
        'dir' => 'Firecool\Inputer\DirInputer',
    ],
    'output' => [
        'stdout' => 'Firecool\Outputer\StdoutOutputer'
    ],
    'filter' => [
        'myfilter' => 'Firecool\Filter\MyFilter'
    ],
    'parser' => [
        'myparser' => 'Firecool\Parser\MyParser'
    ]
];
```

##### Inputer 输入器

输入器读取日志， 调用过滤器/解析器， 然后通过channel，发给 输出器

```php
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
```

##### Outputer 输出器

通过channel 获取日志，进行处理

```php
<?php
declare(strict_types=1);

namespace Firecool\Outputer;

use Firecool\Contract\Clazz\Outputer;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class StdoutOutputer extends Outputer
{
    protected $recover = false;

    public function recover() {
        $this->recover = true;
    }

    public function handle(Channel $chan, Channel $recoverchan) {
        Coroutine::create(function() use ($chan, $recoverchan) {
            while(true) {
                Coroutine::sleep(0.01);
                $msg = $chan->pop(0.001);
                if ($msg === false) {
                    if ($this->recover == true) {
                        $recoverchan->push([
                            'outputer_exit' => true
                        ]);
                        break;
                    }
                    continue;
                }
                echo json_encode($msg), "\n";
            }
        });
    }
}
```

##### Parser 解析器
解析器处理一条日志信息， 进行转换， 并返回
```php
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
```

##### Filter 过滤器
过滤器处理一条日志信息, 进行过滤, 返回false, 抛掉日志, 返回true 保留
```php
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
```
#### 安装
```shell script
bin/php bin/composer.phar config -g repo.packagist composer https://mirrors.aliyun.com/composer
bin/php bin/composer.phar install -vvv
```

#### 配置
```yaml
input:
  #名字
  name: myfile
  #类型
  type: file
  #分隔符
  lineBreak: "\n"
  #文件位置
  file: /home/mytest/test1.log
  #记录文件读取位置的文件
  pos: /tmp/file.pos
  #过滤器
  filter: myfilter
  #解析器
  parser: myparser
output:
  name: console
  type: stdout
```

#### 运行
产生日志
```shell
bin/php test/makelog.php
```

收集
```shell script
bin/php bin/fc.php etc/file.yaml
```
