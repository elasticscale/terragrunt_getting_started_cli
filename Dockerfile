FROM php:8.2-cli

RUN apt-get update && \
    apt-get upgrade -y && \
    apt-get install -y git zip libzip-dev

RUN git config --global user.name "Alex Jeensma"
RUN git config --global user.email "alex@elasticscale.cloud"

RUN docker-php-ext-install -j$(nproc) zip

COPY . /var/app

WORKDIR /var/app

CMD [ "php", "cli.php", "repo" ]