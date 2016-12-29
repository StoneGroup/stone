# 关于Stone

Stone是一个优化Laravel框架性能的方案， 能大幅提升基于Laravel的程序性能，使Laravel能轻松应对高并发的使用场景。

## 优化原理
如果你正在考虑框架性能优化的问题， 你应该对PHP应该已经有足够的了解。 如你所知， PHP每次请求结束， 都会释放掉执行中建立的所有资源。这样有一个很大的好处：PHP程序员基本不用费力去考虑资源释放的问题，诸如内存，IO句柄，数据库连接等，请求结束时PHP将全部释放。PHP程序员几乎不用关心内存释放的问题，也很难写出内存泄露的程序。这让PHP变得更加简单容易上手， 直抒心意。但是同时也带来了一个坏处：PHP很难在请求间复用资源， 类似PHP框架这种耗时的工作， 每次请求都需要反复做——即使每次都在做同样的事情。也正因为如此，在PHP发展过程中，关于是否使用框架的争论也从未停止过。

Stone主要优化的就是这个问题。 在框架资源初始化结束后再开启一个FastCGI服务，这样， 新的请求过来是直接从资源初始化结束后的状态开始，避免每次请求去做资源初始化的事情。所以， 本质上， Stone运行时是常驻内存的，它和PHP-FPM一样，是一个FastCGI的实现，不同的是， FPM每次执行请求都需要重新初始化框架， Stone直接使用初始化的结果。

同样，事情总是有好有坏。使用Stone后的坏处是：PHP编程变得更难了。你需要考虑内存的释放，需要关心PHP如何使用内存。甚至， 你需要了解使用的框架，以免『不小心』写出让人『惊喜』的效果。同时， PHP的调试变得更难， 因为每次修改程序后需要重启进程才能看到效果。事实上，开发Stone时针对调试这方面做了不少工作。好处是：程序的性能得到极大的提高。

当然， 客观上的一些利好因素是：

* PHP的内存回收已经相当稳定和高效
* Swoole稳定性已经在相当多的项目中得到验证
* Laravel代码质量相当高

正是因为有了这些条件， 才使得Stone的出现成为可能， 感谢这个伟大的开源时代！

## 性能对比

| 应用类型        | 原始Laravel | Stone-Web | Stone-Server | 原生php直接echo |
| -------------- | ---------- | --------- | ------------ | ---- |
| laravel5 默认页面 |  150   | 3000  |  --  | -- |
| laravel5 简单接口 |  150   | 3000  |  8500  | 9500 |
| laravel4 实际项目简单页面 |  70   | 1000  |  --  | -- |
| laravel4 简单接口 |  120   | -- |  8200  | 9500 |
| laravel4 实际项目首页 |  35   | 380 |  --  | -- |

* 以上单位全部为RPS
* Stone相对于原始的Laravel有相当可观的提升
* 即使和一个简单的echo相比， Stone性能损失仅10%左右

## 快速指南

### 以Laravel5.3为例

1. 按照Laravel官方文档安装laravel，如果已经安装可以跳过
2. composer安装stone kernel

    ```
    composer require stone/kernel:dev-master
    ```
       
3. 编辑config/app.php，添加Provider

    ```php
     'providers' => [

        /*
         * Laravel Framework Service Providers...
         */
        Illuminate\Auth\AuthServiceProvider::class,
        // 省略很多行

        /*
         * Package Service Providers...
         */
        Stone\StoneServiceProvider::class,

        /*
         * Application Service Providers...
         */
        App\Providers\AppServiceProvider::class,
    ],

    ```
4. 编辑app/Console/Kernel.php，添加新命令
    
    ```php
    protected $commands = [
        \Stone\Console\Commands\StoneServer::class,
    ];
    ```
5. Stone的安装已经完成，正常情况下stone:server的命令应该可以正常执行了

    ```
    php ./artisan stone:server --help
    
    Usage:
        stone:server [options]

    Options:
          --debug
          --start
          --reload
          --stop
      -h, --help            Display this help message
      -q, --quiet           Do not output any message
      -V, --version         Display this application version
          --ansi            Force ANSI output
          --no-ansi         Disable ANSI output
      -n, --no-interaction  Do not ask any interactive question
          --env[=ENV]       The environment the command should run under
      -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
    
    Help:
      A FastCGI server bases on swoole and laravel
    ```
6. 下面我们继续，让Server跑起来， 首先需要设定一些server的参数，我们需要新建一个config/stone.php

    ```php
    <?php
    return [
        'server' => [
            'user' => 'www-data', // 运行用户，一般和php-fpm运行用户相同
            'group' => 'www-data', // 运行组，同上
            'domain' => '/var/run/stone-server-fpm.sock', // unix domain socket地址，用来与nginx进程通信，推荐保持默认即可，如果系统不支持也可以是ip地址
            'port' => 9101, // 端口
            'handler' => 'App\Servers\Handler', // 请求处理器, 一个class名称
            'pid' => '/var/run/stone.pid', // 进程文件
        ]
    ];
    ```
7. 建立Handler, 新建app/Servers/Handler.php
    
    ```php
    <?php namespace App\Servers;
    
    use Stone\Contracts\RequestHandler;
    use Response;
    
    class Handler implements RequestHandler
    {
        public function process()
        {
            return Response::make('hello, stone server!');
        }
    
        public function onWorkerStart()
        {    
        }
    
        public function handleException($e)
        {    
        }
    }

    ```
    
8. 启动server， 正常情况下会显示一个ok

    ```
    sudo php ./artisan stone:server --debug
    ```
9. 配置nginx， 我们把特定的url转发到stone server上来， 让php-fpm和stone共存， 这样方便对比性能， 也可以自由选择是否使用stone server。

    ```
    server {
    
        listen          80;
        server_name  127.0.0.1;
    
        access_log  /var/log/nginx/stone_access.log;
        error_log   /var/log/nginx/stone_error.log;
    
        location ~* (^.+\.(example)$)|(.*protected.*|\.git.*) {
            deny all;
        }
        root   /home/stone/stone-demo/public;
        index  index.html index.htm index.php;
    
        location / {
                try_files $uri $uri/ /index.php?$query_string;
        }
    
        location ~ \.php$ {
            fastcgi_split_path_info ^(.+\.php)(/.+)$;
            fastcgi_pass unix:/var/run/php5-fpm.sock;
            fastcgi_index index.php;
            include fastcgi_params;
        }
    
        location /server/ {
            fastcgi_split_path_info ^(.+\.php)(/.+)$;
            fastcgi_index index.php;
            fastcgi_pass unix:/var/run/stone-server-fpm.sock; # Stone
            include fastcgi_params;
        }
    }
    ```
    
10. 重启nginx， 访问 http://127.0.0.1/server/


如果你对此感兴趣， 继续了解[Stone在线中文文档](https://chefxu.gitbooks.io/stone-docs/content/) 或者[马上安装](https://chefxu.gitbooks.io/stone-docs/content/quick_start.html)吧。

# 更多资源

[Stone在线中文文档](https://chefxu.gitbooks.io/stone-docs/content/) （更新及时，推荐）

# 联系反馈
rssidea(at)qq.com
