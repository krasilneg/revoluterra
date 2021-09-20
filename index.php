<?php
error_reporting(E_ERROR);

include "vendor/autoload.php";
spl_autoload_register(function ($class_name) {
  include __DIR__.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $class_name).'.php';
});

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use krasilneg\Ya;
use krasilneg\Benchmark;
use krasilneg\Cache;
use Swoole\Server as SwooleServer;
use Swoole\Server\Task;

$host = getenv('HOST');
$port = getenv('PORT') ?: 80;
$max = getenv('LIMIT');
$redisUrl = getenv('REDIS_URL');
$searchTO = getenv('SEARCH_TIMEOUT') ?: 3;
$cacheTO = $redisUrl ? (getenv('CACHE_TIMEOUT') ?: 2) : 0;
$bmTO = 30 - $searchTO - 2 * $cacheTO;

$server = new Server($host, $port);

$ya = new Ya($searchTO);

$bm = new Benchmark([
  'followRedirects' => getenv('FOLLOW_REDIRECTS'),
  'requestTimeout' => getenv('REQUEST_TIMEOUT'),
  'timeout' => $bmTO * 0.9
]);

$cache = $redisUrl ? new Cache($redisUrl, getenv('CACHE_TTL'), $cacheTO) : null;

$server->set([
  'worker_num' => 8,
  'task_worker_num' => 60,
  'task_enable_coroutine' => true
]);

if ($max) {
  $server->set([
    'max_coroutine' => $max
  ]);
}

$server->on('start', function () use ($host, $port) {
  echo "Server started at $host: $port\n";
});

$server->on('task', function (SwooleServer $server, Task $task) use ($bm) {
  $task->finish($bm->processUrl($task->data));
});

$server->on('request', function (Request $req, Response $res) use ($server, $ya, $bmTO, $cache) {
  if ($req->server['request_uri'] === '/sites') {
    if (!isset($req->get['search']) || !$req->get['search']) {
      $res->status(400, 'Bad request');
      $res->end('search query is required.');
      return;
    }
    try {
      $urls = $ya->perform($req->get['search']);
      $map = $cache ? $cache->load($urls) : [];
      $urls = \array_filter($urls, function ($url) use (&$map) {
        $res = !isset($map[$url]);
        if ($res) {
          $map[$url] = 0;
        }
        return $res;
      });
  
      $results = $server->taskCo($urls, $bmTO);
  
      foreach ($urls as $i => $url) {
        $map[$url] = $results[$i] ?: 0;
      }
  
      if ($cache && !empty($urls)) {
        $cache->save($map);
      }
      $res->end(json_encode($map));
    } catch (\Throwable $e) {
      $res->status(500, 'Server error');
      $res->end($e->getMessage());
    }
    return;
  }

  $res->status(404, 'Not found');
  $res->end('Unrecognized method');
});

$server->start();
