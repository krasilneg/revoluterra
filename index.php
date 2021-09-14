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

$host = getenv('HOST');
$port = getenv('PORT') ?: 80;
$max = getenv('LIMIT');

$server = new Server($host, $port);

if ($max) {
  $server->set([ 'max_coroutine' => $max ]);
}

$server->on('start', function () use ($host, $port) {
  echo "Server started at $host: $port\n";
});

$server->on('request', function (Request $req, Response $res) {
  if ($req->server['request_uri'] === '/sites') {
    if (!isset($req->get['search']) || !$req->get['search']) {
      $res->status(400, 'Bad request');
      $res->end('search query is required.');
      return;
    }
    $redisUrl = getenv('REDIS_URL');

    try {
      $ya = new Ya($req->get['search'], getenv('SEARCH_TIMEOUT'));
      $bm = new Benchmark([
        'followRedirects' => getenv('FOLLOW_REDIRECTS'),
        'requestTimeout' => getenv('REQUEST_TIMEOUT'),
        'cache' => $redisUrl ? new Cache($redisUrl, getenv('CACHE_TTL')) : null
      ]);
      $map = $bm->perform($ya->perform());
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
