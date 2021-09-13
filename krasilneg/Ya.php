<?php
namespace krasilneg;

use voku\helper\HtmlDomParser;
use Swoole\Coroutine\Http\Client;

class Ya {
  private const PATH = '/search/touch/?service=www.yandex&ui=webmobileapp.yandex&numdoc=50&lr=213&p=0&text=%s';

  private $uri;

  private $timeout = 5;

  public function __construct($query, $timeout = null) {
    $this->uri = \sprintf(self::PATH, \urlencode($query));
    if ($timeout) $this->timeout = $timeout;
  }

  private function parse(string $body) {
    $dom = HtmlDomParser::str_get_html($body);
    $parsedElements = $dom->findMulti('.serp-item');

    $urls = [];
    foreach ($parsedElements as $element) {
      $elementDataAttributes = $element->getAllAttributes();
      $directMarks = array_filter(
        array_keys($elementDataAttributes),
        fn($key) => str_contains($key, 'data-') && mb_strlen($key) === 9 && $key !== 'data-fast'
      );

      $linkElement = $element->findOne('a');
      $link = $linkElement->getAttribute('href');

      if (str_starts_with($link, 'https://yandex.ru/turbo/') || mb_strpos($link, 'turbopages') !== false) {
        $turboLink = $linkElement->getAttribute('data-counter');
        $turboLink = json_decode(htmlspecialchars_decode($turboLink))[1] ?? $link;
        $link = $turboLink;
      }

      if (
        count($directMarks) >= 2
        || str_starts_with($link, '//')
        || str_contains($link, 'yabs.yandex.ru/count')
        || str_contains($link, 'yandex.com/images')
      ) {
        continue;
      }

      $urls[] = $link;
    }

    return $urls;
  }

  public function perform() {
    $c = new Client('yandex.ru', null, true);
    $c->set([ 'timeout' => $this->timeout ]);
    $c->setDefer(true);
    try {
      $c->get($this->uri);
      $c->recv(5);
      if ($c->getStatusCode() !== 200) {
        throw new \Exception("Yandex search failed with status {$c->getStatusCode()}");
      }
      return $this->parse($c->getBody());
    } catch (\Throwable $e) {
      throw $e;
    } finally {
      $c->close();
    }
  }
}
