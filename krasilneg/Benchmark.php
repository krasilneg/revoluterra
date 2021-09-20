<?php
namespace krasilneg;

use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\WaitGroup;
use Swoole\Server;

class Benchmark {
  private $followRedirects = false;

  private $reqTimeout = 3;

  private $defaultHost = null;

  private $timeout = 30;

  public function __construct(array $options = []) {
    extract($options);
    $this->followRedirects = (isset($followRedirects) && $followRedirects) ? $followRedirects : $this->followRedirects;
    $this->reqTimeout = (isset($reqTimeout) && $reqTimeout) ? $reqTimeout : $this->reqTimeout;
    $this->defaultHost = (isset($defaultHost) && $defaultHost) ? $defaultHost : $this->defaultHost;
    $this->timeout = (isset($timeout) && $timeout) ? $timeout : $this->timeout;
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

  public function processUrl($url) {
    $w = new WaitGroup();
    $alive = true;
    $success = 0;
    while ($alive) {
      $cr = go(function () use ($url, $w, &$success, &$alive) {
        $w->add();
        $ok = $this->req($url);
        if ($ok) {
          $success++;
        } else {
          $alive = false;  
        }
        $w->done();
      });
      if (!$cr) $alive = false;
    }
    $w->wait($this->timeout);
    return $success;
  }
}
