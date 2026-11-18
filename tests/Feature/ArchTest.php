<?php

arch('package code never references the host App namespace')
    ->expect('Omarsenusi\MarketplacePlatforms')
    ->not->toUse('App');
