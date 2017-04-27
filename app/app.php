<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Silly\Application;
use Symfony\Component\Console\Output\OutputInterface;

require_once __DIR__ . '/../vendor/autoload.php';

$app = new Application;

$app->command(
    'release repository name [--pipeline=] [--github-token=] [--zenhub-token=]',
    function ($repository, $name, $pipeline, $githubToken, $zenhubToken, OutputInterface $output) {
        $releaseName = $name;

        $deployPipelineName = $pipeline;
        if (empty($deployPipelineName)) {
            throw new Exception('You must provide the name of the deploy pipeline using the --pipeline=... option');
        }

        $http = new Client();

        checkReleaseDoesNotExist($http, $githubToken, $repository, $releaseName);

        $repositoryId = getRepositoryId($http, $githubToken, $repository);

        $board = getZenhubBoard($http, $zenhubToken, $repositoryId);

        $deployPipeline = findDeployPipelineInBoard($board, $deployPipelineName);

        // List all issues in that pipeline
        $markdown = [];
        $output->writeln('<comment>Issues deployed in the release:</comment>');
        foreach ($deployPipeline['issues'] as $issue) {
            $issueNumber = $issue['issue_number'];
            $response = $http->request('GET', "https://api.github.com/repos/$repository/issues/$issueNumber", [
                'headers' => [
                    'Authorization' => 'token ' . $githubToken,
                ],
            ]);
            $issueInfo = json_decode((string) $response->getBody(), true);

            $markdown[] = sprintf('- #%d: %s', $issueNumber, $issueInfo['title']);
            $output->writeln(sprintf('<info>#%d</info>: %s', $issueNumber, $issueInfo['title']));
        }
        $events = getEventsSinceLastRelease($http, $githubToken, $repository, $releaseName);

        $markdown[] = '<details><summary>PRs and rogue commits</summary>';
        $output->writeln('<comment>PRs and rogue commits:</comment>');
        foreach ($events['PRs'] as $PR) {
            $markdown[] = sprintf('- #%d: %s', $PR['number'], $PR['title']);
            $output->writeln(sprintf('<info>%s</info>: %s', $PR['html_url'], $PR['title']));
        }
        foreach ($events['commits'] as $commit) {
            $markdown[] = sprintf('- %s: %s', $commit['sha'], $commit['commit']['message']);
            $output->writeln(sprintf('<info>%s</info>: %s', $commit['html_url'], $commit['commit']['message']));
        }
        $markdown[] = '</details>';

        // Create the release
        $http->request('POST', "https://api.github.com/repos/$repository/releases", [
            'headers' => [
                'Authorization' => 'token ' . $githubToken,
            ],
            'json' => [
                'tag_name' => $releaseName,
                'target_commitish' => 'master',
                'name' => $releaseName,
                'body' => implode("\n", $markdown),
                'draft' => false,
                'prerelease' => false,
            ],
        ]);
    }
);

$app->run();

function getRepositoryId(Client $http, $githubToken, $repositoryName)
{
    $response = $http->request('GET', "https://api.github.com/repos/$repositoryName", [
        'headers' => [
            'Authorization' => 'token ' . $githubToken,
        ],
    ]);
    $repositoryInfo = json_decode((string) $response->getBody(), true);
    return $repositoryInfo['id'];
}

function getZenhubBoard(Client $http, $zenhubToken, $repositoryId)
{
    $response = $http->request('GET', "https://api.zenhub.io/p1/repositories/$repositoryId/board", [
        'headers' => [
            'X-Authentication-Token' => $zenhubToken,
        ],
    ]);
    return json_decode((string) $response->getBody(), true);
}

function findDeployPipelineInBoard(array $board, $deployPipelineName)
{
    foreach ($board['pipelines'] as $pipeline) {
        if ($pipeline['name'] != $deployPipelineName) {
            continue;
        }
        return $pipeline;
    }
    throw new Exception("Pipeline $deployPipelineName not found");
}

function getEventsSinceLastRelease(Client $http, $githubToken, $repositoryName, $tag) {
    $response = $http->request('GET', "https://api.github.com/repos/$repositoryName/releases/latest", [
        'headers' => [
            'Authorization' => 'token ' . $githubToken,
        ],
    ]);

    $latestRelease = json_decode((string) $response->getBody(), true);

    $response = $http->request('GET', "https://api.github.com/repos/$repositoryName/compare/{$latestRelease['tag_name']}...$tag", [
        'headers' => [
            'Authorization' => 'token ' . $githubToken,
        ],
    ]);
    $commits = json_decode((string) $response->getBody(), true)['commits'];

    $result = [
        'PRs' => [],
        'commits' => [],
    ];

    foreach ($commits as $commit) {
        $response = $http->request('GET', "https://api.github.com/search/issues?q={$commit['sha']}  type:pr is:merged base:master", [
            'headers' => [
                'Authorization' => 'token '.$githubToken,
            ],
        ]);

        $PRs = json_decode((string) $response->getBody(), true);
        if ($PRs['total_count'] > 0) {
            $result['PRs'] = array_merge($result['PRs'], $PRs['items']);
        } else {
            $result['commits'][] = $commit;
        }
    }

    foreach ($result as $k => $v) {
        $result[$k] = array_intersect_key($v, array_unique(array_column($v, 'url')));
    }

    return $result;
}

function checkReleaseDoesNotExist(Client $http, $githubToken, $repositoryName, $releaseName)
{
    try {
        $http->request('GET', "https://api.github.com/repos/$repositoryName/releases/tags/$releaseName", [
            'headers' => [
                'Authorization' => 'token ' . $githubToken,
            ],
        ]);
        throw new Exception("The release $releaseName already exists");
    } catch (ClientException $e) {
        // We should get a 404
        if ($e->getResponse()->getStatusCode() !== 404) {
            throw $e;
        }
    }
}
