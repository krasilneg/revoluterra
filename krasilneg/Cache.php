<?php

namespace krasilneg;

use Swoole\Coroutine\Redis;
use Swoole\Coroutine\WaitGroup;

class Cache {
  private $redis;

  private $host;

  private $port;

  private $ttl;

  private $timeout = 1;

  public function __construct(string $url, int $ttl = null, int $timeout = null) {
    $parts = \parse_url($url);
    \extract($parts);
    $this->host = (isset($host) && $host) ? $host : '127.0.0.1';
    $this->port = (isset($port) && $port) ? $port : 6379;
    $this->redis = new Redis();
    if ($ttl) $this->ttl = $ttl;
    if ($timeout) $this->timeout = $timeout;
  }

  public function connect() {
    $this->redis->connect($this->host, $this->port);
  }

  public function set(string $url, int $value) {
    $this->redis->set($url, $value, $this->ttl);
  }

  public function get(string $url) {
    return $this->redis->get($url);
  }

  public function load(array $urls): array {
    $map = [];
    $w = new WaitGroup();
    foreach ($urls as $url) {
      $w->add();
      go(function () use ($w, $url, &$map) {
        $v = $this->get($url);
        if ($v !== null) {
          $map[$url] = $v ?: 0;
        }
        $w->done();
      });
    }
    $w->wait($this->timeout);    
    return $map;
  }

  public function save(array $map) {
    $w = new WaitGroup();
    foreach ($map as $url => $value) {
      $w->add();
      go(function () use ($w, $url, $value) {
        $this->set($url, $value);
        $w->done();
      });
    }
    $w->wait($this->timeout);
  }  
}