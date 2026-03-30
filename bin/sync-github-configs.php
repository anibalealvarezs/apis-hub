#!/usr/bin/env php
<?php

$packages = [
    'api-client-skeleton',
    'OAuth-v1',
    'amazon-api-anibal',
    'facebook-graph-api',
    'google-api-anibal',
    'klaviyo-api-anibal',
    'mailchimp-api-anibal',
    'netsuite-api-anibal',
    'shopify-api-anibal',
    'triple-whale-api-anibal'
];

$basePath = 'd:/laragon/www/';

$ciContent = <<<'YAML'
name: CI

on:
  push:
    branches: [ develop, main ]
  pull_request:
    branches: [ develop, main ]

jobs:
  tests:
    runs-on: ubuntu-latest

    steps:
    - uses: actions/checkout@v4

    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.3'
        extensions: mbstring, json, dom, curl, libxml, pdo_mysql, pdo_pgsql, redis
        coverage: none

    - name: Install dependencies
      run: composer install --prefer-dist --no-progress --ignore-platform-req=ext-imap

    - name: Run PHPUnit
      run: |
        if [ -f vendor/bin/phpunit ]; then
          vendor/bin/phpunit
        fi

    - name: Run PHPStan
      run: |
        if [ -f vendor/bin/phpstan ]; then
          vendor/bin/phpstan analyse
        fi
YAML;

$contributingContent = <<<'MARKDOWN'
# Contributing Guideline

Thank you for your interest in contributing!

## How to Contribute

1. **Bug Reports**: Use the GitHub issue tracker to report bugs. Please include steps to reproduce.
2. **Feature Requests**: Open an issue to discuss new features before implementing them.
3. **Pull Requests**:
   - Branch from `develop`.
   - Ensure all tests pass (`vendor/bin/phpunit`).
   - Ensure static analysis passes (`vendor/bin/phpstan analyse`).
   - Follow PSR-12 coding standards.

## Development Setup

1. Clone the repository.
2. Run `composer install`.
3. Run `vendor/bin/phpunit` to verify the setup.
MARKDOWN;

$codeOfConductContent = <<<'MARKDOWN'
# Contributor Covenant Code of Conduct

## Our Pledge

In the interest of fostering an open and welcoming environment, we as contributors and maintainers pledge to making participation in our project and our community a harassment-free experience for everyone, regardless of age, body size, disability, ethnicity, sex characteristics, gender identity and expression, level of experience, education, socio-economic status, nationality, personal appearance, race, religion, or sexual identity and orientation.

## Our Standards

Examples of behavior that contributes to creating a positive environment include:

* Using welcoming and inclusive language
* Being respectful of differing viewpoints and experiences
* Gracefully accepting constructive criticism
* Focusing on what is best for the community
* Showing empathy towards other community members

Examples of unacceptable behavior by participants include:

* The use of sexualized language or imagery and unwelcome sexual attention or advances
* Trolling, insulting/derogatory comments, and personal or political attacks
* Public or private harassment
* Publishing others' private information, such as a physical or electronic address, without explicit permission
* Other conduct which could reasonably be considered inappropriate in a professional setting

## Our Responsibilities

Project maintainers are responsible for clarifying the standards of acceptable behavior and are expected to take appropriate and fair corrective action in response to any instances of unacceptable behavior.

## Enforcement

Instances of abusive, harassing, or otherwise unacceptable behavior may be reported by contacting the project team at <mailto:anibalealvarezs@gmail.com>. All complaints will be reviewed and investigated and will result in a response that is deemed necessary and appropriate to the circumstances. The project team is obligated to maintain confidentiality with regard to the reporter of an incident. 

## Attribution

This Code of Conduct is adapted from the [Contributor Covenant][homepage], version 1.4, available at <https://www.contributor-covenant.org/version/1/4/code-of-conduct.html>

[homepage]: https://www.contributor-covenant.org
MARKDOWN;

$bugReportTemplate = <<<'MARKDOWN'
---
name: Bug report
about: Create a report to help us improve
title: ''
labels: bug
assignees: ''

---

## Describe the bug

A clear and concise description of what the bug is.

## To Reproduce

Steps to reproduce the behavior:

1. Go to '...'
2. Click on '....'
3. Scroll down to '....'
4. See error

## Expected behavior

A clear and concise description of what you expected to happen.

## Screenshots

If applicable, add screenshots to help explain your problem.

## Desktop (please complete the following information)

- OS: [e.g. iOS]
- Browser [e.g. chrome, safari]
- Version [e.g. 22]

## Additional context

Add any other context about the problem here.
MARKDOWN;

$featureRequestTemplate = <<<'MARKDOWN'
---
name: Feature request
about: Suggest an idea for this project
title: ''
labels: enhancement
assignees: ''

---

## Is your feature request related to a problem? Please describe.

A clear and concise description of what the problem is. Ex. I'm always frustrated when [...]

## Describe the solution you'd like

A clear and concise description of what you want to happen.

## Describe alternatives you've considered

A clear and concise description of any alternative solutions or features you've considered.

## Additional context

Add any other context or screenshots about the feature request here.
MARKDOWN;

foreach ($packages as $pkg) {
    echo "Configuring $pkg...\n";
    $path = $basePath . $pkg . '/';
    if (!is_dir($path)) {
        echo "Directory $path not found. Skipping.\n";
        continue;
    }

    // .github/workflows
    @mkdir($path . '.github/workflows', 0777, true);
    file_put_contents($path . '.github/workflows/ci.yml', $ciContent);

    // .github/ISSUE_TEMPLATE
    @mkdir($path . '.github/ISSUE_TEMPLATE', 0777, true);
    file_put_contents($path . '.github/ISSUE_TEMPLATE/bug_report.md', $bugReportTemplate);
    file_put_contents($path . '.github/ISSUE_TEMPLATE/feature_request.md', $featureRequestTemplate);

    // Root files
    file_put_contents($path . 'CONTRIBUTING.md', $contributingContent);
    file_put_contents($path . 'CODE_OF_CONDUCT.md', $codeOfConductContent);
}

echo "All packages configured successfully.\n";
