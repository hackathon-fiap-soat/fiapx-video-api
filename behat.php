<?php

use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Tests\Behat\SagaContext;

// BDD da SAGA: roda dentro do compose (MySQL do fiapx-infra/local), cada cenário numa transação desfeita.
return (new Config)->withProfile(
    (new Profile('default'))->withSuite(
        (new Suite('saga'))->withPaths('%paths.base%/features')->withContexts(SagaContext::class),
    ),
);
