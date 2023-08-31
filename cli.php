<?php

require('vendor/autoload.php');
require('RepoCommand.php');
require('AwsCommand.php');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$app = new Ahc\Cli\Application('ElasticScale Terragrunt repo initialization tool', 'v0.0.1');
$app->add(new RepoCommand(), 'repo');
$app->add(new AwsCommand(), 'aws');
$app->handle($_SERVER['argv']);
