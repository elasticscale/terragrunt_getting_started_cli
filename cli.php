<?php

require('vendor/autoload.php');
require('RepoCommand.php');

// php 8.2.8 (cli)
// git


$app = new Ahc\Cli\Application('Terragrunt initialization tool', 'v0.0.1');
$app->add(new RepoCommand(), 'repo');
$app->handle($_SERVER['argv']);
