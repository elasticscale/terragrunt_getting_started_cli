FROM php:8.2-cli

COPY . /var/app

WORKDIR /var/app

CMD [ "php", "cli.php", "repo" ]