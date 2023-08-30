<?php

require('vendor/autoload.php');
require('InitCommand.php');

// php 8.2.8 (cli)
// git


$app = new Ahc\Cli\Application('Terragrunt initialization tool', 'v0.0.1');
$app->add(new InitCommand(), 'init');
$app->handle($_SERVER['argv']);
