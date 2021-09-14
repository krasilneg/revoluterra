FROM php:7.4.2-cli

RUN apt-get update && apt-get install -y \
    openssl \
    libcurl4-openssl-dev \
    libssl-dev \
    wget \
    git \
    procps \
    htop

RUN cd /tmp && git clone https://github.com/swoole/swoole-src.git && \
    cd swoole-src && \
    git checkout v4.6.7 && \
    phpize  && \
    ./configure --enable-openssl --enable-swoole-curl --enable-http2 --enable-mysqlnd && \
    make && make install

RUN touch /usr/local/etc/php/conf.d/swoole.ini && \
    echo 'extension=swoole.so' > /usr/local/etc/php/conf.d/swoole.ini

RUN apt-get autoremove -y && rm -rf /var/lib/apt/lists/*

RUN git clone https://github.com/krasilneg/revoluterra.git /app

RUN wget -O /app/composer-setup.php https://getcomposer.org/installer
RUN cd /app && php composer-setup.php
RUN rm /app/composer-setup.php
RUN cd /app && php composer.phar install
RUN rm /app/composer.phar

# COPY ./krasilneg /app/krasilneg
# COPY ./vendor /app/vendor
# COPY ./index.php /app/index.php

ENV PORT=80

EXPOSE 80/tcp

ENTRYPOINT ["php", "/app/index.php"]