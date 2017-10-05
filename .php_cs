<?php

$finder = PhpCsFixer\Finder::create()->in(__DIR__ . '/okapi');

return PhpCsFixer\Config::create()
   ->setRules([
       '@PSR2' => true,
       '@Symfony' => true,
       'no_useless_else' => true,
       'no_useless_return' => true,
       'ordered_imports' => true,
       'visibility_required' => true,
   ])
    ->setFinder($finder);
