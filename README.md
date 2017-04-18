Generate release changelogs using [ZenHub](https://www.zenhub.com/) pipelines.

This script will:

- generate a changelog based on issues in a ZenHub pipeline
- create a GitHub release and set the changelog in its description

## Getting started

First, clone the repository or [download a stable release](https://github.com/wizaplace/zenhub-release/releases) and unzip it. Then install the dependencies using Composer:

```
composer install
```

To create a release, run the command with all the arguments:

```
./zenhub-release release wizaplace/wizaplace 1.17.04.13.0 --pipeline="À déployer" --github-token=... --zenhub-token=...
```
