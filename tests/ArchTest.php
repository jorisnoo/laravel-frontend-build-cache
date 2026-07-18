<?php

arch('source does not use debugging functions')
    ->expect('Noo\LaravelFrontendBuildCache')
    ->not->toUse(['dd', 'dump', 'ray']);
