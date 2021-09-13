<?php
namespace krasilneg;

use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\WaitGroup;

class Benchmark {
  private $followRedirects = false;

  private $reqTimeout = 3;

  private $defaultHost = null;

  /**
   * @var krasilneg\Cache
   */
  private $cache = null;

  public function __construct(array $options = []) {
    extract($options);
    $this->followRedirects = (isset($followRedirects) && $followRedirects) ? $followRedirects : $this->followRedirects;
    $this->reqTimeout = (isset($reqTimeout) && $reqTimeout) ? $reqTimeout : $this->reqTimeout;
    $this->defaultHost = (isset($defaultHost) && $defaultHost) ? $defaultHost : $this->defaultHost;
    $this->cache = (isset($cache) && ($cache instanceof Cache)) ? $cache : null;
  }

  private function parseUrl(string $url) {
    $target = parse_url($url);
    $target['uri'] = '/';
    if (isset($target['path'])) $target['uri'] .= $target['path'];
    if (isset($target['query'])) $target['uri'] .= "?{$target['query']}";
    if (isset($target['fragment'])) $target['uri'] .= "#{$target['fragment']}";
    return $target;
  }

  private function req($url, $actualUrl = null) {
    $target = $this->parseUrl($actualUrl ?: $url);
    extract($target);
    if ((!isset($host) || !$host) && $actualUrl) {
      $t2 = $this->parseUrl($url);
      $host = isset($t2['host']) ? $t2['host'] : null;
    }
    if (!isset($host) || !$host) {
      $host = $this->defaultHost;
    }
    if (!isset($host) || !$host) {
      return false;
    }
    $c = new Client(
      $host,
      isset($port) ? $port : null,
      isset($scheme) ? $scheme == 'https' : false
    );
    if (isset($user) && isset($pass)) {
      $c->setBasicAuth($user, $pass);
    }
    $c->set([ 'timeout' => $this->reqTimeout ]);
    try {
      $c->get($uri);
      if ($c->getStatusCode() === 200) return true;
      if ($c->getStatusCode() === 302 && $this->followRedirects) {
        $c->close();
        return $this->req($url, $c->getHeaders()['Location']);
      }
    } catch (\Throwable $e) {
      error_log($e, \E_WARNING);
    } finally {
      $c->close();
    }
    return false;
  }

  private function load(array $urls): array {
    $map = [];
    if ($this->cache) {
      $w = new WaitGroup();
      foreach ($urls as $url) {
        $w->add();
        go(function () use ($w, $url, &$map) {
          $v = $this->cache->get($url);
          if ($v !== null) {
            $map[$url] = $v ?: 0;
          }
          $w->done();
        });
      }
      $w->wait();
    }    
    return $map;
  }

  private function save(array $map) {
    if ($this->cache) {
      $w = new WaitGroup();
      foreach ($map as $url => $value) {
        $w->add();
        go(function () use ($w, $url, $value) {
          $this->cache->set($url, $value);
          $w->done();
        });
      }
      $w->wait();
    }
  }

  public function perform(array $urls) {
    $map = $this->load($urls);
    $alive = [];
    $urls = \array_filter($urls, function ($url) use (&$map, &$alive) {
      $res = !isset($map[$url]);
      if ($res) {
        $map[$url] = 0;
        $alive[$url] = true;
      }
      return $res;
    });
    $w = new WaitGroup();
    while (!empty($alive)) {
      $tmp = \array_keys($alive);
      foreach ($tmp as $url) {
        $cr = go(function () use ($url, $w, &$map, &$alive) {
          $w->add();
          $ok = $this->req($url);
          if ($ok) {
            $map[$url]++;
          } else {
            unset($alive[$url]);
          }
          $w->done();
        });
        
        if (!$cr) {
          $alive = [];
        }
      }
    }
    $w->wait(20);

    if (!empty($urls)) {
      $this->save($map);
    }

    return $map;
  }
}
