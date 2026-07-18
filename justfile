# List available recipes
default:
    @just --list

install:
    composer install

update:
    composer update

lint:
    vendor/bin/pint

lint-check:
    vendor/bin/pint --test

analyse:
    vendor/bin/phpstan analyse --memory-limit=1G

test:
    vendor/bin/pest

test-coverage:
    vendor/bin/pest --coverage

check: lint-check analyse test
