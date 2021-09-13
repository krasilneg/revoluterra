<?php

namespace krasilneg;

use Swoole\Coroutine\Redis;

class Cache {
  private $redis;

  private $host;

  private $port;

  private $ttl;

  public function __construct(string $url, int $ttl = null) {
    $parts = \parse_url($url);
    \extract($parts);
    $this->host = (isset($host) && $host) ? $host : '127.0.0.1';
    $this->port = (isset($port) && $port) ? $port : 6379;
    $this->redis = new Redis();
    if ($ttl) $this->ttl = $ttl;
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
}